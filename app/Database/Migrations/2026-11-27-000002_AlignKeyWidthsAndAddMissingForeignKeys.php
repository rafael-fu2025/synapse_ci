<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tier 1 Phase 2 database integrity hardening:
 * - Aligns PK/FK integer widths across AI and Guidance Kernel tables and replaces archive_records sentinel 0 with NULL.
 * - Adds archive_records active-entity uniqueness guard.
 * - Adds tenant-scoped foreign keys across all 39 previously FK-less tables and missing operational FK columns.
 */
final class AlignKeyWidthsAndAddMissingForeignKeys extends Migration
{
    private const AI_TABLES = [
        'ai_releases',
        'ai_snapshots',
        'ai_jobs',
        'ai_dispatch_attempts',
        'ai_insights',
        'ai_reviews',
        'ai_department_access',
        'ai_storage_audit',
    ];

    private const PARENT_TENANT_ID_TABLES = [
        'people',
        'users',
        'appointments',
        'queue_entries',
        'referrals',
        'counselling_cases',
        'counselling_sessions',
        'counselling_notes',
        'guidance_form_definitions',
        'guidance_form_definition_revisions',
        'guidance_privacy_notices',
        'guidance_retention_policies',
        'guidance_form_instances',
        'guidance_form_revisions',
        'guidance_intake_referrals',
        'guidance_safety_assessments',
        'guidance_episodes',
        'guidance_appointments',
        'guidance_mi_assignments',
        'archive_retention_policies',
        'ai_snapshots',
        'ai_jobs',
        'ai_insights',
    ];

    private const TENANT_SCOPED_TABLES = [
        // 27 Guidance Kernel
        'guidance_form_definitions',
        'guidance_form_definition_revisions',
        'guidance_privacy_notices',
        'guidance_retention_policies',
        'guidance_consent_receipts',
        'guidance_form_instances',
        'guidance_form_drafts',
        'guidance_form_revisions',
        'guidance_form_attestations',
        'guidance_document_jobs',
        'guidance_document_issuances',
        'guidance_legal_holds',
        'guidance_practitioner_profiles',
        'guidance_supervision_assignments',
        'guidance_case_assignments',
        'guidance_case_transfers',
        'guidance_intake_referrals',
        'guidance_safety_assessments',
        'guidance_safety_escalations',
        'guidance_episodes',
        'guidance_appointments',
        'guidance_call_slips',
        'guidance_mi_assignments',
        'guidance_mi_submissions',
        'guidance_evaluation_tokens',
        'guidance_service_evaluations',
        'guidance_activation_gates',
        // 8 AI Insights
        'ai_releases',
        'ai_snapshots',
        'ai_jobs',
        'ai_dispatch_attempts',
        'ai_insights',
        'ai_reviews',
        'ai_department_access',
        'ai_storage_audit',
        // 3 System Archive
        'archive_retention_policies',
        'archive_records',
        'archive_legal_holds',
        // Student Intern Governance
        'student_intern_placements',
        // Missing core table tenant FK
        'daily_ticket_counters',
    ];

    /** @var list<array{0: string, 1: list<string>, 2: string, 3: list<string>, 4: string}> */
    private const COMPOSITE_FKS = [
        // Guidance Controlled Forms Kernel
        ['guidance_form_definition_revisions', ['tenant_id', 'form_definition_id'], 'guidance_form_definitions', ['tenant_id', 'id'], 'fk_gfdr_def'],
        ['guidance_consent_receipts', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gcr_person'],
        ['guidance_consent_receipts', ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'], 'fk_gcr_user'],
        ['guidance_consent_receipts', ['tenant_id', 'privacy_notice_id'], 'guidance_privacy_notices', ['tenant_id', 'id'], 'fk_gcr_notice'],
        ['guidance_form_instances', ['tenant_id', 'form_definition_id'], 'guidance_form_definitions', ['tenant_id', 'id'], 'fk_gfi_def'],
        ['guidance_form_instances', ['tenant_id', 'definition_revision_id'], 'guidance_form_definition_revisions', ['tenant_id', 'id'], 'fk_gfi_def_rev'],
        ['guidance_form_instances', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gfi_person'],
        ['guidance_form_instances', ['tenant_id', 'case_id'], 'counselling_cases', ['tenant_id', 'id'], 'fk_gfi_case'],
        ['guidance_form_instances', ['tenant_id', 'episode_id'], 'guidance_episodes', ['tenant_id', 'id'], 'fk_gfi_episode'],
        ['guidance_form_instances', ['tenant_id', 'session_id'], 'counselling_sessions', ['tenant_id', 'id'], 'fk_gfi_session'],
        ['guidance_form_instances', ['tenant_id', 'referral_id'], 'referrals', ['tenant_id', 'id'], 'fk_gfi_referral'],
        ['guidance_form_instances', ['tenant_id', 'appointment_id'], 'guidance_appointments', ['tenant_id', 'id'], 'fk_gfi_gappointment'],
        ['guidance_form_instances', ['tenant_id', 'intake_id'], 'guidance_intake_referrals', ['tenant_id', 'id'], 'fk_gfi_intake'],
        ['guidance_form_instances', ['tenant_id', 'privacy_notice_id'], 'guidance_privacy_notices', ['tenant_id', 'id'], 'fk_gfi_notice'],
        ['guidance_form_instances', ['tenant_id', 'retention_policy_id'], 'guidance_retention_policies', ['tenant_id', 'id'], 'fk_gfi_ret_policy'],
        ['guidance_form_instances', ['tenant_id', 'created_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gfi_created_by'],
        ['guidance_form_instances', ['tenant_id', 'finalized_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gfi_finalized_by'],
        ['guidance_form_instances', ['tenant_id', 'assigned_supervisor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gfi_supervisor'],
        ['guidance_form_drafts', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gfd_inst'],
        ['guidance_form_drafts', ['tenant_id', 'updated_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gfd_updated_by'],
        ['guidance_form_revisions', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gfr_inst'],
        ['guidance_form_revisions', ['tenant_id', 'supersedes_revision_id'], 'guidance_form_revisions', ['tenant_id', 'id'], 'fk_gfr_supersedes'],
        ['guidance_form_revisions', ['tenant_id', 'definition_revision_id'], 'guidance_form_definition_revisions', ['tenant_id', 'id'], 'fk_gfr_def_rev'],
        ['guidance_form_revisions', ['tenant_id', 'created_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gfr_created_by'],
        ['guidance_form_attestations', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gfa_inst'],
        ['guidance_form_attestations', ['tenant_id', 'form_revision_id'], 'guidance_form_revisions', ['tenant_id', 'id'], 'fk_gfa_rev'],
        ['guidance_form_attestations', ['tenant_id', 'signer_user_id'], 'users', ['tenant_id', 'id'], 'fk_gfa_signer_user'],
        ['guidance_form_attestations', ['tenant_id', 'signer_person_id'], 'people', ['tenant_id', 'id'], 'fk_gfa_signer_person'],
        ['guidance_document_jobs', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gdj_inst'],
        ['guidance_document_jobs', ['tenant_id', 'form_revision_id'], 'guidance_form_revisions', ['tenant_id', 'id'], 'fk_gdj_rev'],
        ['guidance_document_issuances', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gdi_inst'],
        ['guidance_document_issuances', ['tenant_id', 'form_revision_id'], 'guidance_form_revisions', ['tenant_id', 'id'], 'fk_gdi_rev'],
        ['guidance_document_issuances', ['tenant_id', 'issued_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gdi_issued_by'],
        ['guidance_document_issuances', ['tenant_id', 'voided_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gdi_voided_by'],
        ['guidance_legal_holds', ['tenant_id', 'placed_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_glh_placed_by'],
        ['guidance_legal_holds', ['tenant_id', 'lifted_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_glh_lifted_by'],

        // Guidance Practitioners, Transfers, Intake, Safety
        ['guidance_practitioner_profiles', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gpp_person'],
        ['guidance_practitioner_profiles', ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'], 'fk_gpp_user'],
        ['guidance_supervision_assignments', ['tenant_id', 'associate_user_id'], 'users', ['tenant_id', 'id'], 'fk_gsa_associate'],
        ['guidance_supervision_assignments', ['tenant_id', 'supervisor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gsa_supervisor'],
        ['guidance_case_assignments', ['tenant_id', 'case_id'], 'counselling_cases', ['tenant_id', 'id'], 'fk_gca_case'],
        ['guidance_case_assignments', ['tenant_id', 'counsellor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gca_counsellor'],
        ['guidance_case_assignments', ['tenant_id', 'assigned_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gca_assigned_by'],
        ['guidance_case_transfers', ['tenant_id', 'case_id'], 'counselling_cases', ['tenant_id', 'id'], 'fk_gct_case'],
        ['guidance_case_transfers', ['tenant_id', 'from_counsellor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gct_from'],
        ['guidance_case_transfers', ['tenant_id', 'to_counsellor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gct_to'],
        ['guidance_intake_referrals', ['tenant_id', 'clinic_referral_id'], 'referrals', ['tenant_id', 'id'], 'fk_gir_clinic_ref'],
        ['guidance_intake_referrals', ['tenant_id', 'subject_person_id'], 'people', ['tenant_id', 'id'], 'fk_gir_subject'],
        ['guidance_intake_referrals', ['tenant_id', 'referrer_user_id'], 'users', ['tenant_id', 'id'], 'fk_gir_ref_user'],
        ['guidance_intake_referrals', ['tenant_id', 'referrer_person_id'], 'people', ['tenant_id', 'id'], 'fk_gir_ref_person'],
        ['guidance_intake_referrals', ['tenant_id', 'assigned_counsellor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gir_counsellor'],
        ['guidance_intake_referrals', ['tenant_id', 'linked_case_id'], 'counselling_cases', ['tenant_id', 'id'], 'fk_gir_case'],
        ['guidance_safety_assessments', ['tenant_id', 'intake_referral_id'], 'guidance_intake_referrals', ['tenant_id', 'id'], 'fk_gsaf_intake'],
        ['guidance_safety_assessments', ['tenant_id', 'case_id'], 'counselling_cases', ['tenant_id', 'id'], 'fk_gsaf_case'],
        ['guidance_safety_assessments', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gsaf_person'],
        ['guidance_safety_assessments', ['tenant_id', 'assessor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gsaf_assessor'],
        ['guidance_safety_escalations', ['tenant_id', 'safety_assessment_id'], 'guidance_safety_assessments', ['tenant_id', 'id'], 'fk_gesc_assess'],
        ['guidance_safety_escalations', ['tenant_id', 'escalated_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gesc_user'],

        // Guidance Episodes, Appointments, Call Slips, MI, Evaluations
        ['guidance_episodes', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gep_person'],
        ['guidance_episodes', ['tenant_id', 'intake_referral_id'], 'guidance_intake_referrals', ['tenant_id', 'id'], 'fk_gep_intake'],
        ['guidance_episodes', ['tenant_id', 'case_id'], 'counselling_cases', ['tenant_id', 'id'], 'fk_gep_case'],
        ['guidance_episodes', ['tenant_id', 'primary_counselor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gep_counselor'],
        ['guidance_appointments', ['tenant_id', 'episode_id'], 'guidance_episodes', ['tenant_id', 'id'], 'fk_gappt_episode'],
        ['guidance_appointments', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gappt_person'],
        ['guidance_appointments', ['tenant_id', 'counselor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gappt_counselor'],
        ['guidance_appointments', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gappt_form_inst'],
        ['guidance_appointments', ['tenant_id', 'queue_ticket_id'], 'queue_entries', ['tenant_id', 'id'], 'fk_gappt_queue'],
        ['guidance_call_slips', ['tenant_id', 'episode_id'], 'guidance_episodes', ['tenant_id', 'id'], 'fk_gcs_episode'],
        ['guidance_call_slips', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gcs_person'],
        ['guidance_call_slips', ['tenant_id', 'issuing_counselor_user_id'], 'users', ['tenant_id', 'id'], 'fk_gcs_counselor'],
        ['guidance_call_slips', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gcs_form_inst'],
        ['guidance_mi_assignments', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gmia_person'],
        ['guidance_mi_assignments', ['tenant_id', 'assigned_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gmia_assigned_by'],
        ['guidance_mi_submissions', ['tenant_id', 'assignment_id'], 'guidance_mi_assignments', ['tenant_id', 'id'], 'fk_gmis_assign'],
        ['guidance_mi_submissions', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_gmis_person'],
        ['guidance_mi_submissions', ['tenant_id', 'attested_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gmis_attested_by'],
        ['guidance_mi_submissions', ['tenant_id', 'form_instance_id'], 'guidance_form_instances', ['tenant_id', 'id'], 'fk_gmis_form_inst'],
        ['guidance_evaluation_tokens', ['tenant_id', 'episode_id'], 'guidance_episodes', ['tenant_id', 'id'], 'fk_gevt_episode'],
        ['guidance_evaluation_tokens', ['tenant_id', 'session_id'], 'counselling_sessions', ['tenant_id', 'id'], 'fk_gevt_session'],
        ['guidance_evaluation_tokens', ['tenant_id', 'provider_user_id'], 'users', ['tenant_id', 'id'], 'fk_gevt_provider'],
        ['guidance_service_evaluations', ['tenant_id', 'provider_user_id'], 'users', ['tenant_id', 'id'], 'fk_gse_provider'],
        ['guidance_service_evaluations', ['tenant_id', 'moderated_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_gse_moderated_by'],

        // AI Insights internal chain (actor_id intentionally omitted for synthetic AI recovery tests)
        ['ai_jobs', ['tenant_id', 'snapshot_id'], 'ai_snapshots', ['tenant_id', 'id'], 'fk_ai_jobs_snapshot'],
        ['ai_jobs', ['tenant_id', 'retry_of_id'], 'ai_jobs', ['tenant_id', 'id'], 'fk_ai_jobs_retry_of'],
        ['ai_dispatch_attempts', ['tenant_id', 'job_id'], 'ai_jobs', ['tenant_id', 'id'], 'fk_ai_disp_job'],
        ['ai_insights', ['tenant_id', 'job_id'], 'ai_jobs', ['tenant_id', 'id'], 'fk_ai_ins_job'],
        ['ai_reviews', ['tenant_id', 'insight_id'], 'ai_insights', ['tenant_id', 'id'], 'fk_ai_rev_insight'],

        // System Archive Governance
        ['archive_retention_policies', ['tenant_id', 'created_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_arch_pol_created_by'],
        ['archive_retention_policies', ['tenant_id', 'updated_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_arch_pol_updated_by'],
        ['archive_records', ['tenant_id', 'retention_policy_id'], 'archive_retention_policies', ['tenant_id', 'id'], 'fk_arch_rec_policy'],
        ['archive_records', ['tenant_id', 'archived_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_arch_rec_archived_by'],
        ['archive_records', ['tenant_id', 'restored_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_arch_rec_restored_by'],
        ['archive_legal_holds', ['tenant_id', 'placed_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_arch_hold_placed_by'],
        ['archive_legal_holds', ['tenant_id', 'lifted_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_arch_hold_lifted_by'],

        // Student Intern Placements
        ['student_intern_placements', ['tenant_id', 'user_id'], 'users', ['tenant_id', 'id'], 'fk_sip_user'],
        ['student_intern_placements', ['tenant_id', 'person_id'], 'people', ['tenant_id', 'id'], 'fk_sip_person'],
        ['student_intern_placements', ['tenant_id', 'supervisor_user_id'], 'users', ['tenant_id', 'id'], 'fk_sip_supervisor'],
        ['student_intern_placements', ['tenant_id', 'revoked_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_sip_revoked_by'],
        ['student_intern_placements', ['tenant_id', 'created_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_sip_created_by'],
        ['student_intern_placements', ['tenant_id', 'updated_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_sip_updated_by'],

        // Missing Operational FKs on earlier tables
        ['mis_programs', ['tenant_id', 'department_code'], 'mis_departments', ['tenant_id', 'code'], 'fk_mis_prog_dept'],
        ['appointments', ['tenant_id', 'confirmed_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_appt_confirmed_by'],
        ['appointments', ['tenant_id', 'assigned_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_appt_assigned_by'],
        ['clinic_encounters', ['tenant_id', 'appointment_id'], 'appointments', ['tenant_id', 'id'], 'fk_ce_appointment'],
        ['clinic_encounters', ['tenant_id', 'queue_entry_id'], 'queue_entries', ['tenant_id', 'id'], 'fk_ce_queue_entry'],
        ['clinic_encounters', ['tenant_id', 'encoded_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_ce_encoded_by'],
        ['counselling_cases', ['tenant_id', 'closed_by'], 'users', ['tenant_id', 'id'], 'fk_cc_closed_by'],
        ['counselling_sessions', ['tenant_id', 'appointment_id'], 'appointments', ['tenant_id', 'id'], 'fk_cs_appointment'],
        ['counselling_sessions', ['tenant_id', 'queue_entry_id'], 'queue_entries', ['tenant_id', 'id'], 'fk_cs_queue_entry'],
        ['counselling_notes', ['tenant_id', 'supersedes_note_id'], 'counselling_notes', ['tenant_id', 'id'], 'fk_cn_supersedes'],
        ['guidance_followups', ['tenant_id', 'session_id'], 'counselling_sessions', ['tenant_id', 'id'], 'fk_gf_session'],
        ['guidance_followups', ['tenant_id', 'completed_by'], 'users', ['tenant_id', 'id'], 'fk_gf_completed_by'],
        ['survey_responses', ['tenant_id', 'interviewed_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_sr_interviewed_by'],
        ['team_chat_messages', ['tenant_id', 'unit', 'reply_to_id'], 'team_chat_messages', ['tenant_id', 'unit', 'id'], 'fk_tcm_reply_to'],
        ['bmg_rotation_schedules', ['tenant_id', 'updated_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_brs_updated_by'],
        ['bmg_rotation_commands', ['tenant_id', 'created_by_user_id'], 'users', ['tenant_id', 'id'], 'fk_brc_created_by'],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->alignColumnWidthsAndSentinels();
        $this->ensureArchiveActiveUniqueness();
        $this->ensureParentScopeUniqueKeys();
        $this->addTenantForeignKeys();
        $this->addCompositeForeignKeys();
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        throw new RuntimeException('Tier 1 key alignment and FK hardening is forward-only.');
    }

    private function alignColumnWidthsAndSentinels(): void
    {
        foreach (self::AI_TABLES as $table) {
            if ($this->columnType($table, 'tenant_id') === 'bigint unsigned') {
                $this->sql('ALTER TABLE {' . $table . '} MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL');
            }
        }

        $widthAlignments = [
            ['guidance_appointments', 'queue_ticket_id', 'BIGINT UNSIGNED NULL DEFAULT NULL'],
            ['guidance_mi_assignments', 'assigned_by_user_id', 'BIGINT UNSIGNED NULL DEFAULT NULL'],
            ['guidance_mi_submissions', 'attested_by_user_id', 'BIGINT UNSIGNED NULL DEFAULT NULL'],
            ['guidance_evaluation_tokens', 'provider_user_id', 'BIGINT UNSIGNED NOT NULL'],
            ['guidance_service_evaluations', 'provider_user_id', 'BIGINT UNSIGNED NOT NULL'],
            ['guidance_service_evaluations', 'moderated_by_user_id', 'BIGINT UNSIGNED NULL DEFAULT NULL'],
        ];

        foreach ($widthAlignments as [$table, $column, $definition]) {
            if ($this->columnType($table, $column) === 'int unsigned') {
                $this->sql('ALTER TABLE {' . $table . '} MODIFY COLUMN ' . $this->db->protectIdentifiers($column) . ' ' . $definition);
            }
        }

        // Replace archive_records.archived_by_user_id sentinel 0 with NULL so FK to users is enforceable.
        $archCol = $this->columnMeta('archive_records', 'archived_by_user_id');
        if ($archCol !== null && strtoupper((string) $archCol['IS_NULLABLE']) === 'NO') {
            $this->sql('ALTER TABLE {archive_records} MODIFY COLUMN archived_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL');
        }
        $this->sql('UPDATE {archive_records} SET archived_by_user_id = NULL WHERE archived_by_user_id = 0');
    }

    private function ensureArchiveActiveUniqueness(): void
    {
        if (! $this->columnExists('archive_records', 'active_entity_key')) {
            $this->sql(
                "ALTER TABLE {archive_records} ADD COLUMN active_entity_key VARCHAR(120)
                 GENERATED ALWAYS AS (CASE WHEN state = 'archived' THEN CONCAT(entity_type, ':', entity_id) ELSE NULL END) STORED AFTER entity_id"
            );
        }

        if (! $this->hasUniqueLeadingColumns('archive_records', ['tenant_id', 'active_entity_key'])) {
            $dup = $this->sql(
                "SELECT tenant_id, entity_type, entity_id
                 FROM {archive_records}
                 WHERE state = 'archived'
                 GROUP BY tenant_id, entity_type, entity_id
                 HAVING COUNT(*) > 1
                 LIMIT 1"
            )->getRowArray();

            if ($dup !== null) {
                throw new RuntimeException('Preflight STOP: archive_records contains duplicate active archived rows for the same entity.');
            }

            $this->sql('ALTER TABLE {archive_records} ADD UNIQUE KEY uq_archive_active_entity (tenant_id, active_entity_key)');
        }
    }

    private function ensureParentScopeUniqueKeys(): void
    {
        foreach (self::PARENT_TENANT_ID_TABLES as $table) {
            if (! $this->hasUniqueLeadingColumns($table, ['tenant_id', 'id'])) {
                $idxName = $this->constraintName('uq_' . $table . '_tenant_id');
                $this->sql('ALTER TABLE {' . $table . '} ADD UNIQUE KEY ' . $this->db->protectIdentifiers($idxName) . ' (tenant_id, id)');
            }
        }
    }

    private function addTenantForeignKeys(): void
    {
        foreach (self::TENANT_SCOPED_TABLES as $table) {
            if ($this->hasMatchingFk($table, ['tenant_id'], 'tenants', ['id'])) {
                continue;
            }

            $orphan = $this->sql(
                'SELECT 1 FROM {' . $table . '} c LEFT JOIN {tenants} t ON t.id = c.tenant_id WHERE t.id IS NULL LIMIT 1'
            )->getRowArray();
            if ($orphan !== null) {
                throw new RuntimeException('Preflight STOP: orphan tenant_id found in ' . $table . '.');
            }

            $fkName = $this->constraintName('fk_' . $table . '_tenant');
            $this->sql(
                'ALTER TABLE {' . $table . '} ADD CONSTRAINT ' . $this->db->protectIdentifiers($fkName)
                . ' FOREIGN KEY (tenant_id) REFERENCES {tenants}(id) ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }
    }

    private function addCompositeForeignKeys(): void
    {
        foreach (self::COMPOSITE_FKS as [$table, $cols, $parent, $parentCols, $baseFkName]) {
            if ($this->hasMatchingFk($table, $cols, $parent, $parentCols)) {
                continue;
            }

            $nullableCol = end($cols);
            $joinConds   = [];
            foreach ($cols as $i => $cCol) {
                $pCol        = $parentCols[$i];
                $joinConds[] = 'p.' . $this->db->protectIdentifiers($pCol) . ' = c.' . $this->db->protectIdentifiers($cCol);
            }

            $orphanSql = 'SELECT 1 FROM {' . $table . '} c LEFT JOIN {' . $parent . '} p ON ' . implode(' AND ', $joinConds)
                . ' WHERE c.' . $this->db->protectIdentifiers($nullableCol) . ' IS NOT NULL'
                . ' AND p.' . $this->db->protectIdentifiers($parentCols[0]) . ' IS NULL LIMIT 1';

            if ($this->sql($orphanSql)->getRowArray() !== null) {
                throw new RuntimeException(
                    'Preflight STOP: orphan (' . implode(', ', $cols) . ') in ' . $table . ' referencing ' . $parent . '.'
                );
            }

            // Use a collision-safe constraint name if base name already taken.
            $fkName = $this->constraintName($baseFkName);
            if ($this->fkNameExists($table, $fkName)) {
                $fkName = $this->constraintName($baseFkName . '_tier1');
            }

            $quotedCols       = array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $cols);
            $quotedParentCols = array_map(fn (string $c): string => $this->db->protectIdentifiers($c), $parentCols);

            $this->sql(
                'ALTER TABLE {' . $table . '} ADD CONSTRAINT ' . $this->db->protectIdentifiers($fkName)
                . ' FOREIGN KEY (' . implode(', ', $quotedCols) . ')'
                . ' REFERENCES {' . $parent . '}(' . implode(', ', $quotedParentCols) . ')'
                . ' ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->columnMeta($table, $column) !== null;
    }

    private function columnType(string $table, string $column): string
    {
        $meta = $this->columnMeta($table, $column);
        if ($meta === null) {
            return '';
        }

        return preg_replace('/\b(bigint|int|smallint|tinyint)\(\d+\)/', '$1', strtolower((string) $meta['COLUMN_TYPE']));
    }

    private function columnMeta(string $table, string $column): ?array
    {
        return $this->sql(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column]
        )->getRowArray();
    }

    /** @param list<string> $columns */
    private function hasUniqueLeadingColumns(string $table, array $columns): bool
    {
        $rows = $this->sql(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->prefixTable($table)]
        )->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['INDEX_NAME']][] = $row;
        }

        foreach ($grouped as $indexRows) {
            if ((int) $indexRows[0]['NON_UNIQUE'] !== 0) {
                continue;
            }
            if (array_slice(array_column($indexRows, 'COLUMN_NAME'), 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $cols
     * @param list<string> $parentCols
     */
    private function hasMatchingFk(string $table, array $cols, string $parent, array $parentCols): bool
    {
        $rows = $this->sql(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND REFERENCED_TABLE_NAME = ?
             ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            [$this->db->prefixTable($table), $this->db->prefixTable($parent)]
        )->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['CONSTRAINT_NAME']][] = $row;
        }

        foreach ($grouped as $fkRows) {
            if (array_column($fkRows, 'COLUMN_NAME') === $cols
                && array_column($fkRows, 'REFERENCED_COLUMN_NAME') === $parentCols) {
                return true;
            }
        }

        return false;
    }

    private function fkNameExists(string $table, string $fkName): bool
    {
        return $this->sql(
            'SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$this->db->prefixTable($table), $fkName]
        )->getRowArray() !== null;
    }

    private function constraintName(string $name): string
    {
        $prefix    = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $this->db->DBPrefix);
        $candidate = $prefix . $name;

        if (strlen($candidate) <= 64) {
            return $candidate;
        }

        $suffixLen = min(40, strlen($name));
        return substr($prefix, 0, 15) . '_' . substr(hash('sha256', $prefix . $name), 0, 8) . '_' . substr($name, -$suffixLen);
    }

    private function sql(string $sql, array $binds = [])
    {
        $sql = preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            fn (array $m): string => $this->db->protectIdentifiers($this->db->prefixTable($m[1])),
            $sql
        );

        $result = $this->db->query($sql, $binds);
        if ($result === false) {
            throw new RuntimeException('AlignKeyWidthsAndAddMissingForeignKeys query failed.');
        }

        return $result;
    }
}
