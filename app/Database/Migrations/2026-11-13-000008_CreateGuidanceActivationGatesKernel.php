<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceActivationGatesKernel extends Migration
{
    public function up(): void
    {
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
            'gate_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'required_signoffs_json' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'recorded_signoffs_json' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'is_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'approved_at' => [
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
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'gate_key'], 'uq_guidance_act_gate_key');
        $this->forge->createTable('guidance_activation_gates', true, ['ENGINE' => 'InnoDB']);

        $now = date('Y-m-d H:i:s');
        $requiredSignoffs = json_encode(['guidance_head', 'qa_office', 'dpo'], JSON_UNESCAPED_SLASHES);
        $recordedSignoffs = json_encode([], JSON_UNESCAPED_SLASHES);

        $defaultGates = [
            [
                'gate_key'    => 'canonical_forms_signoff',
                'title'       => 'Canonical Form Codes, Titles, and Revisions Signoff',
                'description' => 'Canonical form codes/titles/revisions (General Referral 00 vs footer 01, Appointment code mismatch, Counseling Contact Form/Sheet title)',
            ],
            [
                'gate_key'    => 'safety_escalation_sop_signoff',
                'title'       => 'Safety, Abuse, and Self-Harm Escalation SOP Signoff',
                'description' => 'Official safety/abuse/self-harm escalation SOP, after-hours owner, deadlines, and permitted channels',
            ],
            [
                'gate_key'    => 'practitioner_credentials_signoff',
                'title'       => 'Practitioner Credentials, Supervision, Co-Sign, and Closure Rules Signoff',
                'description' => 'Counselor Associate/Counselor/qualified-assessor credential, supervision, co-sign, and closure rules',
            ],
            [
                'gate_key'    => 'privacy_retention_dpo_signoff',
                'title'       => 'Privacy Notices, Retention, Disclosures, and Guardian Identity DPO Signoff',
                'description' => 'Final privacy notices, legal bases, signer-capacity rules, retention periods, disclosure/printing policy, and guardian identity process',
            ],
            [
                'gate_key'    => 'mi_reproduction_signoff',
                'title'       => 'Multiple Intelligence Survey Reproduction and Interpretation Policy Signoff',
                'description' => 'MI reproduction/adaptation authority, exact reviewed wording/mapping, intended population, interpretation copy, and result-release policy',
            ],
            [
                'gate_key'    => 'evaluation_cohort_policy_signoff',
                'title'       => 'Service Evaluation Cohort Threshold and Comment Review Policy Signoff',
                'description' => 'Minimum evaluation aggregate cohort and comment-review policy',
            ],
        ];

        foreach ($defaultGates as $gate) {
            $exists = $this->db->table('guidance_activation_gates')
                ->where('tenant_id', 1)
                ->where('gate_key', $gate['gate_key'])
                ->countAllResults();

            if ($exists === 0) {
                $this->db->table('guidance_activation_gates')->insert([
                    'tenant_id'              => 1,
                    'gate_key'               => $gate['gate_key'],
                    'title'                  => $gate['title'],
                    'description'            => $gate['description'],
                    'required_signoffs_json' => $requiredSignoffs,
                    'recorded_signoffs_json' => $recordedSignoffs,
                    'is_enabled'             => 0,
                    'approved_at'            => null,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('guidance_activation_gates', true);
    }
}
