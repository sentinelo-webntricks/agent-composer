<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Repository;

use Sentinelo\AgentCore\Backup\RestoreRepository;
use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Support\Timestamps;

/**
 * Restore rows over PDO. Same UTC-clock discipline as {@see PdoBackupRepository}.
 */
final class PdoRestoreRepository implements RestoreRepository
{
    private readonly string $table;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
        string $prefix = 'sentinelo_',
    ) {
        $this->table = $prefix . 'restores';
    }

    public function create(string $sourceCloudPath, string $scope): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO {$this->table} (source_cloud_path, scope, status, started_at, created_at)
             VALUES (:path, :scope, 'in_progress', :started, :created)"
        );

        $now = Timestamps::store($this->clock->now());
        $statement->execute(['path' => $sourceCloudPath, 'scope' => $scope, 'started' => $now, 'created' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findInProgress(): ?object
    {
        return $this->one(
            "SELECT id, started_at FROM {$this->table} WHERE status IN ('pending','in_progress') ORDER BY id DESC LIMIT 1"
        );
    }

    public function findById(int $id): ?object
    {
        return $this->one("SELECT * FROM {$this->table} WHERE id = :id", ['id' => $id]);
    }

    public function findLatest(): ?object
    {
        return $this->one("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT 1");
    }

    public function linkSafetyBackup(int $id, int $safetyBackupId): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET safety_backup_id = :backup WHERE id = :id")
            ->execute(['backup' => $safetyBackupId, 'id' => $id]);
    }

    public function saveSteps(int $id, array $steps): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET steps_json = :steps WHERE id = :id")
            ->execute(['steps' => (string) json_encode($steps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'id' => $id]);
    }

    public function markCompleted(int $id): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET status = 'completed', completed_at = :completed WHERE id = :id")
            ->execute(['completed' => Timestamps::store($this->clock->now()), 'id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->pdo
            ->prepare("UPDATE {$this->table} SET status = 'failed', completed_at = :completed, error_message = :error WHERE id = :id")
            ->execute(['completed' => Timestamps::store($this->clock->now()), 'error' => $error, 'id' => $id]);
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
