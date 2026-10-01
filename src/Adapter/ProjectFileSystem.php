<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\Agent\Platform\Layout;
use Sentinelo\AgentCore\Port\FileSystem;

/**
 * The {@see FileSystem} port over a Composer application's layout.
 *
 * All the platform knowledge lives in {@see Layout}; this class only turns it into the
 * port's vocabulary and owns the two operational concerns the port adds: scratch space
 * and free-space probing.
 */
final class ProjectFileSystem implements FileSystem
{
    public function __construct(private readonly Layout $layout)
    {
    }

    public function rootPath(): string
    {
        return $this->layout->projectRoot();
    }

    /**
     * Symfony, Sylius et Laravel rangent chacun de ces rôles dans un dossier unique
     * (public/media, storage/app/public, …) : la clé vide conserve exactement la
     * disposition d'archive existante, donc les archives déjà produites restent
     * restaurables. Le port accepte plusieurs sources par rôle depuis que PrestaShop
     * éclate son contenu utilisateur sur img/, upload/ et download/.
     *
     * @return array<string, string>
     */
    public function pathsFor(string $role): array
    {
        $path = $this->layout->pathFor($role);

        return $path === null ? [] : ['' => $path];
    }

    /** Dérivé de pathsFor() : une seule description de la disposition. */
    public function pathFor(string $role): ?string
    {
        $paths = $this->pathsFor($role);

        return $paths === [] ? null : reset($paths);
    }

    public function archivePrefixFor(string $role): string
    {
        return $this->layout->archivePrefixFor($role);
    }

    public function tempPath(): string
    {
        $path = $this->layout->tempPath();
        if (!is_dir($path)) {
            $this->makeDirectory($path);
        }

        return $path;
    }

    /**
     * Voisin du temporaire, et pas dedans : celui-ci est balayé à l'âge.
     *
     * Ces cibles n'appliquent aucune mise à jour de paquet ({@see RefusingPackageInstaller}),
     * donc rien n'y écrit aujourd'hui. Le chemin existe quand même : une capacité déclarée
     * mais sans emplacement se découvre absente le jour où elle sert, et le port doit
     * pouvoir être honoré sans exception.
     */
    public function rollbackPath(): string
    {
        return rtrim($this->layout->tempPath(), '/\\') . '/../rollback/';
    }

    public function freeSpace(string $path): ?int
    {
        $free = @disk_free_space(is_dir($path) ? $path : dirname($path));

        return $free === false ? null : (int) $free;
    }

    public function makeDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        if (!@mkdir($path, 0o775, true) && !is_dir($path)) {
            return false;
        }

        // The agent's scratch space sits inside the document-adjacent tree on Laravel
        // (storage/app). A stray .htaccess costs nothing and closes the case where a
        // misconfigured host serves it.
        $guard = rtrim($path, '/') . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Deny from all\nRequire all denied\n");
        }

        return true;
    }

    public function restoreExclusions(): array
    {
        return $this->layout->restoreExclusions();
    }
}
