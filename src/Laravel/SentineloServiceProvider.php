<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sentinelo\Agent\Agent;
use Sentinelo\AgentCore\Http\Router as AgentRouter;
use Sentinelo\Agent\Scheduling\TaskRunner;

/**
 * Mounts the agent in a Laravel application.
 *
 * Auto-discovered through `extra.laravel.providers` in composer.json, so installing the
 * package is the whole installation. The routes are registered here rather than
 * published into the client's `routes/` — a published file is a file the client edits,
 * and a mount point that drifts from `SitePlatform::apiBasePath()` makes every backend
 * call 404 while looking like an unreachable site.
 *
 * The agent is built from the project root and its own `.env`, not from Laravel's
 * database manager: it has to work in a cron job on an application that will not boot,
 * which is exactly when a backup is wanted.
 *
 * Routes carry NO middleware group. `web` would attach the session and CSRF stack to a
 * machine-to-machine API — a POST from the backend would then be rejected for a missing
 * CSRF token — and `api` applies the client's own throttling, which would rate-limit our
 * platform out of its own agent. Authentication is the agent's API key, checked in
 * {@see \Sentinelo\AgentCore\Http\Authenticator}.
 */
final class SentineloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Agent::class, fn (): Agent => Agent::fromProjectRoot(
            $this->app->basePath(),
            (string) (env('SENTINELO_SAAS_URL') ?: 'https://backend.sentinelo.fr')
        ));

        $this->app->singleton(AgentRouter::class, fn ($app): AgentRouter => new AgentRouter($app->make(Agent::class), Agent::VERSION));
        $this->app->singleton(TaskRunner::class, fn ($app): TaskRunner => new TaskRunner($app->make(Agent::class)));
    }

    public function boot(): void
    {
        Route::match(
            ['GET', 'POST'],
            '_sentinelo/v1/{route}',
            AgentController::class
        )->where('route', '.*');

        // Prepended, so it runs before the client's own middleware — a restore must
        // take the site offline even for routes that skip the `web` group.
        $this->app->make(Kernel::class)->prependMiddleware(EnsureNotUnderMaintenance::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SentineloRunCommand::class, SentineloEnrollCommand::class]);
        }
    }
}
