<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateTeamTasks;
use App\Services\AuthenticatedUser;
use App\Services\TeamTaskService;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/** Only the new migration runs, against a private in-memory database and real auth refresh. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamTaskServiceIntegrationTest extends CIUnitTestCase
{

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
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant,
                'first_name' => 'Person', 'last_name' => (string) $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'user' . $id, 'role' => $role, 'status' => $id === 12 ? 'suspended' : 'active']);
        }
        require_once APPPATH . 'Database/Migrations/2026-10-18-000002_CreateTeamTasks.php';
        (new CreateTeamTasks())->up();
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

    private function full(array $task): array
    {
        return array_intersect_key($task, array_flip(['title', 'description', 'status', 'priority', 'assignee_user_id', 'due_date', 'version']));
    }

    private function alerts(): array
    {
        return array_map(static fn (array $row): array => [(int) $row['recipient_user_id'], $row['type']],
            $this->db->table('notifications')->orderBy('id')->get()->getResultArray());
    }

    public function testTaskNotificationMatrixAcrossAllUnits(): void
    {
        foreach (['clinic' => [1, 2, 3], 'guidance' => [5, 6, 9], 'facilities' => [7, 8, 9]] as $unit => [$owner, $first, $second]) {
            $this->signIn($owner);
            $task = $this->tasks->create($unit, ['title' => 'Work', 'assignee_user_id' => $first]);
            $this->assertSame([[$first, 'team_task_assigned']], $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
            $task = $this->tasks->update($unit, $task['id'], array_replace($this->full($task), [
                'assignee_user_id' => $second, 'status' => 'in_progress',
            ]));
            $expected = [[$first, 'team_task_unassigned'], [$second, 'team_task_assigned']];
            usort($expected, static fn ($a, $b) => $a[0] <=> $b[0]);
            $this->assertSame($expected, $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
            $this->signIn($second);
            $task = $this->tasks->move($unit, $task['id'], ['version' => $task['version'], 'status' => 'done']);
            $this->assertSame([[$owner, 'team_task_status']], $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
            $this->tasks->comment($unit, $task['id'], ['body' => 'Private fixture comment']);
            $this->assertSame([[$owner, 'team_task_commented']], $this->alerts());
            $row = $this->db->table('notifications')->get()->getRowArray();
            $this->assertGreaterThan(0, (int) $row['team_comment_id']);
            $this->assertStringNotContainsString('Private fixture comment', json_encode($row));
            $this->db->table('notifications')->where('id >', 0)->delete();
        }
    }

    public function testTaskMaterialEditsNotifyOnlyOwnerAndAssignee(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Work', 'assignee_user_id' => 2]);
        $this->db->table('notifications')->where('id >', 0)->delete();
        $this->signIn(4);
        foreach (['title' => 'Revised', 'due_date' => '2027-01-01', 'priority' => 'high',
            'description' => 'Revised description', 'checklist' => [['text' => 'Item', 'done' => false]]] as $field => $value) {
            $task = $this->tasks->update('clinic', $task['id'], array_replace($this->full($task), [$field => $value]));
            $this->assertSame([[1, 'team_task_updated'], [2, 'team_task_updated']], $this->alerts());
            $this->db->table('notifications')->where('id >', 0)->delete();
        }
        $this->tasks->comment('clinic', $task['id'], ['body' => 'Comment from manager']);
        $this->assertSame([[1, 'team_task_commented'], [2, 'team_task_commented']], $this->alerts());
    }

    public function testTaskUnchangedEditsAndUnassignmentDoNotSpam(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Work', 'assignee_user_id' => 2]);
        $this->db->table('notifications')->where('id >', 0)->delete();
        $same = $this->tasks->update('clinic', $task['id'], $this->full($task));
        $this->assertSame($task['version'], $same['version']);
        $same = $this->tasks->move('clinic', $task['id'], ['version' => 1, 'status' => 'todo']);
        $this->assertSame(1, $same['version']);
        $this->assertSame([], $this->alerts());
        $this->tasks->update('clinic', $task['id'], array_replace($this->full($task), ['assignee_user_id' => null]));
        $this->assertSame([[2, 'team_task_unassigned']], $this->alerts());
    }

    public function testNotificationFailureRollsBackTaskActivityAndComment(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Work', 'assignee_user_id' => 2]);
        $this->db->table('notifications')->where('id >', 0)->delete();
        $this->db->query("CREATE TRIGGER fail_alert BEFORE INSERT ON isolated_notifications BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->fails(503, fn () => $this->tasks->move('clinic', $task['id'], ['version' => 1, 'status' => 'done']));
        $this->assertSame(1, $this->tasks->detail('clinic', $task['id'])['version']);
        $this->assertSame(1, $this->db->table('team_task_activity')->countAllResults());
        $this->fails(503, fn () => $this->tasks->comment('clinic', $task['id'], ['body' => 'Comment']));
        $this->assertSame(0, $this->db->table('team_task_comments')->countAllResults());
        $this->assertSame(1, $this->db->table('team_task_activity')->countAllResults());
        $this->assertSame([], $this->alerts());
    }

    public function testNotificationRecipientRevalidationAndIdempotency(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Work']);
        $actor = $this->tasks->context('clinic')['actor'];
        $this->db->table('people')->where('id', 3)->update(['deleted_at' => '2026-10-01 00:00:00']);
        $this->db->transBegin();
        $producer = new \App\Services\TeamsNotifications($this->db);
        $plan = array_fill_keys([1, 2, 3, 5, 10, 11, 12], 'team_task_updated');
        $producer->enqueue($task, $actor, $plan);
        $producer->enqueue($task, $actor, $plan);
        $this->assertSame([[2, 'team_task_updated']], $this->alerts());
        $this->db->transRollback();
        $this->assertSame([], $this->alerts());
        $this->fails(503, fn () => $producer->enqueue($task, $actor, $plan));
    }

    public function testAuthoritativeContextNamesAndRoleMembership(): void
    {
        $context = $this->tasks->context('clinic');
        $this->assertSame('clinic_staff', $context['actor']['role']);
        $this->assertSame('Person 1', $context['actor']['name']);
        $this->assertFalse($context['canManage']);
        $this->assertSame([1, 2, 3, 4, 9], array_column($context['members'], 'id'));
        $this->assertSame(['id' => 1, 'name' => 'Person 1', 'role' => 'clinic_staff'], $context['members'][0]);
        $this->db->table('people')->where('id', 2)->update(['tenant_id' => 2, 'first_name' => 'SECRET']);
        $this->assertSame('user2', $this->tasks->context('clinic')['members'][1]['name']);
        $this->db->table('people')->where('id', 3)->update(['deleted_at' => '2026-10-18']);
        $this->assertSame('user3', $this->tasks->context('clinic')['members'][2]['name']);
        $this->signIn(9);
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            $this->assertTrue($this->tasks->context($unit)['canManage']);
        }
        $this->assertSame([5, 6, 9], array_column($this->tasks->context('guidance')['members'], 'id'));
        $this->assertSame([7, 8, 9], array_column($this->tasks->context('facilities')['members'], 'id'));
    }

    public function testAuthRevocationAndWrongDepartmentFailClosed(): void
    {
        $this->fails(403, fn () => $this->tasks->context('guidance'));
        $this->fails(422, fn () => $this->tasks->context('bmg'));
        $this->signIn(10);
        $this->fails(403, fn () => $this->tasks->listing('clinic'));
        $this->signIn(1);
        $this->db->table('users')->where('id', 1)->update(['status' => 'suspended']);
        $this->fails(401, fn () => $this->tasks->create('clinic', ['title' => 'No']));
        $this->signIn(2);
        $this->db->table('users')->where('id', 2)->update(['auth_version' => 2]);
        $this->fails(401, fn () => $this->tasks->context('clinic'));
        session()->remove(['user', '_auth']);
        $this->fails(401, fn () => $this->tasks->context('clinic'));
    }

    public function testCreateOwnsScopeAndHasNoPatientOrAttachmentFields(): void
    {
        $task = $this->tasks->create('clinic', ['title' => ' Plan ', 'tenant_id' => 2, 'unit' => 'facilities',
            'created_by_user_id' => 9, 'version' => 99, 'patient_id' => 1, 'attachments' => ['secret'],
            'description' => 'Private description', 'assignee_user_id' => '2', 'due_date' => '2028-02-29']);
        $this->assertSame(1, $task['tenant_id']);
        $this->assertSame('clinic', $task['unit']);
        $this->assertSame(1, $task['created_by_user_id']);
        $this->assertSame(1, $task['version']);
        $this->assertSame('Plan', $task['title']);
        $this->assertSame(2, $task['assignee_user_id']);
        $this->assertSame('todo', $task['status']);
        $this->assertSame('normal', $task['priority']);
        $this->assertArrayNotHasKey('patient_id', $task);
        $this->assertArrayNotHasKey('attachments', $task);
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertTrue($detail['canEdit']);
        $this->assertSame('created', $detail['activity'][0]['event']);
        $this->assertStringNotContainsString('Private description', json_encode($detail['activity']));
    }

    public function testAllIdOperationsAreTenantAndUnitScoped(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Tenant one']);
        $this->signIn(11);
        $this->assertSame(0, $this->tasks->listing('clinic')['total']);
        $this->assertSame([11], array_column($this->tasks->context('clinic')['members'], 'id'));
        foreach (['detail', 'update', 'move', 'comment'] as $method) {
            $this->fails(404, fn () => $this->tasks->$method('clinic', $task['id'], []));
        }
        $this->signIn(9);
        foreach (['detail', 'update', 'move', 'comment'] as $method) {
            $this->fails(404, fn () => $this->tasks->$method('guidance', $task['id'], []));
        }
        $this->fails(404, fn () => $this->tasks->detail('clinic', 9999));
    }

    public function testCreatorAssigneeAndManagersEditButOtherMembersOnlyComment(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Original', 'assignee_user_id' => 2]);
        $this->signIn(3);
        $this->assertFalse($this->tasks->detail('clinic', $task['id'])['canEdit']);
        $this->fails(403, fn () => $this->tasks->update('clinic', $task['id'], $this->full($task)));
        $this->fails(403, fn () => $this->tasks->move('clinic', $task['id'], ['status' => 'done', 'version' => 1]));
        $this->tasks->comment('clinic', $task['id'], ['body' => 'Member comment', 'actor_user_id' => 9]);
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertSame(3, $detail['comments'][0]['actor_user_id']);
        $this->assertSame(1, $detail['version']);
        foreach ([2, 1, 4, 9] as $editor) {
            $this->signIn($editor);
            $task = $this->tasks->update('clinic', $task['id'], array_replace($this->full($task), ['title' => 'Editor ' . $editor]));
            $this->assertSame('Editor ' . $editor, $task['title']);
        }
        $this->signIn(5);
        $task = $this->tasks->create('guidance', ['title' => 'Guidance']);
        $this->signIn(6);
        $this->fails(403, fn () => $this->tasks->move('guidance', $task['id'], ['status' => 'done', 'version' => 1]));
        $this->tasks->comment('guidance', $task['id'], ['body' => 'Counsellor comment']);
        $this->signIn(5);
        $this->assertSame('done', $this->tasks->move('guidance', $task['id'], ['status' => 'done', 'version' => 1])['status']);
        $this->signIn(7);
        $this->assertTrue($this->tasks->context('facilities')['canManage']);
        $this->assertSame('facilities', $this->tasks->create('facilities', ['title' => 'Facilities'])['unit']);
    }

    public function testAssigneeValidatedOnEveryWriteAndCanBeClearedByFullUpdate(): void
    {
        foreach ([5, 10, 11, 12, 9999] as $id) {
            $this->fails(422, fn () => $this->tasks->create('clinic', ['title' => 'Bad assignment', 'assignee_user_id' => $id]));
        }
        $task = $this->tasks->create('clinic', ['title' => 'Valid', 'assignee_user_id' => 2]);
        foreach ([['status' => 'suspended'], ['status' => 'active', 'role' => 'counsellor'],
            ['role' => 'clinic_staff', 'tenant_id' => 2], ['tenant_id' => 1, 'deleted_at' => '2026-10-18']] as $change) {
            $this->db->table('users')->where('id', 2)->update($change);
            $this->fails(422, fn () => $this->tasks->move('clinic', $task['id'], ['status' => 'done', 'version' => 1]));
            $this->fails(422, fn () => $this->tasks->comment('clinic', $task['id'], ['body' => 'No']));
            $this->fails(422, fn () => $this->tasks->update('clinic', $task['id'], $this->full($task)));
        }
        $updated = $this->tasks->update('clinic', $task['id'], array_replace($this->full($task), ['assignee_user_id' => null]));
        $this->assertNull($updated['assignee_user_id']);
        $this->assertSame(2, $updated['version']);
        $this->assertSame(0, $this->tasks->detail('clinic', $task['id'])['comments_total']);
    }

    public function testStaleVersionCannotOverwriteOrAppendActivity(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Version one']);
        $moved = $this->tasks->move('clinic', $task['id'], ['status' => 'in_progress', 'version' => '1', 'title' => 'Ignored']);
        $this->assertSame('Version one', $moved['title']);
        $this->assertSame(2, $moved['version']);
        $this->fails(409, fn () => $this->tasks->update('clinic', $task['id'], $this->full($task)));
        $this->fails(409, fn () => $this->tasks->move('clinic', $task['id'], ['status' => 'done', 'version' => 1]));
        $this->fails(422, fn () => $this->tasks->move('clinic', $task['id'], ['status' => 'done']));
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertSame('in_progress', $detail['status']);
        $this->assertSame(2, $detail['activity_total']);
        $this->assertSame('todo', $detail['activity'][0]['old_status']);
        $this->assertSame('in_progress', $detail['activity'][0]['new_status']);
    }

    public function testActivityFailureRollsBackCreateUpdateAndComment(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Original']);
        $this->db->query("CREATE TRIGGER isolated_fail_activity BEFORE INSERT ON isolated_team_task_activity
            BEGIN SELECT RAISE(ABORT, 'simulated activity failure'); END");
        $this->fails(503, fn () => $this->tasks->create('clinic', ['title' => 'Rollback']));
        $this->fails(503, fn () => $this->tasks->move('clinic', $task['id'], ['status' => 'done', 'version' => 1]));
        $this->fails(503, fn () => $this->tasks->update('clinic', $task['id'], array_replace($this->full($task), ['title' => 'Rollback'])));
        $this->fails(503, fn () => $this->tasks->comment('clinic', $task['id'], ['body' => 'Rollback']));
        $this->assertSame(1, $this->tasks->listing('clinic')['total']);
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertSame('Original', $detail['title']);
        $this->assertSame(1, $detail['version']);
        $this->assertSame(0, $detail['comments_total']);
        $this->assertSame(1, $detail['activity_total']);
        $this->db->query('DROP TRIGGER isolated_fail_activity');
        $this->assertSame(2, $this->tasks->move('clinic', $task['id'], ['status' => 'done', 'version' => 1])['version']);
    }

    public function testFiltersPaginationAndCounts(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->tasks->create('clinic', ['title' => 'Task ' . $i, 'description' => 'Searchable',
                'status' => $i < 3 ? 'done' : 'todo', 'priority' => $i < 3 ? 'high' : 'normal',
                'assignee_user_id' => $i < 3 ? 1 : 2]);
        }
        $listing = $this->tasks->listing('clinic');
        $this->assertCount(100, $listing['tasks']);
        $this->assertSame(105, $listing['total']);
        $this->assertSame(2, $listing['pages']);
        $this->assertSame(['todo' => 102, 'in_progress' => 0, 'blocked' => 0, 'done' => 3, 'total' => 105], $listing['counts']);
        $second = $this->tasks->listing('clinic', ['page' => '999']);
        $this->assertSame(2, $second['page']);
        $this->assertCount(5, $second['tasks']);
        $this->assertSame(105, $listing['tasks'][0]['id']);
        $filtered = $this->tasks->listing('clinic', ['mine' => '1', 'q' => 'Searchable', 'priority' => 'high', 'status' => 'todo']);
        $this->assertSame(0, $filtered['total']);
        $this->assertSame(3, $filtered['counts']['done']);
        $this->assertSame(3, $this->tasks->listing('clinic', ['mine' => '1'])['total']);
        $this->assertSame(0, $this->tasks->listing('clinic', ['q' => '%'])['total']);
        foreach ([['page' => []], ['page' => 0], ['status' => 'bad'], ['priority' => []], ['mine' => []], ['q' => []]] as $filter) {
            $this->fails(422, fn () => $this->tasks->listing('clinic', $filter));
        }
    }

    public function testDetailLimitsAndCommentValidationAndPrivacy(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Bounded detail', 'description' => 'PRIVATE DESCRIPTION']);
        foreach (['', str_repeat('a', 2001), [], "bad\0text"] as $body) {
            $this->fails(422, fn () => $this->tasks->comment('clinic', $task['id'], ['body' => $body]));
        }
        for ($i = 0; $i < 55; $i++) {
            $this->tasks->comment('clinic', $task['id'], ['body' => $i === 0 ? str_repeat('日', 2000) : 'PRIVATE COMMENT ' . $i]);
        }
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertCount(50, $detail['comments']);
        $this->assertCount(50, $detail['activity']);
        $this->assertSame(55, $detail['comments_total']);
        $this->assertSame(56, $detail['activity_total']);
        $this->assertSame(50, $detail['comments_limit']);
        $this->assertSame(50, $detail['activity_limit']);
        $this->assertSame('PRIVATE COMMENT 54', $detail['comments'][0]['body']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($detail['activity']));
    }

    public function testMissingTablesAndCallerTransactionsFailSafely(): void
    {
        $this->db->transBegin();
        $this->fails(409, fn () => $this->tasks->create('clinic', ['title' => 'Nested']));
        $this->assertSame(1, $this->db->transDepth);
        $this->db->transRollback();
        (new CreateTeamTasks())->down();
        $this->assertNotEmpty($this->tasks->context('clinic')['members']);
        $this->fails(503, fn () => $this->tasks->listing('clinic'));
        $this->fails(503, fn () => $this->tasks->create('clinic', ['title' => 'Unavailable']));
        (new CreateTeamTasks())->up();
        $this->assertSame(1, $this->tasks->create('clinic', ['title' => 'Recreated'])['id']);
    }

    public function testChecklistPersistenceDefaultsAndBackwardCompatibleUpdates(): void
    {
        $empty = $this->tasks->create('clinic', ['title' => 'Default checklist']);
        $this->assertSame([], $empty['checklist']);
        $this->assertSame('[]', $this->db->table('team_tasks')->where('id', $empty['id'])->get()->getRowArray()['checklist']);
        $items = [['text' => 'PRIVATE CHECKLIST', 'done' => false], ['text' => 'Completed step', 'done' => true]];
        $task = $this->tasks->create('clinic', ['title' => 'Checklist', 'checklist' => json_encode($items)]);
        $this->assertSame($items, $task['checklist']);
        $stored = $this->db->table('team_tasks')->where('id', $task['id'])->get()->getRowArray()['checklist'];
        $this->assertIsString($stored);
        $this->assertSame($items, json_decode($stored, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($items, $this->tasks->listing('clinic')['tasks'][0]['checklist']);
        $this->assertSame($items, $this->tasks->detail('clinic', $task['id'])['checklist']);
        // The original full-update contract intentionally excludes checklist.
        $task = $this->tasks->update('clinic', $task['id'], array_replace($this->full($task), ['title' => 'Renamed']));
        $this->assertSame($items, $task['checklist']);
        $task = $this->tasks->move('clinic', $task['id'], ['status' => 'blocked', 'version' => $task['version'], 'checklist' => []]);
        $this->assertSame($items, $task['checklist']);
        $this->tasks->comment('clinic', $task['id'], ['body' => 'Comment', 'checklist' => []]);
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertSame($items, $detail['checklist']);
        $this->assertStringNotContainsString('PRIVATE CHECKLIST', json_encode($detail['activity']));
        $items[0]['done'] = true;
        $task = $this->tasks->update('clinic', $task['id'], $this->full($task) + ['checklist' => $items]);
        $this->assertSame($items, $task['checklist']);
        $this->assertSame('updated', $this->tasks->detail('clinic', $task['id'])['activity'][0]['event']);
        $task = $this->tasks->update('clinic', $task['id'], $this->full($task) + ['checklist' => '[]']);
        $this->assertSame([], $task['checklist']);
        $task = $this->tasks->update('clinic', $task['id'], $this->full($task) + ['checklist' => $items]);
        $task = $this->tasks->update('clinic', $task['id'], $this->full($task) + ['checklist' => []]);
        $this->assertSame([], $task['checklist']);
    }

    public function testChecklistValidationAndStaleWritesHaveNoSideEffects(): void
    {
        $items = [['text' => 'Original', 'done' => false]];
        $task = $this->tasks->create('clinic', ['title' => 'Original', 'checklist' => $items]);
        foreach ([null, '{', '{}', [['text' => 'Bad boolean', 'done' => 'true']],
            array_fill(0, 21, ['text' => 'Too many', 'done' => false]),
            str_repeat(' ', TeamTaskService::CHECKLIST_BYTES_LIMIT) . '[]'] as $value) {
            $this->fails(422, fn () => $this->tasks->create('clinic', ['title' => 'Rejected', 'checklist' => $value]));
            $this->fails(422, fn () => $this->tasks->update('clinic', $task['id'],
                array_replace($this->full($task), ['title' => 'Rejected', 'checklist' => $value])));
        }
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertSame($items, $detail['checklist']);
        $this->assertSame(1, $detail['version']);
        $this->assertSame(1, $detail['activity_total']);
        $this->assertSame(1, $this->tasks->listing('clinic')['total']);
        $moved = $this->tasks->move('clinic', $task['id'], ['status' => 'done', 'version' => 1]);
        $this->fails(409, fn () => $this->tasks->update('clinic', $task['id'], $this->full($task) + ['checklist' => []]));
        $this->assertSame($items, $moved['checklist']);
        $this->assertSame($items, $this->tasks->detail('clinic', $task['id'])['checklist']);
    }

    public function testChecklistCannotMassAssignTaskOrBypassEditPermission(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Task', 'checklist' => [['text' => 'Step', 'done' => false]],
            'tenant_id' => 2, 'created_by_user_id' => 9, 'unit' => 'guidance']);
        $task = $this->tasks->update('clinic', $task['id'], $this->full($task) + [
            'checklist' => '[{"text":"New step","done":true}]', 'tenant_id' => 2, 'unit' => 'facilities',
            'created_by_user_id' => 9, 'id' => 999, 'created_at' => '1900-01-01', 'activity' => [['event' => 'forged']],
        ]);
        $this->assertSame(1, $task['id']);
        $this->assertSame(1, $task['tenant_id']);
        $this->assertSame(1, $task['created_by_user_id']);
        $this->assertSame('clinic', $task['unit']);
        $this->assertSame(2, $task['version']);
        $this->assertNotSame('1900-01-01', $task['created_at']);
        $this->assertSame([['text' => 'New step', 'done' => true]], $task['checklist']);
        $this->fails(422, fn () => $this->tasks->update('clinic', $task['id'], $this->full($task) + [
            'checklist' => [['text' => 'Injected item', 'done' => false, 'tenant_id' => 2]],
        ]));
        $this->signIn(3);
        $this->fails(403, fn () => $this->tasks->update('clinic', $task['id'], $this->full($task) + ['checklist' => []]));
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertSame($task['checklist'], $detail['checklist']);
        $this->assertSame(['updated', 'created'], array_column($detail['activity'], 'event'));
    }

    public function testChecklistUpdateAndActivityRemainAtomic(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Task', 'checklist' => [['text' => 'Keep me', 'done' => false]]]);
        $this->db->query("CREATE TRIGGER isolated_fail_checklist_activity BEFORE INSERT ON isolated_team_task_activity
            BEGIN SELECT RAISE(ABORT, 'simulated activity failure'); END");
        $this->fails(503, fn () => $this->tasks->update('clinic', $task['id'], $this->full($task) + ['checklist' => []]));
        $detail = $this->tasks->detail('clinic', $task['id']);
        $this->assertSame($task['checklist'], $detail['checklist']);
        $this->assertSame(1, $detail['version']);
        $this->assertSame(1, $detail['activity_total']);
    }

    public function testLiteralLikeWildcardsAreEscapedExactlyOnce(): void
    {
        $titles = ['Percent 50% complete', 'Under_score', 'Bang! done', "O'Reilly", 'Literal !%_', 'Ordinary'];
        foreach ($titles as $title) { $this->tasks->create('clinic', ['title' => $title]); }
        // In this installed builder a bare '%' still acts as a wildcard, despite escape=true.
        $this->assertSame(6, $this->db->table('team_tasks')->like('title', '%', 'both', true)->countAllResults());
        foreach (['%' => [5, 1], '_' => [5, 2], '!' => [5, 3], "'" => [4], '!%_' => [5],
            '50% complete' => [1], 'Under_score' => [2], "O'Reilly" => [4], 'not%present' => []] as $query => $ids) {
            $this->assertSame($ids, array_column($this->tasks->listing('clinic', ['q' => $query])['tasks'], 'id'), $query);
        }
    }

    public function testMigrationForeignKeyRejectsCrossScopeChild(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Parent']);
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->db->table('team_task_comments')->insert(['tenant_id' => 2, 'unit' => 'clinic', 'task_id' => $task['id'],
            'actor_user_id' => 11, 'body' => 'Wrong scope', 'created_at' => gmdate('Y-m-d H:i:s')]);
    }
}
