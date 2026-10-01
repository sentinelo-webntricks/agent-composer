<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Platform;

/**
 * Connection parameters, resolved from wherever this application keeps them.
 *
 * This is the reason `DatabaseDumper` is a port at all. Symfony and Sylius put a DSN
 * in `DATABASE_URL`; Laravel puts discrete `DB_*` variables in .env and merges them
 * through `config/database.php`. Neither is readable by the other's rules.
 *
 * The agent resolves them itself rather than asking the framework's container: it has
 * to work from a cron job on a site whose container may not even boot (a broken cache,
 * a bad deploy) — and a site that cannot boot is exactly when a backup matters most.
 */
final class DatabaseSettings
{
    private function __construct(
        public readonly string $driver,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $user,
        public readonly string $password,
        public readonly ?string $unixSocket = null,
    ) {
    }

    /**
     * @throws \RuntimeException when nothing usable was found. Refusing beats guessing:
     *         a dumper pointed at the wrong database produces a backup of nothing and
     *         reports success.
     */
    public static function resolve(Application $application, Environment $environment): self
    {
        $dsn = $environment->get('DATABASE_URL');
        if ($dsn !== null && $dsn !== '') {
            return self::fromDsn($dsn);
        }

        // Laravel's discrete variables. Read second, so a Laravel project that has
        // adopted DATABASE_URL (Laravel supports it) is still handled by the branch above.
        $database = $environment->get('DB_DATABASE');
        if ($database !== null && $database !== '') {
            return new self(
                driver: self::normaliseDriver((string) $environment->get('DB_CONNECTION', 'mysql')),
                host: (string) $environment->get('DB_HOST', '127.0.0.1'),
                port: (int) $environment->get('DB_PORT', '3306'),
                database: $database,
                user: (string) $environment->get('DB_USERNAME', ''),
                password: (string) $environment->get('DB_PASSWORD', ''),
                unixSocket: $environment->get('DB_SOCKET'),
            );
        }

        throw new \RuntimeException(sprintf(
            'Paramètres de base de données introuvables pour %s : ni DATABASE_URL ni DB_DATABASE '
            . 'ne sont définis dans l\'environnement ou le fichier .env.',
            $application->label()
        ));
    }

    /**
     * Parse a Doctrine-style DSN.
     *
     * `parse_url()` alone is not enough: Doctrine appends `?serverVersion=8.0&charset=…`
     * and — the part that bites — percent-encodes credentials, so a password containing
     * `@` or `/` comes back mangled unless it is decoded.
     */
    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new \RuntimeException('DATABASE_URL illisible : ' . $dsn);
        }

        $driver = self::normaliseDriver((string) $parts['scheme']);
        $database = ltrim((string) ($parts['path'] ?? ''), '/');

        // Doctrine's socket form: mysql://user:pass@localhost/db?unix_socket=/path.
        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        if ($database === '') {
            throw new \RuntimeException('DATABASE_URL ne désigne aucune base : ' . $dsn);
        }

        return new self(
            driver: $driver,
            host: (string) ($parts['host'] ?? '127.0.0.1'),
            port: (int) ($parts['port'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            database: rawurldecode($database),
            user: rawurldecode((string) ($parts['user'] ?? '')),
            password: rawurldecode((string) ($parts['pass'] ?? '')),
            unixSocket: isset($query['unix_socket']) ? (string) $query['unix_socket'] : null,
        );
    }

    public function pdoDsn(): string
    {
        if ($this->driver === 'pgsql') {
            return sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->database);
        }

        if ($this->unixSocket !== null && $this->unixSocket !== '') {
            return sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $this->unixSocket, $this->database);
        }

        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database);
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    /**
     * Doctrine's driver names (`pdo_mysql`, `mysqli`, `pdo_pgsql`) and Laravel's
     * (`mysql`, `pgsql`) describe the same two engines. Collapsing them here keeps the
     * distinction out of every caller.
     */
    private static function normaliseDriver(string $raw): string
    {
        $raw = strtolower($raw);

        return match (true) {
            str_contains($raw, 'pgsql'), str_contains($raw, 'postgres') => 'pgsql',
            str_contains($raw, 'mysql'), str_contains($raw, 'maria') => 'mysql',
            default => $raw,
        };
    }
}
