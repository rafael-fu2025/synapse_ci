<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Installation capability and API-family binding for safe Team push delivery/logout. */
final class AddPushInstallationCapabilities extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();
        if (!$this->db->tableExists('push_tokens')) {
            throw new RuntimeException('Push capability preflight STOP: push_tokens is missing.');
        }
        $fields = $this->db->getFieldNames('push_tokens');
        $missing = [];
        if (!in_array('installation_id', $fields, true)) {
            $missing['installation_id'] = ['type' => 'CHAR', 'constraint' => 36, 'null' => true, 'after' => 'platform'];
        }
        if (!in_array('api_family_id', $fields, true)) {
            $missing['api_family_id'] = ['type' => 'CHAR', 'constraint' => 32, 'null' => true, 'after' => 'installation_id'];
        }
        if (!in_array('team_workspace_capable', $fields, true)) {
            $missing['team_workspace_capable'] = ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0, 'after' => 'api_family_id'];
        }
        if ($missing !== [] && !$this->forge->addColumn('push_tokens', $missing)) {
            throw new RuntimeException('Unable to add push installation capability columns.');
        }
        $this->db->resetDataCache();
        $indexes = $this->db->getIndexData('push_tokens');
        if (!isset($indexes['push_tokens_installation'])) {
            $this->forge->addKey(['tenant_id', 'user_id', 'installation_id'], false, false, 'push_tokens_installation');
            if (!$this->forge->processIndexes('push_tokens')) {
                throw new RuntimeException('Unable to add push installation index.');
            }
        }
        if (!isset($indexes['push_tokens_family'])) {
            $this->forge->addKey(['api_family_id', 'revoked_at'], false, false, 'push_tokens_family');
            if (!$this->forge->processIndexes('push_tokens')) {
                throw new RuntimeException('Unable to add push family index.');
            }
        }
        $this->db->resetDataCache();
        foreach (['installation_id', 'api_family_id', 'team_workspace_capable'] as $field) {
            if (!$this->db->fieldExists($field, 'push_tokens')) {
                throw new RuntimeException('Push capability migration validation failed: ' . $field);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Push installation capability migration is forward-only.');
    }
}
