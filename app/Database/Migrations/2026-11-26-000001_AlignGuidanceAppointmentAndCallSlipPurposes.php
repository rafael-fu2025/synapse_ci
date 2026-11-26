<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AlignGuidanceAppointmentAndCallSlipPurposes extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('guidance_appointments')) {
            $this->forge->modifyColumn('guidance_appointments', [
                'purpose' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
            ]);
        }

        if ($this->db->tableExists('guidance_call_slips')) {
            $this->forge->modifyColumn('guidance_call_slips', [
                'purpose' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
            ]);
        }
    }

    public function down(): void
    {
        $legacyApptPurposes = ['routine_interview', 'counseling', 'follow_up', 'intake', 'inquiry', 'others'];
        if ($this->db->tableExists('guidance_appointments')) {
            $nonLegacy = $this->db->table('guidance_appointments')
                ->whereNotIn('purpose', $legacyApptPurposes)
                ->countAllResults();
            if ($nonLegacy === 0) {
                $this->forge->modifyColumn('guidance_appointments', [
                    'purpose' => [
                        'type'       => 'ENUM',
                        'constraint' => $legacyApptPurposes,
                        'null'       => false,
                    ],
                ]);
            }
        }

        $legacyCallPurposes = ['personal_counseling', 'career_counseling', 'routine_interview', 'follow_up', 'academic_counseling', 'others'];
        if ($this->db->tableExists('guidance_call_slips')) {
            $nonLegacy = $this->db->table('guidance_call_slips')
                ->whereNotIn('purpose', $legacyCallPurposes)
                ->countAllResults();
            if ($nonLegacy === 0) {
                $this->forge->modifyColumn('guidance_call_slips', [
                    'purpose' => [
                        'type'       => 'ENUM',
                        'constraint' => $legacyCallPurposes,
                        'null'       => false,
                    ],
                ]);
            }
        }
    }
}
