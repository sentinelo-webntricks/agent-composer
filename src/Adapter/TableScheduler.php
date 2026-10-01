<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\Cache;
use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Port\Logger;
use Sentinelo\AgentCore\Port\Scheduler;

/**
 * Due-date bookkeeping for the agent's recurring work. The trigger is the operator's
 * system cron.
 *
 * There is no wp-cron here, and that is a good thing: wp-cron fires on visitor traffic,
 * so a quiet site simply stops backing up. On these platforms the install instructions
 * add one crontab line calling `sentinelo:run`, and this class answers the only
 * question that tick needs to ask — *what is due?*
 *
 * Two properties matter and are the reason this is not a naive "run everything each
 * tick":
 *
 *  - **Idempotent registration.** Booting twice must not stack two schedules for one
 *    hook, which is why `schedule()` preserves an existing due date instead of
 *    resetting it. Resetting it on every boot would push the nightly backup forward by
 *    a few seconds on each request and, on a busy site, mean it never comes due at all.
 *  - **The anchor survives.** The nightly backup is anchored to 03:00 UTC, not to
 *    "24 h after whenever the agent was installed".
 *
 * None of this is load-bearing for correctness: backups, restores and scans are
 * reconciled by backend-side pollers. A missed tick costs timeliness, not integrity.
 */
final class TableScheduler implements Scheduler
{
    private const PREFIX = 'schedule.';

    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function schedule(string $hook, int $intervalSeconds, ?int $firstRunAt = null): void
    {
        $existing = $this->entry($hook);
        if ($existing !== null) {
            // Already registered: keep its due date. See the class docblock.
            return;
        }

        $this->store($hook, $intervalSeconds, $firstRunAt ?? $this->clock->now()->getTimestamp());
    }

    public function unschedule(string $hook): void
    {
        $this->cache->delete(self::PREFIX . $hook);
    }

    public function nextRun(string $hook): ?int
    {
        $entry = $this->entry($hook);

        return $entry === null ? null : (int) $entry['next_run'];
    }

    public function onDue(string $hook, callable $handler): void
    {
        $this->handlers[$hook] = $handler;
    }

    /**
     * Fire every task whose due date has passed. Called once per cron tick.
     *
     * The due date is advanced BEFORE the handler runs. A backup that dies on a fatal
     * would otherwise still be due on the next tick, and the site would spend the night
     * starting a backup every minute — the failure amplifying itself.
     *
     * @return array<string, string> hook => outcome, for the console command to print
     */
    public function runDue(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $outcomes = [];

        foreach ($this->handlers as $hook => $handler) {
            $entry = $this->entry($hook);
            if ($entry === null || (int) $entry['next_run'] > $now) {
                continue;
            }

            $interval = max(60, (int) $entry['interval']);
            // Roll forward past every missed occurrence rather than adding one
            // interval: a site down for a week must not then run seven backups.
            $next = (int) $entry['next_run'];
            while ($next <= $now) {
                $next += $interval;
            }
            $this->store($hook, $interval, $next);

            try {
                $handler();
                $outcomes[$hook] = 'ok';
            } catch (\Throwable $e) {
                $this->logger->log('error', 'scheduler', $hook . ' : ' . $e->getMessage());
                $outcomes[$hook] = 'échec : ' . $e->getMessage();
            }
        }

        return $outcomes;
    }

    /** @return array{interval: int, next_run: int}|null */
    private function entry(string $hook): ?array
    {
        $raw = $this->cache->get(self::PREFIX . $hook);

        return is_array($raw) && isset($raw['interval'], $raw['next_run']) ? $raw : null;
    }

    private function store(string $hook, int $intervalSeconds, int $nextRun): void
    {
        // ttl 0: a schedule is durable. An expiring entry would silently unschedule the
        // nightly backup of a site nobody visits.
        $this->cache->set(self::PREFIX . $hook, ['interval' => $intervalSeconds, 'next_run' => $nextRun], 0);
    }
}
