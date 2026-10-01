<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\Agent\Platform\DatabaseSettings;
use Sentinelo\AgentCore\Backup\AgentOwnedTables;
use Sentinelo\AgentCore\Port\DatabaseDumper;
use Sentinelo\AgentCore\Port\Logger;

/**
 * Exports and re-imports the database through PDO, with `mysqldump` when available.
 *
 * The dump FORMAT is contractual, not a matter of taste (see {@see DatabaseDumper}):
 * every CREATE TABLE preceded by `DROP TABLE IF EXISTS`, every statement terminated by
 * `";\n"`. The restorer's line-oriented fallback parser splits on that terminator, and
 * the drop-first rule is what makes a restore *replace* a schema instead of colliding
 * with it. Both are asserted by the test suite for that reason.
 *
 * `mysqldump` is preferred when it is there — it handles views, triggers and huge
 * tables that a hand-rolled exporter gets wrong — but its output is normalised to the
 * same contract (`--add-drop-table` is on by default; it is passed explicitly anyway so
 * a distribution that changed the default cannot silently break restores).
 *
 * The PHP fallback streams row by row and never accumulates a whole table in memory:
 * the shared hosts this agent targets are exactly the ones with a 128 MB limit.
 */
final class PdoDatabaseDumper implements DatabaseDumper
{
    /** Rows per INSERT statement. Big enough to be fast, small enough to stay parseable. */
    private const ROWS_PER_INSERT = 200;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly DatabaseSettings $settings,
        private readonly Logger $logger,
        /**
         * Préfixe des tables de l'agent, EXCLUES du dump. Voir tables().
         */
        private readonly string $agentTablePrefix = AgentOwnedTables::MARKER,
        /**
         * Comment on lance `mysqldump`. Injecté UNIQUEMENT pour que le chemin binaire
         * soit exerçable : c'est lui qui a perdu la clé d'API d'un site en production,
         * et il était le seul des deux chemins qu'aucun test ne pouvait lire. Un repli
         * qui masque la panne du chemin principal est le piège n°13 du dépôt ; un
         * chemin principal qu'aucun test ne voit en est la moitié manquante.
         *
         * @var (\Closure(string): int)|null
         */
        private readonly ?\Closure $exec = null,
    ) {
    }

    public function dump(string $destination): string
    {
        $this->assertSupportedEngine();

        if ($this->dumpViaCli($destination)) {
            return $destination;
        }

        $this->dumpViaPhp($destination);

        return $destination;
    }

    /**
     * MySQL et MariaDB seulement, et il faut le dire tôt.
     *
     * `DatabaseSettings` sait lire un DSN PostgreSQL — c'est une analyse correcte — mais
     * ce dumper ne sait pas exporter PostgreSQL : le repli PHP interroge
     * `SHOW FULL TABLES`, qui n'existe que sur MySQL. Sans ce refus, un site Symfony ou
     * Laravel sur PostgreSQL installait l'agent sans rien remarquer, puis **chaque
     * sauvegarde échouait** sur « Impossible de lister les tables » — un message qui
     * désigne un symptôme et laisse chercher le reste.
     *
     * Refuser au premier appel, en nommant la raison, vaut mieux qu'échouer au milieu
     * d'une sauvegarde. C'est la même règle que pour les mises à jour : ce qu'on ne sait
     * pas faire honnêtement, on le dit.
     */
    private function assertSupportedEngine(): void
    {
        if (!$this->settings->isMysql()) {
            throw new \RuntimeException(sprintf(
                'Moteur de base « %s » non pris en charge par l\'agent : seuls MySQL et MariaDB '
                . 'peuvent être sauvegardés. La sauvegarde des fichiers reste possible en '
                . 'retirant « db » de la liste des éléments inclus.',
                $this->settings->driver
            ));
        }
    }

    public function import(string $dumpPath): void
    {
        // Même refus qu'à l'export, et pour la même raison : un dump produit pour MySQL
        // ne s'importe pas ailleurs. Le dire avant d'ouvrir le fichier évite d'échouer
        // instruction par instruction sur une base qu'on vient de mettre hors ligne.
        $this->assertSupportedEngine();

        if (!is_readable($dumpPath)) {
            throw new \RuntimeException('Dump SQL illisible : ' . $dumpPath);
        }

        $handle = fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le dump SQL : ' . $dumpPath);
        }

        $this->execute('SET FOREIGN_KEY_CHECKS=0');
        $buffer = '';

        try {
            while (($line = fgets($handle)) !== false) {
                if ($line === '' || str_starts_with(ltrim($line), '--') || trim($line) === '') {
                    continue;
                }
                $buffer .= $line;

                // Terminator written by dump(); see the class docblock.
                if (!str_ends_with(rtrim($line, "\r\n"), ';')) {
                    continue;
                }

                $statement = trim($buffer);
                $buffer = '';
                if ($statement === '') {
                    continue;
                }

                // Stop at the FIRST failure. A half-imported database is worse than a
                // failed restore: a failed restore still leaves the pre-restore safety
                // backup as an intact recovery path.
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException $e) {
                    throw new \RuntimeException(
                        'Échec de l\'import SQL pendant la restauration : ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        } finally {
            fclose($handle);
            $this->execute('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->logger->log('info', 'restore', 'Base de données réimportée');
    }

    /**
     * Composer applications have no table-prefix convention: Doctrine has no such
     * notion and Laravel ships an empty prefix. Answering '' is the truth, and is why
     * `database_prefix` is absent from this platform's scan profile.
     */
    public function tablePrefix(): string
    {
        return '';
    }

    /**
     * Le chemin binaire — celui qui tourne partout où `exec()` n'est pas coupé.
     *
     * Il n'excluait RIEN. Le repli PHP, lui, écartait bien nos tables : les deux chemins
     * d'un même dumper produisaient donc deux sauvegardes différentes, et c'est le plus
     * emprunté qui emportait notre état. Mesuré de bout en bout sur l'agent Composer :
     * la restauration remettait `sentinelo_config` dans son état d'avant, donc perdait
     * la clé d'API et les identifiants de stockage — restaurer DÉCONNECTAIT le site.
     *
     * `mysqldump` ne sait pas exclure par motif : c'est une option par table, d'où la
     * liste. Elle est construite à partir du MÊME critère que le repli
     * ({@see AgentOwnedTables}), sans quoi les deux périmètres redivergeraient au
     * premier agent qui ajoute une table.
     */
    private function dumpViaCli(string $destination): bool
    {
        if (null === $this->exec && !function_exists('exec')) {
            return false;
        }

        $ignored = '';
        foreach ($this->ownTables() as $table) {
            $ignored .= '--ignore-table=' . escapeshellarg($this->settings->database . '.' . $table) . ' ';
        }

        $command = sprintf(
            'mysqldump --add-drop-table --single-transaction --quick --no-tablespaces '
            . '--default-character-set=utf8mb4 %s--host=%s --port=%d --user=%s %s > %s 2>/dev/null',
            $ignored,
            escapeshellarg($this->settings->host),
            $this->settings->port,
            escapeshellarg($this->settings->user),
            escapeshellarg($this->settings->database),
            escapeshellarg($destination)
        );

        // The password goes through the environment, never the command line: argv is
        // world-readable in /proc on a shared host.
        $command = 'MYSQL_PWD=' . escapeshellarg($this->settings->password) . ' ' . $command;

        $code = $this->run($command);

        if ($code === 0 && is_file($destination) && filesize($destination) > 0) {
            $this->logger->log('info', 'backup', 'Base exportée via mysqldump');

            return true;
        }

        @unlink($destination);
        $this->logger->log('warning', 'backup', 'mysqldump indisponible ou en échec, bascule sur PDO');

        return false;
    }

    private function dumpViaPhp(string $destination): void
    {
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'écrire le dump SQL : ' . $destination);
        }

        try {
            fwrite($handle, "-- Sentinelo agent dump\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");

            foreach ($this->tables() as $table) {
                $quoted = $this->quoteIdentifier($table);

                // Contractual: the drop precedes the create, so a restore replaces.
                fwrite($handle, "DROP TABLE IF EXISTS {$quoted};\n");
                fwrite($handle, $this->createStatement($table) . ";\n");

                $this->writeRows($handle, $table, $quoted);
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        if (filesize($destination) === 0) {
            // A zero-byte dump reported as a backup is the failure mode the port's
            // docblock exists to forbid.
            throw new \RuntimeException('Dump SQL vide — export considéré comme échoué');
        }
    }

    /**
     * Les tables à sauvegarder — celles du CLIENT, pas celles de l'agent.
     *
     * Les tables de l'agent vivent dans la même base que le site. Les inclure faisait
     * qu'une restauration les remettait dans leur état AU MOMENT DE LA SAUVEGARDE, avec
     * trois conséquences, toutes silencieuses :
     *
     *  - la ligne de la restauration en cours disparaissait en plein milieu, si bien que
     *    le `markCompleted()` qui suit l'import ne trouvait plus rien à mettre à jour.
     *    `/backup/restore-status` ne pouvait donc JAMAIS annoncer « terminée », et le
     *    backend finissait par forcer l'échec d'une restauration qui avait réussi ;
     *  - la liste des sauvegardes était réécrite en arrière, la sauvegarde en cours
     *    revenant à « in_progress » — son état au moment du dump ;
     *  - des sauvegardes supprimées par la rétention ressuscitaient, pointant vers des
     *    objets cloud qui n'existent plus.
     *
     * Les exclure est aussi le bon choix pour la configuration : `sentinelo_config`
     * contient la clé API et les identifiants du stockage de CET environnement. Même
     * raisonnement que pour `.env`, déjà exclu de la restauration des fichiers — les
     * identifiants de l'environnement courant doivent l'emporter.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return AgentOwnedTables::theirs($this->allTables(), $this->agentTablePrefix);
    }

    /**
     * Les nôtres — celles que le chemin `mysqldump` doit se faire dire d'ignorer.
     *
     * Le complément EXACT de {@see self::tables()}, calculé sur la même liste et le même
     * critère : c'est la seule forme qui garantit que la sauvegarde ne dépende pas de la
     * présence d'un binaire sur l'hébergement.
     *
     * @return list<string>
     */
    private function ownTables(): array
    {
        return AgentOwnedTables::ours($this->allTables(), $this->agentTablePrefix);
    }

    /** @return list<string> */
    private function allTables(): array
    {
        $statement = $this->pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        if ($statement === false) {
            throw new \RuntimeException('Impossible de lister les tables de la base');
        }

        $tables = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tables[] = (string) $row[0];
        }

        return $tables;
    }

    /**
     * Lance une commande shell et rend son code de retour.
     *
     * @param string $command déjà échappée par l'appelant
     */
    private function run(string $command): int
    {
        if (null !== $this->exec) {
            return ($this->exec)($command);
        }

        exec($command, $output, $code);

        return $code;
    }

    private function createStatement(string $table): string
    {
        $statement = $this->pdo->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table));
        if ($statement === false) {
            throw new \RuntimeException('Impossible de lire le schéma de la table ' . $table);
        }

        $row = $statement->fetch(\PDO::FETCH_NUM);

        return (string) ($row[1] ?? '');
    }

    /** @param resource $handle */
    private function writeRows($handle, string $table, string $quotedTable): void
    {
        $statement = $this->pdo->query('SELECT * FROM ' . $quotedTable);
        if ($statement === false) {
            throw new \RuntimeException('Impossible de lire les lignes de la table ' . $table);
        }

        $buffer = [];
        $columns = null;

        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $columns ??= implode(', ', array_map($this->quoteIdentifier(...), array_keys($row)));

            $values = array_map(
                fn (mixed $value): string => $value === null ? 'NULL' : $this->pdo->quote((string) $value),
                array_values($row)
            );
            $buffer[] = '(' . implode(', ', $values) . ')';

            if (count($buffer) >= self::ROWS_PER_INSERT) {
                fwrite($handle, "INSERT INTO {$quotedTable} ({$columns}) VALUES " . implode(', ', $buffer) . ";\n");
                $buffer = [];
            }
        }

        if ($buffer !== [] && $columns !== null) {
            fwrite($handle, "INSERT INTO {$quotedTable} ({$columns}) VALUES " . implode(', ', $buffer) . ";\n");
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** Best-effort session pragma — absent on PostgreSQL, and not worth failing over. */
    private function execute(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException) {
            // Intentionally ignored: see method docblock.
        }
    }
}
