<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\Clock;

/**
 * The one clock in the agent, and it is UTC.
 *
 * Not a stylistic choice. The backend parses every timestamp the agent sends in ITS
 * timezone, so a site running on Europe/Paris that reports a naive local wall clock
 * files its 03:00 backup at 01:00 — and the report, the retention window and the
 * "stale job" force-fail all shift with it. `date_default_timezone_get()` is whatever
 * the host's php.ini says, which is precisely what must not leak in here.
 *
 * Writes and comparisons therefore share this instance; see
 * {@see \Sentinelo\AgentCore\Support\Timestamps} for the storage/wire formats.
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
