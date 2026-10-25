<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\AddTeamsNotifications;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

require_once APPPATH . 'Database/Migrations/2026-10-23-000002_AddTeamsNotifications.php';

/** Full migration chain and real InnoDB constraints, only in the guarded disposable schema. */
final class TeamsNotificationMigrationTest extends FreshDatabaseTestCase
{
    private static bool $migrated = false;
    private static array $legacyRows = [];
    private static array $legacyIndexes = [];
    private static array $legacyForeignKeys = [];
    private int $tenant;
    private int $recipient;
    private int $otherRecipient;
    private int $person;
    private int $event;
    private int $task;
    private int $comment;
    private const NOW = '2026-10-23 00:00:00';

    protected function setUp(): void
    {
        parent::setUp(); // Refuses unverified schemas before any connection or migration.
        $this->db = Database::connect('tests');
        config(\Config\Realtime::class)->enabled = false;
        if (!self::$migrated) {
            $runner = service('migrations');
            $target = APPPATH . 'Database/Migrations/2026-10-23-000002_AddTeamsNotifications.php';
            $paths = glob(APPPATH . 'Database/Migrations/*.php');
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                if (strcmp(basename($path), basename($target)) >= 0) break;
                $this->assertTrue($runner->force($path, 'App', 'tests'));
            }
            $this->fixtures();
            $this->legacyFixtures();
            self::$legacyRows = $this->db->table('notifications')->orderBy('id')->get()->getResultArray();
            self::$legacyIndexes = $this->indexes();
            self::$legacyForeignKeys = $this->foreignKeys();
            $this->assertTrue($runner->force($target, 'App', 'tests'));
            self::$migrated = true;
        }
        $this->fixtures();
    }

    private function insert(string $table, array $row): int
    {
        $this->assertTrue($this->db->table($table)->insert($row));
        return (int) $this->db->insertID();
    }

    private function fixtures(string $unit = 'clinic'): void
    {
        $nonce = bin2hex(random_bytes(8));
        $this->tenant = $this->insert('tenants', ['slug' => 'teams_' . $nonce, 'name' => 'Synthetic Teams tenant',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->person = $this->insert('people', ['tenant_id' => $this->tenant, 'kind' => 'employee',
            'mis_id' => $nonce, 'first_name' => 'Synthetic', 'last_name' => 'Member',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->recipient = $this->insert('users', ['tenant_id' => $this->tenant, 'person_id' => $this->person,
            'username' => 'member_' . $nonce, 'role' => 'clinic_staff', 'status' => 'active',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->otherRecipient = $this->insert('users', ['tenant_id' => $this->tenant,
            'username' => 'admin_' . $nonce, 'role' => 'superadmin', 'status' => 'active',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $base = ['tenant_id' => $this->tenant, 'unit' => $unit, 'title' => 'Synthetic operation', 'description' => '',
            'created_by_user_id' => $this->recipient, 'created_at' => self::NOW, 'updated_at' => self::NOW];
        $this->event = $this->insert('team_events', $base + ['location' => '', 'starts_at' => self::NOW,
            'ends_at' => '2026-10-23 01:00:00']);
        $this->task = $this->insert('team_tasks', $base + ['checklist' => '[]']);
        $this->comment = $this->insert('team_task_comments', ['tenant_id' => $this->tenant, 'unit' => $unit,
            'task_id' => $this->task, 'actor_user_id' => $this->recipient, 'body' => 'Synthetic comment', 'created_at' => self::NOW]);
    }

    private function legacyFixtures(): void
    {
        $appointment = $this->insert('appointments', ['tenant_id' => $this->tenant, 'unit' => 'clinic', 'person_id' => $this->person,
            'starts_at' => self::NOW, 'ends_at' => '2026-10-23 01:00:00', 'local_date' => '2026-10-23',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $referral = $this->insert('referrals', ['tenant_id' => $this->tenant, 'referral_code' => 'SYNTHETIC-TEAMS',
            'patient_person_id' => $this->person, 'source_module' => 'clinic', 'target_module' => 'guidance',
            'issuer_user_id' => $this->recipient, 'reason_code' => 'synthetic', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $announcement = $this->insert('announcements', ['tenant_id' => $this->tenant, 'unit' => 'clinic', 'title' => 'Synthetic',
            'body' => '', 'audience' => 'all', 'created_by' => $this->recipient, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $survey = $this->insert('surveys', ['tenant_id' => $this->tenant, 'title' => 'Synthetic', 'description' => '',
            'audience' => 'all', 'questions_json' => '[]', 'created_by' => $this->recipient,
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        foreach ([
            ['type' => 'appointment_confirmed', 'recipient_person_id' => $this->person, 'appointment_id' => $appointment, 'appointment_version' => 1],
            ['type' => 'referral_received', 'recipient_user_id' => $this->recipient, 'referral_id' => $referral],
            ['type' => 'announcement_published', 'recipient_person_id' => $this->person, 'announcement_id' => $announcement],
            ['type' => 'survey_published', 'recipient_person_id' => $this->person, 'survey_id' => $survey],
        ] as $row) {
            $this->insert('notifications', $row + ['tenant_id' => $this->tenant, 'created_at' => self::NOW, 'read_at' => self::NOW]);
        }
    }

    private function row(array $changes = []): array
    {
        return array_replace(['tenant_id' => $this->tenant, 'recipient_user_id' => $this->recipient,
            'type' => 'team_event_added', 'team_unit' => 'clinic', 'team_event_id' => $this->event,
            'team_task_id' => null, 'team_version' => 1, 'team_comment_id' => null, 'created_at' => self::NOW], $changes);
    }

    private function rejected(callable $write): void
    {
        try {
            $result = $write();
            $this->assertFalse($result, 'The database must reject the invalid write.');
        } catch (DatabaseException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    private function indexes(): array
    {
        return $this->db->query("SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SUB_PART,SEQ_IN_INDEX FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->getResultArray();
    }

    private function foreignKeys(): array
    {
        return $this->db->query("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
                r.DELETE_RULE,r.UPDATE_RULE,k.ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='notifications' ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->getResultArray();
    }

    public function testUpgradePreservesAllLegacyRowsReadStateIndexesAndForeignKeys(): void
    {
        foreach (self::$legacyRows as $before) {
            $after = $this->db->table('notifications')->where('id', $before['id'])->get()->getRowArray();
            $this->assertSame($before, array_intersect_key($after, $before));
            foreach (['team_unit', 'team_event_id', 'team_task_id', 'team_version', 'team_comment_id', 'team_delivery_key'] as $field) {
                $this->assertNull($after[$field]);
            }
            // Legacy delivery uniqueness must remain enforced after the extension.
            unset($before['id']);
            $this->rejected(fn () => $this->db->table('notifications')->insert($before));
        }
        $oldNames = array_unique(array_column(self::$legacyIndexes, 'INDEX_NAME'));
        $this->assertSame(self::$legacyIndexes, array_values(array_filter($this->indexes(), static fn ($row) => in_array($row['INDEX_NAME'], $oldNames, true))));
        $oldKeys = array_unique(array_column(self::$legacyForeignKeys, 'CONSTRAINT_NAME'));
        $this->assertSame(self::$legacyForeignKeys, array_values(array_filter($this->foreignKeys(), static fn ($row) => in_array($row['CONSTRAINT_NAME'], $oldKeys, true))));
    }

    public function testUpIsRestartableAndDownRefusesToDestroyHistory(): void
    {
        $this->insert('notifications', $this->row());
        $before = $this->db->table('notifications')->orderBy('id')->get()->getResultArray();
        $definition = $this->db->query('SHOW CREATE TABLE notifications')->getRowArray();
        (new AddTeamsNotifications())->up();
        (new AddTeamsNotifications())->up();
        $this->assertSame($definition, $this->db->query('SHOW CREATE TABLE notifications')->getRowArray());
        try {
            (new AddTeamsNotifications())->down();
            $this->fail('Rollback must not discard delivered notifications.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward-only', $e->getMessage());
        }
        $this->assertSame($before, $this->db->table('notifications')->orderBy('id')->get()->getResultArray());
    }

    public function testAllTeamsTypesAndUnitsAndNullableColumns(): void
    {
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            if ($unit !== 'clinic') $this->fixtures($unit);
            foreach (['team_event_added', 'team_event_removed', 'team_event_updated', 'team_event_cancelled',
                'team_task_assigned', 'team_task_unassigned', 'team_task_updated', 'team_task_status', 'team_task_commented'] as $version => $type) {
                $event = str_starts_with($type, 'team_event_');
                $this->insert('notifications', $this->row(['type' => $type, 'team_unit' => $unit, 'team_version' => $version + 1,
                    'team_event_id' => $event ? $this->event : null, 'team_task_id' => $event ? null : $this->task,
                    'team_comment_id' => $type === 'team_task_commented' ? $this->comment : null]));
            }
        }
        $columns = $this->db->query("SELECT COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='notifications' AND COLUMN_NAME LIKE 'team_%'")->getResultArray();
        $this->assertCount(6, $columns);
        foreach ($columns as $column) $this->assertSame('YES', $column['IS_NULLABLE']);
    }

    public function testNullAwareUniquenessEnforcesOneDeliveryPerRecipientAndAction(): void
    {
        $event = $this->row();
        $this->insert('notifications', $event);
        $this->rejected(fn () => $this->db->table('notifications')->insert($event));
        $this->rejected(fn () => $this->db->table('notifications')->insert(array_replace($event, ['type' => 'team_event_updated'])));
        $this->insert('notifications', array_replace($event, ['team_version' => 2]));
        $this->insert('notifications', array_replace($event, ['recipient_user_id' => $this->otherRecipient]));
        $task = $this->row(['type' => 'team_task_assigned', 'team_event_id' => null, 'team_task_id' => $this->task]);
        $this->insert('notifications', $task);
        $this->rejected(fn () => $this->db->table('notifications')->insert($task));
        $this->rejected(fn () => $this->db->table('notifications')->insert(array_replace($task, ['type' => 'team_task_status'])));
        $this->insert('notifications', array_replace($task, ['team_version' => 2]));
        $comment = array_replace($task, ['type' => 'team_task_commented', 'team_comment_id' => $this->comment]);
        $this->insert('notifications', $comment);
        $this->rejected(fn () => $this->db->table('notifications')->insert(array_replace($comment, ['team_version' => 99])));
        $otherComment = $this->insert('team_task_comments', ['tenant_id' => $this->tenant, 'unit' => 'clinic',
            'task_id' => $this->task, 'actor_user_id' => $this->recipient, 'body' => 'Second synthetic comment', 'created_at' => self::NOW]);
        $this->insert('notifications', array_replace($comment, ['team_comment_id' => $otherComment]));
    }

    public function testForeignKeysRejectCrossTenantCrossUnitAndWrongTaskComments(): void
    {
        $event = $this->event;
        $task = $this->task;
        $comment = $this->comment;
        $foreignUser = $this->recipient;
        $this->fixtures();
        foreach ([['team_event_id' => $event], ['recipient_user_id' => $foreignUser], ['team_unit' => 'guidance'],
            ['team_event_id' => null, 'team_task_id' => $task, 'type' => 'team_task_assigned'],
            ['team_event_id' => null, 'team_task_id' => $this->task, 'team_comment_id' => $comment, 'type' => 'team_task_commented']] as $changes) {
            $this->rejected(fn () => $this->db->table('notifications')->insert($this->row($changes)));
        }
        $otherTask = $this->insert('team_tasks', ['tenant_id' => $this->tenant, 'unit' => 'clinic', 'title' => 'Another task',
            'description' => '', 'checklist' => '[]', 'created_by_user_id' => $this->recipient, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->row(['type' => 'team_task_commented',
            'team_event_id' => null, 'team_task_id' => $otherTask, 'team_comment_id' => $this->comment])));
    }

    public function testShapeConstraintRejectsNullRequiredMixedAndLegacyTeamsFields(): void
    {
        foreach ([['team_unit' => null], ['team_unit' => 'bmg'], ['team_version' => null], ['team_version' => 0],
            ['recipient_user_id' => null], ['team_event_id' => null], ['team_comment_id' => $this->comment],
            ['team_task_id' => $this->task], ['recipient_person_id' => $this->person], ['appointment_version' => 1],
            ['type' => 'team_task_commented', 'team_event_id' => null, 'team_task_id' => $this->task],
            ['type' => 'announcement_published']] as $changes) {
            $this->rejected(fn () => $this->db->table('notifications')->insert($this->row($changes)));
        }
    }

    public function testReferencedHistoryCannotBeDeletedOrMoved(): void
    {
        $this->insert('notifications', $this->row());
        $this->insert('notifications', $this->row(['type' => 'team_task_commented', 'team_event_id' => null,
            'team_task_id' => $this->task, 'team_comment_id' => $this->comment]));
        $this->rejected(fn () => $this->db->table('team_events')->where('id', $this->event)->delete());
        $this->rejected(fn () => $this->db->table('team_events')->where('id', $this->event)->update(['unit' => 'guidance']));
        $this->rejected(fn () => $this->db->table('team_task_comments')->where('id', $this->comment)->delete());
        $this->rejected(fn () => $this->db->table('users')->where('id', $this->recipient)->delete());
    }

    public function testCurrentTeamsProducerMatchesTheMigratedContract(): void
    {
        $actor = ['id' => $this->otherRecipient, 'tenant_id' => $this->tenant];
        $record = ['id' => $this->event, 'tenant_id' => $this->tenant, 'unit' => 'clinic', 'version' => 1];
        $this->db->transBegin();
        try {
            $producer = new \App\Services\TeamsNotifications($this->db);
            $producer->enqueue($record, $actor, [$this->recipient => 'team_event_added']);
            $record['id'] = $this->task;
            $producer->enqueue($record, $actor, [$this->recipient => 'team_task_assigned']);
            $producer->enqueue($record, $actor, [$this->recipient => 'team_task_commented'], $this->comment);
            $producer->enqueue($record, $actor, [$this->recipient => 'team_task_commented'], $this->comment);
            $rows = $this->db->table('notifications')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
            $this->assertCount(3, $rows);
            $this->assertSame(['event:clinic:' . $this->event . ':1', 'task:clinic:' . $this->task . ':1',
                'comment:clinic:' . $this->task . ':' . $this->comment], array_column($rows, 'team_delivery_key'));
        } finally {
            $this->db->transRollback();
        }
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
    }

    private function dropShapeCheck(): void
    {
        $version = $this->db->query('SELECT VERSION() AS version')->getRow('version');
        $this->db->query('ALTER TABLE notifications DROP ' . (stripos($version, 'MariaDB') !== false ? 'CONSTRAINT' : 'CHECK')
            . ' notification_team_shape');
    }

    public function testPartialInstallRejectsDuplicateRowsBeforeDdlThenResumesWithoutDeletingHistory(): void
    {
        $this->insert('notifications', $this->row());
        $this->dropShapeCheck();
        $this->db->query('ALTER TABLE notifications DROP INDEX notification_team_delivery');
        $duplicate = $this->insert('notifications', $this->row());
        $before = $this->db->query('SHOW CREATE TABLE notifications')->getRowArray();
        try {
            try {
                (new AddTeamsNotifications())->up();
                $this->fail('A partial installation must not silently deduplicate delivery history.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Duplicate Teams notifications', $e->getMessage());
            }
            $this->assertSame($before, $this->db->query('SHOW CREATE TABLE notifications')->getRowArray());
            $this->assertSame(2, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults());
        } finally {
            // Explicit fixture repair only; the production migration never deletes notices.
            $this->db->table('notifications')->where('id', $duplicate)->delete();
            (new AddTeamsNotifications())->up();
        }
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->row()));
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->row(['team_unit' => null])));
    }

    public function testPartialInstallRejectsMalformedRowsBeforeDdl(): void
    {
        $this->dropShapeCheck();
        $malformed = $this->insert('notifications', $this->row(['team_unit' => null]));
        $before = $this->db->query('SHOW CREATE TABLE notifications')->getRowArray();
        try {
            try {
                (new AddTeamsNotifications())->up();
                $this->fail('A partial installation must reject invalid existing rows.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('preflight failed', $e->getMessage());
            }
            $this->assertSame($before, $this->db->query('SHOW CREATE TABLE notifications')->getRowArray());
            $this->assertNotNull($this->db->table('notifications')->where('id', $malformed)->get()->getRowArray());
        } finally {
            $this->db->table('notifications')->where('id', $malformed)->delete();
            (new AddTeamsNotifications())->up();
        }
    }

    public function testIncompatibleExistingIndexFailsWithoutRewritingRows(): void
    {
        $before = $this->db->table('notifications')->orderBy('id')->get()->getResultArray();
        $this->db->query('ALTER TABLE notifications DROP INDEX notification_team_delivery,
            ADD KEY notification_team_delivery (tenant_id,recipient_user_id,team_delivery_key)');
        try {
            try {
                (new AddTeamsNotifications())->up();
                $this->fail('Must reject a nonunique delivery index.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Incompatible Teams notification index', $e->getMessage());
            }
            $this->assertSame($before, $this->db->table('notifications')->orderBy('id')->get()->getResultArray());
        } finally {
            $this->db->query('ALTER TABLE notifications DROP INDEX notification_team_delivery,
                ADD UNIQUE KEY notification_team_delivery (tenant_id,recipient_user_id,team_delivery_key)');
        }
    }
}
