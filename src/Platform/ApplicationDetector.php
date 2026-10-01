<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Platform;

/**
 * Works out which application the agent has been installed into, from composer.lock.
 *
 * Deliberately reads the LOCK and not `class_exists()`. A Sylius shop has
 * symfony/framework-bundle installed too, so class probing answers "Symfony" for a
 * Sylius site — and the agent would then report the wrong platform, look for
 * config/packages instead of the shop's media root, and file the site under the wrong
 * label in the client's monthly report. The lock file states what was *asked for*.
 *
 * Order matters for the same reason: Sylius is tested before Symfony because every
 * Sylius project is also a Symfony project.
 */
final class ApplicationDetector
{
    /** Packages that identify an application, most specific first. */
    private const SIGNATURES = [
        'sylius/sylius' => Application::SYLIUS,
        'sylius/core-bundle' => Application::SYLIUS,
        'laravel/framework' => Application::LARAVEL,
        'symfony/framework-bundle' => Application::SYMFONY,
    ];

    /** @var array<string, string>|null package => version, lazily read */
    private ?array $packages = null;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @throws \RuntimeException when no signature matches — better than defaulting to
     *         Symfony and silently mis-describing a site for the rest of its life
     */
    public function detect(): Application
    {
        $packages = $this->packages();

        foreach (self::SIGNATURES as $package => $application) {
            if (isset($packages[$package])) {
                return $application;
            }
        }

        throw new \RuntimeException(sprintf(
            'Application non reconnue dans %s : aucun de %s n\'est installé. '
            . 'L\'agent Composer cible Symfony, Sylius et Laravel.',
            $this->projectRoot,
            implode(', ', array_keys(self::SIGNATURES))
        ));
    }

    /** Installed version of the application itself, or null when it cannot be read. */
    public function applicationVersion(Application $application): ?string
    {
        return $this->packages()[$application->versionPackage()] ?? null;
    }

    /**
     * Every installed package and its version, from composer.lock.
     *
     * @return array<string, string>
     */
    public function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $lock = rtrim($this->projectRoot, '/') . '/composer.lock';
        if (!is_readable($lock)) {
            return $this->packages = [];
        }

        $decoded = json_decode((string) file_get_contents($lock), true);
        if (!is_array($decoded)) {
            return $this->packages = [];
        }

        $packages = [];
        // packages-dev included on purpose: a dev dependency present in production is
        // itself part of the site's attack surface, and the backend's CVE correlation
        // should see it rather than have the agent quietly filter it out.
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) ($decoded[$section] ?? []) as $package) {
                if (is_array($package) && isset($package['name'], $package['version'])) {
                    $packages[(string) $package['name']] = ltrim((string) $package['version'], 'v');
                }
            }
        }

        return $this->packages = $packages;
    }

    /** True when composer.lock could be read — the inventory is meaningless without it. */
    public function hasLock(): bool
    {
        return $this->packages() !== [];
    }
}
