<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\AddTeamChatMentionNotifications;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

require_once APPPATH . 'Database/Migrations/2026-10-26-000002_AddTeamChatMentionNotifications.php';

/** Never connects/migrates except through the verified disposable MySQL harness. */
final class TeamChatMentionMigrationTest extends FreshDatabaseTestCase
{
    private function insert(string $table, array $row): int
    {
        $this->assertTrue($this->db->table($table)->insert($row));
        return (int) $this->db->insertID();
    }

    private function rejected(array $row): void
    {
        try {
            $this->assertFalse($this->db->table('notifications')->insert($row), 'Invalid notice must be rejected by storage.');
        } catch (DatabaseException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testRestartableUpgradePreservesTypesChecksReadStateAndScopedConstraints(): void
    {
        // Inherited setUp guards before any connection or migration.
        $this->db = Database::connect('tests');
        $runner = service('migrations');
        $target = APPPATH . 'Database/Migrations/2026-10-26-000002_AddTeamChatMentionNotifications.php';
        $paths = glob(APPPATH . 'Database/Migrations/*.php');
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (strcmp(basename($path), basename($target)) >= 0) break;
            $this->assertTrue($runner->force($path, 'App', 'tests'));
        }
        $type = $this->db->query("SHOW COLUMNS FROM notifications LIKE 'type'")->getRowArray()['Type'];
        $checks = $this->db->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY CONSTRAINT_NAME")->getResultArray();
        $now = '2026-10-26 00:00:00';
        $tenant = $this->insert('tenants', ['slug' => 'chat_mentions', 'name' => 'Synthetic chat tenant', 'created_at' => $now, 'updated_at' => $now]);
        $otherTenant = $this->insert('tenants', ['slug' => 'other_chat', 'name' => 'Other synthetic tenant', 'created_at' => $now, 'updated_at' => $now]);
        $user = $this->insert('users', ['tenant_id' => $tenant, 'username' => 'chat_admin', 'role' => 'superadmin', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $otherUser = $this->insert('users', ['tenant_id' => $otherTenant, 'username' => 'other_chat_admin', 'role' => 'superadmin', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $message = $this->insert('team_chat_messages', ['tenant_id' => $tenant, 'unit' => 'clinic', 'sender_user_id' => $user,
            'client_nonce' => '01234567-89ab-4cde-8fab-0123456789ab', 'body' => 'PRIVATE synthetic message', 'created_at' => $now]);
        $event = $this->insert('team_events', ['tenant_id' => $tenant, 'unit' => 'clinic', 'title' => 'Synthetic', 'description' => '',
            'location' => '', 'starts_at' => $now, 'ends_at' => '2026-10-26 01:00:00', 'created_by_user_id' => $user, 'created_at' => $now, 'updated_at' => $now]);
        $legacy = $this->insert('notifications', ['tenant_id' => $tenant, 'recipient_user_id' => $user,
            'type' => 'team_event_added', 'team_unit' => 'clinic', 'team_event_id' => $event, 'team_version' => 1,
            'read_at' => $now, 'created_at' => $now]);
        $before = $this->db->table('notifications')->where('id', $legacy)->get()->getRowArray();
        // Simulate a deployment stopped after its first additive DDL statement.
        $this->db->query('ALTER TABLE notifications ADD COLUMN chat_unit VARCHAR(16) NULL DEFAULT NULL');
        $migration = new AddTeamChatMentionNotifications();
        $migration->up();
        $migration->up();
        $after = $this->db->table('notifications')->where('id', $legacy)->get()->getRowArray();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertNull($after['chat_unit']);
        $this->assertNull($after['chat_message_id']);
        $this->assertSame(substr($type, 0, -1) . ",'team_chat_mentioned')",
            $this->db->query("SHOW COLUMNS FROM notifications LIKE 'type'")->getRowArray()['Type']);
        $newChecks = $this->db->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY CONSTRAINT_NAME")->getResultArray();
        foreach ($checks as $check) $this->assertContains($check, $newChecks, 'Existing Teams/workflow constraints must remain untouched.');
        $row = ['tenant_id' => $tenant, 'recipient_user_id' => $user, 'type' => 'team_chat_mentioned',
            'chat_unit' => 'clinic', 'chat_message_id' => $message, 'created_at' => $now];
        $id = $this->insert('notifications', $row);
        $this->rejected($row);
        $this->db->table('notifications')->where('id', $id)->delete();
        foreach (['recipient_person_id', 'appointment_id', 'appointment_version', 'referral_id', 'announcement_id',
            'survey_id', 'workflow_event_id', 'team_event_id', 'team_task_id', 'team_version', 'team_comment_id'] as $column) {
            $this->rejected($row + [$column => 1]);
        }
        foreach ([['team_unit' => 'clinic'], ['chat_unit' => null], ['chat_unit' => 'bmg'], ['chat_unit' => 'guidance'],
            ['chat_message_id' => null], ['chat_message_id' => 0], ['chat_message_id' => $message + 10000],
            ['recipient_user_id' => null], ['recipient_user_id' => 0], ['recipient_user_id' => $otherUser],
            ['tenant_id' => $otherTenant, 'recipient_user_id' => $otherUser], ['type' => 'workflow_alert'], ['type' => 'team_event_added']] as $changes) {
            $this->rejected(array_replace($row, $changes));
        }
        // An old valid type must reject either chat field, not just the mentioned type.
        unset($before['id'], $before['team_delivery_key']);
        $before['team_version'] = 2;
        $this->rejected($before + ['chat_unit' => 'clinic']);
        $this->rejected($before + ['chat_message_id' => $message]);
        $id = $this->insert('notifications', $row);
        $migration->up();
        $this->assertSame($id, (int) $this->db->table('notifications')->where('type', 'team_chat_mentioned')->get()->getRowArray()['id']);
        try {
            $migration->down();
            $this->fail('The migration must be forward-only.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward-only', $e->getMessage());
        }
    }
}
