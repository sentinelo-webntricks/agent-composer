<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Platform;

use Sentinelo\AgentCore\Port\FileSystem;

/**
 * Where each application keeps the things the agent cares about.
 *
 * The relevant cut on a Composer application is not wp-content. It is:
 *
 *   Symfony  src/ · templates/ · public/uploads · scratch in var/
 *   Sylius   src/ · templates/ · public/media   · scratch in var/
 *   Laravel  app/ · resources/views · storage/app · scratch in storage/
 *
 * Two decisions are baked in here, and both are about what a backup is FOR.
 *
 * **The media root is the only irreplaceable directory.** Application code is in
 * version control and is rebuilt by the client's pipeline; product images uploaded by
 * a shop owner are not, and exist nowhere else. So the uploads role points at the
 * media root, and it is the role that must never be missing.
 *
 * **var/ and storage/framework are scratch, not content.** They hold the compiled
 * container, the Twig cache, sessions and logs — regenerable by definition, routinely
 * several gigabytes, and actively harmful to restore (a compiled container from
 * another machine's paths does not boot). They are therefore not archived; instead the
 * agent uses that same writable space as its own working directory, which is what it
 * is there for.
 */
final class Layout
{
    public function __construct(
        private readonly Application $application,
        private readonly string $projectRoot,
    ) {
    }

    public function projectRoot(): string
    {
        return rtrim($this->projectRoot, '/');
    }

    /**
     * Absolute directory for a role, or null when this application has none.
     *
     * Returns null rather than a non-existent path: `pathFor()` returning a directory
     * that is not there would make the archiver record an empty role as if it had
     * archived it, and the difference between "this app has no media" and "the media
     * were not backed up" is the difference between a restore that works and one that
     * silently loses every product image.
     */
    public function pathFor(string $role): ?string
    {
        $candidates = match ($role) {
            FileSystem::ROLE_EXTENSIONS => match ($this->application) {
                Application::SYMFONY, Application::SYLIUS => ['src'],
                Application::LARAVEL => ['app'],
            },
            FileSystem::ROLE_THEMES => match ($this->application) {
                Application::SYMFONY, Application::SYLIUS => ['templates'],
                Application::LARAVEL => ['resources/views'],
            },
            FileSystem::ROLE_UPLOADS => match ($this->application) {
                // Sylius' default media root first; the plain-Symfony convention
                // (VichUploader's usual target) second, so a Sylius shop that also has
                // public/uploads still gets its real media root.
                Application::SYLIUS => ['public/media', 'public/uploads'],
                Application::SYMFONY => ['public/uploads', 'public/media'],
                Application::LARAVEL => ['storage/app/public', 'storage/app'],
            },
            default => [],
        };

        foreach ($candidates as $candidate) {
            $path = $this->projectRoot() . '/' . $candidate;
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Directory name a role occupies inside the archive.
     *
     * Stable across applications on purpose — an archive says "media", not
     * "public/media", so restoring onto a project whose media root has been moved
     * still lands in the right place.
     */
    public function archivePrefixFor(string $role): string
    {
        return match ($role) {
            FileSystem::ROLE_EXTENSIONS => 'source',
            FileSystem::ROLE_THEMES => 'templates',
            FileSystem::ROLE_UPLOADS => 'media',
            default => trim($role, '/'),
        };
    }

    /** Writable scratch space — the application's own regenerable-data directory. */
    public function tempPath(): string
    {
        $relative = match ($this->application) {
            Application::SYMFONY, Application::SYLIUS => 'var/sentinelo',
            Application::LARAVEL => 'storage/app/sentinelo',
        };

        return $this->projectRoot() . '/' . $relative;
    }

    /** The application's environment file, whether or not it exists. */
    public function environmentFile(): string
    {
        return $this->projectRoot() . '/.env';
    }

    /** The publicly served document root. */
    public function publicPath(): string
    {
        $relative = match ($this->application) {
            Application::SYMFONY, Application::SYLIUS => 'public',
            Application::LARAVEL => 'public',
        };

        return $this->projectRoot() . '/' . $relative;
    }

    /**
     * Paths a restore must never write into, relative to the archive's files/ root.
     *
     * `vendor` is the agent's own home: overwriting it mid-restore replaces the code
     * currently executing the restore. `.env` carries the credentials of THIS
     * environment — restoring the ones captured on another would point production at
     * the staging database, which is a data-loss bug wearing the costume of a restore.
     *
     * @return list<string>
     */
    public function restoreExclusions(): array
    {
        return ['vendor', '.env', '.env.local', 'var/sentinelo', 'storage/app/sentinelo'];
    }
}
