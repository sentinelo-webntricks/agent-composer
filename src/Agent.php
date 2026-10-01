<?php

declare(strict_types=1);

namespace Sentinelo\Agent;

use Sentinelo\Agent\Adapter\ComposerInventory;
use Sentinelo\Agent\Adapter\FileLogger;
use Sentinelo\Agent\Adapter\FlagMaintenanceMode;
use Sentinelo\Agent\Adapter\OpenSslCrypto;
use Sentinelo\Agent\Adapter\PdoDatabaseDumper;
use Sentinelo\Agent\Adapter\ProjectFileSystem;
use Sentinelo\Agent\Adapter\RefusingPackageInstaller;
use Sentinelo\Agent\Adapter\S3ObjectStorage;
use Sentinelo\Agent\Adapter\StreamHttpClient;
use Sentinelo\Agent\Adapter\SystemClock;
use Sentinelo\Agent\Adapter\TableCache;
use Sentinelo\Agent\Adapter\TableConfigStore;
use Sentinelo\Agent\Adapter\TableScheduler;
use Sentinelo\Agent\Enrolment\Enroller;
use Sentinelo\AgentCore\Backup\GfsRetention;
use Sentinelo\AgentCore\Port\Scheduler;
use Sentinelo\AgentCore\Port\SecurityScanner;
use Sentinelo\AgentCore\Port\ConnectionUnavailable;
use Sentinelo\AgentCore\Http\Authenticator;
use Sentinelo\AgentCore\Http\AgentServices;
use Sentinelo\AgentCore\Http\Envelope;
use Sentinelo\Agent\Platform\Application;
use Sentinelo\Agent\Platform\ApplicationDetector;
use Sentinelo\Agent\Platform\ComposerPlatformProbe;
use Sentinelo\Agent\Platform\DatabaseSettings;
use Sentinelo\Agent\Platform\Environment;
use Sentinelo\Agent\Platform\Layout;
use Sentinelo\Agent\Repository\PdoBackupRepository;
use Sentinelo\Agent\Repository\PdoRestoreRepository;
use Sentinelo\Agent\Repository\PdoScanRepository;
use Sentinelo\Agent\Repository\PdoWebhookQueueRepository;
use Sentinelo\Agent\Repository\Schema;
use Sentinelo\AgentCore\Diagnostics\LogReader;
use Sentinelo\AgentCore\Update\ComponentSnapshots;
use Sentinelo\Agent\Scanner\ComposerScanProfile;
use Sentinelo\Agent\Scanner\FilesystemInspector;
use Sentinelo\Agent\Scanner\ScanRunner;
use Sentinelo\Agent\Webhook\QueueingWebhookEmitter;
use Sentinelo\AgentCore\Backup\ArchiveBuilder;
use Sentinelo\AgentCore\Backup\BackupManifest;
use Sentinelo\AgentCore\Backup\BackupRepository;
use Sentinelo\AgentCore\Backup\BackupRunner;
use Sentinelo\AgentCore\Backup\RestoreRepository;
use Sentinelo\AgentCore\Backup\RestoreRunner;
use Sentinelo\AgentCore\Platform\PlatformProbe;
use Sentinelo\AgentCore\Port\Cache;
use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Port\ConfigStore;
use Sentinelo\AgentCore\Port\Crypto;
use Sentinelo\AgentCore\Port\DatabaseDumper;
use Sentinelo\AgentCore\Port\FileSystem;
use Sentinelo\AgentCore\Port\HttpClient;
use Sentinelo\AgentCore\Port\Inventory;
use Sentinelo\AgentCore\Port\Logger;
use Sentinelo\AgentCore\Port\MaintenanceMode;
use Sentinelo\AgentCore\Port\ObjectStorage;
use Sentinelo\AgentCore\Port\PackageInstaller;
use Sentinelo\AgentCore\Scanner\ScanProfile;
use Sentinelo\AgentCore\Scanner\ScanRepository;
use Sentinelo\AgentCore\Webhook\WebhookDispatcher;

/**
 * The agent, wired.
 *
 * A hand-rolled container rather than the host framework's, and that is a deliberate
 * constraint rather than an oversight. This object has to build itself from a `.env`
 * file and a PDO handle alone, because two of the moments the agent matters most are
 * moments when the framework is unavailable:
 *
 *  - a cron job on a site whose compiled container is broken by a bad deploy — exactly
 *    when someone will want a backup or a restore;
 *  - a Laravel and a Symfony host that would otherwise each need their own wiring, and
 *    would drift.
 *
 * Everything is lazy: an HTTP request that only asks for `/health` must not open a TLS
 * socket or read composer.lock, and constructing the object must not touch the disk.
 */
final class Agent implements AgentServices
{
    /**
     * Reported in `meta.plugin_version` on every response.
     *
     * It carries more weight on this platform than on WordPress. These agents are
     * distributed as a Composer package and cannot self-update — we cannot push a fix
     * to the fleet, only signal one — so the version the platform reads here is the
     * only thing that turns "we can't fix it for you" into "we can tell you to pull".
     *
     * 1.1.0: the backups table carries `mode` / `base_backup_id` (`Schema::migrate()`),
     * so a differential is RETAINED as such. The platform asks this agent for
     * differentials only from 1.1.0 (`SitePlatform::DIFFERENTIAL_MIN_AGENT_VERSION`);
     * 1.0.0 is the number of the code that lacked the columns. Nothing has been
     * published yet: the first tag must be `agent-composer/v1.1.0` (or later).
     */
    public const VERSION = '1.1.0';

    private readonly float $startedAt;

    private ?\PDO $pdo = null;

    private ?Application $application = null;
    private ?ApplicationDetector $detector = null;
    private ?Environment $environment = null;
    private ?Layout $layout = null;
    private ?DatabaseSettings $databaseSettings = null;
    private ?Clock $clock = null;
    private ?Logger $logger = null;
    private ?LogReader $logReader = null;

    private ?ComponentSnapshots $componentSnapshots = null;
    private ?Crypto $crypto = null;
    private ?ConfigStore $config = null;
    private ?Cache $cache = null;
    private ?FileSystem $files = null;
    private ?HttpClient $http = null;
    private ?ObjectStorage $storage = null;
    private ?Inventory $inventory = null;
    private ?PlatformProbe $probe = null;
    private ?DatabaseDumper $database = null;
    private ?MaintenanceMode $maintenance = null;
    private ?TableScheduler $scheduler = null;
    private ?BackupRepository $backups = null;
    private ?RestoreRepository $restores = null;
    private ?ScanRepository $scans = null;
    private ?PdoWebhookQueueRepository $queue = null;
    private ?QueueingWebhookEmitter $webhooks = null;
    private ?ScanProfile $scanProfile = null;
    private ?Envelope $envelope = null;
    private ?Authenticator $authenticator = null;
    private ?Enroller $enroller = null;

    /**
     * La connexion est une FABRIQUE, pas une connexion.
     *
     * Le bundle Symfony et le service provider Laravel câblent cet objet au démarrage de
     * l'application. Ouvrir la base à ce moment-là ferait échouer la CONSTRUCTION du
     * service quand elle est injoignable — donc avant que le routeur puisse répondre —
     * et le client recevrait la page HTML 500 de son framework au lieu d'une enveloppe.
     * C'est exactement le défaut corrigé dans Router::handle(), qui se contentait de
     * remonter d'un cran tant que la connexion s'ouvrait ici.
     *
     * Corollaire utile : une requête sur /health qui n'a pas besoin de la base n'ouvre
     * aucune connexion.
     *
     * @param \Closure(): \PDO $connection
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly \Closure $connection,
        private readonly string $defaultSaasUrl,
        private readonly string $tablePrefix = 'sentinelo_',
    ) {
        $this->startedAt = microtime(true);
    }

    /** Pour les tests et les appelants qui ont déjà une connexion ouverte. */
    public static function withConnection(
        string $projectRoot,
        \PDO $pdo,
        string $defaultSaasUrl,
        string $tablePrefix = 'sentinelo_',
    ): self {
        return new self($projectRoot, static fn (): \PDO => $pdo, $defaultSaasUrl, $tablePrefix);
    }

    /**
     * La connexion, ouverte au premier besoin réel et mémorisée ensuite.
     *
     * L'échec est typé : la cause varie — variable absente, base injoignable,
     * composer.lock illisible — mais toutes disent la même chose au client et appellent
     * la même réponse. Sans ce type, le routeur devait deviner d'après la classe de
     * l'exception et classait en « erreur interne » un agent simplement pas encore
     * configuré.
     */
    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            return $this->pdo = ($this->connection)();
        } catch (\Throwable $e) {
            throw ConnectionUnavailable::from($e);
        }
    }

    /**
     * Construire depuis la racine du projet, sans rien ouvrir.
     *
     * C'est le point d'entrée que câblent le bundle Symfony et le service provider
     * Laravel, donc il s'exécute au DÉMARRAGE de l'application cliente. Rien n'y est lu
     * ni ouvert : ni composer.lock, ni .env, ni la base. Tout ce qui échouerait ici
     * échouerait avant que le routeur puisse répondre — le client recevrait la page HTML
     * 500 de son framework au lieu d'une enveloppe, et la résilience ajoutée à
     * Router::handle() ne servirait à rien puisque l'échec se produit un cran plus haut.
     *
     * Corollaire utile : une requête qui n'a pas besoin de la base n'ouvre aucune
     * connexion.
     *
     * La connexion vient de la configuration de l'application, pas de son conteneur :
     * l'agent doit pouvoir sauvegarder un site dont le conteneur compilé est cassé par
     * un mauvais déploiement, ce qui est précisément quand on veut une sauvegarde.
     */
    public static function fromProjectRoot(string $projectRoot, string $defaultSaasUrl): self
    {
        return new self(
            $projectRoot,
            static function () use ($projectRoot): \PDO {
                $detector = new ApplicationDetector($projectRoot);
                $application = $detector->detect();
                $environment = Environment::forLayout(new Layout($application, $projectRoot));
                $settings = DatabaseSettings::resolve($application, $environment);

                return new \PDO($settings->pdoDsn(), $settings->user, $settings->password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            },
            $defaultSaasUrl,
        );
    }

    /**
     * Create the agent's tables if they are missing, and bring an existing schema
     * forward. Cheap in both cases — this runs from the cron entry point, not per
     * request.
     *
     * The migration step is not optional decoration: `install()` skips everything once
     * the config table exists, so without it a column added by a later release never
     * reaches a project that already runs the agent — which is every project that
     * matters.
     */
    public function install(): void
    {
        $schema = new Schema($this->pdo(), $this->logger(), $this->tablePrefix);
        if (!$schema->isInstalled()) {
            $schema->install();

            return;
        }

        $schema->migrate();
    }

    // ─── Platform ────────────────────────────────────────────────────────────

    public function application(): Application
    {
        return $this->application ??= $this->detector()->detect();
    }

    public function applicationVersion(): string
    {
        return $this->detector()->applicationVersion($this->application()) ?? 'unknown';
    }

    public function detector(): ApplicationDetector
    {
        return $this->detector ??= new ApplicationDetector($this->projectRoot);
    }

    public function environment(): Environment
    {
        return $this->environment ??= Environment::forLayout($this->layout());
    }

    public function layout(): Layout
    {
        return $this->layout ??= new Layout($this->application(), $this->projectRoot);
    }

    public function databaseSettings(): DatabaseSettings
    {
        return $this->databaseSettings ??= DatabaseSettings::resolve($this->application(), $this->environment());
    }

    // ─── Ports ───────────────────────────────────────────────────────────────

    public function clock(): Clock
    {
        return $this->clock ??= new SystemClock();
    }

    public function logger(): Logger
    {
        return $this->logger ??= new FileLogger($this->logDirectory(), $this->clock());
    }

    /**
     * The reader behind the `logs` route.
     *
     * Same directory the logger writes to — hence {@see self::logDirectory()} rather
     * than two copies of the path. UTC, because that is what this agent stamps: the
     * reader must be told where local time starts, and here it does not move.
     */
    public function logReader(): LogReader
    {
        return $this->logReader ??= new LogReader($this->logDirectory(), 'UTC');
    }

    /**
     * Kept pre-update component snapshots.
     *
     * Wired even though this agent applies no package update: the route that lists them
     * must be able to answer "none", which is not the same statement as an unroutable
     * endpoint. A capability declared and left unbound is indistinguishable from an absent
     * one — this repository has paid for that three times.
     */
    public function componentSnapshots(): ComponentSnapshots
    {
        return $this->componentSnapshots ??= new ComponentSnapshots(
            $this->files(),
            $this->clock(),
            $this->logger()
        );
    }

    /** One path, read by whoever writes and by whoever reads. */
    private function logDirectory(): string
    {
        return $this->layout()->tempPath() . '/logs';
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new OpenSslCrypto($this->environment());
    }

    public function config(): ConfigStore
    {
        return $this->config ??= new TableConfigStore($this->pdo(), $this->crypto(), $this->tablePrefix . 'config');
    }

    public function cache(): Cache
    {
        return $this->cache ??= new TableCache($this->pdo(), $this->clock(), $this->tablePrefix . 'cache');
    }

    public function files(): FileSystem
    {
        return $this->files ??= new ProjectFileSystem($this->layout());
    }

    public function http(): HttpClient
    {
        return $this->http ??= new StreamHttpClient();
    }

    public function storage(): ObjectStorage
    {
        return $this->storage ??= new S3ObjectStorage($this->config(), $this->http(), $this->logger());
    }

    public function inventory(): Inventory
    {
        return $this->inventory ??= new ComposerInventory($this->application(), $this->detector());
    }

    public function probe(): PlatformProbe
    {
        return $this->probe ??= new ComposerPlatformProbe(
            $this->application(),
            $this->detector(),
            $this->environment(),
            self::VERSION,
            $this->databaseSettings()->database,
        );
    }

    public function database(): DatabaseDumper
    {
        return $this->database ??= new PdoDatabaseDumper(
            $this->pdo(),
            $this->databaseSettings(),
            $this->logger(),
            $this->tablePrefix,
        );
    }

    public function maintenance(): MaintenanceMode
    {
        return $this->maintenance ??= new FlagMaintenanceMode($this->layout()->tempPath() . '/maintenance.flag');
    }

    /** Always a refusal. See {@see RefusingPackageInstaller} for why. */
    public function packageInstaller(): PackageInstaller
    {
        return new RefusingPackageInstaller($this->application());
    }

    public function scheduler(): Scheduler
    {
        return $this->scheduler ??= new TableScheduler($this->cache(), $this->clock(), $this->logger());
    }

    // ─── Persistence ─────────────────────────────────────────────────────────

    public function backups(): BackupRepository
    {
        return $this->backups ??= new PdoBackupRepository($this->pdo(), $this->clock(), $this->tablePrefix);
    }

    public function restores(): RestoreRepository
    {
        return $this->restores ??= new PdoRestoreRepository($this->pdo(), $this->clock(), $this->tablePrefix);
    }

    public function scans(): ScanRepository
    {
        return $this->scans ??= new PdoScanRepository($this->pdo(), $this->clock(), $this->tablePrefix);
    }

    public function webhookQueue(): PdoWebhookQueueRepository
    {
        return $this->queue ??= new PdoWebhookQueueRepository($this->pdo(), $this->clock(), $this->tablePrefix);
    }

    public function webhooks(): QueueingWebhookEmitter
    {
        return $this->webhooks ??= new QueueingWebhookEmitter(
            new WebhookDispatcher($this->http(), $this->config(), $this->clock(), $this->logger(), $this->defaultSaasUrl),
            $this->webhookQueue(),
            $this->logger(),
        );
    }

    // ─── Orchestrators ───────────────────────────────────────────────────────

    public function backupRunner(): BackupRunner
    {
        return new BackupRunner(
            $this->backups(),
            $this->storage(),
            $this->webhooks(),
            new GfsRetention($this->backups(), $this->storage(), $this->clock(), $this->logger()),
            $this->database(),
            $this->crypto(),
            $this->files(),
            $this->config(),
            $this->logger(),
            new BackupManifest($this->inventory(), $this->probe(), $this->config()),
            new ArchiveBuilder($this->files(), $this->cache(), $this->logger()),
        );
    }

    public function restoreRunner(): RestoreRunner
    {
        return new RestoreRunner(
            $this->restores(),
            $this->storage(),
            $this->webhooks(),
            $this->database(),
            $this->files(),
            $this->maintenance(),
            $this->crypto(),
            $this->logger(),
            // The safety snapshot is the only way back from a restore, so it is taken
            // by the very runner that takes real backups — not a reduced version of it.
            $this->backupRunner(),
        );
    }

    public function scanProfile(): ScanProfile
    {
        return $this->scanProfile ??= ComposerScanProfile::create();
    }

    public function scanRunner(): SecurityScanner
    {
        return new ScanRunner(
            $this->scans(),
            $this->scanProfile(),
            $this->probe(),
            $this->inventory(),
            new FilesystemInspector($this->layout()),
            $this->http(),
            $this->config(),
            $this->layout(),
            $this->webhooks(),
            $this->logger(),
        );
    }

    // ─── HTTP ────────────────────────────────────────────────────────────────

    public function envelope(): Envelope
    {
        return $this->envelope ??= new Envelope(
            self::VERSION,
            $this->application()->value,
            $this->applicationVersion(),
            $this->startedAt,
        );
    }

    public function authenticator(): Authenticator
    {
        return $this->authenticator ??= new Authenticator(
            $this->config(),
            $this->cache(),
            $this->clock(),
            $this->logger(),
        );
    }

    // ─── Enrôlement ──────────────────────────────────────────────────────────

    /**
     * Comment ce site reçoit sa clé. Voir {@see Enroller} pour les arbitrages.
     *
     * Construit à la demande et jamais au démarrage : il lit la table de configuration,
     * donc il ouvre la base. Les commandes console le réclament ; le bundle et le service
     * provider n'en tiennent qu'une définition paresseuse.
     */
    public function enroller(): Enroller
    {
        return $this->enroller ??= new Enroller(
            $this->config(),
            $this->http(),
            $this->clock(),
            $this->logger(),
            $this->defaultSaasUrl,
            fn (): array => [
                // Informatif : la plateforme identifie le site par la CLÉ, pas par ce
                // corps. Il sert au journal de la plateforme et au support.
                'site_url' => $this->environment()->get('APP_URL'),
                'application' => $this->application()->value,
                'application_version' => $this->applicationVersion(),
                'php_version' => PHP_VERSION,
                'agent_version' => self::VERSION,
            ],
        );
    }

    /**
     * L'adresse de la plateforme : celle scellée à l'enrôlement, sinon celle configurée.
     *
     * Une seule réponse à cette question, parce qu'il y en avait deux. `WebhookDispatcher`
     * retombait sur le défaut quand la configuration était vide, le heartbeat non : un
     * agent dont la table ne portait pas `saas_url` envoyait ses webhooks et taisait son
     * heartbeat, donc `sites.agent_version` restait vide sans que rien ne semble cassé.
     */
    public function saasUrl(): string
    {
        return $this->enroller()->saasUrl();
    }

    /** Purge the agent's own expired cache rows; returns how many were removed. */
    public function cachePurge(): int
    {
        $cache = $this->cache();

        return $cache instanceof TableCache ? $cache->purgeExpired() : 0;
    }
}
