<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Additive, forward-only LAN activation binding; existing devices retain nullable legacy scope. */
final class AddLanPushRegistrationScope extends Migration
{
    private const COLUMNS = [
        'audience' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
        'registration_id' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true],
        'scope_version' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => true],
        'deployment_id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
        // Nullable live-installation key lets revoked history coexist with exactly one active LAN context.
        'active_lan_installation_id' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true],
    ];

    private const INDEXES = [
        'push_tokens_lan_active_installation' => ['active_lan_installation_id'],
        'push_tokens_lan_installation' => ['audience', 'installation_id', 'revoked_at'],
        'push_tokens_lan_registration' => ['registration_id'],
    ];

    public function up(): void
    {
        $this->db->resetDataCache();
        if (!$this->db->tableExists('push_tokens')) {
            throw new RuntimeException('LAN push scope preflight STOP: push_tokens is missing.');
        }
        $fields = $this->db->getFieldNames('push_tokens');
        foreach (['tenant_id', 'user_id', 'installation_id', 'api_family_id', 'team_workspace_capable', 'revoked_at'] as $field) {
            if (!in_array($field, $fields, true)) {
                throw new RuntimeException('LAN push scope preflight STOP: required binding column is missing: ' . $field);
            }
        }
        $this->validateColumns();
        $this->validateIndexes();
        $missing = array_diff_key(self::COLUMNS, array_flip($fields));
        if ($missing !== [] && !$this->forge->addColumn('push_tokens', $missing)) {
            throw new RuntimeException('Unable to add LAN push registration scope columns.');
        }
        $this->db->resetDataCache();
        $indexes = $this->db->getIndexData('push_tokens');
        foreach (self::INDEXES as $name => $columns) {
            if (isset($indexes[$name])) {
                continue;
            }
            if ($name === 'push_tokens_lan_active_installation') {
                $this->forge->addUniqueKey($columns, $name);
            } else {
                $this->forge->addKey($columns, false, false, $name);
            }
            if (!$this->forge->processIndexes('push_tokens')) {
                throw new RuntimeException('Unable to add LAN push registration index: ' . $name);
            }
        }
        $this->db->resetDataCache();
        foreach (array_keys(self::COLUMNS) as $field) {
            if (!$this->db->fieldExists($field, 'push_tokens')) {
                throw new RuntimeException('LAN push scope migration validation failed: ' . $field);
            }
        }
        $this->validateColumns();
        $this->validateIndexes();
    }

    public function down(): void
    {
        throw new RuntimeException('LAN push registration scope migration is forward-only.');
    }

    /** Interrupted DDL is resumable, but incompatible preexisting columns must not be accepted. */
    private function validateColumns(): void
    {
        $columns = array_column($this->db->getFieldData('push_tokens'), null, 'name');
        foreach (self::COLUMNS as $name => $definition) {
            $column = $columns[$name] ?? null;
            if ($column === null) {
                continue;
            }
            $type = strtolower((string) $column->type);
            $expected = strtolower($definition['type']);
            $default = $column->default;
            if ($default === 'NULL') { $default = null; }
            if (preg_match('/\A' . $expected . '(?:\(\d+\))?(?: unsigned)?\z/D', $type) !== 1
                || !$column->nullable || $default !== null
                || ($this->db->DBDriver === 'MySQLi' && $expected !== 'tinyint'
                    && (int) $column->max_length !== $definition['constraint'])) {
                throw new RuntimeException('Incompatible LAN push scope column: ' . $name);
            }
        }
    }

    private function validateIndexes(): void
    {
        $indexes = $this->db->getIndexData('push_tokens');
        foreach (self::INDEXES as $name => $fields) {
            $index = $indexes[$name] ?? null;
            if ($index === null) {
                continue;
            }
            $type = $name === 'push_tokens_lan_active_installation' ? 'UNIQUE' : 'INDEX';
            if ($index->fields !== $fields || strtoupper($index->type) !== $type) {
                throw new RuntimeException('Incompatible LAN push scope index: ' . $name);
            }
        }
    }
}
