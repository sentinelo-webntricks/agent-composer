<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Scheduling;

use Sentinelo\Agent\Agent;
use Sentinelo\AgentCore\Scheduling\ScheduledTask;
use Sentinelo\AgentCore\Scheduling\TaskCatalogue;
use Sentinelo\AgentCore\Support\Timestamps;

/**
 * Registers the agent's recurring work and runs whatever is due.
 *
 * Driven by the operator's system cron — one line, calling `sentinelo:run` every
 * minute. That is a real improvement over WordPress, where wp-cron only fires on
 * visitor traffic and a quiet site silently stops backing up.
 *
 * The catalogue is shared, so this agent runs the same jobs on the same cadence as
 * every other. What it deliberately does NOT run is listed in
 * {@see self::DELIBERATELY_UNBOUND}, each entry with its reason. **That constant is the
 * only source**: this paragraph said "two entries" while the constant held more
 * (fourth audit, MWP-16). A docblock that COUNTS what a constant contains eventually
 * counts wrong, and nothing notices — the completeness test reads the constant, not the
 * sentence. The two that matter most, and why:
 *
 *  - **self-update.** These agents are distributed as a Composer package, not as a
 *    self-installing archive. There is no update to check for, and pretending there is
 *    would put a "mise à jour disponible" notice in front of an operator who has no
 *    button to press. What replaces it is `meta.plugin_version` on every response: the
 *    platform can list agents that have fallen behind and tell their owners to pull.
 *  - **inventory sync.** The backend reads the package list from `/plugins` when it
 *    wants it; pushing it hourly from every site would be traffic that changes only
 *    when the client deploys.
 */
final class TaskRunner
{
    /** Les tâches du catalogue que cet agent n'exécute PAS, et l'assume. */
    private const DELIBERATELY_UNBOUND = [
        TaskCatalogue::SELF_UPDATE,
        TaskCatalogue::SYNC,
        // La sauvegarde quotidienne est declenchee par la PLATEFORME, jamais par
        // l'agent. Deux planificateurs pour un meme travail, c'est deux fois le
        // travail et aucune autorite : mesure le 2026-09-02 sur un site client, le
        // cron du site a demarre a 03:00:07 et la plateforme s'est vu refuser le
        // sien en 409 une minute plus tard. Surtout, la sauvegarde est desormais
        // reglable PAR SITE depuis la plateforme (`Site::automaticBackupsEnabled`) :
        // un agent qui continuerait de se declencher seul rendrait ce reglage faux.
        TaskCatalogue::DAILY_BACKUP,
        // MWP-01 (quatrieme audit) : cet agent n'implemente NI `ResumableObjectStorage`
        // NI `ResumableCrypto`. Un envoi ne s'y fait donc qu'en une seule requete, et
        // `BackupRunner::resumeUpload()` rend `null` par construction. La tache etait
        // pourtant planifiee toutes les 300 s : un reveil, une requete de base, rien.
        //
        // Elle est retiree plutot que gardee « au cas ou » — une tache planifiee qui ne
        // peut rien faire est indiscernable d'une reprise qui ne marche pas, et c'est
        // exactement ce qu'on cherche a ne plus confondre. Le refus est declare ICI, et
        // {@see \Sentinelo\Agent\Adapter\S3ObjectStorage} le rend visible cote
        // plateforme : au-dela du seuil multipart, l'envoi est REFUSE avec son motif,
        // au lieu d'etre tente puis coupe. `RESUME_ARCHIVE`, lui, reste : construire
        // l'archive par passages ne demande aucune capacite optionnelle.
        //
        // A rebrancher le jour ou l'adaptateur implementera la capacite — et ce jour-la,
        // le test d'exhaustivite ci-dessous rougira si on l'oublie.
        TaskCatalogue::RESUME_UPLOAD,
    ];

    public function __construct(private readonly Agent $agent)
    {
    }

    /**
     * Ce que cet agent sait exécuter, hook par hook.
     *
     * UNE seule liste, lue à la fois par la planification et par l'exécution forcée.
     * Il y en avait deux, et elles ont divergé : `CLEANUP_LOGS` était planifiée mais
     * absente du `match` de `runNow()`, si bien que forcer la purge — typiquement pour
     * récupérer de la place sur une partition pleine, donc au moment précis où on la
     * cherche — répondait « Tâche inconnue ». Deux listes du même ensemble finissent
     * toujours par se désaligner ; il n'y en a plus qu'une.
     *
     * @return array<string, callable(): mixed>
     */
    private function handlers(): array
    {
        return [
            TaskCatalogue::HEARTBEAT => fn (): bool => $this->heartbeat(),
            TaskCatalogue::WEBHOOK_QUEUE => fn (): int => $this->agent->webhooks()->drain(),
            TaskCatalogue::SECURITY_SCAN => fn (): array => $this->agent->scanRunner()->run('full'),
            TaskCatalogue::CLEANUP_BACKUPS => fn (): array => $this->cleanup(),
            TaskCatalogue::CLEANUP_LOGS => fn (): int => $this->agent->cachePurge(),
            TaskCatalogue::RESUME_ARCHIVE => fn (): bool => $this->resumeArchive(),
        ];
    }

    /**
     * Reprend une construction d'archive interrompue.
     *
     * Cette entrée manquait, et son absence était silencieuse : `register()` saute
     * proprement un hook sans handler, donc la tâche n'était même pas planifiée. Sur un
     * hébergement qui coupe PHP à 360 s, une application dont le contenu ne tient pas
     * dans une requête voyait sa sauvegarde s'arrêter à mi-chemin et n'être JAMAIS
     * reprise — la ligne restait « en cours » jusqu'à ce que la plateforme l'expire.
     *
     * Son pendant `RESUME_UPLOAD` est délibérément absent : voir
     * {@see self::DELIBERATELY_UNBOUND}. L'archivage, lui, se reprend sans aucune
     * capacité optionnelle.
     *
     * Le cas ordinaire est qu'il n'y ait rien à faire, et le cœur rend la main
     * immédiatement quand c'est le cas.
     */
    private function resumeArchive(): bool
    {
        $backupId = $this->agent->backups()->findInProgressId();
        if (null === $backupId) {
            return false;
        }

        $this->agent->backupRunner()->resumeArchive($backupId);

        return true;
    }

    /** Idempotent: safe to call on every boot. */
    public function register(): void
    {
        $scheduler = $this->agent->scheduler();
        $now = $this->agent->clock()->now();
        $handlers = $this->handlers();

        foreach (TaskCatalogue::all() as $task) {
            if (!isset($handlers[$task->hook])) {
                // Deux absences sont voulues et documentées en tête de classe
                // (self-update, sync d'inventaire) ; toute autre est un oubli, et
                // c'est la panne la plus invisible de cet agent — une tâche du
                // catalogue qui n'est jamais planifiée ne laisse aucune trace.
                if (!in_array($task->hook, self::DELIBERATELY_UNBOUND, true)) {
                    $this->agent->logger()->log('warning', 'scheduler', sprintf(
                        'Tâche « %s » (%s) sans handler : elle ne sera pas planifiée.',
                        $task->hook,
                        $task->label
                    ));
                }

                continue;
            }

            // firstRunAt honours the task's anchor, so the nightly backup lands at
            // 03:00 UTC rather than 24 h after whenever the agent was installed.
            $scheduler->schedule($task->hook, $task->intervalSeconds, $task->firstRunAt($now));
            $scheduler->onDue($task->hook, $handlers[$task->hook]);
        }
    }

    /**
     * Run everything that is due.
     *
     * @return array<string, string> hook => outcome
     */
    public function runDue(): array
    {
        $this->register();

        return $this->agent->scheduler()->runDue();
    }

    /** Force one task, ignoring its due date. Used by the console command's argument. */
    public function runNow(string $hook): mixed
    {
        $handlers = $this->handlers();

        if (!isset($handlers[$hook])) {
            throw new \InvalidArgumentException(sprintf(
                'Tâche inconnue : %s. Tâches disponibles : %s',
                $hook,
                implode(', ', $this->hooks())
            ));
        }

        return $handlers[$hook]();
    }

    /**
     * Les hooks que cet agent exécute.
     *
     * @return list<string>
     */
    public function hooks(): array
    {
        return array_keys($this->handlers());
    }

    private function heartbeat(): bool
    {
        $config = $this->agent->config();
        // Une seule autorité sur l'adresse de la plateforme. Ce chemin lisait la
        // configuration SEULE et rendait `false` quand elle était vide, là où
        // WebhookDispatcher retombait sur le défaut : un agent dont la table ne portait
        // pas `saas_url` poussait ses webhooks et taisait son heartbeat — donc
        // `sites.agent_version` restait vide sans que rien n'ait l'air cassé.
        $saasUrl = $this->agent->saasUrl();
        $siteUuid = (string) ($config->get('site_uuid') ?: '');
        $apiKey = $config->get('api_key');

        if ($saasUrl === '' || $siteUuid === '' || !is_string($apiKey) || $apiKey === '') {
            return false;
        }

        $response = $this->agent->http()->request(
            'POST',
            rtrim($saasUrl, '/') . '/api/sites/' . $siteUuid . '/heartbeat',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Sentinelo-API-Key' => $apiKey,
                    'X-Sentinelo-Site-UUID' => $siteUuid,
                ],
                'body' => (string) json_encode([
                    'site_uuid' => $siteUuid,
                    'timestamp' => $this->agent->clock()->now()->getTimestamp(),
                    // `application_version`, not `wp_version`: the backend already
                    // prefers that field, and sending a WordPress version number from a
                    // Sylius shop would be a lie in a field somebody eventually reads.
                    'application' => $this->agent->application()->value,
                    'application_version' => $this->agent->applicationVersion(),
                    'php_version' => PHP_VERSION,
                    'agent_version' => Agent::VERSION,
                ]),
                'timeout' => 10,
            ]
        );

        // UTC, like every timestamp this agent persists. A local string written here
        // and read back as UTC is what made the WordPress admin's "last sync" line
        // permanently wrong by the site's offset.
        $config->set('last_heartbeat', Timestamps::store($this->agent->clock()->now()));

        return $response->isSuccess();
    }

    /** @return list<int> */
    private function cleanup(): array
    {
        $this->agent->webhookQueue()->purgeAbandoned();

        return (new \Sentinelo\AgentCore\Backup\GfsRetention(
            $this->agent->backups(),
            $this->agent->storage(),
            $this->agent->clock(),
            $this->agent->logger(),
        ))->cleanExpired();
    }

    /** @return list<ScheduledTask> the tasks this agent actually runs */
    public function tasks(): array
    {
        return array_values(array_filter(
            TaskCatalogue::all(),
            fn (ScheduledTask $task): bool => in_array($task->hook, $this->hooks(), true)
        ));
    }
}
