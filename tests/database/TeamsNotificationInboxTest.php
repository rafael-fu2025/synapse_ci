<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\AuthenticatedUser;
use App\Services\NotificationInvalidation;
use App\Services\NotificationService;
use App\Services\PortalContext;
use App\Services\TeamCalendarService;
use App\Services\TeamsNotifications;
use App\Services\TeamTaskService;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

/** Real producers/inbox and authoritative schema; only the verified disposable database. */
final class TeamsNotificationInboxTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    private int $tenant;
    private NotificationService $bell;
    private ?string $trigger = null;
    private const NOW = '2026-10-23 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        config(\Config\Realtime::class)->enabled = false;
        AuthenticatedUser::clear();
        $this->tenant = $this->newTenant();
        $this->bell = new NotificationService();
    }

    protected function tearDown(): void
    {
        if ($this->trigger !== null) $this->db->query('DROP TRIGGER IF EXISTS ' . $this->trigger);
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function insert(string $table, array $row): int
    {
        $this->assertTrue($this->db->table($table)->insert($row));
        return (int) $this->db->insertID();
    }

    private function newTenant(): int
    {
        return $this->insert('tenants', ['slug' => 'teams_inbox_' . bin2hex(random_bytes(8)), 'name' => 'Synthetic Teams inbox',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
    }

    private function account(string $role, bool $personless = false, ?int $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $nonce = bin2hex(random_bytes(8));
        $person = $personless ? null : $this->insert('people', ['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => $nonce, 'first_name' => 'SECRET', 'last_name' => 'PRIVATE', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $id = $this->insert('users', ['tenant_id' => $tenant, 'person_id' => $person, 'username' => 'teams_' . $nonce,
            'role' => $role, 'status' => 'active', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        return ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person, 'role' => $role];
    }

    private function crew(string $unit): array
    {
        [$manager, $member] = match ($unit) {
            'clinic' => ['clinic_admin', 'clinic_staff'],
            'guidance' => ['guidance_supervisor', 'counsellor'],
            'facilities' => ['bmg_admin', 'facilities_op'],
        };
        return [$this->account($manager), $this->account($member), $this->account($member)];
    }

    private function login(array $actor): void
    {
        session()->set(['tenant_id' => $actor['tenant_id'],
            'user' => ['id' => $actor['id'], 'tenant_id' => $actor['tenant_id'], 'logged_in' => true,
                'role' => 'superadmin', 'permissions' => ['*']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function eventInput(array $changes = []): array
    {
        return array_replace(['title' => 'SECRET event title', 'description' => 'SECRET event body', 'location' => 'SECRET room',
            'starts_at' => '2027-01-01T09:00', 'ends_at' => '2027-01-01T10:00', 'participant_ids' => []], $changes);
    }

    private function taskInput(array $task): array
    {
        return array_intersect_key($task, array_flip(['title', 'description', 'status', 'priority', 'assignee_user_id', 'due_date', 'version']));
    }

    private function notices(?int $recipient = null): array
    {
        $query = $this->db->table('notifications')->where('tenant_id', $this->tenant);
        if ($recipient !== null) $query->where('recipient_user_id', $recipient);
        return $query->orderBy('id')->get()->getResultArray();
    }

    private function rejected(int $code, callable $call): void
    {
        try { $call(); $this->fail('Expected notification operation rejection.'); }
        catch (RuntimeException $e) { $this->assertSame($code, $e->getCode(), $e->getMessage()); }
    }

    private function latest(string $title, string $destination, int $unread): array
    {
        $inbox = $this->bell->ownInbox();
        $this->assertSame($unread, $inbox['unread_count']);
        $this->assertSame($title, $inbox['items'][0]['title']);
        $this->assertSame(NotificationService::destinationUrls()[$destination], $inbox['items'][0]['url']);
        $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($inbox['items'][0]));
        $this->assertStringNotContainsString('SECRET', json_encode($inbox));
        $this->assertStringNotContainsString('PRIVATE', json_encode($inbox));
        return $inbox;
    }

    public static function units(): array
    {
        return [['clinic'], ['guidance'], ['facilities']];
    }

    #[DataProvider('units')]
    public function testPaginatedInboxFiltersClampsAndPreservesOwnership(string $unit): void
    {
        [$owner, $member, $spectator] = $this->crew($unit);
        $foreign = $this->account($member['role'], false, $this->newTenant());
        $this->login($owner);
        $calendar = new TeamCalendarService();
        for ($i = 0; $i < 25; $i++) {
            $calendar->create($unit, $this->eventInput(['participant_ids' => [$member['id']]]));
        }
        $this->login($member);
        $first = $this->bell->ownPage();
        $second = $this->bell->ownPage(2);
        $this->assertSame(25, $first['total']);
        $this->assertSame(25, $first['unread_count']);
        $this->assertSame(20, $first['per_page']);
        $this->assertSame(2, $first['pages']);
        $this->assertCount(20, $first['items']);
        $this->assertCount(5, $second['items']);
        $ids = array_column([...$first['items'], ...$second['items']], 'id');
        $expected = array_reverse(array_map('intval', array_column($this->notices($member['id']), 'id')));
        $this->assertSame($expected, $ids);
        $this->assertSame(array_slice($first['items'], 0, 10), $this->bell->ownInbox()['items']);
        $this->assertSame($second, $this->bell->ownPage(1000000));
        $this->assertSame(25, $this->db->table('notifications')->where('recipient_user_id', $member['id'])->where('read_at', null)->countAllResults());
        $this->assertStringNotContainsString('SECRET', json_encode($first));
        $this->bell->markRead($ids[0]);
        $unread = $this->bell->ownPage(1, 'unread');
        $this->assertSame(24, $unread['total']);
        $this->assertSame(24, $unread['unread_count']);
        $this->assertNotContains($ids[0], array_column($unread['items'], 'id'));
        $this->assertNotNull($this->bell->ownPage()['items'][0]['read_at']);
        foreach ([$spectator, $foreign] as $other) {
            $this->login($other);
            $empty = $this->bell->ownPage(99, 'unread');
            $this->assertSame([], $empty['items']);
            $this->assertSame(0, $empty['total']);
            $this->assertSame(1, $empty['page']);
            $this->assertSame(1, $empty['pages']);
            $this->bell->markAllRead();
        }
        $this->login($member);
        $this->assertSame(24, $this->bell->ownPage()['unread_count']);
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownPage(2, 'unread')['total']);
        $this->assertSame(25, $this->bell->ownPage()['total']);
        $this->db->table('users')->where('id', $member['id'])->update(['role' => 'student']);
        $this->assertSame(0, $this->bell->ownPage()['total']);
    }

    public function testHistoryControllerValidatesQueryAndReturnsPrivateJson(): void
    {
        [, $member] = $this->crew('clinic');
        $this->login($member);
        foreach ([[], ['page' => '2', 'filter' => 'unread'], ['page' => '0'], ['page' => '-1'],
            ['page' => '1000001'], ['page' => '1.5'], ['page' => ['1']], ['filter' => ['all']],
            ['filter' => 'read'], ['page' => '1 OR 1=1']] as $index => $query) {
            $request = clone service('request');
            $request->setMethod('GET');
            $request->setGlobal('get', $query);
            $response = new \CodeIgniter\HTTP\Response(config('App'));
            $controller = new \App\Controllers\NotificationController();
            $controller->initController($request, $response, service('logger'));
            $result = $controller->history();
            $this->assertSame($index < 2 ? 200 : 422, $result->getStatusCode());
            $this->assertStringContainsString('private', $result->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('no-store', $result->getHeaderLine('Cache-Control'));
            $body = json_decode($result->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if ($index < 2) {
                $this->assertSame(0, $body['total']);
                $this->assertSame(1, $body['page']);
                $this->assertSame(20, $body['per_page']);
            } else {
                $this->assertSame(['message' => 'Invalid notification page or filter.'], $body);
            }
        }
    }

    public function testPaginatedInboxRejectsInvalidServiceArguments(): void
    {
        foreach ([[0, 'all'], [-1, 'all'], [1000001, 'all'], [1, 'read'], [1, 'ALL']] as [$page, $filter]) {
            try {
                $this->bell->ownPage($page, $filter);
                $this->fail('Invalid pagination must be rejected before querying.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    #[DataProvider('units')]
    public function testEventCreationActualInboxReadOwnershipAndPersonlessDelivery(string $unit): void
    {
        [$owner, $member, $spectator] = $this->crew($unit);
        $admin = $this->account('superadmin', true);
        $this->login($owner);
        $event = (new TeamCalendarService())->create($unit, $this->eventInput(['participant_ids' => [$owner['id'], $member['id'], $admin['id']]]));
        $this->assertCount(2, $this->notices());
        $this->assertSame([], $this->notices($owner['id']));
        $this->login($member);
        $inbox = $this->latest('Team event invitation', $unit . 'TeamCalendar', 1);
        $id = $inbox['items'][0]['id'];
        $this->assertTrue($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$id]));
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $spectator['id'], [$id]));
        $this->bell->markRead($id);
        $read = $this->bell->ownInbox();
        $this->assertSame(0, $read['unread_count']);
        $this->assertNotNull($read['items'][0]['read_at']);
        $this->bell->markRead($id);
        $this->assertSame($read, $this->bell->ownInbox());
        $this->login($spectator);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->rejected(404, fn () => $this->bell->markRead($id));
        $this->login($admin);
        $adminInbox = $this->latest('Team event invitation', $unit . 'TeamCalendar', 1);
        $this->assertTrue($this->bell->canReceiveInvalidation($this->tenant, $admin['id'], [$adminInbox['items'][0]['id']]));
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $this->login($owner);
        $same = (new TeamCalendarService())->update($unit, $event['id'], $this->eventInput([
            'version' => 1, 'participant_ids' => [$admin['id'], $member['id'], $owner['id']],
        ]));
        $this->assertSame(1, $same['version']);
        $this->assertCount(2, $this->notices());
    }

    #[DataProvider('units')]
    public function testEventRemovalMaterialUpdateAndCancellationRemainAuthorized(string $unit): void
    {
        [$owner, $member, $replacement] = $this->crew($unit);
        $calendar = new TeamCalendarService();
        $this->login($owner);
        $event = $calendar->create($unit, $this->eventInput(['participant_ids' => [$member['id']]]));
        $event = $calendar->update($unit, $event['id'], $this->eventInput(['version' => 1, 'participant_ids' => [$replacement['id']]]));
        $this->assertSame(0, $this->db->table('team_event_participants')->where('event_id', $event['id'])->where('user_id', $member['id'])->countAllResults());
        $this->login($member);
        $inbox = $this->latest('Team event participation removed', $unit . 'TeamCalendar', 2);
        $this->assertTrue($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$inbox['items'][0]['id']]));
        $this->bell->markAllRead();
        $this->login($owner);
        $event = $calendar->update($unit, $event['id'], $this->eventInput(['version' => $event['version'],
            'title' => 'SECRET revised title', 'location' => 'SECRET changed room', 'participant_ids' => [$replacement['id']]]));
        $this->login($replacement);
        $this->latest('Team event updated', $unit . 'TeamCalendar', 2);
        $this->login($owner);
        $event = $calendar->cancel($unit, $event['id'], ['version' => $event['version']]);
        $calendar->cancel($unit, $event['id'], ['version' => $event['version']]);
        $this->login($replacement);
        $this->latest('Team event cancelled', $unit . 'TeamCalendar', 3);
        $this->login($member);
        $this->latest('Team event participation removed', $unit . 'TeamCalendar', 0);
    }

    #[DataProvider('units')]
    public function testTaskAssignmentReassignmentStatusUpdatesAndComments(string $unit): void
    {
        [$owner, $member, $replacement] = $this->crew($unit);
        $tasks = new TeamTaskService();
        $this->login($owner);
        $task = $tasks->create($unit, ['title' => 'SECRET task title', 'description' => 'SECRET description', 'assignee_user_id' => $member['id']]);
        $this->assertSame([], $this->notices($owner['id']));
        $this->login($member);
        $this->latest('Team task assigned', $unit . 'Teams', 1);
        $this->login($owner);
        $this->assertSame(1, $tasks->update($unit, $task['id'], $this->taskInput($task))['version']);
        $task = $tasks->update($unit, $task['id'], array_replace($this->taskInput($task), ['assignee_user_id' => $replacement['id']]));
        $this->login($member);
        $removed = $this->latest('Team task assignment removed', $unit . 'Teams', 2);
        $this->bell->markRead($removed['items'][0]['id']);
        $this->login($replacement);
        $this->latest('Team task assigned', $unit . 'Teams', 1);
        $task = $tasks->move($unit, $task['id'], ['version' => $task['version'], 'status' => 'done']);
        $this->login($owner);
        $this->latest('Team task status changed', $unit . 'Teams', 1);
        $tasks->comment($unit, $task['id'], ['body' => 'SECRET comment body']);
        $this->login($replacement);
        $this->latest('Team task comment', $unit . 'Teams', 2);
        $this->login($owner);
        $tasks->update($unit, $task['id'], array_replace($this->taskInput($task), ['due_date' => '2027-01-02']));
        $this->login($replacement);
        $this->latest('Team task updated', $unit . 'Teams', 3);
        $this->login($member);
        $this->latest('Team task assignment removed', $unit . 'Teams', 1);
    }

    #[DataProvider('units')]
    public function testRoleRevocationSuspensionAndTenantDeactivationRevokeInboxReadAndHints(string $unit): void
    {
        [$owner, $member] = $this->crew($unit);
        $this->login($owner);
        (new TeamCalendarService())->create($unit, $this->eventInput(['participant_ids' => [$member['id']]]));
        $id = (int) $this->notices($member['id'])[0]['id'];
        $this->db->table('users')->where('id', $member['id'])->update(['role' => $unit === 'clinic' ? 'counsellor' : 'clinic_staff']);
        $this->login($member);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$id]));
        $this->rejected(404, fn () => $this->bell->markRead($id));
        $this->bell->markAllRead();
        $this->assertNull($this->notices($member['id'])[0]['read_at']);
        $this->db->table('users')->where('id', $member['id'])->update(['role' => $member['role']]);
        $this->assertSame(1, $this->bell->ownInbox()['unread_count']);
        $this->db->table('users')->where('id', $member['id'])->update(['status' => 'suspended']);
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$id]));
        $this->rejected(401, fn () => $this->bell->ownInbox());
        $this->db->table('users')->where('id', $member['id'])->update(['status' => 'active']);
        $this->login($member);
        $this->db->table('tenants')->where('id', $this->tenant)->update(['is_active' => 0]);
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$id]));
        $this->rejected(401, fn () => $this->bell->markRead($id));
    }

    public function testDeletedPersonOrAccountCannotReadOrReceiveHints(): void
    {
        [$owner, $member] = $this->crew('clinic');
        $this->login($owner);
        (new TeamCalendarService())->create('clinic', $this->eventInput(['participant_ids' => [$member['id']]]));
        $id = (int) $this->notices($member['id'])[0]['id'];
        foreach ([['people', $member['person_id']], ['users', $member['id']]] as [$table, $identity]) {
            $this->db->table($table)->where('id', $identity)->update(['deleted_at' => self::NOW]);
            $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$id]));
            $this->login($member);
            $this->rejected(401, fn () => $this->bell->ownInbox());
            $this->db->table($table)->where('id', $identity)->update(['deleted_at' => null]);
        }
        $this->assertNull($this->notices($member['id'])[0]['read_at']);
    }

    public function testCrossTenantIdentityCannotReadMarkOrReceiveHints(): void
    {
        [$owner, $member] = $this->crew('clinic');
        $foreign = $this->account('clinic_staff', false, $this->newTenant());
        $this->login($owner);
        (new TeamCalendarService())->create('clinic', $this->eventInput(['participant_ids' => [$member['id']]]));
        $id = (int) $this->notices($member['id'])[0]['id'];
        $this->login($foreign);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
        $this->rejected(404, fn () => $this->bell->markRead($id));
        $this->bell->markAllRead();
        $this->assertNull($this->notices($member['id'])[0]['read_at']);
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $foreign['id'], [$id]));
        $this->assertFalse($this->bell->canReceiveInvalidation($foreign['tenant_id'], $member['id'], [$id]));
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $member['id'], []));
    }

    public function testPersonlessSuperadminReceivesOnlyAddressedTeamsNeverNullRecipientLegacyRows(): void
    {
        $admin = $this->account('superadmin', true);
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            [$owner] = $this->crew($unit);
            $this->login($owner);
            (new TeamCalendarService())->create($unit, $this->eventInput(['participant_ids' => [$admin['id']]]));
        }
        $legacy = [];
        foreach (['appointment_confirmed', 'referral_received', 'announcement_published', 'survey_published'] as $type) {
            // Legacy nullable polymorphic fields may contain malformed rows; null is never a recipient wildcard.
            $legacy[] = $this->insert('notifications', ['tenant_id' => $this->tenant, 'type' => $type, 'created_at' => self::NOW]);
        }
        $this->login($admin);
        $inbox = $this->bell->ownInbox();
        $this->assertSame(3, $inbox['unread_count']);
        $this->assertSame(['Team event invitation'], array_values(array_unique(array_column($inbox['items'], 'title'))));
        foreach ($legacy as $id) {
            $this->rejected(404, fn () => $this->bell->markRead($id));
            $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $admin['id'], [$id]));
        }
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $this->assertSame(4, $this->db->table('notifications')->whereIn('id', $legacy)->where('read_at', null)->countAllResults());
    }

    public function testRollbackDiscardsCalendarTaskAndNotificationWrites(): void
    {
        [$owner, $member] = $this->crew('clinic');
        $this->login($owner);
        $this->trigger = 'teams_inbox_fail_' . bin2hex(random_bytes(8));
        $this->db->query('CREATE TRIGGER ' . $this->trigger . ' BEFORE INSERT ON notifications FOR EACH ROW BEGIN IF NEW.tenant_id='
            . $this->tenant . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic fixture failure'; END IF; END");
        $this->rejected(503, fn () => (new TeamCalendarService())->create('clinic', $this->eventInput(['participant_ids' => [$member['id']]])));
        $this->rejected(503, fn () => (new TeamTaskService())->create('clinic', ['title' => 'Synthetic task', 'assignee_user_id' => $member['id']]));
        foreach (['team_events', 'team_event_participants', 'team_tasks', 'team_task_activity', 'notifications'] as $table) {
            $this->assertSame(0, $this->db->table($table)->where('tenant_id', $this->tenant)->countAllResults());
        }
        $this->login($member);
        $this->assertSame(['items' => [], 'unread_count' => 0], $this->bell->ownInbox());
    }

    public function testRealtimeUsesActualInboxAuthorityOnlyAfterCommitAndPublishesNoContent(): void
    {
        [$owner, $member, $spectator] = $this->crew('clinic');
        $admin = $this->account('superadmin', true);
        $this->login($owner);
        $event = (new TeamCalendarService())->create('clinic', $this->eventInput(['participant_ids' => [$member['id'], $admin['id']]]));
        $ids = array_map('intval', array_column($this->notices(), 'id'));
        $sent = [];
        $publisher = new NotificationInvalidation($this->db, null, null, function (...$hint) use (&$sent) {
            $this->assertSame(0, $this->db->transDepth);
            $sent[] = $hint;
            return true;
        }, true);
        $this->db->transBegin();
        $publisher->publishCommitted($this->tenant, $ids);
        $this->assertSame([], $sent);
        $this->db->transRollback();
        $publisher->publishCommitted($this->tenant, [...$ids, ...$ids]);
        $channels = array_column($sent, 0);
        sort($channels);
        $expected = ['private.tenant.' . $this->tenant . '.user.' . $member['id'], 'private.tenant.' . $this->tenant . '.user.' . $admin['id']];
        sort($expected);
        $this->assertSame($expected, $channels);
        foreach ($sent as $hint) $this->assertSame(['notifications.changed', []], array_slice($hint, 1));
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $spectator['id'], $ids));
        $this->db->transBegin();
        $record = ['id' => $event['id'], 'tenant_id' => $this->tenant, 'unit' => 'clinic', 'version' => 2];
        // A future version is not readable; its rollback must not authorize any later hint either.
        (new TeamsNotifications($this->db))->enqueue($record, $owner, [$member['id'] => 'team_event_updated']);
        $rolledBack = (int) $this->db->insertID();
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$rolledBack]));
        $this->db->transRollback();
        $this->assertFalse($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$rolledBack]));
    }

    public function testInboxLimitUnreadSaturationAndMarkAll(): void
    {
        [$owner, $member] = $this->crew('clinic');
        $this->login($owner);
        $event = (new TeamCalendarService())->create('clinic', $this->eventInput(['participant_ids' => [$member['id']]]));
        $this->db->table('team_events')->where('id', $event['id'])->update(['version' => 105]);
        for ($version = 2; $version <= 105; ++$version) {
            $this->insert('notifications', ['tenant_id' => $this->tenant, 'recipient_user_id' => $member['id'],
                'type' => 'team_event_updated', 'team_unit' => 'clinic', 'team_event_id' => $event['id'],
                'team_version' => $version, 'created_at' => self::NOW]);
        }
        $this->login($member);
        $inbox = $this->bell->ownInbox();
        $this->assertCount(10, $inbox['items']);
        $this->assertSame(100, $inbox['unread_count']);
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $this->assertSame(105, $this->db->table('notifications')->where('tenant_id', $this->tenant)->where('read_at IS NOT NULL', null, false)->countAllResults());
    }

    public function testPersonalReferralAndPublicationNotificationsStillCoexistWithTeams(): void
    {
        [$owner, $member] = $this->crew('clinic');
        $this->login($owner);
        (new TeamCalendarService())->create('clinic', $this->eventInput(['participant_ids' => [$member['id']]]));
        $appointment = $this->insert('appointments', ['tenant_id' => $this->tenant, 'unit' => 'clinic', 'person_id' => $member['person_id'],
            'starts_at' => self::NOW, 'ends_at' => '2026-10-23 01:00:00', 'local_date' => '2026-10-23',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $referral = $this->insert('referrals', ['tenant_id' => $this->tenant, 'referral_code' => 'TEAMS-MIXED',
            'patient_person_id' => $member['person_id'], 'source_module' => 'guidance', 'target_module' => 'clinic',
            'issuer_user_id' => $owner['id'], 'reason_code' => 'synthetic', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $permission = $this->db->table('permissions')->where('code', 'referrals.read')->get()->getRowArray();
        $permissionId = $permission['id'] ?? $this->insert('permissions', ['code' => 'referrals.read', 'name' => 'Synthetic read grant', 'module' => 'referrals']);
        if ($this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $permissionId)->countAllResults() === 0) {
            $this->insert('role_permissions', ['role' => 'clinic_staff', 'permission_id' => $permissionId]);
        }
        $this->insert('employee_profiles', ['tenant_id' => $this->tenant, 'person_id' => $member['person_id'],
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $announcement = $this->insert('announcements', ['tenant_id' => $this->tenant, 'unit' => 'clinic', 'title' => 'SECRET announcement',
            'body' => 'SECRET announcement body', 'audience' => 'employees', 'status' => 'published', 'published_at' => self::NOW,
            'created_by' => $owner['id'], 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $survey = $this->insert('surveys', ['tenant_id' => $this->tenant, 'title' => 'SECRET survey', 'description' => 'SECRET survey body',
            'audience' => 'employees', 'status' => 'published', 'published_at' => self::NOW, 'questions_json' => '[]',
            'created_by' => $owner['id'], 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        foreach ([
            ['type' => 'appointment_confirmed', 'recipient_person_id' => $member['person_id'], 'appointment_id' => $appointment, 'appointment_version' => 1],
            ['type' => 'referral_received', 'recipient_user_id' => $member['id'], 'referral_id' => $referral],
            ['type' => 'announcement_published', 'recipient_person_id' => $member['person_id'], 'announcement_id' => $announcement],
            ['type' => 'survey_published', 'recipient_person_id' => $member['person_id'], 'survey_id' => $survey],
        ] as $row) $this->insert('notifications', $row + ['tenant_id' => $this->tenant, 'created_at' => self::NOW]);
        $this->login($member);
        $inbox = $this->bell->ownInbox();
        $this->assertSame(5, $inbox['unread_count']);
        $this->assertSame(['New survey', 'New announcement', 'Incoming referral', 'Appointment confirmed', 'Team event invitation'], array_column($inbox['items'], 'title'));
        $this->assertStringNotContainsString('SECRET', json_encode($inbox));
        foreach ($inbox['items'] as $item) $this->assertTrue($this->bell->canReceiveInvalidation($this->tenant, $member['id'], [$item['id']]));
        $this->bell->markAllRead();
        $this->assertSame(0, $this->bell->ownInbox()['unread_count']);
        $this->assertSame(5, $this->db->table('notifications')->where('tenant_id', $this->tenant)->where('read_at IS NOT NULL', null, false)->countAllResults());
    }

    public function testExactDestinationKeysAndPortalAwareRoutes(): void
    {
        $destinations = NotificationService::destinationUrls();
        $this->assertSame(['portal', 'clinicReferrals', 'guidanceReferrals', 'clinicReferralHistory', 'guidanceReferralHistory', 'announcements', 'surveys',
            'clinicTeams', 'clinicTeamCalendar', 'guidanceTeams', 'guidanceTeamCalendar', 'facilitiesTeams', 'facilitiesTeamCalendar',
            'clinicAppointments', 'guidanceAppointments', 'clinicReferralSent', 'guidanceReferralSent', 'guidanceCases', 'clinicInventory', 'facilitiesOperations'], array_keys($destinations));
        foreach (['clinic', 'guidance'] as $unit) {
            $this->assertSame(PortalContext::urlFor($unit, $unit . '/referrals?tab=history'), $destinations[$unit . 'ReferralHistory']);
        }
        foreach (['clinic' => 'clinic', 'guidance' => 'guidance', 'facilities' => 'bmg'] as $unit => $portal) {
            $this->assertSame(PortalContext::urlFor($portal, $unit . '/teams'), $destinations[$unit . 'Teams']);
            $this->assertSame(PortalContext::urlFor($portal, $unit . '/teams/calendar'), $destinations[$unit . 'TeamCalendar']);
        }
    }
}
