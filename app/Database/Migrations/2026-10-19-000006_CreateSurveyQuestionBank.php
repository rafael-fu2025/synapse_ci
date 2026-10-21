<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Additive library only: existing survey JSON and responses are not rewritten. */
final class CreateSurveyQuestionBank extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $tenant = ['type' => 'INT', 'unsigned' => true];
        $integer = ['type' => 'INT', 'unsigned' => true];
        $time = ['type' => 'DATETIME'];
        $attributes = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true], 'tenant_id' => $tenant,
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active'],
            'current_revision' => $integer + ['default' => 1], 'version' => $integer + ['default' => 1],
            'created_by' => $id, 'created_at' => $time, 'updated_at' => $time,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'id'], 'question_bank_scope');
        $this->forge->addKey(['tenant_id', 'status', 'id'], false, false, 'question_bank_listing');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('survey_question_bank', false, $attributes);

        $this->forge->addField([
            'tenant_id' => $tenant, 'question_id' => $id, 'revision' => $integer,
            'label' => ['type' => 'VARCHAR', 'constraint' => 500],
            'category' => ['type' => 'VARCHAR', 'constraint' => 80],
            'audience' => ['type' => 'VARCHAR', 'constraint' => 16],
            'review_note' => ['type' => 'TEXT'], 'question_json' => ['type' => 'TEXT'],
            'created_by' => $id, 'created_at' => $time,
        ]);
        $this->forge->addPrimaryKey(['tenant_id', 'question_id', 'revision']);
        $this->forge->addKey(['tenant_id', 'category', 'question_id'], false, false, 'question_bank_category');
        $this->forge->addForeignKey(['tenant_id', 'question_id'], 'survey_question_bank', ['tenant_id', 'id'], 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('survey_question_bank_revisions', false, $attributes);
    }

    public function down(): void
    {
        throw new RuntimeException('Question bank history is forward-only; archive entries instead of deleting revisions.');
    }
}
