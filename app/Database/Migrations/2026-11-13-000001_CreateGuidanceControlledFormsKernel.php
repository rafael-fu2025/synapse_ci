<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGuidanceControlledFormsKernel extends Migration
{
    public function up(): void
    {
        // 1. Guidance Form Definitions (Controlled Form catalog)
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
            'internal_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'form_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'canonical_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'owner' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => false,
                'default'    => 'Office of the Guidance Center (OGC)',
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
        $this->forge->addUniqueKey(['tenant_id', 'internal_key'], 'uq_gform_def_key');
        $this->forge->createTable('guidance_form_definitions', true, ['ENGINE' => 'InnoDB']);

        // 2. Guidance Form Definition Revisions (Immutable versioned schemas)
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
            'form_definition_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'revision_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
            ],
            'schema_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0.0',
            ],
            'renderer_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0.0',
            ],
            'schema_json' => [
                'type' => 'LONGTEXT',
                'null' => false,
            ],
            'schema_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'effectivity_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'approval_state' => [
                'type'       => 'ENUM',
                'constraint' => ['provisional_gated', 'approved', 'deprecated'],
                'null'       => false,
                'default'    => 'provisional_gated',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'form_definition_id'], false, false, 'idx_gform_def_rev');
        $this->forge->addUniqueKey(['tenant_id', 'form_definition_id', 'revision_number'], 'uq_gform_def_rev_num');
        $this->forge->createTable('guidance_form_definition_revisions', true, ['ENGINE' => 'InnoDB']);

        // 3. Privacy Notices
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
            'notice_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0',
            ],
            'content' => [
                'type' => 'LONGTEXT',
                'null' => false,
            ],
            'content_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
            ],
            'effectivity_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'notice_code', 'version'], 'uq_gpriv_notice_ver');
        $this->forge->createTable('guidance_privacy_notices', true, ['ENGINE' => 'InnoDB']);

        // 4. Retention Policies
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
            'policy_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'duration_months' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 60,
            ],
            'legal_basis' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'purge_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
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
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'policy_code'], 'uq_gretention_policy_code');
        $this->forge->createTable('guidance_retention_policies', true, ['ENGINE' => 'InnoDB']);

        // 5. Consent Receipts
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
            'user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'privacy_notice_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'collection_basis' => [
                'type'       => 'ENUM',
                'constraint' => ['legal_obligation', 'vital_interest', 'legitimate_interest', 'explicit_consent'],
                'null'       => false,
            ],
            'consent_given' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
            ],
            'statement_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'recorded_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gconsent_person');
        $this->forge->createTable('guidance_consent_receipts', true, ['ENGINE' => 'InnoDB']);

        // 6. Guidance Form Instances
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
            'form_definition_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'definition_revision_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'case_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'session_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'referral_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'appointment_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'intake_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'instance_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'pending_cosign', 'finalized', 'superseded', 'void'],
                'null'       => false,
                'default'    => 'draft',
            ],
            'privacy_notice_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'retention_policy_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'retention_due_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'finalized_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'finalized_at' => [
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
        $this->forge->addUniqueKey(['tenant_id', 'instance_number'], 'uq_gform_inst_num');
        $this->forge->addKey(['tenant_id', 'person_id'], false, false, 'idx_gform_inst_person');
        $this->forge->addKey(['tenant_id', 'case_id'], false, false, 'idx_gform_inst_case');
        $this->forge->addKey(['tenant_id', 'status'], false, false, 'idx_gform_inst_status');
        $this->forge->createTable('guidance_form_instances', true, ['ENGINE' => 'InnoDB']);

        // 7. Guidance Form Drafts (One mutable draft per instance)
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
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'draft_cipher' => [
                'type' => 'LONGBLOB',
                'null' => false,
            ],
            'draft_nonce' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'draft_tag' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'draft_key_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'draft_schema_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0.0',
            ],
            'updated_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
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
        $this->forge->addUniqueKey(['tenant_id', 'form_instance_id'], 'uq_gform_inst_draft');
        $this->forge->createTable('guidance_form_drafts', true, ['ENGINE' => 'InnoDB']);

        // 8. Guidance Form Revisions (Immutable finalized snapshots)
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
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'revision_number' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'revision_type' => [
                'type'       => 'ENUM',
                'constraint' => ['initial', 'addendum', 'correction', 'superseding'],
                'null'       => false,
                'default'    => 'initial',
            ],
            'supersedes_revision_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'snapshot_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'snapshot_cipher' => [
                'type' => 'LONGBLOB',
                'null' => false,
            ],
            'snapshot_nonce' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'snapshot_tag' => [
                'type'       => 'VARBINARY',
                'constraint' => 16,
                'null'       => false,
            ],
            'snapshot_key_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'schema_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0.0',
            ],
            'definition_revision_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'created_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'form_instance_id', 'revision_number'], 'uq_gform_inst_rev');
        $this->forge->addKey(['tenant_id', 'snapshot_hash'], false, false, 'idx_gform_rev_hash');
        $this->forge->createTable('guidance_form_revisions', true, ['ENGINE' => 'InnoDB']);

        // 9. Guidance Form Attestations (Cryptographically bound digital sign-off)
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
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'form_revision_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'signer_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'signer_person_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'signer_role' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'signer_auth_version' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ],
            'credential_snapshot' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'attestation_statement_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'snapshot_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'attested_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'attestation_method' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'default'    => 'account_attestation',
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'user_agent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'form_instance_id'], false, false, 'idx_gform_attest_inst');
        $this->forge->addKey(['tenant_id', 'form_revision_id'], false, false, 'idx_gform_attest_rev');
        $this->forge->createTable('guidance_form_attestations', true, ['ENGINE' => 'InnoDB']);

        // 10. Guidance Document Jobs (Asynchronous PDF compilation tracking)
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
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'form_revision_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'job_type' => [
                'type'       => 'ENUM',
                'constraint' => ['draft_preview', 'final_issuance'],
                'null'       => false,
                'default'    => 'draft_preview',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['queued', 'processing', 'completed', 'failed'],
                'null'       => false,
                'default'    => 'queued',
            ],
            'idempotency_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'pdf_sha256' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'renderer_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0.0',
            ],
            'artifact_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'attempts' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
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
        $this->forge->addUniqueKey(['tenant_id', 'idempotency_key'], 'uq_gdoc_job_idemp');
        $this->forge->addKey(['tenant_id', 'status'], false, false, 'idx_gdoc_job_status');
        $this->forge->createTable('guidance_document_jobs', true, ['ENGINE' => 'InnoDB']);

        // 11. Guidance Document Issuances (Immutable issued outputs and verification registry)
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
            'form_instance_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'form_revision_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'verification_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
            ],
            'pdf_sha256' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'renderer_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => '1.0.0',
            ],
            'issued_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'issued_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'superseded', 'void'],
                'null'       => false,
                'default'    => 'active',
            ],
            'void_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'voided_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'voided_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'verification_code'], 'uq_gdoc_issuance_vercode');
        $this->forge->addKey(['tenant_id', 'form_instance_id'], false, false, 'idx_gdoc_issuance_inst');
        $this->forge->createTable('guidance_document_issuances', true, ['ENGINE' => 'InnoDB']);

        // 12. Guidance Legal Holds
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
            'entity_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'entity_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'reason' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'placed_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'placed_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'lifted_by_user_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'lifted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'entity_type', 'entity_id'], false, false, 'idx_glegal_hold_entity');
        $this->forge->createTable('guidance_legal_holds', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('guidance_legal_holds', true);
        $this->forge->dropTable('guidance_document_issuances', true);
        $this->forge->dropTable('guidance_document_jobs', true);
        $this->forge->dropTable('guidance_form_attestations', true);
        $this->forge->dropTable('guidance_form_revisions', true);
        $this->forge->dropTable('guidance_form_drafts', true);
        $this->forge->dropTable('guidance_form_instances', true);
        $this->forge->dropTable('guidance_consent_receipts', true);
        $this->forge->dropTable('guidance_retention_policies', true);
        $this->forge->dropTable('guidance_privacy_notices', true);
        $this->forge->dropTable('guidance_form_definition_revisions', true);
        $this->forge->dropTable('guidance_form_definitions', true);
    }
}

