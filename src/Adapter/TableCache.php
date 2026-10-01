<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\Cache;
use Sentinelo\AgentCore\Port\Clock;

/**
 * Short-lived values in a table, with expiry treated as a guarantee.
 *
 * Not the framework's cache pool, and the reason is the rate limiter. On a Symfony
 * site the default pool is often the filesystem adapter, which is per-server; on
 * Laravel it is frequently the `array` driver in a queue worker, which is per-process.
 * A rate-limit counter that resets when a request lands on another PHP-FPM worker does
 * not limit anything — and this cache also backs the webhook retry queue's backoff,
 * where forgetting a marker means re-sending an event the backend already processed.
 *
 * The table is the same one the rest of the agent already needs, so this costs no new
 * infrastructure and works identically on one server or four.
 */
final class TableCache implements Cache
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
        private readonly string $table = 'sentinelo_cache',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->row($key);
        if ($row === null) {
            return $default;
        }

        $decoded = json_decode((string) $row['cache_value'], true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return false;
        }

        // ttl 0 means "until explicitly deleted": stored as NULL rather than as a far
        // future date, so a purge query can tell the two apart.
        $expires = $ttlSeconds > 0
            ? $this->clock->now()->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s')
            : null;

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (cache_key, cache_value, expires_at) VALUES (:k, :v, :e) '
            . 'ON DUPLICATE KEY UPDATE cache_value = :v2, expires_at = :e2',
            $this->table
        ));

        return $statement->execute(['k' => $key, 'v' => $encoded, 'e' => $expires, 'v2' => $encoded, 'e2' => $expires]);
    }

    public function delete(string $key): bool
    {
        $statement = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE cache_key = :k', $this->table));

        return $statement->execute(['k' => $key]);
    }

    /** Distinguishes a stored null from an absent key — the port requires that. */
    public function has(string $key): bool
    {
        return $this->row($key) !== null;
    }

    /** Removes expired rows. Called from the scheduled sweep, not on every read. */
    public function purgeExpired(): int
    {
        $statement = $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE expires_at IS NOT NULL AND expires_at < :now',
            $this->table
        ));
        $statement->execute(['now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }

    /** @return array<string, mixed>|null */
    private function row(string $key): ?array
    {
        $statement = $this->pdo->prepare(sprintf(
            // The expiry is evaluated against the agent's UTC clock, not the database
            // server's local time: the two are routinely different, and the mismatch
            // would make a 60-second rate-limit window last an hour, or none at all.
            'SELECT cache_value FROM %s WHERE cache_key = :k AND (expires_at IS NULL OR expires_at > :now)',
            $this->table
        ));
        $statement->execute(['k' => $key, 'now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
