<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\Agent\Platform\Application;
use Sentinelo\Agent\Platform\ApplicationDetector;
use Sentinelo\AgentCore\Inventory\InstalledComponent;
use Sentinelo\AgentCore\Port\Inventory;
use Sentinelo\AgentCore\Port\InventoryDeclaration;

/**
 * The installed inventory, read from composer.lock.
 *
 * "Installed extension" does not mean the same thing here as on WordPress, and
 * pretending otherwise would produce a report the client cannot act on. There is no
 * marketplace, no per-package enable/disable, and no notion of a theme. What exists is
 * a dependency graph that the client's `composer.json` pins and their pipeline
 * deploys.
 *
 * So this adapter answers honestly and narrowly:
 *
 *  - **Extensions** are the Composer packages in the lock, reported for one reason: the
 *    backend correlates them against CVE feeds (`ComposerAuditChecker`). That is real
 *    value the client cannot get from the agent alone.
 *  - **Themes** are an empty list. There is no such concept; returning a fabricated
 *    entry would put a row in the client's report that maps to nothing on their disk.
 *  - **`latestVersion` is always null — UNKNOWN, never "up to date".** The agent has no
 *    way to learn a target version: querying Packagist for every package on every scan
 *    would be hundreds of outbound requests from a client's production host, and the
 *    answer would be wrong anyway because the client's constraints, not the latest
 *    release, decide what they can install. Collapsing unknown into "up to date" is the
 *    exact bug that made the WordPress themes endpoint announce updates *to the version
 *    already running*; here it would additionally imply the fleet is patched when
 *    nobody has checked.
 *
 * `active` is likewise always false: a package being in the lock says nothing about
 * whether the application loads it, and inventing an answer would make the field lie
 * on a platform where it happens to be readable.
 */
final class ComposerInventory implements Inventory, InventoryDeclaration
{
    public function __construct(
        private readonly Application $application,
        private readonly ApplicationDetector $detector,
    ) {
    }

    public function listComponents(string $type): array
    {
        if ($type !== self::TYPE_EXTENSION) {
            // Core is served by core(); themes do not exist here.
            return [];
        }

        $components = [];
        foreach ($this->detector->packages() as $name => $version) {
            $components[] = new InstalledComponent(
                type: self::TYPE_EXTENSION,
                slug: $name,
                name: $name,
                version: $version,
                // See the class docblock: UNKNOWN, and it must stay distinguishable
                // from "up to date".
                latestVersion: null,
                active: false,
                autoUpdate: false,
                extra: ['source' => 'composer.lock'],
            );
        }

        return $components;
    }

    /**
     * What this inventory knows about ITSELF — the half a list cannot express.
     *
     * The themes list here is empty for a reason a reader cannot recover from the list:
     * a Composer application has **no notion of an installable theme**, so the list will
     * never fill. Left undeclared, the shared router answers `supported: null` — "not
     * declared" — which is the honest reading of silence but weaker than the truth we
     * actually have. `false` is a fact here, and stating it is what lets the interface
     * omit a theme screen rather than show an empty one.
     *
     * Read {@see InventoryDeclaration} for why this is an optional capability rather
     * than three more methods on `Inventory`.
     */
    public function supportsComponentType(string $type): bool
    {
        return $type !== self::TYPE_THEME;
    }

    /**
     * The artefact this list is actually read from.
     *
     * This sentence used to be hardcoded in the shared router, which meant every other
     * platform was told about a file it does not have. It belongs to whoever owns the
     * list. Themes are declared unsupported above, so naming a source for them would be
     * naming the provenance of something that cannot exist.
     */
    public function inventorySource(string $type): ?string
    {
        return $type === self::TYPE_EXTENSION ? 'composer.lock' : null;
    }

    /**
     * Why no target version is ever known — the platform-specific half of the reason.
     *
     * The class docblock carries the full argument; this is the sentence a human reads
     * in a report. Without it, a payload where `update_available` is false throughout
     * reads as "everything is up to date", which is precisely the inference
     * `latestVersion: null` exists to prevent.
     */
    public function updateDetectionReason(string $type): ?string
    {
        if ($type !== self::TYPE_EXTENSION) {
            return null;
        }

        return 'La version cible dépend des contraintes du projet, pas de la dernière '
            . 'version publiée : l\'agent ne peut pas la déterminer et ne la devine pas.';
    }

    public function core(): InstalledComponent
    {
        $package = $this->application->versionPackage();
        $version = $this->detector->applicationVersion($this->application);

        return new InstalledComponent(
            type: self::TYPE_CORE,
            slug: $package,
            name: $this->application->label(),
            // An unreadable lock leaves the version unknown too. '' rather than a
            // plausible-looking guess: the backend can render "inconnu", it cannot
            // un-believe a number.
            version: $version ?? '',
            latestVersion: null,
            active: true,
            autoUpdate: false,
            extra: [
                'lock_readable' => $this->detector->hasLock(),
                // Explicit, because "latest_version: null" alone has historically been
                // read as "nothing to do". This says why it is null, so the backend and
                // the report can word it as "non déterminé" instead of "à jour".
                'latest_version_status' => 'unknown',
                'latest_version_reason' => 'Aucun dépôt de référence : la version cible dépend des '
                    . 'contraintes du projet, pas de la dernière version publiée.',
            ],
        );
    }
}
