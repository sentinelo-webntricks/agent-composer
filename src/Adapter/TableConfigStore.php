<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\ConfigStore;
use Sentinelo\AgentCore\Port\Crypto;

/**
 * The agent's own settings, in its own table.
 *
 * Not in `.env`, and not in the framework's parameter system, for one reason: some of
 * these values change at RUNTIME. `/cloud-config` writes the object-storage credentials
 * the platform sends, and the site uuid is assigned at enrolment. Writing those into a
 * file that the client's deploy pipeline overwrites — or that is baked into a
 * container image — would silently lose them on the next release.
 *
 * Values whose key is marked secret are encrypted at rest. The credentials this table
 * holds open a bucket containing full database dumps of the client's site; a plain-text
 * row in a database that is itself dumped into those backups would make every backup a
 * copy of the key to all the others.
 */
final class TableConfigStore implements ConfigStore
{
    /**
     * Keys stored encrypted. Explicit list rather than a heuristic on the key name:
     * "encrypt anything containing 'key'" is the kind of rule that quietly stops
     * matching the day someone names a field `bucket_id`.
     */
    private const SECRET_KEYS = [
        'api_key',
        'webhook_secret',
        'cloud_credentials',
    ];

    /** @var array<string, mixed> */
    private array $cache = [];

    private bool $loaded = false;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Crypto $crypto,
        private readonly string $table = 'sentinelo_config',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        if (!array_key_exists($key, $this->cache)) {
            return $default;
        }

        return $this->cache[$key];
    }

    public function set(string $key, mixed $value): bool
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return false;
        }

        $secret = in_array($key, self::SECRET_KEYS, true);
        $stored = $secret ? $this->crypto->encrypt($encoded) : $encoded;

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (config_key, config_value, is_secret, updated_at) VALUES (:k, :v, :s, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE config_value = :v2, is_secret = :s2, updated_at = UTC_TIMESTAMP()',
            $this->table
        ));

        $ok = $statement->execute([
            'k' => $key,
            'v' => $stored,
            's' => $secret ? 1 : 0,
            'v2' => $stored,
            's2' => $secret ? 1 : 0,
        ]);

        if ($ok) {
            $this->cache[$key] = $value;
        }

        return $ok;
    }

    /** Drops the in-process cache; used after a write from another process. */
    public function refresh(): void
    {
        $this->loaded = false;
        $this->cache = [];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $statement = $this->pdo->query(sprintf('SELECT config_key, config_value, is_secret FROM %s', $this->table));
        if ($statement === false) {
            return;
        }

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw = (string) $row['config_value'];

            if ((int) $row['is_secret'] === 1) {
                try {
                    $raw = $this->crypto->decrypt($raw);
                } catch (\RuntimeException) {
                    // A secret we cannot decrypt is a secret we do not have: the app
                    // secret was rotated, or the row was written by another install.
                    // Absent is the truthful answer — pretending it is there would make
                    // the next upload fail with an authentication error instead of the
                    // "credentials not configured" the orchestrator knows how to skip.
                    continue;
                }
            }

            $decoded = json_decode($raw, true);
            $this->cache[(string) $row['config_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
    }
}
