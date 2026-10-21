<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Stop BMG writers for either direction: MySQL DDL implicitly commits. No history backfill. */
final class AddBmgBatchNotes extends Migration
{
    private function notesColumn(): ?array
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('BMG batch notes require MySQL/MariaDB with InnoDB.');
        }
        $table = $this->db->prefixTable('bmg_batches');
        $metadata = $this->db->query('SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])->getRowArray();
        if ($metadata === null || strtoupper($metadata['ENGINE'] ?? '') !== 'INNODB' || $metadata['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new RuntimeException('BMG batch notes require an existing InnoDB bmg_batches table.');
        }
        $column = $this->db->query('SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'notes'])->getRowArray();
        if ($column !== null && (strtolower($column['DATA_TYPE']) !== 'text' || $column['IS_NULLABLE'] !== 'YES'
            || !in_array($column['COLUMN_DEFAULT'], [null, 'NULL'], true))) {
            throw new RuntimeException('Unexpected bmg_batches.notes definition; refusing to replace or remove it.');
        }
        return $column;
    }

    public function up(): void
    {
        $column = $this->notesColumn();
        if ($column !== null) {
            return;
        }
        if (!$this->forge->addColumn('bmg_batches', [
            'notes' => ['type' => 'TEXT', 'null' => true],
        ])) {
            throw new RuntimeException('Unable to add BMG batch notes; keep writers stopped and review before retrying.');
        }
        // MySQL DDL implicitly commits. Verify the installed shape before releasing writers.
        if ($this->notesColumn() === null) {
            throw new RuntimeException('BMG batch notes column is still absent; keep writers stopped and review before retrying.');
        }
    }

    public function down(): void
    {
        if ($this->notesColumn() === null) {
            return;
        }
        if ($this->db->table('bmg_batches')->where('notes IS NOT NULL', null, false)->countAllResults() > 0) {
            throw new RuntimeException('Batch notes are retained history; use a forward migration instead of dropping them.');
        }
        if (!$this->forge->dropColumn('bmg_batches', 'notes')) {
            throw new RuntimeException('Unable to remove unused BMG batch notes column.');
        }
    }
}
