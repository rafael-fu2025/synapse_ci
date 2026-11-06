<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Additive encrypted guidance outcome fields.
 *
 * Nullable by design: legacy completion/closure calls remain valid without notes.
 * No intake text, case status, permissions or historical rows are rewritten.
 */
final class AddGuidanceOutcomes extends Migration
{
    private const COLUMNS = [
        'guidance_followups' => [
            'completed_by' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
            'completion_notes_cipher' => 'VARBINARY(8000) NULL DEFAULT NULL',
            'completion_notes_nonce' => 'BINARY(12) NULL DEFAULT NULL',
            'completion_notes_tag' => 'BINARY(16) NULL DEFAULT NULL',
            'completion_notes_key_version' => 'INT UNSIGNED NULL DEFAULT NULL',
        ],
        'counselling_cases' => [
            'closed_by' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
            'closure_disposition_cipher' => 'VARBINARY(8000) NULL DEFAULT NULL',
            'closure_disposition_nonce' => 'BINARY(12) NULL DEFAULT NULL',
            'closure_disposition_tag' => 'BINARY(16) NULL DEFAULT NULL',
            'closure_disposition_key_version' => 'INT UNSIGNED NULL DEFAULT NULL',
        ],
    ];

    /**
     * Additive and resumable.
     *
     * MySQL commits DDL implicitly, so a run that added one table's columns and
     * then failed is not recorded. A retry therefore adopts an already-present
     * column only when its installed type matches exactly; anything else is a
     * genuine collision and refuses rather than repairing silently.
     */
    public function up(): void
    {
        $this->requireMariaDb();
        foreach (self::COLUMNS as $table => $columns) {
            $existing = $this->columnTypes($table);
            if ($existing === []) {
                throw new RuntimeException('Missing guidance table: ' . $table);
            }
            $add = [];
            foreach ($columns as $name => $definition) {
                if (isset($existing[$name])) {
                    if ($existing[$name] !== $this->expectedType($definition)) {
                        throw new RuntimeException('Incompatible existing guidance outcome column: ' . $table . '.' . $name);
                    }
                    continue;
                }
                $add[] = 'ADD COLUMN ' . $name . ' ' . $definition;
            }
            if ($add !== []) {
                $this->query('ALTER TABLE ' . $this->table($table) . ' ' . implode(', ', $add));
            }
        }
    }

    /** Installed type without nullability, e.g. "varbinary(8000)". */
    private function expectedType(string $definition): string
    {
        return $this->normalizeType((string) preg_replace('/\s+NULL.*$/i', '', $definition));
    }

    /**
     * Normalize for comparison only.
     *
     * MariaDB reports integer display widths (bigint(20)) that a definition does
     * not need to repeat, while binary lengths are semantically significant.
     */
    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = (string) preg_replace('/\b(bigint|int)\(\d+\)/', '$1', $type);
        return (string) preg_replace('/\s+/', ' ', $type);
    }

    /**
     * Reversal exists for migration verification and clean test databases only.
     *
     * Clinical outcome text is not recoverable once its columns are dropped, so
     * any stored outcome refuses the rollback instead of destroying it silently.
     */
    public function down(): void
    {
        $this->requireMariaDb();
        $existing = [];
        // Preflight every table before any DDL: MySQL cannot roll back ALTER TABLE,
        // so a late refusal must not leave a half-reverted schema.
        foreach (self::COLUMNS as $table => $columns) {
            $existing[$table] = $this->columnTypes($table);
            $this->refuseWhenOutcomeStored($table, array_keys($existing[$table]));
        }
        foreach (self::COLUMNS as $table => $columns) {
            $drop = [];
            foreach (array_keys($columns) as $name) {
                if (isset($existing[$table][$name])) {
                    $drop[] = 'DROP COLUMN ' . $name;
                }
            }
            if ($drop !== []) {
                $this->query('ALTER TABLE ' . $this->table($table) . ' ' . implode(', ', $drop));
            }
        }
    }

    /**
     * Refuse when either encrypted text OR actor provenance is populated.
     *
     * Legacy calls may persist completed_by/closed_by with a null cipher, so a
     * cipher-only predicate would silently destroy attribution on rollback.
     *
     * @param array<int, string> $existing
     */
    private function refuseWhenOutcomeStored(string $table, array $existing): void
    {
        $guarded = array_values(array_filter(
            ['completion_notes_cipher', 'closure_disposition_cipher', 'completed_by', 'closed_by'],
            static fn (string $column): bool => in_array($column, $existing, true)
        ));
        if ($guarded === []) {
            return;
        }
        $where = implode(' OR ', array_map(static fn (string $column): string => $column . ' IS NOT NULL', $guarded));
        $stored = $this->db->query('SELECT COUNT(*) AS total FROM ' . $this->table($table) . ' WHERE ' . $where)
            ->getRowArray();
        if ((int) ($stored['total'] ?? 0) > 0) {
            throw new RuntimeException('Refusing to drop stored guidance outcome text or actor provenance: ' . $table . '.');
        }
    }

    private function requireMariaDb(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Guidance outcome columns require MySQL/MariaDB.');
        }
    }

    /** @return array<string, string> column name => installed type */
    private function columnTypes(string $table): array
    {
        $rows = $this->db->query('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable($table)])->getResultArray();
        $types = [];
        foreach ($rows as $row) {
            $types[(string) $row['COLUMN_NAME']] = $this->normalizeType((string) $row['COLUMN_TYPE']);
        }
        return $types;
    }

    private function table(string $table): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($table));
    }

    private function query(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Guidance outcome migration failed.');
        }
    }
}
