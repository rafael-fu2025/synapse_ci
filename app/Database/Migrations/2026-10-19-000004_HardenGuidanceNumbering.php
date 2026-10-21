<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Stop guidance writers and take a verified backup before either direction.
 * MySQL/MariaDB DDL implicitly commits: retry up() after a partial installation.
 * Never repair numbering, draft flags or encrypted history in this migration.
 * Writers must serialize on the case for session MAX (including deleted rows)
 * and on the session for note versions, rolling back the whole transaction on failure.
 */
final class HardenGuidanceNumbering extends Migration
{
    private const INDEXES = [
        'counselling_sessions' => [
            'name' => 'uniq_guidance_session_number',
            'columns' => ['tenant_id', 'case_id', 'session_number'],
        ],
        'counselling_notes' => [
            'name' => 'uniq_guidance_note_version',
            'columns' => ['tenant_id', 'session_id', 'version'],
        ],
    ];

    private function table(string $name): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($name));
    }

    private function rows(string $sql, array $bindings = []): array
    {
        $result = $this->db->query($sql, $bindings);
        if ($result === false) {
            throw new RuntimeException('Guidance numbering preflight failed; no automatic repairs performed.');
        }

        return $result->getResultArray();
    }

    private function execute(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Guidance numbering DDL failed; keep writers stopped and retry after review.');
        }
    }

    private function assertSupportedTables(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Guidance numbering hardening requires MySQL/MariaDB with InnoDB.');
        }

        foreach (['counselling_cases', 'counselling_sessions', 'counselling_notes'] as $table) {
            $rows = $this->rows(
                'SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->db->prefixTable($table)],
            );
            if (count($rows) !== 1 || strtoupper($rows[0]['ENGINE'] ?? '') !== 'INNODB' || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE') {
                throw new RuntimeException('Guidance numbering hardening requires an existing InnoDB ' . $table . ' table.');
            }
        }
    }

    private function assertNumberingColumns(): void
    {
        foreach (self::INDEXES as $table => $index) {
            $columns = $index['columns'];
            if ($table === 'counselling_notes') {
                $columns[] = 'is_draft';
            }
            $rows = $this->rows(
                'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->db->prefixTable($table)],
            );
            $definitions = array_column($rows, null, 'COLUMN_NAME');
            foreach ($columns as $column) {
                $definition = $definitions[$column] ?? null;
                if ($definition === null || $definition['IS_NULLABLE'] !== 'NO'
                    || !in_array(strtolower($definition['DATA_TYPE']), ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
                    throw new RuntimeException('Expected a NOT NULL integer ' . $table . '.' . $column . '; refusing schema repair.');
                }
            }
        }
    }

    /** Refuse collisions, partial keys and reordered columns; never replace an index. */
    private function hasIndex(string $table, array $index): bool
    {
        $rows = $this->rows(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, COLLATION, INDEX_TYPE FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$this->db->prefixTable($table), $index['name']],
        );
        if ($rows === []) {
            return false;
        }

        $valid = count($rows) === count($index['columns']);
        foreach ($rows as $position => $row) {
            if ((int) $row['NON_UNIQUE'] !== 0 || (int) $row['SEQ_IN_INDEX'] !== $position + 1
                || $row['COLUMN_NAME'] !== ($index['columns'][$position] ?? null)
                || $row['SUB_PART'] !== null || $row['COLLATION'] !== 'A'
                || strtoupper($row['INDEX_TYPE']) !== 'BTREE') {
                $valid = false;
            }
        }
        if (!$valid) {
            throw new RuntimeException('Unexpected ' . $table . '.' . $index['name'] . ' definition; refusing to replace or remove it.');
        }

        return true;
    }

    private function assertCleanData(): void
    {
        $cases = $this->table('counselling_cases');
        $sessions = $this->table('counselling_sessions');
        $notes = $this->table('counselling_notes');

        // Deliberately no deleted_at filters: soft-deleted history still owns its numbers.
        $checks = [
            'Duplicate session numbers (including soft-deleted sessions)' =>
                'SELECT 1 FROM ' . $sessions . ' GROUP BY tenant_id, case_id, session_number HAVING COUNT(*) > 1 LIMIT 1',
            'Duplicate note versions (including version-zero drafts)' =>
                'SELECT 1 FROM ' . $notes . ' GROUP BY tenant_id, session_id, version HAVING COUNT(*) > 1 LIMIT 1',
            'Invalid session number' =>
                'SELECT 1 FROM ' . $sessions . ' WHERE session_number IS NULL OR session_number < 1 LIMIT 1',
            'Inconsistent draft/version state; drafts must be version 0 and published notes positive versions' =>
                'SELECT 1 FROM ' . $notes . ' WHERE is_draft IS NULL OR version IS NULL OR is_draft NOT IN (0, 1)'
                . ' OR version < 0 OR (is_draft = 1 AND version <> 0) OR (is_draft = 0 AND version = 0) LIMIT 1',
            'Missing case or session/case tenant mismatch' =>
                'SELECT 1 FROM ' . $sessions . ' s LEFT JOIN ' . $cases . ' c ON c.id = s.case_id'
                . ' WHERE c.id IS NULL OR s.tenant_id IS NULL OR c.tenant_id IS NULL OR s.tenant_id <> c.tenant_id LIMIT 1',
            'Missing session or note/session tenant mismatch' =>
                'SELECT 1 FROM ' . $notes . ' n LEFT JOIN ' . $sessions . ' s ON s.id = n.session_id'
                . ' WHERE s.id IS NULL OR n.tenant_id IS NULL OR s.tenant_id IS NULL OR n.tenant_id <> s.tenant_id LIMIT 1',
        ];
        foreach ($checks as $reason => $sql) {
            if ($this->rows($sql) !== []) {
                throw new RuntimeException('Guidance numbering preflight: ' . $reason . '. No data changed; resolve explicitly before retrying.');
            }
        }
    }

    public function up(): void
    {
        $this->assertSupportedTables();
        $this->assertNumberingColumns();
        $existing = [];
        foreach (self::INDEXES as $table => $index) {
            $existing[$table] = $this->hasIndex($table, $index);
        }
        // All checks precede the first ALTER, even when resuming a partial installation.
        $this->assertCleanData();

        foreach (self::INDEXES as $table => $index) {
            if (!$existing[$table]) {
                $columns = array_map(fn (string $column): string => $this->db->protectIdentifiers($column), $index['columns']);
                $this->execute('ALTER TABLE ' . $this->table($table) . ' ADD UNIQUE INDEX '
                    . $this->db->protectIdentifiers($index['name']) . ' (' . implode(', ', $columns) . ')');
            }
        }
    }

    public function down(): void
    {
        // CAUTION: this removes numbering/draft uniqueness protection. Keep writers
        // stopped until an explicitly reviewed rollback or replacement is ready.
        $this->assertSupportedTables();
        $existing = [];
        foreach (self::INDEXES as $table => $index) {
            $existing[$table] = $this->hasIndex($table, $index);
        }
        foreach (self::INDEXES as $table => $index) {
            if ($existing[$table]) {
                $this->execute('ALTER TABLE ' . $this->table($table) . ' DROP INDEX '
                    . $this->db->protectIdentifiers($index['name']));
            }
        }
    }
}
