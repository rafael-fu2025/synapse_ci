<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds staff-only interview attribution and encrypted remarks columns to survey_responses.
 */
final class AddSurveyResponseInterviewFields extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('survey_responses')) {
            return;
        }

        $table = $this->db->prefixTable('survey_responses');
        $columns = [
            'interviewed_by_user_id'    => 'BIGINT UNSIGNED NULL AFTER submitted_at',
            'interviewed_at'            => 'DATETIME NULL AFTER interviewed_by_user_id',
            'staff_remarks_cipher'      => 'MEDIUMBLOB NULL AFTER interviewed_at',
            'staff_remarks_nonce'       => 'BINARY(12) NULL AFTER staff_remarks_cipher',
            'staff_remarks_tag'         => 'BINARY(16) NULL AFTER staff_remarks_nonce',
            'staff_remarks_key_version' => 'SMALLINT UNSIGNED NULL AFTER staff_remarks_tag',
        ];

        foreach ($columns as $column => $definition) {
            if (!$this->db->fieldExists($column, 'survey_responses')) {
                $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        if (!$this->db->tableExists('survey_responses')) {
            return;
        }

        $table = $this->db->prefixTable('survey_responses');
        $columns = [
            'staff_remarks_key_version',
            'staff_remarks_tag',
            'staff_remarks_nonce',
            'staff_remarks_cipher',
            'interviewed_at',
            'interviewed_by_user_id',
        ];

        foreach ($columns as $column) {
            if ($this->db->fieldExists($column, 'survey_responses')) {
                $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }
    }
}

