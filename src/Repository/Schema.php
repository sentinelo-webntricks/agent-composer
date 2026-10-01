<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Repository;

use Sentinelo\AgentCore\Port\Logger;

/**
 * The agent's own tables, created idempotently on boot.
 *
 * Deliberately NOT Doctrine migrations, and not a Laravel migration either. Those live
 * in the client's project, are run by the client's pipeline, and are versioned in the
 * client's repository — so an agent that shipped one would be asking the client to
 * deploy our schema change before our code could work, and would appear in the diff of
 * every release they cut. `CREATE TABLE IF NOT EXISTS` on boot costs one cheap query
 * behind a version marker and belongs to us alone.
 *
 * The tables mirror the WordPress agent's, on purpose: the two feed the same backend
 * columns and the same poller, so a divergence in what a row can hold becomes a
 * divergence in what the backend can display.
 */
final class Schema
{
    /** Bumped when a statement below changes. */
    public const VERSION = '2';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Logger $logger,
        private readonly string $prefix = 'sentinelo_',
    ) {
    }

    public function install(): void
    {
        foreach ($this->statements() as $sql) {
            $this->pdo->exec($sql);
        }

        $this->logger->log('info', 'schema', 'Schéma de l\'agent vérifié (version ' . self::VERSION . ')');
    }

    /**
     * Bring an EXISTING installation up to the current schema.
     *
     * `install()` only ever runs on a project whose config table is missing, and its
     * statements are all `IF NOT EXISTS`: a column added to `statements()` therefore
     * reaches new installations and nowhere else. That is how this agent ended up
     * without `mode` / `base_backup_id` while the shared runner was already producing
     * differential archives — the code arrived, the column did not.
     *
     * We check before we alter. `ADD COLUMN IF NOT EXISTS` is MariaDB-only; MySQL 5.7
     * and 8.0 reject it, and those are the hosts this agent runs on. Probing first
     * makes the migration idempotent everywhere rather than on some engines — and the
     * probe is a `SELECT ... LIMIT 0`, not `SHOW COLUMNS`; see {@see self::hasColumn()}.
     *
     * Failures are logged, never thrown: the caller is a cron entry point, and an
     * agent that refuses to start because one column is missing is worse than an agent
     * that keeps taking full backups. The backend will not ask it for a differential
     * until `SitePlatform::supportsDifferentialBackup()` says so.
     */
    public function migrate(): void
    {
        foreach ($this->pendingStatements() as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->logger->log('info', 'schema', 'Migration appliquée : ' . $sql);
            } catch (\PDOException $e) {
                $this->logger->log('error', 'schema', 'Migration refusée (' . $sql . ') : ' . $e->getMessage());
            }
        }
    }

    /**
     * The alterations that are MISSING, and only those.
     *
     * Split out from applying them so the idempotence — the whole point — can be read
     * in a test: a column already present must produce no statement at all.
     *
     * @return list<string>
     */
    public function pendingStatements(): array
    {
        $table = $this->prefix . 'backups';
        $statements = [];

        // Pas de table : `install()` la créera déjà pourvue. Migrer une table absente
        // produirait des ALTER qui échouent tous, et un journal d'erreurs qui ferait
        // chercher une panne là où il n'y a qu'une installation neuve.
        if (!$this->hasTable($table)) {
            return [];
        }

        if (!$this->hasColumn($table, 'mode')) {
            $statements[] = "ALTER TABLE {$table} ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'full'";
        }

        if (!$this->hasColumn($table, 'base_backup_id')) {
            $statements[] = "ALTER TABLE {$table} ADD COLUMN base_backup_id BIGINT UNSIGNED NULL";
        }

        return $statements;
    }

    /**
     * `SELECT <column> FROM <table> LIMIT 0` rather than `SHOW COLUMNS`.
     *
     * Two reasons, and the second is the one that matters. It is portable — `SHOW
     * COLUMNS` is MySQL-only, so the migration could not be exercised against the
     * sqlite the test suite runs on, and an untested migration is exactly the kind that
     * turns out not to work on the day it is needed. And it asks the database the
     * question we actually have ("can I read this column?") instead of one that
     * approximates it.
     *
     * A missing TABLE is answered separately, by {@see self::hasTable()}: it means
     * `install()` has not run, so there is nothing to migrate and everything to create.
     */
    private function hasColumn(string $table, string $column): bool
    {
        try {
            $this->pdo->query("SELECT {$column} FROM {$table} LIMIT 0");

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table} LIMIT 0");

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * True when the config table is present — the cheap probe the bootstrap uses to
     * decide whether install() has ever run, without paying for eight DDL statements
     * on every request.
     */
    public function isInstalled(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . $this->prefix . 'config LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return list<string> */
    public function statements(): array
    {
        $p = $this->prefix;
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            "CREATE TABLE IF NOT EXISTS {$p}config (
                config_key VARCHAR(191) NOT NULL PRIMARY KEY,
                config_value LONGTEXT NULL,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NULL
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS {$p}cache (
                cache_key VARCHAR(191) NOT NULL PRIMARY KEY,
                cache_value LONGTEXT NULL,
                expires_at DATETIME NULL,
                INDEX idx_expires (expires_at)
            ) {$options}",

            // Column-for-column the WordPress agent's backups table: both write the
            // same contract fields and the same backend poller reads them.
            //
            // It stopped being true once — `mode` and `base_backup_id` landed on the
            // WordPress side only — and nothing caught it, because this agent's
            // contract suite asserts the envelope and not the backup payloads. A
            // comment is not a check: see Contract\BackupPayloadContractTest.
            "CREATE TABLE IF NOT EXISTS {$p}backups (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                backup_type ENUM('daily','weekly','monthly','yearly','manual','safety') NOT NULL,
                status ENUM('pending','in_progress','completed','failed','uploading') NOT NULL DEFAULT 'pending',
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                file_size_bytes BIGINT UNSIGNED NULL,
                db_size_bytes BIGINT UNSIGNED NULL,
                cloud_provider VARCHAR(50) NULL,
                cloud_bucket VARCHAR(255) NULL,
                cloud_path VARCHAR(500) NULL,
                cloud_url VARCHAR(1000) NULL,
                checksum_sha256 VARCHAR(64) NULL,
                -- `full` or `differential`, and the full backup a differential hangs
                -- off. Absent, they did not fail — they LIED: the core's payload
                -- re-reads the row, a missing column yields its default, and a
                -- differential archive therefore announced itself complete. The
                -- backend then resolved no base, and the restore failed weeks later,
                -- at the only moment a backup is worth anything. See Migrator for
                -- installations that already have the table.
                mode VARCHAR(16) NOT NULL DEFAULT 'full',
                base_backup_id BIGINT UNSIGNED NULL,
                includes_db TINYINT(1) NOT NULL DEFAULT 1,
                includes_files TINYINT(1) NOT NULL DEFAULT 1,
                includes_uploads TINYINT(1) NOT NULL DEFAULT 1,
                -- 64, not 20: a 1st-of-January backup that also falls on a Sunday is
                -- tagged 'daily,weekly,monthly,yearly' (27 chars). The narrower column
                -- truncated it, dropped the yearly tag, and the annual archive was
                -- pruned. Same lesson, inherited rather than relearned.
                retention_tag VARCHAR(64) NULL,
                expires_at DATETIME NULL,
                error_message TEXT NULL,
                steps_json LONGTEXT NULL,
                webhook_sent TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_type (backup_type),
                INDEX idx_expires (expires_at),
                INDEX idx_created (created_at)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS {$p}restores (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_cloud_path VARCHAR(500) NOT NULL,
                scope ENUM('db','files','all') NOT NULL DEFAULT 'all',
                status ENUM('pending','in_progress','completed','failed') NOT NULL DEFAULT 'pending',
                safety_backup_id BIGINT UNSIGNED NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                error_message TEXT NULL,
                steps_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS {$p}security_scans (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scan_type ENUM('full','quick','scheduled') NOT NULL DEFAULT 'full',
                status ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
                score TINYINT UNSIGNED NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                checks_passed SMALLINT UNSIGNED DEFAULT 0,
                checks_warned SMALLINT UNSIGNED DEFAULT 0,
                checks_failed SMALLINT UNSIGNED DEFAULT 0,
                checks_skipped SMALLINT UNSIGNED DEFAULT 0,
                details_json LONGTEXT NULL,
                vulnerabilities_json LONGTEXT NULL,
                critical_issues SMALLINT UNSIGNED DEFAULT 0,
                high_issues SMALLINT UNSIGNED DEFAULT 0,
                medium_issues SMALLINT UNSIGNED DEFAULT 0,
                low_issues SMALLINT UNSIGNED DEFAULT 0,
                error_message TEXT NULL,
                webhook_sent TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS {$p}webhook_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(100) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
                next_attempt_at DATETIME NULL,
                last_error TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_next_attempt (next_attempt_at)
            ) {$options}",
        ];
    }
}
