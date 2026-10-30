<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Preserve encrypted releases and history; grant no department access by default. */
final class AddAiAnalysisScope extends Migration
{
    public function up(): void
    {
        if (!in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('AI analysis scope requires MySQL or SQLite.');
        }

        $table = $this->db->escapeIdentifiers($this->db->prefixTable('ai_snapshots'));
        if (!$this->db->fieldExists('analysis_code', 'ai_snapshots')) {
            // Native ADD COLUMN preserves IDs, ciphertext, indexes and incoming FKs.
            // All old payloads are v1 clinic.activity, even isolation-test units.
            $this->execute('ALTER TABLE ' . $table
                . " ADD COLUMN analysis_code VARCHAR(64) NOT NULL DEFAULT 'clinic.activity'");
            unset($this->db->dataCache['field_names']['ai_snapshots']);
        }

        $indexes = $this->db->getIndexData('ai_snapshots');
        $newKey = ['tenant_id', 'unit', 'analysis_code', 'period'];
        $hasNew = false;
        foreach ($indexes as $index) {
            if (in_array(strtoupper($index->type), ['PRIMARY', 'UNIQUE'], true) && $index->fields === $newKey) {
                $hasNew = true;
            }
        }
        // Install protection before removing the old key. The store rejects the
        // intermediate state with both keys, so it cannot accept scoped releases yet.
        if (!$hasNew) {
            $name = $this->db->escapeIdentifiers($this->db->DBPrefix . 'ai_snapshot_analysis_release');
            $this->execute('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table
                . ' (tenant_id, unit, analysis_code, period)');
        }
        foreach ($indexes as $index) {
            if (in_array(strtoupper($index->type), ['PRIMARY', 'UNIQUE'], true)
                && $index->fields === ['tenant_id', 'unit', 'period']) {
                if (!$this->forge->dropKey('ai_snapshots', $index->name, false)) {
                    throw new RuntimeException('Unable to replace the legacy AI snapshot key.');
                }
            }
        }

        $number = ['type' => 'BIGINT', 'unsigned' => true];
        $this->forge->addField([
            'id' => $number + ['auto_increment' => true],
            'tenant_id' => $number,
            'role' => ['type' => 'VARCHAR', 'constraint' => 32],
            'unit' => ['type' => 'VARCHAR', 'constraint' => 32],
            'permission' => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_by' => $number,
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'role', 'permission'], 'ai_department_access_grant');
        if (!$this->forge->createTable('ai_department_access', true,
            $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [])) {
            throw new RuntimeException('Unable to create AI department access storage.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('AI analysis scope rollback requires review: multiple analyses may share a period.');
    }

    private function execute(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Unable to install AI analysis scope.');
        }
    }
}
