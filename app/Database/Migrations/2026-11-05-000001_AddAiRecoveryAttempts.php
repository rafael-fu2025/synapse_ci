<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Additive only. Historical jobs deliberately receive no dispatch provenance. */
final class AddAiRecoveryAttempts extends Migration
{
    public function up(): void
    {
        $number = ['type' => 'BIGINT', 'unsigned' => true];
        $this->checked($this->forge->addColumn('ai_snapshots', [
            'materialization_generation' => $number + ['default' => 1],
        ]));
        $this->checked($this->forge->addColumn('ai_jobs', [
            'snapshot_generation' => $number + ['null' => true],
            'pipeline_digest' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'retry_of_id' => $number + ['null' => true],
            'root_job_id' => $number + ['null' => true],
        ]));
        $this->forge->addUniqueKey('retry_of_id', 'ai_retry_successor');
        $this->forge->addKey(['tenant_id', 'unit', 'root_job_id'], false, false, 'ai_retry_family');
        $this->checked($this->forge->processIndexes('ai_jobs'));
        $this->forge->addField([
            'id' => $number + ['auto_increment' => true],
            'tenant_id' => $number, 'unit' => ['type' => 'VARCHAR', 'constraint' => 32],
            'actor_id' => $number, 'action' => ['type' => 'VARCHAR', 'constraint' => 32],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 16],
            'entity_id' => $number, 'generation' => $number,
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'unit', 'entity_type', 'entity_id']);
        $this->checked($this->forge->createTable('ai_storage_audit', false,
            $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : []));
        $this->db->resetDataCache();
    }

    private function checked(bool $success): void
    {
        if (!$success) { throw new \RuntimeException('AI recovery migration incomplete; operator review required.'); }
    }

    public function down(): void
    {
        // Removing lineage/audit could enable unsafe replay after a downgrade.
        throw new \RuntimeException('AI recovery history is irreversible; restore a reviewed backup instead.');
    }
}
