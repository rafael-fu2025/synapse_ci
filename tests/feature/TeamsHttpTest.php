<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Migrations\CreateTeamTasks;
use App\Services\AuthenticatedUser;
use App\Services\TeamTaskService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Real application routes, filters, controller and views; only private SQLite data. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'isolated_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);
        $this->db->query('CREATE TABLE isolated_tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE isolated_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE isolated_employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE isolated_users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE isolated_permissions (id INTEGER PRIMARY KEY, code TEXT)');
        $this->db->query('CREATE TABLE isolated_role_permissions (role TEXT, permission_id INTEGER)');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => 'clinic_staff', 2 => 'clinic_staff', 3 => 'clinic_admin', 4 => 'counsellor',
            5 => 'facilities_op', 6 => 'student', 7 => 'superadmin', 8 => 'clinic_staff'] as $id => $role) {
            $tenant = $id === 8 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'first_name' => 'Person', 'last_name' => (string) $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id, 'username' => 'user' . $id, 'role' => $role, 'status' => 'active']);
        }
        require_once APPPATH . 'Database/Migrations/2026-10-18-000002_CreateTeamTasks.php';
        (new CreateTeamTasks())->up();
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function identity(int $id): array
    {
        $tenant = $id === 8 ? 2 : 1;
        // Deliberately forge cached privilege: every request must refresh from SQLite.
        return ['tenant_id' => $tenant, 'user' => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true,
            'role' => 'superadmin', 'name' => 'Forged admin', 'permissions' => ['*']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function requestAs(int $id, string $method, string $path, array $data = [], bool $json = true, bool $csrf = true)
    {
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        if ($method === 'POST' && $csrf) {
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $data[config('Security')->tokenName] = $hash;
        }
        return $this->withSession($this->identity($id))
            ->withHeaders($json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [])
            ->call($method, $path, $data);
    }

    private function task(string $unit = 'clinic', int $id = 1, array $data = []): array
    {
        session()->set($this->identity($id));
        return (new TeamTaskService())->create($unit, $data + ['title' => 'Original task']);
    }

    private function json($result, int $status): array
    {
        $this->assertSame($status, $result->response()->getStatusCode(), $result->getBody());
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $result->response()->getHeaderLine('Cache-Control'));
        $json = json_decode($result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($status < 400, $json['success']);
        $this->assertSame(csrf_token(), $json['csrf']['name']);
        $this->assertNotEmpty($json['csrf']['hash']);
        return $json;
    }

    public function testEveryDepartmentResolvesAllEightRoutesWithoutClinicalGrants(): void
    {
        foreach (['clinic' => 1, 'guidance' => 4, 'facilities' => 5] as $unit => $actor) {
            $base = $unit . '/teams';
            $this->requestAs($actor, 'GET', $base)->assertStatus(200);
            $form = $this->requestAs($actor, 'GET', $base . '/create?fragment=1');
            $form->assertStatus(200);
            $this->assertStringContainsString(site_url($base . '/store'), $form->getBody());
            $this->json($this->requestAs($actor, 'POST', $base . '/store', ['title' => 'HTTP ' . $unit]), 200);
            $task = $this->db->table('team_tasks')->where('unit', $unit)->get()->getRowArray();
            $id = (int) $task['id'];
            foreach (['', '/edit'] as $suffix) {
                $result = $this->requestAs($actor, 'GET', $base . '/' . $id . $suffix . '?fragment=1');
                $result->assertStatus(200);
                $this->assertStringContainsString('HTTP ' . $unit, $result->getBody());
            }
            $this->json($this->requestAs($actor, 'POST', "$base/$id/update", ['title' => 'Updated ' . $unit, 'description' => '',
                'status' => 'todo', 'priority' => 'normal', 'assignee_user_id' => '', 'due_date' => '', 'version' => 1]), 200);
            $this->json($this->requestAs($actor, 'POST', "$base/$id/move", ['status' => 'done', 'version' => 2]), 200);
            $comment = $this->json($this->requestAs($actor, 'POST', "$base/$id/comments", ['body' => 'HTTP comment']), 200);
            $this->assertSame(site_url("$base/$id"), $comment['redirect']);
            $stored = $this->db->table('team_tasks')->where('id', $id)->get()->getRowArray();
            $this->assertSame('done', $stored['status']);
            $this->assertSame(3, (int) $stored['version']);
            $this->assertSame(1, $this->db->table('team_task_comments')->where('task_id', $id)->countAllResults());
        }
    }

    public function testMembershipAndTenantBoundariesForEveryIdOperation(): void
    {
        $task = $this->task();
        $id = $task['id'];
        foreach (['GET' => ['', '/edit'], 'POST' => ['/update', '/move', '/comments']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $path = "clinic/teams/$id$suffix";
                $this->requestAs(8, $method, $path)->assertStatus(404);
                $this->requestAs(4, $method, $path)->assertStatus(403);
                $this->requestAs(6, $method, $path)->assertStatus(403);
                $this->requestAs(7, $method, "guidance/teams/$id$suffix")->assertStatus(404);
            }
        }
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            $this->requestAs(6, 'GET', "$unit/teams")->assertStatus(403);
            $this->requestAs(6, 'GET', "$unit/teams/create?fragment=1")->assertStatus(403);
            $this->requestAs(6, 'POST', "$unit/teams/store", ['title' => 'Forbidden'])->assertStatus(403);
        }
        $this->assertSame(1, $this->db->table('team_tasks')->countAllResults());
        $this->assertSame(0, $this->db->table('team_task_comments')->countAllResults());
        $this->withSession([])->withHeaders([])->get('clinic/teams')->assertRedirectTo('/auth/login');
    }

    public function testOtherMemberMayReadAndCommentButCannotEditOrMove(): void
    {
        $id = $this->task()['id'];
        $show = $this->requestAs(2, 'GET', "clinic/teams/$id?fragment=1");
        $show->assertStatus(200);
        $this->assertStringNotContainsString('name="version"', $show->getBody());
        $this->requestAs(2, 'GET', "clinic/teams/$id/edit?fragment=1")->assertStatus(403);
        foreach (['update', 'move'] as $action) {
            $this->json($this->requestAs(2, 'POST', "clinic/teams/$id/$action", ['version' => 1, 'status' => 'done']), 403);
        }
        $this->json($this->requestAs(2, 'POST', "clinic/teams/$id/comments", ['body' => 'Allowed discussion']), 200);
        $this->requestAs(3, 'GET', "clinic/teams/$id/edit?fragment=1")->assertStatus(200);
    }

    public function testInvalidAndStaleWritesAreJsonAndDoNotChangeStoredTask(): void
    {
        $task = $this->task();
        $base = 'clinic/teams/' . $task['id'];
        foreach ([['title' => ''], ['title' => 'Bad assignee', 'assignee_user_id' => 8],
            ['title' => 'Wrong department', 'assignee_user_id' => 4], ['title' => 'Bad checklist', 'checklist' => '{bad'],
            ['title' => 'Bad date', 'due_date' => '2027-02-30']] as $input) {
            $this->json($this->requestAs(1, 'POST', 'clinic/teams/store', $input), 422);
        }
        foreach ([[], ['version' => 1, 'status' => 'invalid'], ['version' => [], 'status' => 'done']] as $input) {
            $this->json($this->requestAs(1, 'POST', $base . '/move', $input), 422);
        }
        $this->json($this->requestAs(1, 'POST', $base . '/comments', ['body' => '']), 422);
        $this->json($this->requestAs(1, 'POST', $base . '/move', ['version' => 1, 'status' => 'in_progress']), 200);
        foreach (['move', 'update'] as $action) {
            $this->json($this->requestAs(1, 'POST', $base . '/' . $action, ['version' => 1, 'status' => 'done', 'title' => 'Stale']), 409);
        }
        $stored = $this->db->table('team_tasks')->get()->getRowArray();
        $this->assertSame('Original task', $stored['title']);
        $this->assertSame('in_progress', $stored['status']);
        $this->assertSame(2, (int) $stored['version']);
        $this->assertSame(1, $this->db->table('team_tasks')->countAllResults());
        $this->assertSame(2, $this->db->table('team_task_activity')->countAllResults());
        $this->assertSame(0, $this->db->table('team_task_comments')->countAllResults());
        $this->requestAs(1, 'GET', 'clinic/teams?view=invalid')->assertStatus(422);
        $this->requestAs(1, 'GET', 'clinic/teams?page[]=1')->assertStatus(422);
    }

    public function testRenderedTaskCommentAndChecklistAreEscapedAndModalContractsHold(): void
    {
        $payload = '<img src=x onerror="window.teamXss=1">';
        $task = $this->task(data: ['title' => $payload, 'description' => '</textarea>' . $payload,
            'checklist' => [['text' => $payload, 'done' => true]]]);
        $id = $task['id'];
        $this->json($this->requestAs(1, 'POST', "clinic/teams/$id/comments", ['body' => $payload]), 200);
        foreach (['create', (string) $id, "$id/edit"] as $path) {
            foreach ([false, true] as $fragment) {
                $result = $this->requestAs(1, 'GET', 'clinic/teams/' . $path . ($fragment ? '?fragment=1' : ''), json: false);
                $result->assertStatus(200);
                $html = (string) $result->response()->getBody();
                $this->assertStringNotContainsString($payload, $html);
                $this->assertSame(1, substr_count($html, 'id="appFormModal"'));
                $this->assertStringContainsString('data-bs-backdrop="static"', $html);
                $this->assertStringContainsString('data-bs-keyboard="false"', $html);
                $this->assertStringContainsString('data-modal-submit', $html);
                $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
                $this->assertStringContainsString('data-app-modal-close', $html);
                if ($fragment) {
                    $this->assertStringNotContainsString('<html', $html);
                    $this->assertStringNotContainsString('<script', $html);
                    $this->assertStringNotContainsString('data-modal-direct', $html);
                } else {
                    $this->assertStringContainsString('<html', $html);
                    $this->assertStringContainsString('data-modal-direct="true"', $html);
                    $this->assertStringContainsString('assets/js/form-modal.js', $html);
                }
                if ($path !== 'create') {
                    $this->assertStringContainsString('&lt;img', $html);
                }
                if ($path === "$id/edit") {
                    $dom = new \DOMDocument();
                    @$dom->loadHTML($html);
                    $xp = new \DOMXPath($dom);
                    $json = $xp->query('//input[@name="checklist"]')->item(0)->getAttribute('value');
                    $this->assertSame($task['checklist'], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
                    $this->assertSame('1', $xp->query('//input[@name="version"]')->item(0)->getAttribute('value'));
                }
                if ($path === (string) $id) {
                    $this->assertSame(4, substr_count($html, '&lt;img'), 'Title, description, checklist and comment must each be escaped.');
                }
            }
        }
        foreach (['board', 'list'] as $mode) {
            $result = $this->requestAs(1, 'GET', 'clinic/teams?view=' . $mode, json: false);
            $result->assertStatus(200);
            $this->assertStringContainsString('&lt;img', $result->getBody());
            $this->assertStringNotContainsString($payload, $result->getBody());
            $this->assertStringContainsString('data-app-modal-open', $result->getBody());
        }
    }

    public function testAllWriteRoutesRejectMissingAndInvalidCsrfBeforeMutation(): void
    {
        $id = $this->task()['id'];
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            foreach (['store', "$id/update", "$id/move", "$id/comments"] as $action) {
                foreach ([[], [csrf_token() => str_repeat('0', 64)]] as $input) {
                    $result = $this->requestAs(7, 'POST', "$unit/teams/$action", $input, csrf: false);
                    $json = $this->json($result, 403);
                    $this->assertTrue($json['reload'], 'Must be rejected by the registered web_csrf filter, not a domain guard.');
                }
            }
        }
        $this->assertSame(1, $this->db->table('team_tasks')->countAllResults());
        $this->assertSame(0, $this->db->table('team_task_comments')->countAllResults());
        $this->assertSame(1, $this->db->table('team_task_activity')->countAllResults());
    }

    public function testRevokedAuthenticationCannotReadOrWriteUsingCachedAdminRole(): void
    {
        $id = $this->task()['id'];
        $this->db->table('users')->where('id', 1)->update(['auth_version' => 2]);
        foreach (['GET' => ['clinic/teams', "clinic/teams/$id?fragment=1"],
            'POST' => ['clinic/teams/store', "clinic/teams/$id/move", "clinic/teams/$id/comments"]] as $method => $paths) {
            foreach ($paths as $path) {
                $this->requestAs(1, $method, $path, ['title' => 'Revoked', 'body' => 'Revoked', 'version' => 1, 'status' => 'done'])->assertStatus(401);
            }
        }
        $this->assertSame(1, $this->db->table('team_tasks')->countAllResults());
        $this->assertSame(1, $this->db->table('team_task_activity')->countAllResults());
        $this->assertSame(0, $this->db->table('team_task_comments')->countAllResults());
    }

    public function testUnavailableTeamsSchemaReturnsSanitizedPrivate503(): void
    {
        // Deliberately damage only this process's private in-memory schema.
        $this->db->query('DROP TABLE isolated_team_task_activity');
        foreach (['GET' => ['clinic/teams', 'clinic/teams/999?fragment=1'],
            'POST' => ['clinic/teams/store']] as $method => $paths) {
            foreach ($paths as $path) {
                $json = $this->json($this->requestAs(1, $method, $path, ['title' => 'Not persisted']), 503);
                $this->assertSame('Teams is not available yet. Ask the administrator to apply the Teams database migration, or retry later.', $json['message']);
                $this->assertStringNotContainsString('isolated_', $json['message']);
                $this->assertStringNotContainsString('SQL', $json['message']);
            }
        }
        $this->assertSame(0, $this->db->table('team_tasks')->countAllResults());
    }

    public function testNonAjaxWritesRedirectAndErrorsStayPlainText(): void
    {
        $this->requestAs(1, 'POST', 'clinic/teams/store', ['title' => 'Normal form'], json: false)->assertRedirectTo('/clinic/teams');
        $result = $this->requestAs(1, 'POST', 'clinic/teams/store', ['title' => ''], json: false);
        $result->assertStatus(422);
        $this->assertStringContainsString('text/plain', $result->response()->getHeaderLine('Content-Type'));
        $this->assertFalse($result->isRedirect());
    }
}
