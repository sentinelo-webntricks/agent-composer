<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Scanner;

use Sentinelo\Agent\Platform\Layout;
use Sentinelo\AgentCore\Port\SecurityScanner;
use Sentinelo\AgentCore\Inventory\InstalledComponent;
use Sentinelo\AgentCore\Platform\PlatformProbe;
use Sentinelo\AgentCore\Port\ConfigStore;
use Sentinelo\AgentCore\Port\HttpClient;
use Sentinelo\AgentCore\Port\Inventory;
use Sentinelo\AgentCore\Port\Logger;
use Sentinelo\AgentCore\Port\WebhookEmitter;
use Sentinelo\AgentCore\Scanner\Check\ConfigFileScorer;
use Sentinelo\AgentCore\Scanner\Check\DebugModeScorer;
use Sentinelo\AgentCore\Scanner\Check\DirectoryListingScorer;
use Sentinelo\AgentCore\Scanner\Check\FilePermissionsScorer;
use Sentinelo\AgentCore\Scanner\Check\PhpVersionScorer;
use Sentinelo\AgentCore\Scanner\Check\SecurityHeadersScorer;
use Sentinelo\AgentCore\Scanner\Check\SensitiveFilesScorer;
use Sentinelo\AgentCore\Scanner\Check\SslScorer;
use Sentinelo\AgentCore\Scanner\Check\SuspiciousFilesScorer;
use Sentinelo\AgentCore\Scanner\CheckCatalogue;
use Sentinelo\AgentCore\Scanner\CheckResult;
use Sentinelo\AgentCore\Scanner\ScanAggregator;
use Sentinelo\AgentCore\Scanner\ScanProfile;
use Sentinelo\AgentCore\Scanner\ScanRepository;
use Sentinelo\AgentCore\Scanner\ScoreCalculator;

/**
 * Runs the security scan: gathers facts, hands them to the shared scorers, persists,
 * notifies.
 *
 * The division of labour is the whole design. This class knows how to look at a
 * Composer application; it decides no point values and no thresholds. Those live in
 * `CheckCatalogue` and the scorers, which is what makes 78/100 mean the same thing on
 * a Sylius shop and a WordPress site — the promise the monthly client report makes
 * implicitly by putting both scores on the same page.
 *
 * A check that throws is recorded as an 'error' result rather than aborting the scan.
 * `ScoreCalculator` excludes non-definitive results from BOTH the numerator and the
 * denominator, so one unreachable probe costs its check, not the whole run — and cannot
 * inflate the score either.
 */
final class ScanRunner implements SecurityScanner
{
    /** Paths probed from the outside for accidental exposure. */
    private const SENSITIVE_PATHS = [
        '/.env',
        '/.env.local',
        '/composer.json',
        '/composer.lock',
        '/.git/config',
        '/var/log/prod.log',
        '/storage/logs/laravel.log',
        '/phpinfo.php',
    ];

    /** Directories probed for an index listing. */
    private const LISTING_PATHS = ['/uploads/', '/media/', '/storage/', '/vendor/'];

    public function __construct(
        private readonly ScanRepository $repository,
        private readonly ScanProfile $profile,
        private readonly PlatformProbe $probe,
        private readonly Inventory $inventory,
        private readonly FilesystemInspector $files,
        private readonly HttpClient $http,
        private readonly ConfigStore $config,
        private readonly Layout $layout,
        private readonly WebhookEmitter $webhooks,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{scan_id: int, score: int, label: string}
     */
    public function run(string $scanType = 'full'): array
    {
        $profile = $scanType === 'quick' ? $this->profile->quick() : $this->profile;
        $scanId = (int) $this->repository->startScan($scanType);

        try {
            $results = $this->collect($profile);

            $score = ScoreCalculator::calculate($results);
            $aggregate = ScanAggregator::aggregate($results);

            $this->repository->completeScan($scanId, [
                'score' => $score['score'],
                'checks_passed' => $aggregate['checks_passed'],
                'checks_warned' => $aggregate['checks_warned'],
                'checks_failed' => $aggregate['checks_failed'],
                'checks_skipped' => $aggregate['checks_skipped'],
                'critical_issues' => $aggregate['critical_issues'],
                'high_issues' => $aggregate['high_issues'],
                'medium_issues' => $aggregate['medium_issues'],
                'low_issues' => $aggregate['low_issues'],
                'details' => $aggregate['details'],
                'vulnerabilities' => $aggregate['vulnerabilities'],
            ]);

            $this->webhooks->send('security.scan_complete', [
                'scan_id' => $scanId,
                'scan_type' => $scanType,
                'status' => 'completed',
                'score' => $score['score'],
            ] + $aggregate);

            // Same trigger conditions as every other agent, from the shared aggregator:
            // otherwise the identical site state would page an operator on one platform
            // and stay silent on another.
            if (ScanAggregator::warrantsAlert($score['score'], $aggregate['critical_issues'])) {
                $this->webhooks->send('security.alert', [
                    'scan_id' => $scanId,
                    'severity' => ScanAggregator::alertSeverity($aggregate['critical_issues']),
                    'score' => $score['score'],
                    'critical_issues' => $aggregate['critical_issues'],
                ]);
            }

            $this->repository->markWebhookSent($scanId);
            $this->logger->log('info', 'security', "Scan #{$scanId} terminé : {$score['score']}/100");

            return ['scan_id' => $scanId, 'score' => $score['score'], 'label' => $score['label']];
        } catch (\Throwable $e) {
            // The reason is persisted, not just logged: the backend reads error_message
            // off the status endpoint, and without it every agent-side failure reaches
            // the operator as a generic "scan échoué".
            $this->repository->failScan($scanId, $e->getMessage());
            $this->logger->log('error', 'security', "Scan #{$scanId} échoué : " . $e->getMessage());

            throw $e;
        }
    }

    /** @return list<CheckResult> */
    private function collect(ScanProfile $profile): array
    {
        $checks = [
            CheckCatalogue::PHP_VERSION => fn (): CheckResult => PhpVersionScorer::score($this->probe),
            CheckCatalogue::DEBUG_MODE => fn (): CheckResult => DebugModeScorer::score($this->probe),
            CheckCatalogue::SSL => fn (): CheckResult => $this->ssl(),
            CheckCatalogue::SECURITY_HEADERS => fn (): CheckResult => SecurityHeadersScorer::score($this->homepageHeaders()),
            CheckCatalogue::SENSITIVE_FILES => fn (): CheckResult => SensitiveFilesScorer::score($this->probePaths(self::SENSITIVE_PATHS)),
            CheckCatalogue::DIRECTORY_LISTING => fn (): CheckResult => DirectoryListingScorer::score($this->probePaths(self::LISTING_PATHS)),
            CheckCatalogue::CONFIG_FILE => fn (): CheckResult => $this->configFile(),
            CheckCatalogue::FILE_PERMISSIONS => fn (): CheckResult => FilePermissionsScorer::score($this->files->permissionFindings()),
            CheckCatalogue::SUSPICIOUS_FILES => fn (): CheckResult => $this->suspiciousFiles(),
            CheckCatalogue::EXTENSION_VULNERABILITIES => fn (): CheckResult => $this->packageInventory(),
        ];

        $results = [];
        foreach ($checks as $id => $check) {
            if (!$profile->has($id)) {
                continue;
            }

            try {
                $results[] = $check();
            } catch (\Throwable $e) {
                $this->logger->log('warning', 'security', "Check {$id} en erreur : " . $e->getMessage());
                $results[] = new CheckResult(
                    $id,
                    'error',
                    0,
                    $profile->maxScore($id),
                    'info',
                    ['error' => $e->getMessage()],
                    'Vérification impossible : ' . $e->getMessage()
                );
            }
        }

        return $results;
    }

    private function ssl(): CheckResult
    {
        $host = (string) parse_url($this->siteUrl(), PHP_URL_HOST);
        if ($host === '') {
            throw new \RuntimeException('URL du site non configurée');
        }

        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
        $stream = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno,
            $error,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($stream === false) {
            throw new \RuntimeException('Connexion TLS impossible : ' . ($error ?: 'erreur inconnue'));
        }

        $params = stream_context_get_params($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if ($certificate === false) {
            throw new \RuntimeException('Certificat TLS illisible');
        }

        $days = (int) floor((((int) $certificate['validTo_time_t']) - time()) / 86400);
        $protocol = (string) ($meta['crypto']['protocol'] ?? 'inconnu');
        $issuer = (string) ($certificate['issuer']['O'] ?? $certificate['issuer']['CN'] ?? 'inconnu');

        return SslScorer::score($days, $protocol, $issuer);
    }

    private function configFile(): CheckResult
    {
        $facts = $this->files->configFileFacts();

        // Whether the file is actually served can only be established by asking the
        // webserver. Inferring it from the path would flag every project whose vhost
        // already denies dotfiles.
        $response = $this->fetch('/' . ltrim($facts['path'], '/'));
        $served = $response['code'] === 200 && str_contains($response['body'], '=');

        return ConfigFileScorer::score(
            $facts['path'],
            $facts['present'],
            $served,
            $facts['inside_public_root'],
            $facts['world_readable'],
        );
    }

    private function suspiciousFiles(): CheckResult
    {
        $sweep = $this->files->suspiciousFiles();

        return SuspiciousFilesScorer::score($sweep['findings'], $sweep['complete']);
    }

    /**
     * Zero-weight inventory: the packages, so the BACKEND can correlate them against
     * CVE feeds. The agent deliberately does no correlation of its own — a per-site
     * vulnerability lookup would be hundreds of outbound requests from the client's
     * production host, and the free tiers of those feeds are per-account anyway.
     */
    private function packageInventory(): CheckResult
    {
        $components = $this->inventory->listComponents(Inventory::TYPE_EXTENSION);

        return new CheckResult(
            CheckCatalogue::EXTENSION_VULNERABILITIES,
            'info',
            0,
            0,
            'info',
            [
                'source' => 'composer.lock',
                'count' => count($components),
                'packages' => array_map(
                    static fn (InstalledComponent $c): array => ['slug' => $c->slug, 'version' => $c->version],
                    $components
                ),
            ],
            'Inventaire transmis — la corrélation CVE est faite côté plateforme'
        );
    }

    /** @return array<string, string> */
    private function homepageHeaders(): array
    {
        $response = $this->http->request('GET', $this->siteUrl(), ['timeout' => 15]);

        return $response->headers;
    }

    /**
     * Probe each path and keep ONLY the ones the server actually answered.
     *
     * A status of 0 means the request never reached a server — no DNS, no route, the
     * host blocking our own outbound call, or `site_url` not configured yet. Passing
     * those through as `code: 0` made both scorers read them as "not exposed" and award
     * full marks for checks that never ran. Dropping them lets the scorers apply their
     * own "no answer proves nothing" rule, which returns a skip rather than a pass.
     *
     * Both `path` and `url` are emitted because the two shared scorers read different
     * keys — SensitiveFilesScorer reports by `path`, DirectoryListingScorer by `url`.
     * Supplying one of them left the other rendering a null in the operator's report.
     *
     * @param list<string> $paths
     *
     * @return list<array{path: string, url: string, code: int, body: string}>
     */
    private function probePaths(array $paths): array
    {
        $responses = [];
        foreach ($paths as $path) {
            $result = $this->fetch($path);

            if ($result['code'] <= 0) {
                continue;
            }

            $responses[] = [
                'path' => $path,
                'url' => $path,
                'code' => $result['code'],
                'body' => $result['body'],
            ];
        }

        return $responses;
    }

    /** @return array{code: int, body: string} */
    private function fetch(string $path): array
    {
        $siteUrl = $this->siteUrl();
        if ('' === $siteUrl) {
            // No configured URL: there is nothing to probe, and building a relative
            // request would fail at transport level and look like "the file is not
            // exposed". Reported as code 0 so probePaths() drops it.
            return ['code' => 0, 'body' => ''];
        }

        $response = $this->http->request('GET', rtrim($siteUrl, '/') . '/' . ltrim($path, '/'), [
            'timeout' => 10,
        ]);

        // Only the head of the body is kept: these probes exist to recognise a page,
        // not to download whatever a misconfigured host is willing to serve.
        return ['code' => $response->statusCode, 'body' => substr($response->body, 0, 4096)];
    }

    private function siteUrl(): string
    {
        return (string) ($this->config->get('site_url') ?: '');
    }
}
