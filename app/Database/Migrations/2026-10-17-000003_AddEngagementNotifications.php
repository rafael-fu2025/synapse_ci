<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Additive, restartable publication channels; never resends historical notices. */
final class AddEngagementNotifications extends Migration
{
    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $result = $this->db->query($sql, $binds);
        if ($result === false) { throw new RuntimeException('Publication notification migration failed.'); }
        return $result;
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') { throw new RuntimeException('Publication notifications require MySQL/MariaDB.'); }
        foreach (['notifications', 'announcements', 'surveys', 'permissions', 'role_permissions'] as $table) {
            $meta = $this->sql('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($meta['ENGINE'] ?? '') !== 'INNODB') { throw new RuntimeException('Missing InnoDB prerequisite: ' . $table); }
        }
        foreach (['announcement_id' => 'announcements', 'survey_id' => 'surveys'] as $column => $parent) {
            $meta = $this->sql('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$this->db->prefixTable('notifications'), $column])->getRowArray();
            if ($meta !== null && (preg_replace('/bigint\(\d+\)/', 'bigint', strtolower($meta['COLUMN_TYPE'])) !== 'bigint unsigned' || $meta['IS_NULLABLE'] !== 'YES')) {
                throw new RuntimeException('Incompatible publication notification column: ' . $column);
            }
            if ($meta === null) { $this->sql('ALTER TABLE {notifications} ADD ' . $column . ' BIGINT UNSIGNED NULL DEFAULT NULL'); }
            $indexName = 'notification_' . $column . '_event';
            $indexes = $this->sql('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX', [$this->db->prefixTable('notifications'), $indexName])->getResultArray();
            $expected = ['tenant_id', $column, 'recipient_person_id', 'type'];
            if ($indexes === []) { $this->sql('ALTER TABLE {notifications} ADD UNIQUE KEY ' . $indexName . ' (tenant_id,' . $column . ',recipient_person_id,type)'); }
            elseif (array_column($indexes, 'COLUMN_NAME') !== $expected || (int) $indexes[0]['NON_UNIQUE'] !== 0) { throw new RuntimeException('Incompatible publication notification index.'); }
            $fk = $this->db->prefixTable('notification_' . $column . '_fk');
            $keys = $this->sql('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION', [$this->db->prefixTable('notifications'), $fk])->getResultArray();
            if ($keys === []) {
                $this->sql('ALTER TABLE {notifications} ADD CONSTRAINT ' . $this->db->protectIdentifiers($fk) . ' FOREIGN KEY (tenant_id,' . $column . ') REFERENCES {' . $parent . '}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT');
            } elseif (array_column($keys, 'COLUMN_NAME') !== ['tenant_id', $column] || array_column($keys, 'REFERENCED_COLUMN_NAME') !== ['tenant_id', 'id']
                || array_unique(array_column($keys, 'REFERENCED_TABLE_NAME')) !== [$this->db->prefixTable($parent)]
                || array_unique(array_column($keys, 'DELETE_RULE')) !== ['RESTRICT'] || array_unique(array_column($keys, 'UPDATE_RULE')) !== ['RESTRICT']) {
                throw new RuntimeException('Incompatible publication notification foreign key.');
            }
        }
        $meta = $this->sql('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$this->db->prefixTable('notifications'), 'type'])->getRowArray();
        $old = "enum('appointment_confirmed','referral_received')";
        $new = "enum('appointment_confirmed','referral_received','announcement_published','survey_published')";
        if (!in_array(strtolower($meta['COLUMN_TYPE'] ?? ''), [$old, $new], true) || ($meta['IS_NULLABLE'] ?? '') !== 'NO') { throw new RuntimeException('Incompatible notification types.'); }
        if (strtolower($meta['COLUMN_TYPE']) === $old) { $this->sql('ALTER TABLE {notifications} MODIFY type ' . $new . ' NOT NULL'); }
        // Add only this module's catalog/grants. Never run the destructive role seeder on existing installations.
        $permissions = [
            'clinic.announcements.manage' => ['clinic', 'Manage Clinic announcements', ['clinic_admin', 'superadmin']],
            'guidance.announcements.manage' => ['guidance', 'Manage Guidance announcements', ['guidance_admin', 'guidance_supervisor', 'superadmin']],
            'guidance.surveys.manage' => ['guidance', 'Manage identified Guidance surveys', ['guidance_admin', 'guidance_supervisor', 'superadmin']],
            'guidance.responses.read' => ['guidance', 'Read confidential survey responses', ['guidance_admin', 'guidance_supervisor', 'superadmin']],
        ];
        foreach ($permissions as $code => [$module, $name, $roles]) {
            $row = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if ($row === null) {
                if (!$this->db->table('permissions')->insert(['code' => $code, 'name' => $name, 'module' => $module])) { throw new RuntimeException('Unable to install content permission.'); }
                $id = (int) $this->db->insertID();
            } else { $id = (int) $row['id']; }
            foreach ($roles as $role) {
                $key = ['role' => $role, 'permission_id' => $id];
                if ($this->db->table('role_permissions')->where($key)->countAllResults() === 0 && !$this->db->table('role_permissions')->insert($key)) { throw new RuntimeException('Unable to install content grant.'); }
            }
        }
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Publication notifications are forward-only; preserve delivered notices and read state.');
    }
}
