<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\AuthenticatedUser;
use App\Services\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/** Intentionally unconstrained private fixtures exercise defensive reads of malformed rows. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamsNotificationInboxIsolationTest extends CIUnitTestCase
{
    private NotificationService $bell;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000];
        $this->db = Database::connect();
        $this->assertSame('SQLite3', $this->db->DBDriver);
        $this->assertSame(':memory:', $this->db->database);
        require_once ROOTPATH . 'tests/_support/TeamsNotificationFixture.php';
        \Tests\Support\TeamsNotificationFixture::install($this->db);
        $definitions = [
            'tenants' => 'id INTEGER PRIMARY KEY,is_active INTEGER,deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,first_name TEXT,last_name TEXT,email TEXT,mis_id TEXT,deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,person_id INTEGER,is_teaching INTEGER',
            'student_profiles' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,person_id INTEGER',
            'users' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,person_id INTEGER,username TEXT,email TEXT,role TEXT,status TEXT,auth_version INTEGER DEFAULT 1,deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY,code TEXT',
            'role_permissions' => 'role TEXT,permission_id INTEGER',
            'referrals' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,target_module TEXT,status TEXT,patient_person_id INTEGER',
            'announcements' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,unit TEXT,status TEXT,published_at TEXT,starts_at TEXT,ends_at TEXT',
            'surveys' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,status TEXT,published_at TEXT,starts_at TEXT,ends_at TEXT',
            'team_events' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,unit TEXT,version INTEGER',
            'team_tasks' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,unit TEXT,version INTEGER',
            'team_task_comments' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,unit TEXT,task_id INTEGER',
        ];
        foreach ($definitions as $table => $definition) $this->db->query('CREATE TABLE ' . $this->db->prefixTable($table) . ' (' . $definition . ')');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        $this->db->table('people')->insert(['id' => 3, 'tenant_id' => 1, 'first_name' => 'SECRET', 'last_name' => 'PRIVATE']);
        $this->db->table('users')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'person_id' => null, 'username' => 'admin', 'role' => 'superadmin', 'status' => 'active'],
            ['id' => 2, 'tenant_id' => 1, 'person_id' => null, 'username' => 'other_admin', 'role' => 'superadmin', 'status' => 'active'],
            ['id' => 3, 'tenant_id' => 1, 'person_id' => 3, 'username' => 'member', 'role' => 'clinic_staff', 'status' => 'active'],
        ]);
        $this->db->table('team_events')->insert(['id' => 1, 'tenant_id' => 1, 'unit' => 'clinic', 'version' => 2]);
        $this->db->table('team_tasks')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'unit' => 'clinic', 'version' => 2],
            ['id' => 2, 'tenant_id' => 1, 'unit' => 'clinic', 'version' => 2],
        ]);
        $this->db->table('team_task_comments')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'unit' => 'clinic', 'task_id' => 1],
            ['id' => 2, 'tenant_id' => 1, 'unit' => 'clinic', 'task_id' => 2],
            ['id' => 3, 'tenant_id' => 2, 'unit' => 'clinic', 'task_id' => 1],
        ]);
        $this->bell = new NotificationService();
        $this->login(1);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function login(int $id): void
    {
        session()->set(['tenant_id' => 1, 'user' => ['id' => $id, 'tenant_id' => 1, 'logged_in' => true, 'role' => 'superadmin'],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function notice(array $changes = []): int
    {
        $this->assertTrue($this->db->table('notifications')->insert(array_replace([
            'tenant_id' => 1, 'recipient_user_id' => 1, 'type' => 'team_event_added', 'team_unit' => 'clinic',
            'team_event_id' => 1, 'team_task_id' => null, 'team_comment_id' => null, 'team_version' => 1,
            'created_at' => '2026-10-23 00:00:00',
        ], $changes)));
        return (int) $this->db->insertID();
    }

    private function cannotRead(int $id): void
    {
        try { $this->bell->markRead($id); $this->fail('Ineligible notification must not be readable.'); }
        catch (RuntimeException $e) { $this->assertSame(404, $e->getCode()); }
        $this->assertFalse($this->bell->canReceiveInvalidation(1, 1, [$id]));
    }

    public function testMalformedShapesRecipientsScopesAndUnknownTypesNeverAppearOrBecomeRead(): void
    {
        $valid = $this->notice();
        $invalid = [];
        foreach ([
            ['recipient_user_id' => null], ['recipient_user_id' => 2], ['tenant_id' => 2],
            ['team_unit' => null], ['team_unit' => 'bmg'], ['team_unit' => 'guidance'],
            ['team_event_id' => null], ['team_event_id' => 42], ['team_version' => null], ['team_version' => 0], ['team_version' => 3],
            ['team_task_id' => 1], ['team_comment_id' => 1], ['recipient_person_id' => 3],
            ['appointment_id' => 1], ['appointment_version' => 1], ['referral_id' => 1], ['announcement_id' => 1], ['survey_id' => 1],
            ['type' => 'team_event_secret'],
            ['type' => 'team_task_assigned', 'team_event_id' => null, 'team_task_id' => 42],
            ['type' => 'team_task_assigned', 'team_event_id' => null, 'team_task_id' => 1, 'team_comment_id' => 1],
            ['type' => 'team_task_commented', 'team_event_id' => null, 'team_task_id' => 1],
            ['type' => 'team_task_commented', 'team_event_id' => null, 'team_task_id' => 1, 'team_comment_id' => 42],
            ['type' => 'team_task_commented', 'team_event_id' => null, 'team_task_id' => 1, 'team_comment_id' => 2],
            ['type' => 'team_task_commented', 'team_event_id' => null, 'team_task_id' => 1, 'team_comment_id' => 3],
        ] as $changes) $invalid[] = $this->notice($changes);
        $inbox = $this->bell->ownInbox();
        $this->assertSame([$valid], array_column($inbox['items'], 'id'));
        $this->assertSame(1, $inbox['unread_count']);
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        foreach ($invalid as $id) $this->cannotRead($id);
        $this->assertSame(count($invalid), $this->db->table('notifications')->whereIn('id', $invalid)->where('read_at', null)->countAllResults());
    }

    public function testDeletedOrRescopedRecordsHideHistoricalAlertsEvenFromSuperadmin(): void
    {
        $event = $this->notice();
        $task = $this->notice(['type' => 'team_task_updated', 'team_event_id' => null, 'team_task_id' => 1]);
        $comment = $this->notice(['type' => 'team_task_commented', 'team_event_id' => null, 'team_task_id' => 1, 'team_comment_id' => 1]);
        $this->assertSame(3, $this->bell->ownInbox()['unread_count']);
        $this->db->table('team_events')->where('id', 1)->delete();
        $this->db->table('team_tasks')->where('id', 1)->update(['unit' => 'guidance']);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        foreach ([$event, $task, $comment] as $id) $this->cannotRead($id);
    }

    public function testOlderNotificationSchemaRequiresNoTeamsTablesAndPreservesPersonalInbox(): void
    {
        $this->db->query('DROP TABLE notifications');
        $this->db->query('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,
            recipient_user_id INTEGER,recipient_person_id INTEGER,appointment_id INTEGER,appointment_version INTEGER,
            referral_id INTEGER,announcement_id INTEGER,survey_id INTEGER,type TEXT,read_at TEXT,created_at TEXT)');
        foreach (['team_task_comments', 'team_tasks', 'team_events'] as $table) $this->db->query('DROP TABLE ' . $this->db->prefixTable($table));
        $this->db->resetDataCache();
        $this->db->table('notifications')->insert(['tenant_id' => 1, 'recipient_person_id' => 3,
            'appointment_id' => 1, 'appointment_version' => 1, 'type' => 'appointment_confirmed', 'created_at' => '2026-10-23 00:00:00']);
        $id = (int) $this->db->insertID();
        $this->login(3);
        $inbox = $this->bell->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame('Appointment confirmed', $inbox['items'][0]['title']);
        $this->assertTrue($this->bell->canReceiveInvalidation(1, 3, [$id]));
        $this->bell->markRead($id);
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $this->login(1);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->cannotRead($id);
    }

    public function testPrefixedTeamsQueriesReadAndMarkWithoutAliasPrefixCorruption(): void
    {
        foreach ($this->db->listTables() as $table) {
            $this->db->query('ALTER TABLE ' . $table . ' RENAME TO inbox_teams_' . $table);
        }
        $this->db->setPrefix('inbox_teams_');
        $this->db->resetDataCache();
        $id = $this->notice();
        $this->assertSame(1, $this->bell->ownInbox()['unread_count']);
        $this->assertTrue($this->bell->canReceiveInvalidation(1, 1, [$id]));
        $this->bell->markRead($id);
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
    }

    public function testPresentTeamsColumnsButMissingTablesFailClosedWithoutBreakingPersonlessInbox(): void
    {
        $id = $this->notice();
        $this->db->query('DROP TABLE team_events');
        $this->db->resetDataCache();
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->cannotRead($id);
        $this->bell->markAllRead();
        $this->assertNull($this->db->table('notifications')->where('id', $id)->get()->getRowArray()['read_at']);
    }
}
