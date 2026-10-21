<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateSchedulingAndQueueTables extends Migration
{
    public function up(): void
    {
        // 1. Provider Availability (Recurring weekly shifts/slots for Nurses, Doctors, Counsellors)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'provider_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'unit' => [
                'type'       => 'ENUM',
                'constraint' => ['clinic', 'guidance'],
                'null'       => false,
            ],
            'day_of_week' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'null'     => false,
                'comment'  => '0=Sunday, 1=Monday, ..., 6=Saturday',
            ],
            'start_time' => [
                'type' => 'TIME',
                'null' => false,
            ],
            'end_time' => [
                'type' => 'TIME',
                'null' => false,
            ],
            'slot_duration_minutes' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 30,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'unit', 'day_of_week', 'is_active'], false, false, 'idx_avail_unit_dow');
        $this->forge->addKey(['tenant_id', 'provider_user_id'], false, false, 'idx_avail_provider');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('provider_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('provider_availability', true, ['ENGINE' => 'InnoDB']);

        // 2. Availability Exceptions (Leaves, Holidays, Custom Shift Adjustments)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'provider_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'exception_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'is_unavailable' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
                'comment'    => '1=Full day off, 0=Has custom hours',
            ],
            'custom_start_time' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'custom_end_time' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'provider_user_id', 'exception_date'], 'uniq_provider_exception_date');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('provider_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('availability_exceptions', true, ['ENGINE' => 'InnoDB']);

        // 3. Appointments (Unified scheduling for Clinic Visits and Guidance Consultations)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'unit' => [
                'type'       => 'ENUM',
                'constraint' => ['clinic', 'guidance'],
                'null'       => false,
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'provider_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'starts_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'comment' => 'UTC timestamp',
            ],
            'ends_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'comment' => 'UTC timestamp',
            ],
            'local_date' => [
                'type' => 'DATE',
                'null' => false,
                'comment' => 'Asia/Manila local calendar date for efficient day queries',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['scheduled', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'],
                'null'       => false,
                'default'    => 'scheduled',
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['routine', 'initial', 'follow_up', 'crisis', 'referral_based'],
                'null'       => false,
                'default'    => 'routine',
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'cancellation_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'booked_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'unit', 'local_date', 'status'], false, false, 'idx_appt_unit_date_status');
        $this->forge->addKey(['tenant_id', 'person_id', 'starts_at'], false, false, 'idx_appt_person_starts');
        $this->forge->addKey(['tenant_id', 'provider_user_id', 'starts_at'], false, false, 'idx_appt_provider_starts');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('provider_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('booked_by_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('appointments', true, ['ENGINE' => 'InnoDB']);

        // 4. Atomic Daily Ticket Counters (Prevents queue race conditions under concurrency)
        $this->forge->addField([
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'unit' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
            ],
            'queue_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'last_ticket_no' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
            ],
        ]);

        $this->forge->addPrimaryKey(['tenant_id', 'unit', 'queue_date']);
        $this->forge->createTable('daily_ticket_counters', true, ['ENGINE' => 'InnoDB']);

        // 5. Daily Queue Entries (Patient waiting lines for Clinic and Guidance)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'unit' => [
                'type'       => 'ENUM',
                'constraint' => ['clinic', 'guidance'],
                'null'       => false,
            ],
            'queue_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'ticket_no' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'comment'  => '1, 2, 3...',
            ],
            'ticket_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'comment'    => 'e.g. C-001 or G-001',
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'appointment_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['waiting', 'called', 'in_consultation', 'done', 'skipped'],
                'null'       => false,
                'default'    => 'waiting',
            ],
            'priority' => [
                'type'       => 'ENUM',
                'constraint' => ['routine', 'urgent', 'emergency'],
                'null'       => false,
                'default'    => 'routine',
            ],
            'called_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'called_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'skip_until' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => '60-minute recall window for skipped patients',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'queue_date', 'ticket_no'], 'uniq_unit_day_ticket');
        $this->forge->addKey(['tenant_id', 'unit', 'queue_date', 'status', 'priority'], false, false, 'idx_queue_unit_status');
        $this->forge->addKey(['tenant_id', 'person_id', 'queue_date'], false, false, 'idx_queue_person_date');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('person_id', 'people', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('appointment_id', 'appointments', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('called_by_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('queue_entries', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('queue_entries', true);
        $this->forge->dropTable('daily_ticket_counters', true);
        $this->forge->dropTable('appointments', true);
        $this->forge->dropTable('availability_exceptions', true);
        $this->forge->dropTable('provider_availability', true);
    }
}
