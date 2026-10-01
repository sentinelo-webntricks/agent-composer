<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Repository;

use Sentinelo\AgentCore\Backup\BackupRepository;
use Sentinelo\AgentCore\Backup\SignOfLife;
use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Support\Timestamps;

/**
 * Backup rows over PDO.
 *
 * Every timestamp written here goes through {@see Timestamps::store()} on the agent's
 * UTC {@see Clock} — never `NOW()`. The database server's session timezone is whatever
 * the host set it to, and the backend parses these values as UTC: letting MySQL supply
 * the instant is how a 03:00 backup on a Paris host gets filed at 01:00, taking the
 * retention window and the "stale job" force-fail with it.
 */
final class PdoBackupRepository implements BackupRepository
{
    private readonly string $table;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
        string $prefix = 'sentinelo_',
    ) {
        $this->table = $prefix . 'backups';
    }

    public function findInProgressId(): ?int
    {
        $row = $this->one("SELECT id FROM {$this->table} WHERE status IN ('pending','in_progress','uploading') ORDER BY id DESC LIMIT 1");

        return $row === null ? null : (int) $row->id;
    }

    public function cancelStaleInProgress(int $maxAgeMinutes, string $reason): ?int
    {
        $row = $this->one(
            "SELECT id, started_at FROM {$this->table}
             WHERE status IN ('pending','in_progress','uploading') ORDER BY id DESC LIMIT 1"
        );

        if ($row === null) {
            return null;
        }

        // L'age se compte depuis le DERNIER SIGNE DE VIE, pas depuis le depart :
        // une sauvegarde reprenable dure legitimement des heures. Voir SignOfLife.
        $age = SignOfLife::minutesSince(
            $this->getSteps((int) $row->id),
            isset($row->started_at) ? (string) $row->started_at : null,
            $this->clock->now()->getTimestamp()
        );

        if ($age <= $maxAgeMinutes) {
            return null;
        }

        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = 'failed', completed_at = :completed,
                error_message = :error WHERE id = :id"
        );
        $statement->execute([
            'completed' => Timestamps::store($this->clock->now()),
            'error' => $reason,
            'id' => (int) $row->id,
        ]);

        return (int) $row->id;
    }

    public function create(string $type, bool $includesDb, bool $includesFiles, bool $includesUploads): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO {$this->table} (backup_type, status, started_at, includes_db, includes_files, includes_uploads, created_at)
             VALUES (:type, 'in_progress', :started, :db, :files, :uploads, :created)"
        );

        $now = Timestamps::store($this->clock->now());
        $statement->execute([
            'type' => $type,
            'started' => $now,
            'db' => $includesDb ? 1 : 0,
            'files' => $includesFiles ? 1 : 0,
            'uploads' => $includesUploads ? 1 : 0,
            'created' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function saveSteps(int $id, array $steps): void
    {
        $statement = $this->pdo->prepare("UPDATE {$this->table} SET steps_json = :steps WHERE id = :id");
        $statement->execute(['steps' => $this->encode($steps), 'id' => $id]);
    }

    public function getSteps(int $id): array
    {
        $row = $this->one("SELECT steps_json FROM {$this->table} WHERE id = :id", ['id' => $id]);
        if ($row === null || $row->steps_json === null) {
            return [];
        }

        $decoded = json_decode((string) $row->steps_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function markCompleted(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            // `mode` / `base_backup_id` are part of the contract, not bookkeeping.
            // Dropping them did not fail — it lied: BackupPayload re-reads the row, a
            // missing value yields 'full', and a differential archive announced itself
            // complete. The restore then ran without its base and refused, months later.
            "UPDATE {$this->table} SET status = 'completed', completed_at = :completed,
                file_size_bytes = :size, db_size_bytes = :dbSize, cloud_provider = :provider,
                cloud_bucket = :bucket, cloud_path = :path, cloud_url = :url, checksum_sha256 = :checksum,
                mode = :mode, base_backup_id = :baseBackupId
             WHERE id = :id"
        );

        $statement->execute([
            'completed' => Timestamps::store($this->clock->now()),
            // Bytes are the authoritative unit. Nothing here derives them from a
            // megabyte figure, and nothing should ever start.
            'size' => $data['file_size_bytes'] ?? null,
            'dbSize' => $data['db_size_bytes'] ?? null,
            'provider' => $data['cloud_provider'] ?? null,
            'bucket' => $data['cloud_bucket'] ?? null,
            'path' => $data['cloud_path'] ?? null,
            'url' => $data['cloud_url'] ?? null,
            'checksum' => $data['checksum_sha256'] ?? null,
            'mode' => (string) ($data['mode'] ?? 'full'),
            'baseBackupId' => isset($data['base_backup_id']) && (int) $data['base_backup_id'] > 0
                ? (int) $data['base_backup_id']
                : null,
            'id' => $id,
        ]);
    }

    public function markFailed(int $id, string $errorMessage, array $steps): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = 'failed', completed_at = :completed,
                error_message = :error, steps_json = :steps WHERE id = :id"
        );

        $statement->execute([
            'completed' => Timestamps::store($this->clock->now()),
            'error' => $errorMessage,
            'steps' => $this->encode($steps),
            'id' => $id,
        ]);
    }

    public function markWebhookSent(int $id): void
    {
        $this->pdo->prepare("UPDATE {$this->table} SET webhook_sent = 1 WHERE id = :id")->execute(['id' => $id]);
    }

    public function tag(int $id, string $retentionTag, ?string $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET retention_tag = :tag, expires_at = :expires WHERE id = :id"
        );
        $statement->execute(['tag' => $retentionTag, 'expires' => $expiresAt, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id")->execute(['id' => $id]);
    }

    /**
     * Failure with NO completion timestamp, on purpose: the backup never reached a
     * terminal state we can vouch for, and stamping one would make it look like a
     * finished job in every list that orders by completion.
     */
    public function markVerificationFailed(int $id, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = 'failed', error_message = :error WHERE id = :id"
        );
        $statement->execute(['error' => $error, 'id' => $id]);
    }

    /**
     * Manual and safety backups are excluded on purpose. Retention is a promise about
     * the AUTOMATED series (the GFS ladder); a manual backup is something a human asked
     * for and must not be pruned by a policy they never saw, and a safety snapshot
     * belongs to the restore that created it.
     */
    public function listRetainable(): array
    {
        return $this->all(
            "SELECT id, retention_tag, cloud_path, cloud_provider, COALESCE(completed_at, created_at) AS backup_at
             FROM {$this->table}
             WHERE status = 'completed' AND backup_type NOT IN ('manual','safety')
             ORDER BY backup_at DESC"
        );
    }

    public function findById(int $id): ?object
    {
        return $this->one("SELECT * FROM {$this->table} WHERE id = :id", ['id' => $id]);
    }

    public function findLatest(array $excludeTypes = []): ?object
    {
        if ($excludeTypes === []) {
            return $this->one("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT 1");
        }

        $placeholders = [];
        $parameters = [];
        foreach (array_values($excludeTypes) as $index => $type) {
            $placeholders[] = ':t' . $index;
            $parameters['t' . $index] = $type;
        }

        return $this->one(
            sprintf(
                'SELECT * FROM %s WHERE backup_type NOT IN (%s) ORDER BY id DESC LIMIT 1',
                $this->table,
                implode(', ', $placeholders)
            ),
            $parameters
        );
    }

    public function statistics(): array
    {
        // Windows are measured against UTC_TIMESTAMP() because created_at is written in
        // UTC by this repository. Comparing to NOW() would use the database session's
        // timezone and shift the "last 7 days" boundary by the host's offset.
        $row = $this->one(
            "SELECT
                SUM(created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY))  AS last_7,
                SUM(created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)) AS last_30,
                -- `stored` est un MOT RÉSERVÉ depuis MySQL 8 (colonnes générées) : cet
                -- alias faisait échouer toute la requête, donc /backup/status — la route
                -- que le backend interroge pour savoir si une sauvegarde a réussi. Les
                -- sauvegardes se terminaient bien et le backend les forçait en échec
                -- faute de pouvoir lire leur statut.
                SUM(status = 'completed')                              AS completed_count,
                SUM(file_size_bytes)                                   AS total_bytes,
                MIN(created_at)                                        AS oldest
             FROM {$this->table}"
        );

        return [
            'last_7_days' => (int) ($row->last_7 ?? 0),
            'last_30_days' => (int) ($row->last_30 ?? 0),
            'total_stored' => (int) ($row->completed_count ?? 0),
            // Bytes, authoritative. Any megabyte figure is derived downstream, never here.
            'total_size_bytes' => (int) ($row->total_bytes ?? 0),
            'oldest_backup' => $row?->oldest === null ? null : (string) $row->oldest,
        ];
    }

    public function retentionAggregate(): ?object
    {
        return $this->one(
            "SELECT
                SUM(retention_tag LIKE '%daily%')   AS daily,
                SUM(retention_tag LIKE '%weekly%')  AS weekly,
                SUM(retention_tag LIKE '%monthly%') AS monthly,
                SUM(retention_tag LIKE '%yearly%')  AS yearly,
                SUM(file_size_bytes) AS total_bytes
             FROM {$this->table} WHERE status = 'completed'"
        );
    }

    public function nextExpiry(): ?string
    {
        $row = $this->one(
            "SELECT MIN(expires_at) AS next_expiry FROM {$this->table} WHERE expires_at IS NOT NULL"
        );

        return $row?->next_expiry === null ? null : (string) $row->next_expiry;
    }

    /** @param array<string, mixed> $parameters */
    private function one(string $sql, array $parameters = []): ?object
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(\PDO::FETCH_OBJ);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<object>
     */
    private function all(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @param array<int, mixed> $steps */
    private function encode(array $steps): string
    {
        return (string) json_encode($steps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
