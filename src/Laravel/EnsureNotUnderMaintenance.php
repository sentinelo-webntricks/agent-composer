<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Laravel;

use Closure;
use Illuminate\Http\Request;
use Sentinelo\Agent\Agent;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves 503 while a restore is in progress. The Laravel half of
 * {@see \Sentinelo\Agent\Symfony\MaintenanceSubscriber} — same reasoning, same
 * exemptions.
 *
 * Deliberately not `php artisan down`: that writes a file whose JSON shape has changed
 * across Laravel majors, so hand-writing it works until the client upgrades and then
 * fails silently — at the exact moment a restore is rewriting their database.
 */
final class EnsureNotUnderMaintenance
{
    public function __construct(private readonly Agent $agent)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with('/' . ltrim($request->path(), '/'), '/_sentinelo/')) {
            // The agent's own routes stay reachable: they are how the backend learns
            // the restore finished, and therefore how the site comes back up.
            return $next($request);
        }

        try {
            if (!$this->agent->maintenance()->isEnabled()) {
                return $next($request);
            }
        } catch (\Throwable) {
            // The agent failing must never take the client's site down.
            return $next($request);
        }

        return new Response(
            '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<title>Maintenance en cours</title></head><body>'
            . '<h1>Maintenance en cours</h1>'
            . '<p>Le site est temporairement indisponible pendant une opération de restauration. '
            . 'Il revient dans quelques minutes.</p></body></html>',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '300', 'Content-Type' => 'text/html; charset=utf-8']
        );
    }
}
