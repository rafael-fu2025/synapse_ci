<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateTeamEvents;
use App\Database\Migrations\CreateTeamTasks;
use App\Services\AuthenticatedUser;
use App\Services\TeamCalendarService;
use App\Services\TeamTaskService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/** Private prefixed in-memory database, real auth refresh, only team migrations. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamCalendarServiceIntegrationTest extends CIUnitTestCase
{
    private TeamCalendarService $calendar;
    private TeamTaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'isolated_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        require_once ROOTPATH . 'tests/_support/TeamsNotificationFixture.php';
        \Tests\Support\TeamsNotificationFixture::install($this->db);
        $this->db->query('CREATE TABLE isolated_tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE isolated_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT,
            last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE isolated_employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE isolated_users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER,
            username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE isolated_permissions (id INTEGER PRIMARY KEY, code TEXT)');
        $this->db->query('CREATE TABLE isolated_role_permissions (role TEXT, permission_id INTEGER)');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        $roles = [1 => 'clinic_staff', 2 => 'clinic_staff', 3 => 'clinic_staff', 4 => 'clinic_admin',
            5 => 'guidance_supervisor', 6 => 'counsellor', 7 => 'bmg_admin', 8 => 'facilities_op',
            9 => 'superadmin', 10 => 'student', 11 => 'clinic_staff', 12 => 'clinic_staff'];
        foreach ($roles as $id => $role) {
            $tenant = $id === 11 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'first_name' => 'Person', 'last_name' => (string) $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'user' . $id, 'role' => $role, 'status' => $id === 12 ? 'suspended' : 'active']);
        }
        require_once APPPATH . 'Database/Migrations/2026-10-18-000002_CreateTeamTasks.php';
        require_once APPPATH . 'Database/Migrations/2026-10-19-000001_CreateTeamEvents.php';
        (new CreateTeamTasks())->up();
        (new CreateTeamEvents())->up();
        $this->calendar = new TeamCalendarService();
        $this->tasks = new TeamTaskService();
        $this->signIn(1);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function signIn(int $id): void
    {
        $tenant = $id === 11 ? 2 : 1;
        session()->set(['tenant_id' => $tenant, 'user' => ['id' => $id, 'tenant_id' => $tenant,
            'logged_in' => true, 'role' => 'superadmin', 'name' => 'Forged name'],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function fails(int $code, callable $call): void
    {
        try { $call(); $this->fail('Expected RuntimeException ' . $code); }
        catch (RuntimeException $e) { $this->assertSame($code, $e->getCode(), $e->getMessage()); }
    }

    private function input(array $changes = []): array
    {
        return $changes + ['title' => 'Planning', 'description' => '', 'location' => '',
            'starts_at' => '2026-10-01T00:00', 'ends_at' => '2026-10-01T01:00', 'participant_ids' => []];
    }

    private function create(array $changes = [], string $unit = 'clinic'): array
    {
        return $this->calendar->create($unit, $this->input($changes));
    }

    private function alerts(): array
    {
        return array_map(static fn (array $row): array => [(int) $row['recipient_user_id'], $row['type']],
            $this->db->table('notifications')->orderBy('id')->get()->getResultArray());
    }

    public function testNotificationMatrixAcrossAllUnits(): void
    {
        foreach (['clinic' => [1, 2, 3], 'guidance' => [5, 6, 9], 'facilities' => [7, 8, 9]] as $unit => [$owner, $first, $second]) {
            $this->signIn($owner);
            $event = $this->create(['participant_ids' => [$owner, $first]], $unit);
            $this->assertSame([[$first, 'team_event_added']], $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
            $updated = $this->calendar->update($unit, $event['id'], $this->input([
                'version' => $event['version'], 'participant_ids' => [$owner, $second], 'title' => 'Changed',
            ]));
            $expected = [[$first, 'team_event_removed'], [$second, 'team_event_added']];
            usort($expected, static fn ($a, $b) => $a[0] <=> $b[0]);
            $this->assertSame($expected, $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
            $this->calendar->cancel($unit, $event['id'], ['version' => $updated['version']]);
            $this->assertSame([[$second, 'team_event_cancelled']], $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
        }
    }

    public function testEventMaterialChangesAndUnchangedEdits(): void
    {
        $event = $this->create(['participant_ids' => [1, 2]]);
        $this->db->table('notifications')->where('id >', 0)->delete();
        $same = $this->calendar->update('clinic', $event['id'], $this->input(['version' => 1, 'participant_ids' => [2, 1]]));
        $this->assertSame(1, $same['version']);
        $this->assertSame([], $this->alerts());
        $changes = [];
        foreach (['title' => 'New title', 'location' => 'New room', 'starts_at' => '2026-10-01T00:10',
            'ends_at' => '2026-10-01T01:10'] as $field => $value) {
            $changes[$field] = $value;
            $event = $this->calendar->update('clinic', $event['id'], $this->input($changes + [
                'version' => $event['version'], 'participant_ids' => [1, 2],
            ]));
            $this->assertSame([[2, 'team_event_updated']], $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
        }
        $changes['description'] = 'Description only';
        $this->calendar->update('clinic', $event['id'], $this->input($changes + [
            'version' => $event['version'], 'participant_ids' => [1, 2],
        ]));
        $this->assertSame([], $this->alerts());
    }

    public function testManagerChangesNotifyOrganizerWithoutDuplicateParticipantAlerts(): void
    {
        $event = $this->create(['participant_ids' => [1, 2]]);
        $this->db->table('notifications')->where('id >', 0)->delete();
        $this->signIn(4);
        $event = $this->calendar->update('clinic', $event['id'], $this->input([
            'version' => 1, 'participant_ids' => [1, 2, 3],
        ]));
        $this->assertSame([[1, 'team_event_updated'], [3, 'team_event_added']], $this->alerts());
        $this->db->table('notifications')->where('id >', 0)->delete();
        $this->db->table('users')->where('id', 2)->update(['status' => 'suspended']);
        $this->calendar->cancel('clinic', $event['id'], ['version' => $event['version']]);
        $this->assertSame([[1, 'team_event_cancelled'], [3, 'team_event_cancelled']], $this->alerts());
    }

    public function testNotificationFailureRollsBackEventAndParticipants(): void
    {
        $this->db->query("CREATE TRIGGER fail_alert BEFORE INSERT ON isolated_notifications BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->fails(503, fn () => $this->create(['participant_ids' => [2]]));
        $this->assertSame(0, $this->db->table('team_events')->countAllResults());
        $this->assertSame(0, $this->db->table('team_event_participants')->countAllResults());
        $this->assertSame([], $this->alerts());
    }

    public function testExactContractContextDefaultsAndServerOwnedFields(): void
    {
        $this->assertSame($this->tasks->context('clinic'), $this->calendar->context('clinic'));
        $event = $this->calendar->create('clinic', ['title' => ' Plan ', 'starts_at' => '2026-10-01T00:00',
            'ends_at' => '2026-10-01T00:01', 'status' => 'cancelled', 'version' => 90, 'tenant_id' => 2,
            'unit' => 'facilities', 'created_by_user_id' => 9, 'patient_id' => 5]);
        $this->assertSame('Plan', $event['title']);
        $this->assertSame('', $event['description']);
        $this->assertSame('', $event['location']);
        $this->assertSame('2026-09-30 16:00:00', $event['starts_at']);
        $this->assertSame('2026-09-30 16:01:00', $event['ends_at']);
        $this->assertSame('scheduled', $event['status']);
        $this->assertSame(1, $event['version']);
        $this->assertSame(1, $event['tenant_id']);
        $this->assertSame(1, $event['created_by_user_id']);
        $this->assertSame('clinic', $event['unit']);
        $this->assertTrue($event['canEdit']);
        $this->assertSame([], $event['participant_ids']);
        $this->assertSame([], $event['participants']);
        $keys = ['id', 'tenant_id', 'unit', 'title', 'description', 'location', 'starts_at', 'ends_at',
            'created_by_user_id', 'status', 'version', 'created_at', 'updated_at', 'participants', 'participant_ids', 'canEdit'];
        $this->assertEqualsCanonicalizing($keys, array_keys($event));
        $this->assertSame($event, $this->calendar->detail('clinic', $event['id']));
        $list = $this->calendar->listing('clinic', ['date' => '2026-10-01', 'view' => 'agenda']);
        $this->assertEqualsCanonicalizing(['actor', 'members', 'canManage', 'events', 'tasks', 'totalEvents', 'totalTasks',
            'truncated', 'date', 'view', 'mine', 'startDate', 'endDate', 'prevDate', 'nextDate'], array_keys($list));
        $this->assertArrayNotHasKey('canEdit', $list['events'][0]);
        $this->assertSame(1, $list['totalEvents']);
        $this->assertSame(0, $list['totalTasks']);
        $this->assertFalse($list['truncated']);
        $this->assertSame('clinic_staff', $list['actor']['role']);
        $this->assertSame(0, $this->db->table('team_task_activity')->countAllResults());
    }

    public function testOverlapUsesExclusiveManilaMidnightAtMonthBoundary(): void
    {
        // August 2026 grid: July 27 00:00 .. September 7 00:00 Manila.
        $this->create(['title' => 'Ends at start', 'starts_at' => '2026-07-26T23:00', 'ends_at' => '2026-07-27T00:00']);
        $crossing = $this->create(['title' => 'Crosses start', 'starts_at' => '2026-07-26T23:59', 'ends_at' => '2026-07-27T00:01']);
        $last = $this->create(['title' => 'Last minute', 'starts_at' => '2026-09-06T23:59', 'ends_at' => '2026-09-07T00:01']);
        $this->create(['title' => 'Starts at end', 'starts_at' => '2026-09-07T00:00', 'ends_at' => '2026-09-07T01:00']);
        $list = $this->calendar->listing('clinic', ['date' => '2026-08-31']);
        $this->assertSame('2026-07-27', $list['startDate']);
        $this->assertSame('2026-09-07', $list['endDate']);
        $this->assertSame([$crossing['id'], $last['id']], array_column($list['events'], 'id'));
        $this->assertSame('2026-07-26 15:59:00', $list['events'][0]['starts_at']);
        $spanning = $this->create(['starts_at' => '2026-09-30T23:00', 'ends_at' => '2026-10-02T01:00']);
        $agenda = $this->calendar->listing('clinic', ['date' => '2026-10-01', 'view' => 'agenda']);
        $this->assertSame([$spanning['id']], array_column($agenda['events'], 'id'));
    }

    public function testMineIsCreatorOrParticipantForEventsButOnlyAssigneeForTasks(): void
    {
        $own = $this->create();
        $this->signIn(2);
        $attending = $this->create(['participant_ids' => [1]]);
        $this->create();
        $assigned = $this->tasks->create('clinic', ['title' => 'Assigned', 'assignee_user_id' => 1, 'due_date' => '2026-10-01']);
        $this->tasks->create('clinic', ['title' => 'Other', 'assignee_user_id' => 2, 'due_date' => '2026-10-01']);
        $this->signIn(1);
        $this->tasks->create('clinic', ['title' => 'Creator only', 'due_date' => '2026-10-01']);
        $list = $this->calendar->listing('clinic', ['date' => '2026-10-01', 'view' => 'agenda', 'mine' => '1']);
        $this->assertSame([$own['id'], $attending['id']], array_column($list['events'], 'id'));
        $this->assertSame([$assigned['id']], array_column($list['tasks'], 'id'));
        $this->assertSame(2, $list['totalEvents']);
        $this->assertSame(1, $list['totalTasks']);
        $this->assertFalse($this->calendar->detail('clinic', $attending['id'])['canEdit']);
        $this->fails(403, fn () => $this->calendar->update('clinic', $attending['id'], $this->input(['version' => 1])));
        $this->fails(403, fn () => $this->calendar->cancel('clinic', $attending['id'], ['version' => 1]));
    }

    public function testTasksReadLiveDatesWithoutServicePageCapOrDuplication(): void
    {
        foreach (['2026-09-30', '2026-10-01', '2026-10-30', '2026-10-31', null] as $date) {
            $this->tasks->create('clinic', ['title' => 'Due', 'due_date' => $date]);
        }
        $list = $this->calendar->listing('clinic', ['date' => '2026-10-01', 'view' => 'agenda']);
        $this->assertSame(['2026-10-01', '2026-10-30'], array_column($list['tasks'], 'due_date'));
        $this->assertSame([], $list['tasks'][0]['checklist']);
        $this->assertSame(0, $this->db->table('team_events')->countAllResults());
        $id = $list['tasks'][0]['id'];
        $this->tasks->move('clinic', $id, ['status' => 'done', 'version' => 1]);
        $this->assertSame('done', $this->calendar->listing('clinic', ['date' => '2026-10-01', 'view' => 'agenda'])['tasks'][0]['status']);
        $this->db->table('team_tasks')->where('id', $id)->update(['due_date' => null]);
        $this->assertSame(1, $this->calendar->listing('clinic', ['date' => '2026-10-01', 'view' => 'agenda'])['totalTasks']);
    }

    public function testPermissionsScopeAndAuthoritativeAuthOnEveryOperation(): void
    {
        $event = $this->create();
        $this->signIn(3);
        $this->assertFalse($this->calendar->detail('clinic', $event['id'])['canEdit']);
        $this->fails(403, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 1]));
        foreach ([1, 4, 9] as $id) {
            $this->signIn($id);
            $event = $this->calendar->update('clinic', $event['id'], $this->input(['version' => $event['version']]));
        }
        $this->signIn(11);
        $this->assertSame(0, $this->calendar->listing('clinic', ['date' => '2026-10-01'])['totalEvents']);
        foreach (['detail', 'update', 'cancel'] as $method) {
            $this->fails(404, fn () => $this->calendar->$method('clinic', $event['id'], []));
        }
        $this->signIn(9);
        foreach (['detail', 'update', 'cancel'] as $method) {
            $this->fails(404, fn () => $this->calendar->$method('guidance', $event['id'], []));
        }
        $this->fails(404, fn () => $this->calendar->detail('clinic', 999999));
        $this->signIn(1);
        $this->fails(403, fn () => $this->calendar->listing('guidance'));
        $this->fails(422, fn () => $this->calendar->context('bmg'));
        $this->signIn(10);
        $this->fails(403, fn () => $this->calendar->create('clinic', $this->input()));
        $this->signIn(1);
        $this->db->table('users')->where('id', 1)->update(['status' => 'suspended']);
        $this->fails(401, fn () => $this->calendar->context('clinic'));
        $this->fails(401, fn () => $this->calendar->listing('clinic'));
        $this->fails(401, fn () => $this->calendar->detail('clinic', $event['id']));
        $this->fails(401, fn () => $this->calendar->create('clinic', $this->input()));
        $this->fails(401, fn () => $this->calendar->update('clinic', $event['id'], $this->input(['version' => 4])));
        $this->fails(401, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 4]));
        $this->signIn(2);
        $this->db->table('users')->where('id', 2)->update(['auth_version' => 2]);
        $this->fails(401, fn () => $this->calendar->context('clinic'));
    }

    public function testBothLiveSourcesAreTenantAndDepartmentScoped(): void
    {
        foreach ([1 => 'clinic', 11 => 'clinic', 5 => 'guidance', 7 => 'facilities'] as $user => $unit) {
            $this->signIn($user);
            $this->create([], $unit);
            $this->tasks->create($unit, ['title' => 'Scoped task', 'due_date' => '2026-10-01']);
        }
        foreach ([1 => 'clinic', 11 => 'clinic', 5 => 'guidance', 7 => 'facilities'] as $user => $unit) {
            $this->signIn($user);
            $list = $this->calendar->listing($unit, ['date' => '2026-10-01', 'view' => 'week']);
            $this->assertSame(1, $list['totalEvents']);
            $this->assertSame(1, $list['totalTasks']);
            foreach (['tasks', 'events'] as $source) {
                $this->assertSame($user, $list[$source][0]['created_by_user_id']);
                $this->assertSame($unit, $list[$source][0]['unit']);
                $this->assertSame($user === 11 ? 2 : 1, $list[$source][0]['tenant_id']);
            }
        }
    }

    public function testAllUnitsUseTaskMembershipAndManagerPermissions(): void
    {
        foreach (['clinic' => [2, 4], 'guidance' => [6, 5], 'facilities' => [8, 7]] as $unit => [$staff, $manager]) {
            $this->signIn($staff);
            $this->assertSame($this->tasks->context($unit), $this->calendar->context($unit));
            $event = $this->create(['participant_ids' => [$manager]], $unit);
            $this->signIn($manager);
            $this->assertTrue($this->calendar->detail($unit, $event['id'])['canEdit']);
            $this->assertSame('cancelled', $this->calendar->cancel($unit, $event['id'], ['version' => 1])['status']);
        }
    }

    public function testParticipantsAreStrictCurrentEligibleAndRevalidatedOnUpdates(): void
    {
        foreach ([[11], [12], [6], [10], [9999]] as $ids) {
            $this->fails(422, fn () => $this->create(['participant_ids' => $ids]));
        }
        $this->assertSame(0, $this->db->table('team_events')->countAllResults());
        $event = $this->create(['participant_ids' => ['3', 2]]);
        $this->assertSame([2, 3], $event['participant_ids']);
        $this->assertSame(['id' => 2, 'name' => 'Person 2', 'role' => 'clinic_staff'], $event['participants'][0]);
        $this->db->table('users')->where('id', 2)->update(['status' => 'suspended']);
        $this->db->table('users')->where('id', 3)->update(['role' => 'counsellor']);
        $detail = $this->calendar->detail('clinic', $event['id']);
        $this->assertSame([], $detail['participants']);
        $this->assertSame([], $detail['participant_ids']);
        $this->assertSame([], $this->calendar->listing('clinic', ['date' => '2026-10-01'])['events'][0]['participants']);
        $this->fails(422, fn () => $this->calendar->update('clinic', $event['id'], $this->input(['participant_ids' => [2], 'version' => 1])));

        $this->assertSame(1, $this->calendar->detail('clinic', $event['id'])['version']);
        $this->assertSame(2, $this->db->table('team_event_participants')->countAllResults());
        $event = $this->calendar->update('clinic', $event['id'], $this->input(['version' => 1, 'participant_ids' => [4]]));
        $this->assertSame([4], $event['participant_ids']);
        $this->assertSame(1, $this->db->table('team_event_participants')->countAllResults());
    }

    public function testCancellationAllowsFormerlyEligibleAttendeesWithoutGrantingAccess(): void
    {
        foreach ([['status' => 'suspended'], ['role' => 'counsellor']] as $change) {
            foreach ([1, 4] as $editor) {
                $this->db->table('users')->where('id', 2)->update(['status' => 'active', 'role' => 'clinic_staff']);
                $this->signIn(1);
                $event = $this->create(['participant_ids' => [2, 3]]);
                $this->assertSame([2, 3], $event['participant_ids']);
                $stored = $this->db->table('team_event_participants')->where('event_id', $event['id'])
                    ->orderBy('user_id', 'ASC')->get()->getResultArray();
                $this->db->table('users')->where('id', 2)->update($change);

                $this->signIn(2);
                $denied = isset($change['status']) ? 401 : 403;
                $this->fails($denied, fn () => $this->calendar->detail('clinic', $event['id']));
                $this->fails($denied, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 1]));
                $this->signIn(3);
                $this->fails(403, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 1]));
                $this->signIn(11);
                $this->fails(404, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 1]));

                $this->signIn($editor);
                $this->fails(422, fn () => $this->calendar->update('clinic', $event['id'],
                    $this->input(['version' => 1, 'participant_ids' => [2, 3]])));
                $this->fails(409, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 2]));
                $cancelled = $this->calendar->cancel('clinic', $event['id'], ['version' => 1, 'participant_ids' => [4]]);
                $this->assertSame('cancelled', $cancelled['status']);
                $this->assertSame(2, $cancelled['version']);
                $this->assertSame([3], $cancelled['participant_ids']);
                $this->assertSame([['id' => 3, 'name' => 'Person 3', 'role' => 'clinic_staff']], $cancelled['participants']);
                $this->assertSame($cancelled, $this->calendar->cancel('clinic', $event['id'], ['version' => 2]));
                $this->fails(409, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 1]));
                $this->assertSame($stored, $this->db->table('team_event_participants')->where('event_id', $event['id'])
                    ->orderBy('user_id', 'ASC')->get()->getResultArray());

                $this->signIn(2);
                $this->fails($denied, fn () => $this->calendar->detail('clinic', $event['id']));
                $this->fails($denied, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 2]));
            }
        }
    }

    public function testVersionedUpdateCancellationIdempotenceAndNoReactivation(): void
    {
        $event = $this->create(['participant_ids' => [2]]);
        foreach ([[], ['version' => 0], ['version' => true], ['version' => '1.0'], ['version' => PHP_INT_MAX]] as $input) {
            $this->fails(422, fn () => $this->calendar->cancel('clinic', $event['id'], $input));
        }
        $this->fails(422, fn () => $this->calendar->update('clinic', $event['id'], ['version' => 1, 'title' => 'Incomplete']));
        $event = $this->calendar->update('clinic', $event['id'], $this->input(['version' => '1', 'title' => 'New', 'participant_ids' => [3], 'status' => 'cancelled']));
        $this->assertSame(2, $event['version']);
        $this->assertSame('scheduled', $event['status']);
        $this->fails(409, fn () => $this->calendar->update('clinic', $event['id'], $this->input(['version' => 1, 'participant_ids' => [4]])));
        $this->assertSame([3], $this->calendar->detail('clinic', $event['id'])['participant_ids']);
        $this->fails(409, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 1]));
        $cancelled = $this->calendar->cancel('clinic', $event['id'], ['version' => 2, 'title' => [], 'participant_ids' => false]);
        $this->assertSame('New', $cancelled['title']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(3, $cancelled['version']);
        $this->assertSame($cancelled, $this->calendar->cancel('clinic', $event['id'], ['version' => 3]));
        $this->fails(409, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 2]));
        $this->fails(409, fn () => $this->calendar->update('clinic', $event['id'], $this->input(['version' => 3])));
        $this->assertSame(0, $this->calendar->listing('clinic', ['date' => '2026-10-01'])['totalEvents']);
        foreach (['all', 'cancelled'] as $status) {
            $this->assertSame(1, $this->calendar->listing('clinic', ['date' => '2026-10-01', 'status' => $status])['totalEvents']);
        }
        $this->fails(422, fn () => $this->calendar->listing('clinic', ['status' => 'deleted']));
    }

    public function testIndependentBoundsAndTotalsAndNoTaskListingCap(): void
    {
        $event = $this->create();
        $task = $this->tasks->create('clinic', ['title' => 'Task', 'assignee_user_id' => 1, 'due_date' => '2026-10-01']);
        $eventData = array_diff_key($event, array_flip(['id', 'participants', 'participant_ids', 'canEdit']));
        $taskData = $task;
        unset($taskData['id']);
        $taskData['checklist'] = '[]';
        $this->db->table('team_events')->insertBatch(array_fill(0, 249, $eventData));
        $this->db->table('team_tasks')->insertBatch(array_fill(0, 249, $taskData));
        $filters = ['date' => '2026-10-01', 'view' => 'week', 'mine' => '1'];
        $list = $this->calendar->listing('clinic', $filters);
        $this->assertSame(250, $list['totalTasks']);
        $this->assertSame(250, $list['totalEvents']);
        $this->assertCount(250, $list['tasks']);
        $this->assertCount(250, $list['events']);
        $this->assertFalse($list['truncated']);
        $this->db->table('team_tasks')->insert($taskData);
        $list = $this->calendar->listing('clinic', $filters);
        $this->assertSame(251, $list['totalTasks']);
        $this->assertTrue($list['truncated']);
        $this->db->table('team_events')->insert($eventData);
        $list = $this->calendar->listing('clinic', $filters);
        $this->assertSame(251, $list['totalEvents']);
        $this->assertSame(251, $list['totalTasks']);
        $this->assertCount(250, $list['tasks']);
        $this->assertCount(250, $list['events']);
        $this->assertSame(range(1, 250), array_column($list['events'], 'id'));
        $this->assertSame(range(1, 250), array_column($list['tasks'], 'id'));
    }

    public function testMigrationCompositeScopeForeignKeysAndReversibility(): void
    {
        $event = $this->create();
        foreach ([[2, 'clinic'], [1, 'guidance']] as [$tenant, $unit]) {
            try {
                $this->db->table('team_event_participants')->insert(['tenant_id' => $tenant, 'unit' => $unit,
                    'event_id' => $event['id'], 'user_id' => 2]);
                $this->fail('Composite scope FK must reject mismatched child');
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException) {
                $this->assertSame(0, $this->db->table('team_event_participants')->countAllResults());
            }
        }
        $this->assertSame([], $this->db->query('PRAGMA foreign_key_check')->getResultArray());
        (new CreateTeamEvents())->down();
        $this->assertFalse($this->db->tableExists('team_events'));
        $this->assertFalse($this->db->tableExists('team_event_participants'));
        $this->assertTrue($this->db->tableExists('users'));
        $this->assertTrue($this->db->tableExists('team_tasks'));
        (new CreateTeamEvents())->up();
        $this->assertSame(0, $this->calendar->listing('clinic')['totalEvents']);
    }

    public function testWriteFailureRollsBackParentVersionAndChildrenAndCanRecover(): void
    {
        $event = $this->create(['participant_ids' => [2]]);
        $this->db->query("CREATE TRIGGER isolated_reject_participant BEFORE INSERT ON isolated_team_event_participants
            BEGIN SELECT RAISE(ABORT, 'forced test failure'); END");
        $this->fails(503, fn () => $this->calendar->update('clinic', $event['id'], $this->input(['version' => 1, 'participant_ids' => [3]])));
        $this->assertSame($event, $this->calendar->detail('clinic', $event['id']));
        $this->fails(503, fn () => $this->create(['participant_ids' => [3]]));
        $this->assertSame(1, $this->db->table('team_events')->countAllResults());
        $this->db->query('DROP TRIGGER isolated_reject_participant');
        $updated = $this->calendar->update('clinic', $event['id'], $this->input(['version' => 1, 'participant_ids' => [3]]));
        $this->assertSame(2, $updated['version']);
        $this->assertSame([3], $updated['participant_ids']);
    }

    public function testLostCompareAndSwapFails409BeforeChangingParticipants(): void
    {
        $event = $this->create(['participant_ids' => [2]]);
        // Deterministically simulate a CAS that matched no row, without threading
        // or timing-dependent tests on a shared external database.
        $this->db->query('CREATE TRIGGER isolated_ignore_event_update BEFORE UPDATE ON isolated_team_events
            BEGIN SELECT RAISE(IGNORE); END');
        $this->fails(409, fn () => $this->calendar->update('clinic', $event['id'], $this->input(['version' => 1, 'participant_ids' => [3]])));
        $this->fails(409, fn () => $this->calendar->cancel('clinic', $event['id'], ['version' => 1]));
        $this->assertSame($event, $this->calendar->detail('clinic', $event['id']));
        $this->db->query('DROP TRIGGER isolated_ignore_event_update');
        $this->assertSame(2, $this->calendar->cancel('clinic', $event['id'], ['version' => 1])['version']);
    }

    public function testUnavailableSchemaAndNestedTransactionFailExplicitly(): void
    {
        $this->db->transBegin();
        $this->fails(409, fn () => $this->create());
        $this->db->transRollback();
        (new CreateTeamEvents())->down();
        $this->fails(503, fn () => $this->calendar->listing('clinic'));
        $this->fails(503, fn () => $this->calendar->detail('clinic', 1));
        $this->fails(503, fn () => $this->create());
        (new CreateTeamEvents())->up();
        (new CreateTeamTasks())->down();
        $this->fails(503, fn () => $this->calendar->listing('clinic'));
        $this->assertSame('scheduled', $this->create()['status']);
    }
}
