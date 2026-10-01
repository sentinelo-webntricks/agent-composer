<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Repository;

use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Support\Timestamps;
use Sentinelo\AgentCore\Webhook\Queue\QueuedWebhook;
use Sentinelo\AgentCore\Webhook\Queue\WebhookQueueRepository;

/**
 * The webhook retry queue over PDO.
 *
 * One clock, and it is the agent's UTC one. The WordPress agent shipped a version of
 * this where rows were written with a local-time `next_attempt_at` and read back
 * against a UTC comparison: on a site offset by two hours, every retry sat "not due
 * yet" for two hours, or fired immediately in a loop, depending on the sign. The bug
 * cost a release. Both the write and the comparison here go through
 * {@see Timestamps::store()} on the same {@see Clock}.
 */
final class PdoWebhookQueueRepository implements WebhookQueueRepository
{
    private readonly string $table;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
        string $prefix = 'sentinelo_',
    ) {
        $this->table = $prefix . 'webhook_queue';
    }

    public function enqueue(string $eventType, string $payloadJson, int $maxAttempts): void
    {
        $now = Timestamps::store($this->clock->now());

        $this->pdo->prepare(
            "INSERT INTO {$this->table} (event_type, payload_json, attempts, max_attempts, next_attempt_at, created_at)
             VALUES (:event, :payload, 0, :max, :next, :created)"
        )->execute([
            'event' => $eventType,
            'payload' => $payloadJson,
            'max' => $maxAttempts,
            'next' => $now,
            'created' => $now,
        ]);
    }

    public function dueItems(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, event_type, payload_json, attempts, max_attempts
             FROM {$this->table}
             WHERE next_attempt_at IS NOT NULL AND next_attempt_at <= :now
             ORDER BY id ASC
             LIMIT " . max(1, $limit)
        );
        $statement->execute(['now' => Timestamps::store($this->clock->now())]);

        $items = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $items[] = new QueuedWebhook(
                (int) $row['id'],
                (string) $row['event_type'],
                (string) $row['payload_json'],
                (int) $row['attempts'],
                (int) $row['max_attempts'],
            );
        }

        return $items;
    }

    public function markSent(mixed $id): void
    {
        // Delivered items are deleted, not flagged: the queue is a work list, and a
        // table that only ever grows becomes the largest thing in the client's backup.
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id")->execute(['id' => (int) $id]);
    }

    public function markInvalid(mixed $id, string $reason): void
    {
        // next_attempt_at NULL removes it from dueItems() for good while keeping the
        // row readable: an operator asking "why did this event never arrive?" gets an
        // answer instead of an absence.
        $this->stop((int) $id, $reason);
    }

    public function reschedule(mixed $id, int $attempts, int $delayMinutes): void
    {
        $next = $this->clock->now()->modify('+' . max(1, $delayMinutes) . ' minutes');

        $this->pdo->prepare(
            "UPDATE {$this->table} SET attempts = :attempts, next_attempt_at = :next WHERE id = :id"
        )->execute([
            'attempts' => $attempts,
            'next' => Timestamps::store($next),
            'id' => (int) $id,
        ]);
    }

    public function markFailed(mixed $id, int $attempts, string $reason): void
    {
        $this->pdo->prepare(
            "UPDATE {$this->table} SET attempts = :attempts, next_attempt_at = NULL, last_error = :error WHERE id = :id"
        )->execute(['attempts' => $attempts, 'error' => $reason, 'id' => (int) $id]);
    }

    /** Rows abandoned long ago; called from the daily cleanup. */
    public function purgeAbandoned(int $olderThanDays = 30): int
    {
        $cutoff = $this->clock->now()->modify('-' . max(1, $olderThanDays) . ' days');

        $statement = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE next_attempt_at IS NULL AND created_at < :cutoff"
        );
        $statement->execute(['cutoff' => Timestamps::store($cutoff)]);

        return $statement->rowCount();
    }

    private function stop(int $id, string $reason): void
    {
        $this->pdo->prepare(
            "UPDATE {$this->table} SET next_attempt_at = NULL, last_error = :error WHERE id = :id"
        )->execute(['error' => $reason, 'id' => $id]);
    }
}
