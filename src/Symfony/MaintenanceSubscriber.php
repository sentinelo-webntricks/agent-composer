<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Symfony;

use Sentinelo\Agent\Agent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves 503 while a restore is in progress.
 *
 * A restore rewrites the database underneath a running application. Without this, a
 * visitor arriving mid-restore reads a half-imported schema — at best an error page, at
 * worst an order written into a table that is about to be dropped.
 *
 * Two exemptions, and both are deliberate:
 *
 *  - **The agent's own routes stay open.** They are what the backend polls to learn that
 *    the restore finished. Locking them out would mean the site could never be brought
 *    back by the process that took it down.
 *  - **The flag expires.** A restore killed by a fatal never reaches its `disable()`.
 *    With an expiry the worst case is a site that returns by itself within the hour;
 *    without one it stays dark until a human notices.
 *
 * `Retry-After` is set so crawlers treat the outage as temporary rather than
 * de-indexing the site — a real cost of a maintenance page that does not say so.
 */
final class MaintenanceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Agent $agent)
    {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Priority above Symfony's router (32) so the check costs no routing work, and
        // applies to URLs that do not match any route either.
        return [KernelEvents::REQUEST => ['onRequest', 64]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/_sentinelo/')) {
            return;
        }

        try {
            if (!$this->agent->maintenance()->isEnabled()) {
                return;
            }
        } catch (\Throwable) {
            // The agent failing to answer must never take the client's site down. An
            // unreadable flag means "not in maintenance".
            return;
        }

        $event->setResponse(new Response(
            '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<title>Maintenance en cours</title></head><body>'
            . '<h1>Maintenance en cours</h1>'
            . '<p>Le site est temporairement indisponible pendant une opération de restauration. '
            . 'Il revient dans quelques minutes.</p></body></html>',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '300', 'Content-Type' => 'text/html; charset=utf-8']
        ));
    }
}
