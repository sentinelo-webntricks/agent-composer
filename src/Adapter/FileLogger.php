<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Port\Logger;

/**
 * Appends to a rotating file inside the agent's scratch directory.
 *
 * Deliberately does NOT reach for the application's logger. The agent has to be able
 * to say why a backup failed on a site whose container will not boot — which is when
 * an operator most wants the log — and a PSR-3 logger obtained from that container is
 * unavailable exactly then. It also keeps the client's own log stream free of our
 * chatter.
 *
 * Rotation is size-based and keeps a single previous file: enough to survive a failed
 * backup's timeline, bounded enough that a forgotten site cannot fill its own disk —
 * which would be an agent causing the incident it exists to prevent.
 */
final class FileLogger implements Logger
{
    private const MAX_BYTES = 2097152;

    private bool $unwritable = false;

    public function __construct(
        private readonly string $directory,
        private readonly Clock $clock,
    ) {
    }

    public function log(string $level, string $channel, string $message): void
    {
        if ($this->unwritable) {
            return;
        }

        $file = $this->prepare();
        if ($file === null) {
            // Remembered, so a read-only disk does not cost a syscall per log line for
            // the rest of the request.
            $this->unwritable = true;

            return;
        }

        // `[date] [LEVEL] [channel] message` — the shape
        // {@see \Sentinelo\AgentCore\Diagnostics\LogReader} parses, and therefore the
        // only one that lets the interface filter by level or channel.
        //
        // The old Monolog-ish `channel.LEVEL:` form read fine by eye but fell into the
        // reader's "unparsed line" branch, which returns the text but files everything
        // under `info` / `raw`. Nothing noticed, because the route did not reach here.
        $line = sprintf(
            "[%s] [%s] [%s] %s\n",
            $this->clock->now()->format('Y-m-d H:i:s'),
            strtoupper($level),
            $channel,
            str_replace(["\r", "\n"], ' ', $message)
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function prepare(): ?string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return null;
        }

        // One file PER DAY, `sentinelo-YYYY-MM-DD.log`, not a single rotating log.
        //
        // Not a filing preference: it is the shape
        // {@see \Sentinelo\AgentCore\Diagnostics\LogReader} knows how to read, and
        // therefore the condition for the `logs` route — the one that makes an agent's
        // journal readable from the interface instead of over SSH on the client's
        // server — to return anything at all. It is also what makes the reader's guard
        // honest ("a date, never a filename"): a date only means something if it
        // designates a file.
        //
        // The date comes from the injected clock, never from `date()`: this agent
        // already writes every timestamp in UTC, and a filename on local time would
        // put an entry in yesterday's file for half the fleet.
        $file = rtrim($this->directory, '/') . '/sentinelo-' . $this->clock->now()->format('Y-m-d') . '.log';

        // Size rotation stays as a second net: one very loud day must not produce a
        // file the reader has to walk in full.
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.1');
        }

        return $file;
    }
}
