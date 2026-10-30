<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Additive AI storage only. No operational foreign keys or permission grants. */
final class CreateAiInsights extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true];
        $number = ['type' => 'BIGINT', 'unsigned' => true];
        $time = ['type' => 'DATETIME'];
        $nullableTime = $time + ['null' => true];
        $hash = ['type' => 'VARCHAR', 'constraint' => 64];
        $scope = ['id' => $id, 'tenant_id' => $number, 'unit' => ['type' => 'VARCHAR', 'constraint' => 32]];
        $attributes = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];

        // Durable disclosure tombstones: no counts, payloads, or expiry. Adjacent
        // snapshots must agree even after their encrypted payloads are erased.
        $this->forge->addField($scope + [
            'metric_code' => ['type' => 'VARCHAR', 'constraint' => 64],
            'month' => ['type' => 'VARCHAR', 'constraint' => 7],
            'fingerprint' => $hash, 'created_at' => $time,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'metric_code', 'month'], 'ai_monthly_release');
        $this->forge->createTable('ai_releases', true, $attributes);

        $this->forge->addField($scope + [
            'period' => ['type' => 'VARCHAR', 'constraint' => 7],
            'schema_version' => ['type' => 'VARCHAR', 'constraint' => 32],
            'disclosure_version' => ['type' => 'VARCHAR', 'constraint' => 32],
            'fingerprint' => $hash, 'payload' => ['type' => 'TEXT', 'null' => true],
            'created_at' => $time, 'expires_at' => $time,
        ]);
        $this->forge->addKey('id', true);
        // Deliberately stronger than version-scoped uniqueness: a new policy version
        // cannot silently release a corrected copy of the same month's aggregates.
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'period'], 'ai_snapshot_release');
        $this->forge->addKey(['tenant_id', 'unit', 'expires_at']);
        $this->forge->createTable('ai_snapshots', true, $attributes);

        $this->forge->addField($scope + [
            'snapshot_id' => $number, 'actor_id' => $number,
            'profile_revision' => $hash,
            'model' => ['type' => 'VARCHAR', 'constraint' => 191],
            'dedupe_key' => $hash, 'budget_key' => $hash,
            // Nullable unique key is the cross-unit tenant/profile worker mutex.
            'active_key' => $hash + ['null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'attempts' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'lease_token' => $hash + ['null' => true], 'lease_expires_at' => $nullableTime,
            'created_at' => $time, 'updated_at' => $time, 'expires_at' => $time,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('dedupe_key', 'ai_job_dedupe');
        $this->forge->addUniqueKey('budget_key', 'ai_job_budget');
        $this->forge->addUniqueKey('active_key', 'ai_job_active');
        $this->forge->addKey(['tenant_id', 'unit', 'profile_revision', 'status', 'id']);
        $this->forge->addKey(['tenant_id', 'unit', 'lease_expires_at']);
        $this->forge->createTable('ai_jobs', true, $attributes);

        // Outbound-day spend reservations survive errors, crashes and payload purge.
        $this->forge->addField($scope + [
            'job_id' => $number, 'day' => ['type' => 'DATE'],
            'slot' => ['type' => 'INT', 'unsigned' => true], 'reserved_at' => $time,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'day', 'slot'], 'ai_dispatch_day_slot');
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'job_id'], 'ai_dispatch_job');
        $this->forge->createTable('ai_dispatch_attempts', true, $attributes);

        $this->forge->addField($scope + [
            'job_id' => $number, 'snapshot_id' => $number,
            'payload' => ['type' => 'TEXT', 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'created_at' => $time, 'updated_at' => $time, 'expires_at' => $time,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'job_id'], 'ai_insight_job');
        $this->forge->addKey(['tenant_id', 'unit', 'id']);
        $this->forge->addKey(['tenant_id', 'unit', 'expires_at']);
        $this->forge->createTable('ai_insights', true, $attributes);

        $this->forge->addField($scope + [
            'insight_id' => $number, 'actor_id' => $number,
            'version' => ['type' => 'INT', 'unsigned' => true],
            'decision' => ['type' => 'VARCHAR', 'constraint' => 16],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 32],
            'created_at' => $time,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'insight_id', 'version'], 'ai_review_version');
        $this->forge->createTable('ai_reviews', true, $attributes);
    }

    public function down(): void
    {
        foreach (['ai_reviews', 'ai_insights', 'ai_dispatch_attempts', 'ai_jobs', 'ai_snapshots', 'ai_releases'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
