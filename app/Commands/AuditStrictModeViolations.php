<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DomainException;
use Throwable;

/**
 * Report the rows a strict SQL session would reject, before strict mode is enabled.
 *
 * The runtime connection runs without STRICT_TRANS_TABLES, so an over-length string
 * or an invalid date is silently truncated with only a warning. The test connection
 * runs strict, so the suite can never surface that. Enable this audit first, repair
 * whatever it reports, and only then turn strict mode on.
 */
final class AuditStrictModeViolations extends BaseCommand
{
    protected $group = 'Synapse';
    protected $name = 'synapse:strict-mode-audit';
    protected $description = 'Report rows that a strict SQL session would reject, before strict mode is enabled.';
    protected $usage = 'synapse:strict-mode-audit';

    public function run(array $params)
    {
        try {
            $db = Database::connect('default');
            if ($db->DBDriver !== 'MySQLi') {
                throw new DomainException('This audit expects the MySQL/MariaDB driver.');
            }

            $mode = (string) $db->query('SELECT @@SESSION.sql_mode AS sql_mode')->getRowArray()['sql_mode'];
            CLI::write('Session sql_mode: ' . $mode);
            if (stripos($mode, 'STRICT_TRANS_TABLES') === false && stripos($mode, 'STRICT_ALL_TABLES') === false) {
                CLI::write('Strict mode is currently OFF: invalid values are truncated with a warning instead of failing.');
            }

            $violations = $this->inspect($db);
            if ($violations === []) {
                CLI::write('No strict-mode violations found. Strict mode can be enabled safely.');
                return EXIT_SUCCESS;
            }

            CLI::error('Strict-mode violations found: ' . count($violations) . ' column(s).');
            CLI::table($violations);

            return EXIT_ERROR;
        } catch (Throwable $e) {
            log_message('error', 'Strict mode audit failed: {class}: {message}', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            CLI::error($e instanceof DomainException ? $e->getMessage() : 'Strict mode audit failed.');
            return EXIT_ERROR;
        }
    }

    /**
     * @return list<array{column: string, issue: string, rows: int}>
     */
    private function inspect(\CodeIgniter\Database\BaseConnection $db): array
    {
        $columns = $db->query(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION'
        )->getResultArray();

        $violations = [];
        $currentTable = null;
        $quotedTable = '';
        foreach ($columns as $column) {
            $table = (string) $column['TABLE_NAME'];
            if ($table !== $currentTable) {
                $currentTable = $table;
                $quotedTable = $db->protectIdentifiers($db->prefixTable($table));
            }
            $name = (string) $column['COLUMN_NAME'];
            $quotedColumn = $db->protectIdentifiers($name);
            $type = strtolower((string) $column['DATA_TYPE']);

            if (($type === 'varchar' || $type === 'char') && $column['CHARACTER_MAXIMUM_LENGTH'] !== null) {
                $limit = (int) $column['CHARACTER_MAXIMUM_LENGTH'];
                $rows = (int) $db->query(
                    "SELECT COUNT(*) AS total FROM {$quotedTable} WHERE CHAR_LENGTH({$quotedColumn}) > ?",
                    [$limit]
                )->getRowArray()['total'];
                if ($rows > 0) {
                    $violations[] = ['column' => $table . '.' . $name, 'issue' => 'value exceeds ' . $limit . ' characters', 'rows' => $rows];
                }
                continue;
            }

            if ($type === 'date' || $type === 'datetime' || $type === 'timestamp') {
                $rows = (int) $db->query(
                    "SELECT COUNT(*) AS total FROM {$quotedTable} WHERE {$quotedColumn} IS NOT NULL
                     AND (CAST({$quotedColumn} AS CHAR) LIKE '0000-00-00%' OR CAST({$quotedColumn} AS CHAR) < '1000-01-01')"
                )->getRowArray()['total'];
                if ($rows > 0) {
                    $violations[] = ['column' => $table . '.' . $name, 'issue' => 'zero or out-of-range date value', 'rows' => $rows];
                }
                continue;
            }

            if (strtoupper((string) $column['IS_NULLABLE']) === 'NO' && $column['COLUMN_DEFAULT'] === null) {
                $rows = (int) $db->query(
                    "SELECT COUNT(*) AS total FROM {$quotedTable} WHERE {$quotedColumn} IS NULL"
                )->getRowArray()['total'];
                if ($rows > 0) {
                    $violations[] = ['column' => $table . '.' . $name, 'issue' => 'NULL in a NOT NULL column without a default', 'rows' => $rows];
                }
            }
        }

        return $violations;
    }
}
