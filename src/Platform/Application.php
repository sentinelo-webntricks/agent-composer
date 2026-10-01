<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Platform;

/**
 * Which Composer-based application the agent is installed in.
 *
 * The three share a package manager and nothing else that matters here: their file
 * layouts differ, their database configuration lives in different places, and their
 * debug switches have different names. Every one of those differences is answered by
 * this enum or by {@see Layout}, and nowhere else — that is what keeps the adapters
 * from growing a `if (class_exists('Illuminate\…'))` in twelve places.
 *
 * What they emphatically share: **none of them applies updates**. See
 * {@see \Sentinelo\Agent\Adapter\RefusingPackageInstaller}.
 */
enum Application: string
{
    case SYMFONY = 'symfony';
    case SYLIUS = 'sylius';
    case LARAVEL = 'laravel';

    public function label(): string
    {
        return match ($this) {
            self::SYMFONY => 'Symfony',
            self::SYLIUS => 'Sylius',
            self::LARAVEL => 'Laravel',
        };
    }

    /**
     * The Composer package whose installed version IS the application version.
     *
     * Reporting "the framework version" only means something if it names a package the
     * operator recognises: a Sylius shop owner tracks Sylius releases, not the version
     * of symfony/framework-bundle that Sylius happens to pin.
     */
    public function versionPackage(): string
    {
        return match ($this) {
            self::SYMFONY => 'symfony/framework-bundle',
            self::SYLIUS => 'sylius/sylius',
            self::LARAVEL => 'laravel/framework',
        };
    }

    /**
     * The environment variable holding the debug switch.
     *
     * Symfony and Sylius read APP_DEBUG; Laravel reads APP_DEBUG too but ALSO exposes
     * it through config/app.php. The name is what reaches the client's report, so it
     * has to be the one they would actually go and change.
     */
    public function debugVariable(): string
    {
        return 'APP_DEBUG';
    }

    /**
     * The variable holding the deployment environment.
     *
     * Not cosmetic: `APP_ENV=dev` in production is itself a finding, and the two
     * families spell the production value differently (`prod` vs `production`), so a
     * shared scorer cannot compare against a literal.
     *
     * @return array{variable: string, production: string}
     */
    public function environmentVariable(): array
    {
        return match ($this) {
            self::SYMFONY, self::SYLIUS => ['variable' => 'APP_ENV', 'production' => 'prod'],
            self::LARAVEL => ['variable' => 'APP_ENV', 'production' => 'production'],
        };
    }
}
