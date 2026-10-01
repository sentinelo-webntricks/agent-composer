<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Webhook;

use Sentinelo\AgentCore\Port\Logger;
use Sentinelo\AgentCore\Port\WebhookEmitter;
use Sentinelo\AgentCore\Webhook\Queue\WebhookQueueRepository;
use Sentinelo\AgentCore\Webhook\Queue\WebhookRetryPolicy;
use Sentinelo\AgentCore\Webhook\WebhookDispatcher;

/**
 * Sends a webhook now; queues it for retry when — and only when — retrying could help.
 *
 * The signing, enveloping and routing all live in the shared
 * {@see WebhookDispatcher}. What is platform-specific is where a failed delivery
 * waits, so that is all this class adds.
 *
 * The `retryable` distinction is load-bearing. A delivery that failed because the
 * agent has no webhook secret configured will fail identically in five minutes and in
 * five hours; queueing it just fills the table and buries the real failures. Only a
 * transport-level failure earns a retry.
 *
 * Worth remembering while reading this: the webhook is a **fast path, never the source
 * of truth**. The backend also polls `/backup/status` and `/security/report`, and acts
 * on whichever arrives first. A webhook lost for good costs latency, not data — which
 * is exactly why it is safe for this class to give up after five attempts.
 */
final class QueueingWebhookEmitter implements WebhookEmitter
{
    private const MAX_ATTEMPTS = 5;

    /** Items drained per sweep — bounded so a cron tick cannot run for minutes. */
    private const BATCH = 50;

    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly WebhookQueueRepository $queue,
        private readonly Logger $logger,
    ) {
    }

    public function send(string $eventType, array $payload): bool
    {
        $result = $this->dispatcher->send($eventType, $payload);

        if (!$result->sent && $result->retryable) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                $this->logger->log('error', 'webhook', "Payload {$eventType} non encodable — abandonné");

                return false;
            }

            $this->queue->enqueue($eventType, $encoded, self::MAX_ATTEMPTS);
            $this->logger->log('info', 'webhook', "Webhook {$eventType} mis en file d'attente");
        }

        return $result->sent;
    }

    /** Drain the queue. Called from the scheduled sweep. */
    public function drain(): int
    {
        $sent = 0;

        foreach ($this->queue->dueItems(self::BATCH) as $item) {
            $payload = json_decode($item->payloadJson, true);
            if (!is_array($payload)) {
                // Unparseable: no number of retries will fix it.
                $this->queue->markInvalid($item->id, 'Payload JSON illisible');
                continue;
            }

            // Straight to the dispatcher, not through send(): going through send()
            // would re-enqueue a failed retry as a brand-new item, and the queue would
            // grow one row per attempt instead of counting attempts on the row.
            if ($this->dispatcher->send($item->eventType, $payload)->sent) {
                $this->queue->markSent($item->id);
                ++$sent;
                continue;
            }

            $decision = WebhookRetryPolicy::decide($item->attempts, $item->maxAttempts);
            if ($decision->abandon) {
                $this->queue->markFailed($item->id, $decision->attempts, 'Nombre maximal de tentatives atteint');
            } else {
                $this->queue->reschedule($item->id, $decision->attempts, $decision->delayMinutes);
            }
        }

        return $sent;
    }
}
