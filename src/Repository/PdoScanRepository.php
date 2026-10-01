<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Repository;

use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Scanner\ScanRepository;
use Sentinelo\AgentCore\Support\Timestamps;

/**
 * Security-scan rows over PDO.
 *
 * `failScan()` persists the reason. That is not a nicety: the backend reads
 * `error_message` off the status endpoint, and an adapter that drops it leaves every
 * agent-side failure reaching the operator as a generic "scan échoué côté agent" — the
 * exact dead end the port's docblock was written to prevent, after the WordPress agent
 * spent a release doing it.
 */
final class PdoScanRepository implements ScanRepository
{
    private readonly string $table;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
        string $prefix = 'sentinelo_',
    ) {
        $this->table = $prefix . 'security_scans';
    }

    public function startScan(string $scanType): mixed
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO {$this->table} (scan_type, status, started_at, created_at)
             VALUES (:type, 'running', :started, :created)"
        );

        $now = Timestamps::store($this->clock->now());
        $statement->execute(['type' => $scanType, 'started' => $now, 'created' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?object
    {
        return $this->one("SELECT * FROM {$this->table} WHERE id = :id", ['id' => $id]);
    }

    public function findLatestTerminal(): ?object
    {
        // 'failed' is included deliberately: on the trigger-timeout path the backend
        // has no scan id and asks for "the latest". Returning only completed scans made
        // a failed run look like it was still going, and its cause was lost.
        return $this->one(
            "SELECT * FROM {$this->table} WHERE status IN ('completed','failed') ORDER BY id DESC LIMIT 1"
        );
    }

    public function completeScan(mixed $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = 'completed', completed_at = :completed, score = :score,
                checks_passed = :passed, checks_warned = :warned, checks_failed = :failed, checks_skipped = :skipped,
                critical_issues = :critical, high_issues = :high, medium_issues = :medium, low_issues = :low,
                details_json = :details, vulnerabilities_json = :vulnerabilities
             WHERE id = :id"
        );

        $statement->execute([
            'completed' => Timestamps::store($this->clock->now()),
            'score' => $data['score'] ?? 0,
            'passed' => $data['checks_passed'] ?? 0,
            'warned' => $data['checks_warned'] ?? 0,
            'failed' => $data['checks_failed'] ?? 0,
            'skipped' => $data['checks_skipped'] ?? 0,
            'critical' => $data['critical_issues'] ?? 0,
            'high' => $data['high_issues'] ?? 0,
            'medium' => $data['medium_issues'] ?? 0,
            'low' => $data['low_issues'] ?? 0,
            'details' => (string) json_encode($data['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'vulnerabilities' => (string) json_encode($data['vulnerabilities'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'id' => (int) $id,
        ]);
    }

    public function markWebhookSent(mixed $id): void
    {
        $this->pdo->prepare("UPDATE {$this->table} SET webhook_sent = 1 WHERE id = :id")->execute(['id' => (int) $id]);
    }

    public function failScan(mixed $id, ?string $errorMessage = null): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET status = 'failed', completed_at = :completed, error_message = :error WHERE id = :id")
            ->execute([
                'completed' => Timestamps::store($this->clock->now()),
                'error' => $errorMessage,
                'id' => (int) $id,
            ]);
    }

    /** @param array<string, mixed> $parameters */
    private function one(string $sql, array $parameters = []): ?object
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(\PDO::FETCH_OBJ);

        return $row === false ? null : $row;
    }
}
