<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Reader-only indexes for the dashboard aggregations.
 *
 * These never rewrite rows, never touch the append-only audit triggers and never
 * change a column definition: they only make the predicates the dashboards already
 * use able to seek an index instead of scanning the tenant's history.
 */
final class AddDashboardQueryIndexes extends Migration
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> table, index name, columns */
    private const INDEXES = [
        ['counselling_sessions', 'idx_session_started', ['tenant_id', 'started_at']],
        ['counselling_sessions', 'idx_session_status', ['tenant_id', 'status']],
        ['counselling_cases', 'idx_case_category', ['tenant_id', 'category']],
        ['clinic_encounters', 'idx_enc_status', ['tenant_id', 'status']],
        ['users', 'idx_users_status', ['tenant_id', 'status']],
        ['bmg_batches', 'idx_bmg_created', ['tenant_id', 'created_at']],
    ];

    /**
     * @return array<string, list<string>> table => list of index names
     */
    private function plan(): array
    {
        $plan = [];
        foreach (self::INDEXES as [$table, $name]) {
            $plan[$table][] = $name;
        }

        return $plan;
    }

    /**
     * @param list<string> $columns
     */
    private function verify(string $table, string $name, array $columns): void
    {
        $existing = $this->db->getIndexData($table);
        if (!isset($existing[$name])) {
            return;
        }
        if ($existing[$name]->fields !== $columns || strtoupper($existing[$name]->type) !== 'INDEX') {
            throw new RuntimeException('Unexpected dashboard reader index definition: ' . $table . '.' . $name);
        }
    }

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $name, $columns]) {
            $this->verify($table, $name, $columns);
        }

        foreach ($this->plan() as $table => $names) {
            $existing = $this->db->getIndexData($table);
            $missing = array_values(array_filter(
                $names,
                static fn (string $name): bool => ! isset($existing[$name])
            ));
            if ($missing === []) {
                continue;
            }
            foreach ($missing as $name) {
                $this->forge->addKey(self::columnsFor($table, $name), false, false, $name);
            }
            if (!$this->forge->processIndexes($table)) {
                throw new RuntimeException('Unable to install dashboard reader indexes on ' . $table . '. Review the schema before retrying.');
            }
        }
    }

    public function down(): void
    {
        foreach ($this->plan() as $table => $names) {
            $existing = $this->db->getIndexData($table);
            foreach ($names as $name) {
                $this->verify($table, $name, self::columnsFor($table, $name));
                if (isset($existing[$name]) && !$this->forge->dropKey($table, $name, false)) {
                    throw new RuntimeException('Unable to remove dashboard reader index: ' . $table . '.' . $name);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function columnsFor(string $table, string $name): array
    {
        foreach (self::INDEXES as [$indexTable, $indexName, $columns]) {
            if ($indexTable === $table && $indexName === $name) {
                return $columns;
            }
        }

        throw new RuntimeException('Unknown dashboard reader index: ' . $table . '.' . $name);
    }
}
