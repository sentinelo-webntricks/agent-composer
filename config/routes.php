<?php

declare(strict_types=1);

use Sentinelo\Agent\Symfony\AgentController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The agent's mount point on Symfony and Sylius: `_sentinelo/v1`.
 *
 * That exact prefix is what the backend builds its URLs from
 * (`App\Enum\SitePlatform::apiBasePath()`). Changing it here without changing it there
 * makes every call 404 — which the backend would report as "agent unreachable", sending
 * the operator to look at DNS.
 *
 * One catch-all route rather than sixteen declarations: the route list belongs to the
 * agent↔backend contract and lives in the shared `AgentRoute` enum, so restating it in
 * a routing file would create a second list to keep in step.
 *
 * Import from the application's config/routes.yaml:
 *
 *     sentinelo:
 *         resource: '@SentineloBundle/config/routes.php'
 *         type: php
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('sentinelo_agent', '/_sentinelo/v1/{route}')
        ->controller(AgentController::class)
        ->requirements(['route' => '.+'])
        ->methods(['GET', 'POST']);
};
