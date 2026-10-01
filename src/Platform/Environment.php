<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Platform;

/**
 * Reads the application's environment, from the process first and the .env file second.
 *
 * Order matters and is not arbitrary. In production the real values are usually
 * injected by the web server or the container and never appear in a file; on a shared
 * host they are only in .env. Reading the process first means a value overridden at
 * deploy time wins, which is what the application itself does.
 *
 * The .env parser is deliberately small: it exists so the agent can be *bootstrapped
 * from a cron job* where the framework has not run its own Dotenv, not to reimplement
 * one. It understands `KEY=value`, quotes, `export`, and comments — beyond that it
 * defers to whatever the framework already put in the process.
 */
final class Environment
{
    /** @var array<string, string>|null parsed .env, lazily read */
    private ?array $file = null;

    /** @param list<string> $files .env files, later ones overriding earlier ones */
    public function __construct(private readonly array $files)
    {
    }

    public static function forLayout(Layout $layout): self
    {
        $base = $layout->environmentFile();

        // .env.local last: that is the precedence both Symfony and Laravel use, and an
        // operator who put the production DSN there expects it to win.
        return new self([$base, $base . '.local']);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && is_scalar($_ENV[$key]) && (string) $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key]) && (string) $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        return $this->fromFile()[$key] ?? $default;
    }

    /** Interprets the loose truthiness both frameworks accept for boolean flags. */
    public function bool(string $key, bool $default = false): bool
    {
        $raw = $this->get($key);
        if ($raw === null) {
            return $default;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'on', 'yes'], true);
    }

    /** True when the key is set anywhere — distinct from being set to an empty value. */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** @return array<string, string> */
    private function fromFile(): array
    {
        if ($this->file !== null) {
            return $this->file;
        }

        $values = [];
        foreach ($this->files as $path) {
            if (!is_readable($path) || !is_file($path)) {
                continue;
            }
            foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim((string) $line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }
                $separator = strpos($line, '=');
                if ($separator === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $separator));
                $value = trim(substr($line, $separator + 1));

                // Strip one matching pair of quotes; anything else is taken literally.
                if (strlen($value) >= 2
                    && (($value[0] === '"' && str_ends_with($value, '"'))
                        || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }

                if ($key !== '') {
                    $values[$key] = $value;
                }
            }
        }

        return $this->file = $values;
    }
}
