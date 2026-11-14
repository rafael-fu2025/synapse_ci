<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceEpisodesAppointmentsAndCallSlipsKernel extends Migration
{
    public function up(): void
    {
        // 1. Guidance Episodes
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
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'intake_referral_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'active', 'closed', 'transferred'],
                'null'       => false,
                'default'    => 'open',
            ],
            'opened_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'closed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'primary_counselor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'closure_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'closure_notes_cipher' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addKey('tenant_id');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gepisode_person');
        $this->forge->addKey(['tenant_id', 'intake_referral_id'], false, false, 'idx_gepisode_intake_referral');
        $this->forge->addKey(['tenant_id', 'case_id'], false, false, 'idx_gepisode_case');
        $this->forge->addKey(['tenant_id', 'primary_counselor_user_id'], false, false, 'idx_gepisode_primary_counselor');
        $this->forge->addKey(['tenant_id', 'status'], false, false, 'idx_gepisode_status');
        $this->forge->addKey('person_id');
        $this->forge->addKey('intake_referral_id');
        $this->forge->addKey('case_id');
        $this->forge->addKey('primary_counselor_user_id');
        $this->forge->addKey('status');
        $this->forge->createTable('guidance_episodes', true, ['ENGINE' => 'InnoDB']);

        // 2. Guidance Appointments
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
            'episode_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'counselor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'appointment_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'scheduled_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'scheduled_time' => [
                'type' => 'TIME',
                'null' => false,
            ],
            'purpose' => [
                'type'       => 'ENUM',
                'constraint' => ['routine_interview', 'counseling', 'follow_up', 'intake', 'inquiry', 'others'],
                'null'       => false,
            ],
            'purpose_other' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['scheduled', 'confirmed', 'attended', 'cancelled', 'rescheduled', 'no_show'],
                'null'       => false,
                'default'    => 'scheduled',
            ],
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'queue_ticket_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'notes_cipher' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addUniqueKey(['tenant_id', 'appointment_number'], 'uq_gappointment_number');
        $this->forge->addKey('tenant_id');
        $this->forge->addKey(['tenant_id', 'episode_id'], false, false, 'idx_gappointment_episode');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gappointment_person');
        $this->forge->addKey(['tenant_id', 'counselor_user_id'], false, false, 'idx_gappointment_counselor');
        $this->forge->addKey(['tenant_id', 'status'], false, false, 'idx_gappointment_status');
        $this->forge->addKey(['tenant_id', 'scheduled_date'], false, false, 'idx_gappointment_scheduled_date');
        $this->forge->addKey(['tenant_id', 'form_instance_id'], false, false, 'idx_gappointment_form_instance');
        $this->forge->addKey('episode_id');
        $this->forge->addKey('person_id');
        $this->forge->addKey('counselor_user_id');
        $this->forge->addKey('appointment_number');
        $this->forge->addKey('status');
        $this->forge->addKey('scheduled_date');
        $this->forge->createTable('guidance_appointments', true, ['ENGINE' => 'InnoDB']);

        // 3. Guidance Call Slips
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
            'episode_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'issuing_counselor_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'call_slip_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'call_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'call_time' => [
                'type' => 'TIME',
                'null' => false,
            ],
            'purpose' => [
                'type'       => 'ENUM',
                'constraint' => ['personal_counseling', 'career_counseling', 'routine_interview', 'follow_up', 'academic_counseling', 'others'],
                'null'       => false,
            ],
            'purpose_other' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'attendance_status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'acknowledged', 'attended', 'missed', 'cancelled'],
                'null'       => false,
                'default'    => 'pending',
            ],
            'parent_guardian_delivery' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'delivery_notes' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
            ],
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'acknowledged_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'attended_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
        $this->forge->addUniqueKey(['tenant_id', 'call_slip_number'], 'uq_gcall_slip_number');
        $this->forge->addKey('tenant_id');
        $this->forge->addKey(['tenant_id', 'episode_id'], false, false, 'idx_gcallslip_episode');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gcallslip_person');
        $this->forge->addKey(['tenant_id', 'issuing_counselor_user_id'], false, false, 'idx_gcallslip_issuing_counselor');
        $this->forge->addKey(['tenant_id', 'attendance_status'], false, false, 'idx_gcallslip_attendance_status');
        $this->forge->addKey(['tenant_id', 'call_date'], false, false, 'idx_gcallslip_call_date');
        $this->forge->addKey(['tenant_id', 'form_instance_id'], false, false, 'idx_gcallslip_form_instance');
        $this->forge->addKey('episode_id');
        $this->forge->addKey('person_id');
        $this->forge->addKey('issuing_counselor_user_id');
        $this->forge->addKey('call_slip_number');
        $this->forge->addKey('attendance_status');
        $this->forge->addKey('call_date');
        $this->forge->createTable('guidance_call_slips', true, ['ENGINE' => 'InnoDB']);

        // 4. Permissions and Role Mappings
        $permissions = [
            'guidance.appointments.manage' => ['guidance', 'Manage guidance appointments', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
            'guidance.intake.manage'       => ['guidance', 'Manage guidance intake and episodes', ['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin']],
        ];

        foreach ($permissions as $code => [$module, $name, $roles]) {
            $row = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if ($row === null) {
                $this->db->table('permissions')->insert(['code' => $code, 'name' => $name, 'module' => $module]);
                $id = (int) $this->db->insertID();
            } else {
                $id = (int) $row['id'];
            }
            foreach ($roles as $role) {
                $key = ['role' => $role, 'permission_id' => $id];
                if ($this->db->table('role_permissions')->where($key)->countAllResults() === 0) {
                    $this->db->table('role_permissions')->insert($key);
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('guidance_call_slips', true);
        $this->forge->dropTable('guidance_appointments', true);
        $this->forge->dropTable('guidance_episodes', true);

        $codes = [
            'guidance.appointments.manage',
            'guidance.intake.manage',
        ];
        $rows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
        if ($rows !== []) {
            $ids = array_column($rows, 'id');
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
            $this->db->table('permissions')->whereIn('id', $ids)->delete();
        }
    }
}
