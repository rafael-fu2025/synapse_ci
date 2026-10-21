<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddAppointmentRequests extends Migration
{
    public function up(): void
    {
        // MySQL DDL auto-commits: preflight duplicates before changing any schema.
        $duplicates = $this->db->table('queue_entries')->select('appointment_id')
            ->where('appointment_id IS NOT NULL', null, false)->groupBy('appointment_id')
            ->having('COUNT(*) >', 1)->get()->getRowArray();
        if ($duplicates !== null) {
            throw new \RuntimeException('Resolve duplicate queue_entries.appointment_id values before adding the unique check-in constraint.');
        }
        $this->forge->modifyColumn('appointments', [
            'provider_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['requested', 'scheduled', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'], 'default' => 'scheduled'],
        ]);
        $this->forge->addColumn('appointments', [
            'confirmed_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'confirmed_at' => ['type' => 'DATETIME', 'null' => true],
            'assigned_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'assigned_at' => ['type' => 'DATETIME', 'null' => true],
            'version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
        ]);
        // No timestamp conversion: new requests retain the existing application-timezone convention.

        $this->forge->addUniqueKey('appointment_id', 'uniq_queue_appointment');
        $this->forge->processIndexes('queue_entries');
    }

    public function down(): void
    {
        // Restoring NOT NULL/old ENUM would destroy unassigned request semantics.
        if ($this->db->table('appointments')->where('provider_user_id', null)->orWhere('status', 'requested')->countAllResults() > 0) {
            throw new \RuntimeException('Resolve unassigned appointment requests before rolling back.');
        }
        $this->forge->dropKey('queue_entries', 'uniq_queue_appointment');
        $this->forge->dropColumn('appointments', ['confirmed_by_user_id', 'confirmed_at', 'assigned_by_user_id', 'assigned_at', 'version']);
        $this->forge->modifyColumn('appointments', [
            'provider_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'status' => ['type' => 'ENUM', 'constraint' => ['scheduled', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'], 'default' => 'scheduled'],
        ]);
    }
}
