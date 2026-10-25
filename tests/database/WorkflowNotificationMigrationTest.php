<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\AddWorkflowNotifications;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

require_once APPPATH . 'Database/Migrations/2026-10-23-000003_AddWorkflowNotifications.php';

/** Real InnoDB DDL, only after the disposable-schema bootstrap verifies both connections. */
final class WorkflowNotificationMigrationTest extends FreshDatabaseTestCase
{
    private const NOW = '2026-10-23 00:00:00';
    private const TYPES = ['appointment_confirmed', 'referral_received', 'announcement_published', 'survey_published',
        'team_event_added', 'team_event_removed', 'team_event_updated', 'team_event_cancelled',
        'team_task_assigned', 'team_task_unassigned', 'team_task_updated', 'team_task_status', 'team_task_commented'];
    private static bool $migrated = false;
    private static array $legacyRows = [];
    private static array $legacyIndexes = [];
    private static array $legacyFks = [];
    private static string $teamCheck;
    private int $tenant;
    private int $person;
    private int $user;
    private int $otherUser;
    private int $event;

    protected function setUp(): void
    {
        parent::setUp(); // Skip before connecting or performing DDL outside the verified runner.
        $this->db = Database::connect('tests');
        if (!self::$migrated) {
            $runner = service('migrations');
            $target = APPPATH . 'Database/Migrations/2026-10-23-000003_AddWorkflowNotifications.php';
            $paths = glob(APPPATH . 'Database/Migrations/*.php');
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                if (strcmp(basename($path), basename($target)) >= 0) break;
                $this->assertTrue($runner->force($path, 'App', 'tests'));
            }
            $this->fixtures();
            $this->legacyFixtures();
            self::$legacyRows = $this->db->table('notifications')->orderBy('id')->get()->getResultArray();
            self::$legacyIndexes = $this->indexes('notifications');
            self::$legacyFks = $this->foreignKeys('notifications');
            self::$teamCheck = $this->checkClause('notification_team_shape');
            $this->assertTrue($runner->force($target, 'App', 'tests'));
            self::$migrated = true;
        }
        $this->fixtures();
        $this->event = $this->insert('workflow_events', $this->eventRow());
    }

    private function insert(string $table, array $row): int
    {
        $this->assertTrue($this->db->table($table)->insert($row));
        return (int) $this->db->insertID();
    }

    private function fixtures(): void
    {
        $nonce = bin2hex(random_bytes(8));
        $this->tenant = $this->insert('tenants', ['slug' => 'workflow_' . $nonce, 'name' => 'Synthetic Workflow tenant',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->person = $this->insert('people', ['tenant_id' => $this->tenant, 'kind' => 'employee', 'mis_id' => $nonce,
            'first_name' => 'Synthetic', 'last_name' => 'Workflow', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->user = $this->insert('users', ['tenant_id' => $this->tenant, 'person_id' => $this->person,
            'username' => 'workflow_' . $nonce, 'role' => 'clinic_staff', 'status' => 'active',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->otherUser = $this->insert('users', ['tenant_id' => $this->tenant, 'username' => 'other_' . $nonce,
            'role' => 'superadmin', 'status' => 'active', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
    }

    private function eventRow(array $changes = []): array
    {
        return array_replace(['tenant_id' => $this->tenant, 'unit' => 'clinic', 'event_key' => hash('sha256', random_bytes(16)),
            'event_kind' => 'appointment.cancelled', 'subject_kind' => 'appointment', 'subject_id' => 1,
            'actor_user_id' => $this->user, 'occurrence_key' => 'version:1', 'occurred_at' => self::NOW,
            'created_at' => self::NOW, 'available_at' => self::NOW], $changes);
    }

    private function notice(array $changes = []): array
    {
        return array_replace(['tenant_id' => $this->tenant, 'type' => 'workflow_alert', 'workflow_event_id' => $this->event,
            'recipient_user_id' => $this->user, 'created_at' => self::NOW, 'read_at' => null], $changes);
    }

    private function legacyFixtures(): void
    {
        $base = ['tenant_id' => $this->tenant, 'created_at' => self::NOW, 'updated_at' => self::NOW];
        $appointment = $this->insert('appointments', $base + ['unit' => 'clinic', 'person_id' => $this->person,
            'starts_at' => self::NOW, 'ends_at' => '2026-10-23 01:00:00', 'local_date' => '2026-10-23']);
        $referral = $this->insert('referrals', $base + ['referral_code' => 'SYNTHETIC-WORKFLOW', 'patient_person_id' => $this->person,
            'source_module' => 'clinic', 'target_module' => 'guidance', 'issuer_user_id' => $this->user, 'reason_code' => 'synthetic']);
        $announcement = $this->insert('announcements', $base + ['unit' => 'clinic', 'title' => 'Synthetic', 'body' => '',
            'audience' => 'all', 'created_by' => $this->user]);
        $survey = $this->insert('surveys', $base + ['title' => 'Synthetic', 'description' => '', 'audience' => 'all',
            'questions_json' => '[]', 'created_by' => $this->user]);
        $teamBase = $base + ['unit' => 'clinic', 'title' => 'Synthetic', 'description' => '', 'created_by_user_id' => $this->user];
        $event = $this->insert('team_events', $teamBase + ['location' => '', 'starts_at' => self::NOW, 'ends_at' => '2026-10-23 01:00:00']);
        $task = $this->insert('team_tasks', $teamBase + ['checklist' => '[]']);
        $comment = $this->insert('team_task_comments', ['tenant_id' => $this->tenant, 'unit' => 'clinic', 'task_id' => $task,
            'actor_user_id' => $this->user, 'body' => 'Synthetic', 'created_at' => self::NOW]);
        $rows = [
            ['type' => 'appointment_confirmed', 'recipient_person_id' => $this->person, 'appointment_id' => $appointment, 'appointment_version' => 1],
            ['type' => 'referral_received', 'recipient_user_id' => $this->user, 'referral_id' => $referral],
            ['type' => 'announcement_published', 'recipient_person_id' => $this->person, 'announcement_id' => $announcement],
            ['type' => 'survey_published', 'recipient_person_id' => $this->person, 'survey_id' => $survey],
        ];
        foreach (array_slice(self::TYPES, 4) as $version => $type) {
            $isEvent = str_starts_with($type, 'team_event_');
            $rows[] = ['type' => $type, 'recipient_user_id' => $this->user, 'team_unit' => 'clinic', 'team_version' => $version + 1,
                'team_event_id' => $isEvent ? $event : null, 'team_task_id' => $isEvent ? null : $task,
                'team_comment_id' => $type === 'team_task_commented' ? $comment : null];
        }
        foreach ($rows as $i => $row) {
            $this->insert('notifications', $row + ['tenant_id' => $this->tenant, 'created_at' => self::NOW,
                'read_at' => $i % 2 === 0 ? self::NOW : null]);
        }
    }

    private function rejected(callable $write): void
    {
        try {
            $this->assertFalse($write(), 'Database must reject the invalid write.');
        } catch (DatabaseException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    private function indexes(string $table): array
    {
        return $this->db->query('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SUB_PART,SEQ_IN_INDEX FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table])->getResultArray();
    }

    private function foreignKeys(string $table): array
    {
        return $this->db->query('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
                r.DELETE_RULE,r.UPDATE_RULE,k.ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table])->getResultArray();
    }

    private function checkClause(string $name): string
    {
        return (string) $this->db->query('SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?', [$name])->getRow('CHECK_CLAUSE');
    }

    private function definition(string $table): array
    {
        return $this->db->query('SHOW CREATE TABLE ' . $table)->getRowArray();
    }

    private function dropCheck(string $table, string $name): void
    {
        $version = $this->db->query('SELECT VERSION() AS version')->getRow('version');
        $this->db->query('ALTER TABLE ' . $table . ' DROP ' . (stripos($version, 'MariaDB') !== false ? 'CONSTRAINT ' : 'CHECK ') . $name);
    }

    private function preflightRejected(string $message = 'preflight failed'): void
    {
        $before = [$this->definition('workflow_events'), $this->definition('notifications')];
        $events = $this->db->table('workflow_events')->orderBy('id')->get()->getResultArray();
        $notices = $this->db->table('notifications')->orderBy('id')->get()->getResultArray();
        try {
            (new AddWorkflowNotifications())->up();
            $this->fail('Must reject incompatible partial installations before DDL.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame($before, [$this->definition('workflow_events'), $this->definition('notifications')]);
        $this->assertSame($events, $this->db->table('workflow_events')->orderBy('id')->get()->getResultArray());
        $this->assertSame($notices, $this->db->table('notifications')->orderBy('id')->get()->getResultArray());
    }

    public function testUpgradePreservesThirteenTypesRowsReadStateAndOldConstraints(): void
    {
        $this->assertCount(13, self::$legacyRows);
        foreach (self::$legacyRows as $before) {
            $after = $this->db->table('notifications')->where('id', $before['id'])->get()->getRowArray();
            $this->assertSame($before, array_intersect_key($after, $before));
            $this->assertNull($after['workflow_event_id']);
            $event = $this->insert('workflow_events', $this->eventRow(['tenant_id' => $before['tenant_id'], 'actor_user_id' => null]));
            $this->rejected(fn () => $this->db->table('notifications')->where('id', $before['id'])->update(['workflow_event_id' => $event]));
            unset($before['id']);
            $this->rejected(fn () => $this->db->table('notifications')->insert($before));
        }
        $oldIndexes = array_unique(array_column(self::$legacyIndexes, 'INDEX_NAME'));
        $this->assertSame(self::$legacyIndexes, array_values(array_filter($this->indexes('notifications'),
            static fn ($row) => in_array($row['INDEX_NAME'], $oldIndexes, true))));
        $oldFks = array_unique(array_column(self::$legacyFks, 'CONSTRAINT_NAME'));
        $this->assertSame(self::$legacyFks, array_values(array_filter($this->foreignKeys('notifications'),
            static fn ($row) => in_array($row['CONSTRAINT_NAME'], $oldFks, true))));
        $this->assertSame(self::$teamCheck, $this->checkClause('notification_team_shape'));
        $type = $this->db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='type'")->getRow('COLUMN_TYPE');
        $this->assertSame("enum('" . implode("','", [...self::TYPES, 'workflow_alert']) . "')", $type);
    }

    public function testExactEventColumnsIndexesForeignKeysAndDefaults(): void
    {
        $columns = $this->db->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_events' ORDER BY ORDINAL_POSITION")->getResultArray();
        $this->assertSame(['id', 'tenant_id', 'unit', 'event_key', 'event_kind', 'subject_kind', 'subject_id', 'actor_user_id',
            'occurrence_key', 'occurred_at', 'created_at', 'available_at', 'attempts', 'processed_at', 'last_error'], array_column($columns, 'COLUMN_NAME'));
        $byName = array_column($columns, null, 'COLUMN_NAME');
        foreach (['id' => 'bigint unsigned', 'tenant_id' => 'int unsigned', 'unit' => 'varchar(16)', 'event_key' => 'char(64)',
            'event_kind' => 'varchar(64)', 'subject_kind' => 'varchar(32)', 'subject_id' => 'bigint unsigned',
            'actor_user_id' => 'bigint unsigned', 'occurrence_key' => 'varchar(96)', 'occurred_at' => 'datetime',
            'created_at' => 'datetime', 'available_at' => 'datetime', 'attempts' => 'int unsigned', 'processed_at' => 'datetime', 'last_error' => 'varchar(64)'] as $name => $type) {
            $this->assertSame($type, preg_replace('/\b(bigint|int)\(\d+\)/', '$1', $byName[$name]['COLUMN_TYPE']));
            $this->assertSame(in_array($name, ['actor_user_id', 'processed_at', 'last_error'], true) ? 'YES' : 'NO', $byName[$name]['IS_NULLABLE']);
        }
        $this->assertSame('ascii', $byName['event_key']['CHARACTER_SET_NAME']);
        $this->assertSame('ascii_bin', $byName['event_key']['COLLATION_NAME']);
        $this->assertSame('auto_increment', $byName['id']['EXTRA']);
        $this->assertSame('0', $byName['attempts']['COLUMN_DEFAULT']);
        $event = $this->db->table('workflow_events')->where('id', $this->event)->get()->getRowArray();
        $this->assertSame('0', (string) $event['attempts']);
        $this->assertNull($event['processed_at']);
        $this->assertNull($event['last_error']);
        $this->assertSame(self::NOW, $event['occurred_at']);
        $indexes = $this->indexes('workflow_events');
        foreach (['PRIMARY' => ['id'], 'workflow_event_tenant' => ['tenant_id', 'id'],
            'workflow_event_identity' => ['tenant_id', 'event_key'], 'workflow_event_pending' => ['processed_at', 'available_at', 'id']] as $name => $fields) {
            $rows = array_values(array_filter($indexes, static fn ($row) => $row['INDEX_NAME'] === $name));
            $this->assertSame($fields, array_column($rows, 'COLUMN_NAME'));
            $this->assertSame($name === 'workflow_event_pending' ? '1' : '0', (string) $rows[0]['NON_UNIQUE']);
        }
        $this->assertCount(3, $this->foreignKeys('workflow_events'));
        foreach ($this->foreignKeys('workflow_events') as $fk) {
            $this->assertSame('RESTRICT', $fk['DELETE_RULE']);
            $this->assertSame('RESTRICT', $fk['UPDATE_RULE']);
        }
    }

    public function testAllUnitsNullableActorAndIdentifierOnlyFields(): void
    {
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            $this->insert('workflow_events', $this->eventRow(['unit' => $unit, 'actor_user_id' => null,
                'occurrence_key' => '2026-10-23T00:00:00.000Z_v1-' . hash('sha256', $unit), 'last_error' => 'SOURCE_UNAVAILABLE']));
        }
        foreach (['unit' => [null, '', 'bmg', 'CLINIC'], 'subject_id' => [null, 0],
            'occurrence_key' => [null, '', 'contains spaces', 'secret/token', "line\nbreak", 'José'],
            'last_error' => ['', 'free text error', 'lowercase', "FAILED\n", 'SECRET:TOKEN']] as $field => $values) {
            foreach ($values as $value) {
                $this->rejected(fn () => $this->db->table('workflow_events')->insert($this->eventRow([$field => $value])));
            }
        }
    }

    public function testEventIdentityIsTenantScopedAndBinary(): void
    {
        $key = str_repeat('a', 64);
        $this->insert('workflow_events', $this->eventRow(['event_key' => $key]));
        $this->rejected(fn () => $this->db->table('workflow_events')->insert($this->eventRow(['event_key' => $key])));
        $this->insert('workflow_events', $this->eventRow(['event_key' => strtoupper($key)]));
        $this->fixtures();
        $this->insert('workflow_events', $this->eventRow(['event_key' => $key]));
    }

    public function testExactlyOnePositiveRecipientAndNoMixedSourceReferences(): void
    {
        foreach ([['workflow_event_id' => null], ['workflow_event_id' => 0], ['recipient_user_id' => null],
            ['recipient_user_id' => 0], ['recipient_person_id' => $this->person],
            ['recipient_user_id' => null, 'recipient_person_id' => 0], ['type' => 'appointment_confirmed'],
            ['appointment_id' => 1], ['appointment_version' => 1], ['referral_id' => 1], ['announcement_id' => 1], ['survey_id' => 1],
            ['team_unit' => 'clinic'], ['team_event_id' => 1], ['team_task_id' => 1], ['team_version' => 1], ['team_comment_id' => 1]] as $changes) {
            $this->rejected(fn () => $this->db->table('notifications')->insert($this->notice($changes)));
        }
        $this->insert('notifications', $this->notice());
        $this->insert('notifications', $this->notice(['recipient_user_id' => null, 'recipient_person_id' => $this->person]));
        $this->assertStringContainsString('is true', strtolower($this->checkClause('notification_workflow_shape')));
    }

    public function testDeliveryDedupeForBothRecipientKindsRetainsReadState(): void
    {
        foreach ([$this->notice(['read_at' => self::NOW]),
            $this->notice(['recipient_user_id' => null, 'recipient_person_id' => $this->person, 'read_at' => self::NOW])] as $row) {
            $id = $this->insert('notifications', $row);
            $this->rejected(fn () => $this->db->table('notifications')->insert($row));
            $this->assertSame(self::NOW, $this->db->table('notifications')->where('id', $id)->get()->getRow('read_at'));
        }
        $this->insert('notifications', $this->notice(['recipient_user_id' => $this->otherUser]));
        $this->event = $this->insert('workflow_events', $this->eventRow());
        $this->insert('notifications', $this->notice());
        $this->insert('notifications', $this->notice(['recipient_user_id' => null, 'recipient_person_id' => $this->person]));
    }

    public function testTenantIsolationActorAndRecipientForeignKeys(): void
    {
        $foreignEvent = $this->event;
        $foreignUser = $this->user;
        $foreignPerson = $this->person;
        $this->fixtures();
        $this->rejected(fn () => $this->db->table('workflow_events')->insert($this->eventRow(['actor_user_id' => $foreignUser])));
        $this->rejected(fn () => $this->db->table('workflow_events')->insert($this->eventRow(['tenant_id' => 4294967295, 'actor_user_id' => null])));
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->notice(['workflow_event_id' => $foreignEvent])));
        $this->event = $this->insert('workflow_events', $this->eventRow());
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->notice(['recipient_user_id' => $foreignUser])));
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->notice(['recipient_user_id' => null, 'recipient_person_id' => $foreignPerson])));
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->notice(['workflow_event_id' => 1844674407])));
    }

    public function testReferencesRestrictDeletionAndMovingHistory(): void
    {
        $this->insert('notifications', $this->notice());
        $this->rejected(fn () => $this->db->table('workflow_events')->where('id', $this->event)->delete());
        $this->rejected(fn () => $this->db->table('workflow_events')->where('id', $this->event)->update(['id' => 1844674407]));
        $this->rejected(fn () => $this->db->table('users')->where('id', $this->user)->delete());
        $this->rejected(fn () => $this->db->table('users')->where('id', $this->user)->update(['id' => 1844674407]));
        $this->rejected(fn () => $this->db->table('tenants')->where('id', $this->tenant)->delete());
    }

    public function testEventAndDeliveryRollbackAtomically(): void
    {
        $key = hash('sha256', random_bytes(16));
        $this->db->transBegin();
        try {
            $this->event = $this->insert('workflow_events', $this->eventRow(['event_key' => $key]));
            $notice = $this->insert('notifications', $this->notice());
        } finally {
            $this->db->transRollback();
        }
        $this->assertNull($this->db->table('workflow_events')->where('event_key', $key)->get()->getRowArray());
        $this->assertNull($this->db->table('notifications')->where('id', $notice)->get()->getRowArray());
    }

    public function testRestartAndForwardOnlyRollbackPreserveHistoryAndDefinitions(): void
    {
        $this->insert('notifications', $this->notice(['read_at' => self::NOW]));
        $before = [$this->definition('workflow_events'), $this->definition('notifications')];
        $rows = $this->db->table('notifications')->orderBy('id')->get()->getResultArray();
        (new AddWorkflowNotifications())->up();
        (new AddWorkflowNotifications())->up();
        $this->assertSame($before, [$this->definition('workflow_events'), $this->definition('notifications')]);
        try {
            (new AddWorkflowNotifications())->down();
            $this->fail('Must not discard history.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward-only', $e->getMessage());
        }
        $this->assertSame($rows, $this->db->table('notifications')->orderBy('id')->get()->getResultArray());
    }

    public function testPartialMissingConstraintsResumeWithoutRewritingData(): void
    {
        $this->insert('notifications', $this->notice(['read_at' => self::NOW]));
        $rows = $this->db->table('notifications')->orderBy('id')->get()->getResultArray();
        $this->dropCheck('notifications', 'notification_workflow_shape');
        $this->dropCheck('workflow_events', 'workflow_event_unit');
        $this->db->query('ALTER TABLE notifications DROP FOREIGN KEY notification_workflow_event_fk,
            DROP INDEX notification_workflow_user_delivery, DROP INDEX notification_workflow_person_delivery');
        $this->db->query('ALTER TABLE workflow_events DROP FOREIGN KEY workflow_event_actor_fk, DROP INDEX workflow_event_pending');
        (new AddWorkflowNotifications())->up();
        $this->assertSame($rows, $this->db->table('notifications')->orderBy('id')->get()->getResultArray());
        $this->rejected(fn () => $this->db->table('notifications')->insert($this->notice()));
        $this->rejected(fn () => $this->db->table('workflow_events')->insert($this->eventRow(['unit' => 'bmg'])));
    }

    public function testPartialDuplicateDeliveriesRejectBeforeDdlAndResumeAfterFixtureRepair(): void
    {
        foreach (['user', 'person'] as $kind) {
            $row = $kind === 'user' ? $this->notice() : $this->notice(['recipient_user_id' => null, 'recipient_person_id' => $this->person]);
            $this->insert('notifications', $row);
            $name = 'notification_workflow_' . $kind . '_delivery';
            $this->db->query('ALTER TABLE notifications DROP INDEX ' . $name);
            $duplicate = $this->insert('notifications', $row);
            try {
                $this->preflightRejected();
            } finally {
                $this->db->table('notifications')->where('id', $duplicate)->delete();
                (new AddWorkflowNotifications())->up();
            }
        }
    }

    public function testPartialMalformedNotificationRejectsBeforeDdl(): void
    {
        $this->dropCheck('notifications', 'notification_workflow_shape');
        $id = $this->insert('notifications', $this->notice(['recipient_user_id' => null]));
        try {
            $this->preflightRejected();
        } finally {
            $this->db->table('notifications')->where('id', $id)->delete();
            (new AddWorkflowNotifications())->up();
        }
    }

    public function testPartialCrossTenantReferenceRejectsBeforeDdl(): void
    {
        $foreignEvent = $this->event;
        $this->fixtures();
        $this->db->query('ALTER TABLE notifications DROP FOREIGN KEY notification_workflow_event_fk');
        $id = $this->insert('notifications', $this->notice(['workflow_event_id' => $foreignEvent]));
        try {
            $this->preflightRejected();
        } finally {
            $this->db->table('notifications')->where('id', $id)->delete();
            (new AddWorkflowNotifications())->up();
        }
    }

    public function testPartialMalformedEventRejectsBeforeDdl(): void
    {
        $this->dropCheck('workflow_events', 'workflow_event_occurrence');
        $id = $this->insert('workflow_events', $this->eventRow(['occurrence_key' => 'confidential free text']));
        try {
            $this->preflightRejected();
        } finally {
            $this->db->table('workflow_events')->where('id', $id)->delete();
            (new AddWorkflowNotifications())->up();
        }
    }

    public function testPartialDuplicateEventIdentityRejectsBeforeDdl(): void
    {
        $key = hash('sha256', random_bytes(16));
        $this->insert('workflow_events', $this->eventRow(['event_key' => $key]));
        $this->db->query('ALTER TABLE workflow_events DROP INDEX workflow_event_identity');
        $id = $this->insert('workflow_events', $this->eventRow(['event_key' => $key]));
        try {
            $this->preflightRejected();
        } finally {
            $this->db->table('workflow_events')->where('id', $id)->delete();
            (new AddWorkflowNotifications())->up();
        }
    }

    public function testIncompatibleColumnAndIndexRejectWithoutDdl(): void
    {
        $this->db->query('ALTER TABLE workflow_events MODIFY attempts INT UNSIGNED NOT NULL DEFAULT 1');
        try {
            $this->preflightRejected('Incompatible workflow column');
        } finally {
            $this->db->query('ALTER TABLE workflow_events MODIFY attempts INT UNSIGNED NOT NULL DEFAULT 0');
        }
        $this->db->query('ALTER TABLE notifications DROP INDEX notification_workflow_person_delivery,
            ADD KEY notification_workflow_person_delivery (tenant_id,workflow_event_id,recipient_person_id)');
        try {
            $this->preflightRejected('Incompatible workflow index');
        } finally {
            $this->db->query('ALTER TABLE notifications DROP INDEX notification_workflow_person_delivery,
                ADD UNIQUE KEY notification_workflow_person_delivery (tenant_id,workflow_event_id,recipient_person_id)');
        }
    }

    public function testPartialOrphanActorRejectsBeforeDdl(): void
    {
        $foreignUser = $this->user;
        $this->fixtures();
        $this->db->query('ALTER TABLE workflow_events DROP FOREIGN KEY workflow_event_actor_fk');
        $id = $this->insert('workflow_events', $this->eventRow(['actor_user_id' => $foreignUser]));
        try {
            $this->preflightRejected();
        } finally {
            $this->db->table('workflow_events')->where('id', $id)->delete();
            (new AddWorkflowNotifications())->up();
        }
    }

    public function testPrefixedInstallAndInterruptedCreateResumeStayIsolated(): void
    {
        $prefix = 'wf_' . bin2hex(random_bytes(3)) . '_';
        $config = config('Database')->tests;
        $config['DBPrefix'] = $prefix;
        $db = Database::connect($config, false);
        $migration = new AddWorkflowNotifications(Database::forge($db));
        $notificationsBefore = $this->definition('notifications');
        try {
            $db->query('CREATE TABLE ' . $prefix . 'tenants (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
            $db->query('CREATE TABLE ' . $prefix . 'users (id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
                UNIQUE KEY notification_user_tenant (tenant_id,id)) ENGINE=InnoDB');
            $db->query('CREATE TABLE ' . $prefix . "notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
                recipient_user_id BIGINT UNSIGNED NULL, recipient_person_id BIGINT UNSIGNED NULL,
                appointment_id BIGINT UNSIGNED NULL, appointment_version INT UNSIGNED NULL,
                referral_id BIGINT UNSIGNED NULL, announcement_id BIGINT UNSIGNED NULL, survey_id BIGINT UNSIGNED NULL,
                team_unit VARCHAR(16) NULL, team_event_id BIGINT UNSIGNED NULL, team_task_id BIGINT UNSIGNED NULL,
                team_version INT UNSIGNED NULL, team_comment_id BIGINT UNSIGNED NULL,
                type ENUM('" . implode("','", self::TYPES) . "') NOT NULL,
                read_at DATETIME NULL, created_at DATETIME NOT NULL,
                CONSTRAINT " . $prefix . 'notification_team_shape CHECK (' . self::$teamCheck . ')
                ) ENGINE=InnoDB');
            // Wrong prerequisite enum must fail before even creating the outbox.
            $db->query('ALTER TABLE ' . $prefix . "notifications MODIFY type ENUM('appointment_confirmed') NOT NULL");
            try {
                $migration->up();
                $this->fail('An unrecognized enum must fail before DDL.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Incompatible workflow notification types', $e->getMessage());
            }
            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) AS n FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$prefix . 'workflow_events'])->getRow('n'));
            $db->query('ALTER TABLE ' . $prefix . "notifications MODIFY type ENUM('" . implode("','", self::TYPES) . "') NOT NULL");
            $migration->up();
            $db->table('tenants')->insert(['id' => 1]);
            $db->table('users')->insert(['tenant_id' => 1, 'id' => 1]);
            $this->assertTrue($db->table('workflow_events')->insert($this->eventRow(['tenant_id' => 1, 'actor_user_id' => 1])));
            $event = (int) $db->insertID();
            $this->assertTrue($db->table('notifications')->insert(['tenant_id' => 1, 'recipient_user_id' => 1,
                'type' => 'workflow_alert', 'workflow_event_id' => $event, 'created_at' => self::NOW, 'read_at' => self::NOW]));
            $rows = $db->table('notifications')->get()->getResultArray();
            $migration->up();
            $this->assertSame($rows, $db->table('notifications')->get()->getResultArray());
            // Rehearse interruption after CREATE TABLE but before the notification ALTER.
            $db->table('notifications')->where('tenant_id', 1)->delete();
            $version = $db->query('SELECT VERSION() AS version')->getRow('version');
            $db->query('ALTER TABLE ' . $prefix . 'notifications DROP FOREIGN KEY ' . $prefix . 'notification_workflow_event_fk');
            $db->query('ALTER TABLE ' . $prefix . 'notifications DROP '
                . (stripos($version, 'MariaDB') !== false ? 'CONSTRAINT ' : 'CHECK ') . $prefix . 'notification_workflow_shape');
            $db->query('ALTER TABLE ' . $prefix . 'notifications DROP INDEX notification_workflow_user_delivery,
                DROP INDEX notification_workflow_person_delivery, DROP COLUMN workflow_event_id,
                MODIFY type ENUM(\'' . implode("','", self::TYPES) . '\') NOT NULL');
            $migration->up();
            $this->assertSame($event, (int) $db->table('workflow_events')->get()->getRow('id'));
            $this->assertSame($notificationsBefore, $this->definition('notifications'));
        } finally {
            foreach (['notifications', 'workflow_events', 'users', 'tenants'] as $table) {
                $db->query('DROP TABLE IF EXISTS ' . $prefix . $table);
            }
            $db->close();
        }
    }

    public function testIncompatibleCheckAndForeignKeyRejectWithoutDdl(): void
    {
        $this->dropCheck('workflow_events', 'workflow_event_subject');
        $this->db->query('ALTER TABLE workflow_events ADD CONSTRAINT workflow_event_subject CHECK (subject_id>=0)');
        try {
            $this->preflightRejected('Incompatible workflow check');
        } finally {
            $this->dropCheck('workflow_events', 'workflow_event_subject');
            (new AddWorkflowNotifications())->up();
        }
        $this->db->query('ALTER TABLE workflow_events DROP FOREIGN KEY workflow_event_actor_fk');
        $this->db->query('ALTER TABLE workflow_events ADD CONSTRAINT workflow_event_actor_fk
            FOREIGN KEY (tenant_id,actor_user_id) REFERENCES users(tenant_id,id) ON DELETE CASCADE ON UPDATE RESTRICT');
        try {
            $this->preflightRejected('Incompatible workflow foreign key');
        } finally {
            $this->db->query('ALTER TABLE workflow_events DROP FOREIGN KEY workflow_event_actor_fk');
            (new AddWorkflowNotifications())->up();
        }
    }
}
