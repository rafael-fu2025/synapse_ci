<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Atomic, content-free API nonce/hash fields on the three idempotent Team writes. */
final class AddTeamApiIdempotencyKeys extends Migration
{
    private const TABLES = [
        'team_tasks' => ['team_task_api_nonce', ['tenant_id', 'unit', 'created_by_user_id', 'api_client_nonce']],
        'team_task_comments' => ['team_comment_api_nonce', ['tenant_id', 'unit', 'actor_user_id', 'api_client_nonce']],
        'team_events' => ['team_event_api_nonce', ['tenant_id', 'unit', 'created_by_user_id', 'api_client_nonce']],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$index, $fields]) {
            $this->db->resetDataCache();
            if (!$this->db->tableExists($table)) {
                throw new RuntimeException('Missing Team idempotency prerequisite: ' . $table);
            }
            $columns = $this->db->getFieldNames($table);
            $hasNonce = in_array('api_client_nonce', $columns, true);
            $hasHash = in_array('api_request_hash', $columns, true);
            if ($hasNonce !== $hasHash) {
                throw new RuntimeException('Partial Team API idempotency columns: ' . $table);
            }
            if ($hasNonce) {
                $this->verifyColumns($table);
                if ($this->db->table($table)->groupStart()
                    ->where('api_client_nonce', null)->where('api_request_hash !=', null)
                    ->groupEnd()->orGroupStart()->where('api_client_nonce !=', null)->where('api_request_hash', null)
                    ->groupEnd()->countAllResults() !== 0) {
                    throw new RuntimeException('Incompatible Team API idempotency rows: ' . $table);
                }
            } else {
                $missing = [
                    'api_client_nonce' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true, 'after' => $fields[2]],
                    'api_request_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'api_client_nonce'],
                ];
                if (!$this->forge->addColumn($table, $missing)) {
                    throw new RuntimeException('Unable to add Team API idempotency columns: ' . $table);
                }
                $this->db->resetDataCache();
                $this->verifyColumns($table);
            }

            $indexes = $this->db->getIndexData($table);
            if (isset($indexes[$index])) {
                if ($indexes[$index]->fields !== $fields || strtoupper($indexes[$index]->type) !== 'UNIQUE') {
                    throw new RuntimeException('Incompatible Team API idempotency index: ' . $index);
                }
                continue;
            }
            $this->forge->addKey($fields, false, true, $index);
            if (!$this->forge->processIndexes($table)) {
                throw new RuntimeException('Unable to add Team API idempotency index: ' . $index);
            }
        }
    }

    private function verifyColumns(string $table): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $rows = $this->db->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (?,?)', [
                $this->db->prefixTable($table), 'api_client_nonce', 'api_request_hash',
            ])->getResultArray();
            $rows = array_column($rows, null, 'COLUMN_NAME');
            foreach (['api_client_nonce' => 'char(36)', 'api_request_hash' => 'char(64)'] as $field => $type) {
                if (strtolower($rows[$field]['COLUMN_TYPE'] ?? '') !== $type || ($rows[$field]['IS_NULLABLE'] ?? '') !== 'YES') {
                    throw new RuntimeException('Incompatible Team API idempotency column: ' . $table . '.' . $field);
                }
            }
            return;
        }
        $info = $this->db->query('PRAGMA table_info(' . $this->db->protectIdentifiers($this->db->prefixTable($table)) . ')')->getResultArray();
        $info = array_column($info, null, 'name');
        foreach (['api_client_nonce' => 'CHAR', 'api_request_hash' => 'CHAR'] as $field => $type) {
            if (strtoupper($info[$field]['type'] ?? '') !== $type || (int) ($info[$field]['notnull'] ?? 1) !== 0) {
                throw new RuntimeException('Incompatible Team API idempotency column: ' . $table . '.' . $field);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Team API idempotency is forward-only; preserve nonce evidence to prevent duplicate writes.');
    }
}
