<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\Agent\Platform\Application;
use Sentinelo\AgentCore\Port\PackageInstaller;
use Sentinelo\AgentCore\Port\UpdatesNotSupported;
use Sentinelo\AgentCore\Update\UpdateOutcome;

/**
 * The update port, implemented as an explicit and permanent refusal.
 *
 * This is a **product decision, not a missing feature**, and the code says so out loud
 * rather than leaving a TODO for someone to helpfully "fix".
 *
 * Updating a Composer package in production means `composer update` followed by
 * database migrations and a cache rebuild. That sequence is not atomic: the moment
 * `vendor/` changes, the running application is a mixture of two versions, and if the
 * migration then fails there is no rollback. It also requires a writable `vendor/`,
 * which most production deployments deliberately do not have — and where it exists,
 * writing to it silently diverges the running code from the artefact the client's
 * pipeline built, so their next deploy would erase our change without either side
 * noticing.
 *
 * What the client gets on these platforms is surveillance, backup, security and
 * reporting. What they do not get is a "mettre à jour" button — and the backend knows
 * it, through `SitePlatform::supportsPackageUpdates()`, so the button is not offered in
 * the first place. This class is the second line: an interface can be bypassed by
 * calling the API directly, a refusal here cannot.
 *
 * The exception type carries the distinction the operator needs: `UpdatesNotSupported`
 * is a permanent architectural answer, not a transient failure worth retrying.
 */
final class RefusingPackageInstaller implements PackageInstaller
{
    public function __construct(private readonly Application $application)
    {
    }

    public function update(string $type, string $slug, ?string $version, bool $rollbackOnError): UpdateOutcome
    {
        throw UpdatesNotSupported::forType($type, $this->application->label());
    }

    public function supports(string $type): bool
    {
        return false;
    }

    /**
     * Toujours `null`, et c'est le même refus que le reste de cette classe.
     *
     * Sur ces cibles, un paquet vit dans `vendor/`, que le pipeline de déploiement du
     * client reconstruit. Y désigner un répertoire laisserait croire qu'on saurait l'y
     * remettre en place — on ne le saurait pas, et le prochain déploiement l'écraserait.
     */
    public function componentPath(string $type, string $slug): ?string
    {
        return null;
    }

    /** Rien à aligner : cette cible n'applique aucune mise à jour, donc n'en défait aucune. */
    public function reconcileInstalledVersion(string $type, string $slug): void
    {
    }
}
