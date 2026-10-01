<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\MaintenanceMode;

/**
 * Maintenance mode as a flag file, enforced by the agent's own HTTP layer.
 *
 * Neither Symfony nor Sylius has a built-in maintenance switch, and Laravel's
 * (`php artisan down`) writes a file whose JSON shape has changed across majors —
 * hand-writing it would work until the client upgrades and then fail silently, at the
 * exact moment a restore is overwriting their database.
 *
 * So the agent owns the flag and ships the enforcement: a Symfony event subscriber and
 * a Laravel middleware, both of which answer 503 while the flag exists. The client
 * wires one line at install time. That is honest — the client can read what it does —
 * and it cannot break under a framework upgrade.
 *
 * The flag carries a timestamp because the failure mode that matters is a maintenance
 * mode left ON: a restore that dies on a fatal never reaches its `disable()`. The
 * enforcement layer treats a flag older than its lifetime as expired, so the worst case
 * is a site that comes back by itself rather than one that stays down until someone
 * notices.
 */
final class FlagMaintenanceMode implements MaintenanceMode
{
    /** After this long the flag is ignored — see the class docblock. */
    public const MAX_AGE_SECONDS = 3600;

    public function __construct(private readonly string $flagPath)
    {
    }

    public function enable(): void
    {
        $directory = dirname($this->flagPath);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le répertoire du drapeau de maintenance : ' . $directory);
        }

        $payload = json_encode([
            'enabled_at' => gmdate('c'),
            'by' => 'sentinelo-agent',
        ], JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($this->flagPath, $payload) === false) {
            throw new \RuntimeException('Impossible d\'activer le mode maintenance : ' . $this->flagPath);
        }
    }

    public function disable(): void
    {
        if (is_file($this->flagPath)) {
            @unlink($this->flagPath);
        }
    }

    public function isEnabled(): bool
    {
        if (!is_file($this->flagPath)) {
            return false;
        }

        $age = time() - (int) @filemtime($this->flagPath);

        return $age >= 0 && $age < self::MAX_AGE_SECONDS;
    }

    public function flagPath(): string
    {
        return $this->flagPath;
    }
}
