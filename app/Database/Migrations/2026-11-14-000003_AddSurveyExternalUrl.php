<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Adds nullable external_url to surveys so Guidance can distribute Google Forms and external survey links.
 */
final class AddSurveyExternalUrl extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('surveys')) {
            return;
        }

        if (!$this->db->fieldExists('external_url', 'surveys')) {
            $table = $this->db->prefixTable('surveys');
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `external_url` VARCHAR(2048) NULL AFTER `questions_json`");
            $this->db->resetDataCache();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Survey migrations are forward-only; survey definitions may never be dropped.');
    }
}

