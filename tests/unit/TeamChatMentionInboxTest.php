<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedUser;
use App\Services\NotificationService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/** Private, intentionally unconstrained fixtures test defensive reads, not the writer. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamChatMentionInboxTest extends CIUnitTestCase
{
    private BaseConnection $chatDb;
    private NotificationService $bell;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000];
        config(\Config\Realtime::class)->enabled = false;
        $this->chatDb = Database::connect();
        $this->assertSame('SQLite3', $this->chatDb->DBDriver);
        $this->assertSame(':memory:', $this->chatDb->database);
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
            'team_chat_messages' => 'id INTEGER PRIMARY KEY,tenant_id INTEGER,unit VARCHAR(16),body TEXT,mentions TEXT',
        ];
        foreach ($definitions as $table => $fields) $this->chatDb->query('CREATE TABLE ' . $table . ' (' . $fields . ')');
        $this->createNotifications();
        $this->chatDb->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        $this->chatDb->table('people')->insert(['id' => 3, 'tenant_id' => 1, 'first_name' => 'SECRET', 'last_name' => 'NAME']);
        $this->chatDb->table('users')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'person_id' => null, 'username' => 'admin', 'role' => 'superadmin', 'status' => 'active'],
            ['id' => 2, 'tenant_id' => 1, 'person_id' => null, 'username' => 'other', 'role' => 'superadmin', 'status' => 'active'],
            ['id' => 3, 'tenant_id' => 1, 'person_id' => 3, 'username' => 'member', 'role' => 'clinic_staff', 'status' => 'active'],
        ]);
        foreach (['clinic', 'guidance', 'facilities'] as $index => $unit) {
            $this->chatDb->table('team_chat_messages')->insert(['id' => $index + 1, 'tenant_id' => 1, 'unit' => $unit,
                'body' => 'PRIVATE MESSAGE @SECRET NAME', 'mentions' => '[{"user_id":1,"start":16,"length":12},{"user_id":3,"start":16,"length":12}]']);
        }
        $this->bell = new NotificationService();
        $this->login(1);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->chatDb->close();
        parent::tearDown();
    }

    private function createNotifications(bool $chat = true, bool $otherReferences = true): void
    {
        $this->chatDb->query('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,
            recipient_user_id INTEGER,recipient_person_id INTEGER,appointment_id INTEGER,appointment_version INTEGER,
            referral_id INTEGER,announcement_id INTEGER,survey_id INTEGER,type TEXT,read_at TEXT,created_at TEXT'
            . ($chat ? ',chat_unit VARCHAR(16),chat_message_id BIGINT' : '')
            . ($otherReferences ? ',workflow_event_id INTEGER,team_unit TEXT,team_event_id INTEGER,team_task_id INTEGER,team_version INTEGER,team_comment_id INTEGER' : '') . ')');
    }

    private function login(int $id): void
    {
        session()->set(['tenant_id' => 1, 'user' => ['id' => $id, 'tenant_id' => 1, 'logged_in' => true, 'role' => 'superadmin'],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function notice(array $changes = []): int
    {
        $this->assertTrue($this->chatDb->table('notifications')->insert(array_replace([
            'tenant_id' => 1, 'recipient_user_id' => 1, 'type' => 'team_chat_mentioned',
            'chat_unit' => 'clinic', 'chat_message_id' => 1, 'created_at' => '2026-10-26 00:00:00',
        ], $changes)));
        return (int) $this->chatDb->insertID();
    }

    private function cannotRead(int $id, int $user = 1, int $code = 404): void
    {
        try {
            $this->bell->markRead($id);
            $this->fail('Ineligible notification was readable.');
        } catch (RuntimeException $e) {
            $this->assertSame($code, $e->getCode());
        }
        $this->assertFalse($this->bell->canReceiveInvalidation(1, $user, [$id]));
        $this->assertNull($this->chatDb->table('notifications')->where('id', $id)->get()->getRowArray()['read_at']);
    }

    public function testAllUnitDestinationsAndGenericPresentationWithoutChatText(): void
    {
        service('request')->setGlobal('server', ['HTTP_HOST' => 'synapse.foundationu.com']);
        $origin = parse_url(site_url());
        foreach (['clinic', 'guidance', 'facilities'] as $index => $unit) {
            $id = $this->notice(['chat_unit' => $unit, 'chat_message_id' => $index + 1]);
            $item = $this->bell->ownInbox()['items'][0];
            $base = $origin['scheme'] . '://' . ($unit === 'facilities' ? 'bmg' : $unit) . '.foundationu.com'
                            . (isset($origin['port']) ? ':' . $origin['port'] : '')
                            . rtrim($origin['path'] ?? '', '/') . '/' . $unit . '/teams/chat';
            $this->assertSame($base, NotificationService::destinationUrls()[$unit . 'TeamChat']);
            $this->assertSame($base . '?message=' . ($index + 1) . '#chat-message-' . ($index + 1), $item['url']);
            $this->assertSame($id, $item['id']);
            $this->assertSame('Team chat mention', $item['title']);
            $this->assertSame('You were mentioned in your team chat. Open the conversation to view the message.', $item['message']);
            $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($item));
            $this->assertStringNotContainsString('PRIVATE', json_encode($item, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('SECRET', json_encode($item, JSON_THROW_ON_ERROR));
            $this->assertTrue($this->bell->canReceiveInvalidation(1, 1, [$id]));
        }
        $this->assertSame(3, $this->bell->ownPage()['total']);
    }

    public function testMalformedShapesAndOtherRecipientsNeverLeakOrBecomeRead(): void
    {
        $valid = $this->notice();
        $invalid = [];
        foreach ([
            ['recipient_user_id' => null], ['recipient_user_id' => 2], ['recipient_person_id' => 3], ['tenant_id' => 2],
            ['chat_unit' => null], ['chat_unit' => 'bmg'], ['chat_unit' => 'guidance'],
            ['chat_message_id' => null], ['chat_message_id' => 0], ['chat_message_id' => -1], ['chat_message_id' => 99],
            ['type' => 'team_chat_unknown'], ['appointment_id' => 1], ['appointment_version' => 1],
            ['referral_id' => 1], ['announcement_id' => 1], ['survey_id' => 1], ['workflow_event_id' => 1],
            ['team_unit' => 'clinic'], ['team_event_id' => 1], ['team_task_id' => 1], ['team_version' => 1], ['team_comment_id' => 1],
        ] as $changes) $invalid[] = $this->notice($changes);
        $this->assertSame([$valid], array_column($this->bell->ownInbox()['items'], 'id'));
        $this->assertSame(1, $this->bell->ownInbox()['unread_count']);
        $this->assertSame(1, $this->bell->ownPage(1, 'unread')['total']);
        $this->assertFalse($this->bell->canReceiveInvalidation(2, 1, [$valid]));
        $this->assertFalse($this->bell->canReceiveInvalidation(1, 2, [$valid]));
        $this->bell->markRead($valid);
        $this->bell->markRead($valid);
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $second = $this->notice();
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownPage(1, 'unread')['total']);
        $this->assertNotNull($this->chatDb->table('notifications')->where('id', $second)->get()->getRowArray()['read_at']);
        foreach ($invalid as $id) $this->cannotRead($id);
    }

    public function testPersonLinkedRecipientIsUserScopedAndCurrentDepartmentScoped(): void
    {
        $mine = $this->notice(['recipient_user_id' => 3]);
        $other = $this->notice();
        $guidance = $this->notice(['recipient_user_id' => 3, 'chat_unit' => 'guidance', 'chat_message_id' => 2]);
        $this->login(3);
        $this->assertSame([$mine], array_column($this->bell->ownInbox()['items'], 'id'));
        $this->assertTrue($this->bell->canReceiveInvalidation(1, 3, [$mine]));
        $this->cannotRead($other, 3);
        $this->cannotRead($guidance, 3);
        $this->chatDb->table('users')->where('id', 3)->update(['role' => 'counsellor']);
        $this->assertSame([$guidance], array_column($this->bell->ownInbox()['items'], 'id'));
        $this->cannotRead($mine, 3);
        $this->bell->markAllRead();
        $this->assertNull($this->chatDb->table('notifications')->where('id', $mine)->get()->getRowArray()['read_at']);
    }

    public static function departmentMembers(): array
    {
        return [
            ['clinic_admin', 'clinic', 1], ['clinic_staff', 'clinic', 1],
            ['guidance_admin', 'guidance', 2], ['guidance_supervisor', 'guidance', 2], ['counsellor', 'guidance', 2],
            ['bmg_admin', 'facilities', 3], ['facilities_op', 'facilities', 3],
        ];
    }

    #[DataProvider('departmentMembers')]
    public function testCurrentDepartmentRolesReceiveOnlyTheirOwnUnit(string $role, string $unit, int $message): void
    {
        $this->chatDb->table('users')->where('id', 3)->update(['role' => $role]);
        $ids = [];
        foreach (['clinic', 'guidance', 'facilities'] as $index => $candidate) {
            $ids[$candidate] = $this->notice(['recipient_user_id' => 3, 'chat_unit' => $candidate, 'chat_message_id' => $index + 1]);
        }
        $this->login(3);
        $this->assertSame([$ids[$unit]], array_column($this->bell->ownInbox()['items'], 'id'));
        $this->assertStringEndsWith('?message=' . $message . '#chat-message-' . $message, $this->bell->ownInbox()['items'][0]['url']);
        $this->bell->markAllRead();
        foreach ($ids as $candidate => $id) {
            $this->assertSame($candidate === $unit, $this->bell->canReceiveInvalidation(1, 3, [$id]));
            $read = $this->chatDb->table('notifications')->where('id', $id)->get()->getRowArray()['read_at'];
            $this->assertSame($candidate === $unit, $read !== null);
        }
    }

    public static function revokedIdentities(): array
    {
        return [
            'out of department' => ['users', ['role' => 'facilities_op'], false],
            'disabled user' => ['users', ['status' => 'inactive'], true],
            'deleted user' => ['users', ['deleted_at' => '2026-10-26'], true],
            'changed tenant' => ['users', ['tenant_id' => 2], true],
            'lost person link' => ['users', ['person_id' => null], true],
            'missing person link' => ['users', ['person_id' => 999], true],
            'deleted person' => ['people', ['deleted_at' => '2026-10-26'], true],
            'cross tenant person' => ['people', ['tenant_id' => 2], true],
            'disabled tenant' => ['tenants', ['is_active' => 0], true],
            'deleted tenant' => ['tenants', ['deleted_at' => '2026-10-26'], true],
        ];
    }

    #[DataProvider('revokedIdentities')]
    public function testRevokedIdentityLosesInboxReadAndRealtime(string $table, array $changes, bool $unauthenticated): void
    {
        $id = $this->notice(['recipient_user_id' => 3]);
        $this->login(3);
        $this->assertSame(1, $this->bell->ownInbox()['unread_count']);
        $this->chatDb->table($table)->where('id', $table === 'tenants' ? 1 : 3)->update($changes);
        if ($unauthenticated) {
            try { $this->bell->ownInbox(); $this->fail('Invalid identity remained authenticated.'); }
            catch (RuntimeException $e) { $this->assertSame(401, $e->getCode()); }
            $this->login(3);
        } else {
            $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
            $this->bell->markAllRead();
        }
        $this->cannotRead($id, 3, $unauthenticated ? 401 : 404);
    }

    public function testDeletedOrRescopedMessageLosesVisibilityAndReadAuthority(): void
    {
        $id = $this->notice();
        foreach ([['unit' => 'guidance'], ['unit' => 'clinic', 'tenant_id' => 2]] as $changes) {
            $this->chatDb->table('team_chat_messages')->where('id', 1)->update($changes);
            $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
            $this->cannotRead($id);
            $this->bell->markAllRead();
        }
        $this->chatDb->table('team_chat_messages')->where('id', 1)->delete();
        $this->assertSame(0, $this->bell->ownPage()['total']);
        $this->cannotRead($id);
    }

    public function testChatDoesNotDependOnTeamsWorkflowOrOptionalReferenceColumns(): void
    {
        $this->chatDb->query('DROP TABLE notifications');
        $this->createNotifications(true, false);
        $this->chatDb->resetDataCache();
        $id = $this->notice();
        $this->assertSame(1, $this->bell->ownInbox()['unread_count']);
        $this->assertTrue($this->bell->canReceiveInvalidation(1, 1, [$id]));
        $this->bell->markRead($id);
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
    }

    public function testOlderSchemaNeedsNoMigrationAndPreservesPersonalInbox(): void
    {
        $this->chatDb->query('DROP TABLE notifications');
        $this->createNotifications(false, false);
        $this->chatDb->query('DROP TABLE team_chat_messages');
        $this->chatDb->resetDataCache();
        $this->chatDb->table('notifications')->insert(['tenant_id' => 1, 'recipient_person_id' => 3,
            'appointment_id' => 1, 'appointment_version' => 1, 'type' => 'appointment_confirmed', 'created_at' => '2026-10-26 00:00:00']);
        $id = (int) $this->chatDb->insertID();
        $this->login(3);
        $this->assertSame('Appointment confirmed', $this->bell->ownInbox()['items'][0]['title']);
        $this->assertSame(1, $this->bell->ownPage()['total']);
        $this->bell->markRead($id);
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $this->login(1);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->bell->markAllRead();
        $this->assertFalse($this->chatDb->fieldExists('chat_unit', 'notifications'));
    }

    public function testPartialChatSchemaFailsClosedWithoutReferencingMissingColumns(): void
    {
        foreach (['chat_unit VARCHAR(16)', 'chat_message_id BIGINT'] as $column) {
            $this->chatDb->query('DROP TABLE notifications');
            $this->createNotifications(false, false);
            $this->chatDb->query('ALTER TABLE notifications ADD COLUMN ' . $column);
            $this->chatDb->resetDataCache();
            $this->chatDb->table('notifications')->insert(['tenant_id' => 1, 'recipient_user_id' => 1,
                'type' => 'team_chat_mentioned', 'created_at' => '2026-10-26 00:00:00']);
            $id = (int) $this->chatDb->insertID();
            $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
            $this->cannotRead($id);
            $this->bell->markAllRead();
        }
    }

    public function testOtherNotificationTypesCannotCarryChatReferences(): void
    {
        $this->login(3);
        $invalid = [];
        foreach ([['chat_unit' => 'clinic'], ['chat_message_id' => 1]] as $reference) {
            $this->chatDb->table('notifications')->insert($reference + ['tenant_id' => 1, 'recipient_person_id' => 3,
                'appointment_id' => 1, 'appointment_version' => 1, 'type' => 'appointment_confirmed', 'created_at' => '2026-10-26 00:00:00']);
            $invalid[] = (int) $this->chatDb->insertID();
        }
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->bell->markAllRead();
        foreach ($invalid as $id) $this->cannotRead($id, 3);
    }

    public function testMissingChatStorageFailsClosedWithoutSchemaChanges(): void
    {
        $id = $this->notice();
        $this->chatDb->query('DROP TABLE team_chat_messages');
        $this->chatDb->resetDataCache();
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->cannotRead($id);
        $this->bell->markAllRead();
        $this->assertFalse($this->chatDb->tableExists('team_chat_messages'));
    }

    public function testPrefixedQueriesShareTheSameReadPredicate(): void
    {
        foreach ($this->chatDb->listTables() as $table) $this->chatDb->query('ALTER TABLE ' . $table . ' RENAME TO chat_inbox_' . $table);
        $this->chatDb->setPrefix('chat_inbox_');
        $this->chatDb->resetDataCache();
        $id = $this->notice();
        $this->assertSame(1, $this->bell->ownInbox()['unread_count']);
        $this->assertTrue($this->bell->canReceiveInvalidation(1, 1, [$id]));
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
    }
}
