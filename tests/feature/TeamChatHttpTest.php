<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Migrations\AddTeamChatReferences;
use App\Database\Migrations\CreateTeamChat;
use App\Database\Migrations\CreateTeamTasks;
use App\Services\AuthenticatedUser;
use App\Services\TeamTaskService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Real routes and filters, isolated migrated SQLite only. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamChatHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'chat_http_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        require_once ROOTPATH . 'tests/_support/TeamsNotificationFixture.php';
        \Tests\Support\TeamsNotificationFixture::install($this->db);
        $this->assertSame(':memory:', $this->db->database);
        $this->db->query('CREATE TABLE chat_http_tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE chat_http_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE chat_http_employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE chat_http_users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE chat_http_permissions (id INTEGER PRIMARY KEY, code TEXT)');
        $this->db->query('CREATE TABLE chat_http_role_permissions (role TEXT, permission_id INTEGER)');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => 'clinic_staff', 2 => 'clinic_staff', 3 => 'clinic_admin', 4 => 'counsellor',
            5 => 'facilities_op', 6 => 'student', 7 => 'superadmin', 8 => 'clinic_staff'] as $id => $role) {
            $tenant = $id === 8 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'first_name' => 'Person', 'last_name' => (string) $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'user' . $id, 'role' => $role, 'status' => 'active']);
        }
        require_once APPPATH . 'Database/Migrations/2026-10-18-000002_CreateTeamTasks.php';
        require_once APPPATH . 'Database/Migrations/2026-10-19-000002_CreateTeamChat.php';
        (new CreateTeamTasks())->up();
        (new CreateTeamChat())->up();
        require_once APPPATH . 'Database/Migrations/2026-10-25-000001_AddTeamChatReferences.php';
        (new AddTeamChatReferences())->up();
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
        // Deliberately forged cache: authorization must refresh the persisted role.
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
            ->withHeaders(($json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [])
                + ($method === 'POST' ? ['Content-Type' => 'application/x-www-form-urlencoded'] : []))
            ->call($method, $path, $data);
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

    private function input(int $n = 1, array $changes = []): array
    {
        return $changes + ['body' => 'Message ' . $n, 'client_nonce' => sprintf('00000000-0000-4000-8000-%012d', $n)];
    }

    private function seed(int $n, int $sender = 2, string $unit = 'clinic', int $tenant = 1): int
    {
        $this->db->table('team_chat_messages')->insert(['tenant_id' => $tenant, 'unit' => $unit,
            'sender_user_id' => $sender, 'client_nonce' => $this->input($n)['client_nonce'],
            'body' => 'Message ' . $n, 'task_id' => null, 'created_at' => '2026-10-01 16:15:00']);
        return (int) $this->db->insertID();
    }

    public function testAllFiveRoutesForEveryDepartment(): void
    {
        foreach (['clinic' => 1, 'guidance' => 4, 'facilities' => 5] as $unit => $actor) {
            $base = "$unit/teams/chat";
            $page = $this->requestAs($actor, 'GET', $base, json: false);
            $page->assertStatus(200);
            $this->assertStringContainsString('no-store', $page->response()->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('private', $page->response()->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('assets/js/team-chat.js', $page->getBody());
            $empty = $this->json($this->requestAs($actor, 'GET', "$base/history"), 200)['data'];
            $this->assertSame([], $empty['messages']);
            $this->assertSame(0, $empty['unread_count']);
            $this->assertSame(['tasks' => []], $this->json($this->requestAs($actor, 'GET', "$base/tasks"), 200)['data']);
            $message = $this->json($this->requestAs($actor, 'POST', "$base/send", $this->input(changes: ['task_id' => ''])), 200)['data']['message'];
            $this->assertNull($message['task_id']);
            $history = $this->json($this->requestAs($actor, 'GET', "$base/history"), 200)['data'];
            $this->assertSame([$message['id']], array_column($history['messages'], 'id'));
            $this->assertSame(0, $history['unread_count'], 'Own messages are not unread.');
            $read = $this->json($this->requestAs($actor, 'POST', "$base/read", ['last_read_id' => $message['id']]), 200)['data'];
            $this->assertSame($message['id'], $read['last_read_id']);
        }
    }

    public function testDepartmentTenantAndAuthenticationBoundaries(): void
    {
        $id = $this->seed(1);
        $this->seed(2, 8, tenant: 2);
        $this->seed(3, 4, 'guidance');
        foreach (['GET' => ['', '/history', '/tasks'], 'POST' => ['/send', '/read']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                foreach ([4, 5, 6] as $actor) {
                    $this->requestAs($actor, $method, 'clinic/teams/chat' . $suffix, $this->input())->assertStatus(403);
                }
            }
        }
        foreach ([1 => [1], 8 => [2]] as $actor => $expected) {
            $history = $this->json($this->requestAs($actor, 'GET', 'clinic/teams/chat/history'), 200)['data'];
            $this->assertSame($expected, array_column($history['messages'], 'id'));
        }
        $this->json($this->requestAs(8, 'POST', 'clinic/teams/chat/read', ['last_read_id' => $id]), 422);
        $this->db->table('users')->where('id', 1)->update(['auth_version' => 2]);
        foreach (['GET' => ['', '/history', '/tasks'], 'POST' => ['/send', '/read']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $this->requestAs(1, $method, 'clinic/teams/chat' . $suffix, $this->input())->assertStatus(401);
            }
        }
        $this->withSession([])->withHeaders([])->get('clinic/teams/chat')->assertRedirectTo('/auth/login');
        $this->assertSame(3, $this->db->table('team_chat_messages')->countAllResults());
    }

    public function testTaskRoutesScopeSearchAndAuthorizationInEveryDepartment(): void
    {
        $active = [];
        foreach (['clinic' => 1, 'guidance' => 4, 'facilities' => 5] as $unit => $actor) {
            session()->set($this->identity($actor));
            $active[$unit] = (new TeamTaskService())->create($unit, ['title' => 'Needle ' . $unit, 'description' => 'PRIVATE']);
            $done = (new TeamTaskService())->create($unit, ['title' => 'Needle done']);
            $this->db->table('team_tasks')->where('id', $done['id'])->update(['status' => 'done']);
        }
        session()->set($this->identity(8));
        $foreign = (new TeamTaskService())->create('clinic', ['title' => 'Needle foreign']);
        foreach (['clinic' => 1, 'guidance' => 4, 'facilities' => 5] as $unit => $actor) {
            $path = "$unit/teams/chat/tasks";
            $expected = ['tasks' => [['id' => $active[$unit]['id'], 'title' => 'Needle ' . $unit]]];
            $this->assertSame($expected, $this->json($this->requestAs($actor, 'GET', "$path?q=Needle"), 200)['data']);
            $this->assertSame($expected, $this->json($this->requestAs(7, 'GET', $path), 200)['data']);
            $this->assertSame(['tasks' => []], $this->json($this->requestAs($actor, 'GET', "$path?q=missing"), 200)['data']);
            $this->json($this->requestAs($actor, 'GET', "$path?q[]=bad"), 422);
            foreach ([1, 4, 5, 6] as $outsider) {
                if ($outsider !== $actor) { $this->requestAs($outsider, 'GET', $path)->assertStatus(403); }
            }
            $this->db->table('users')->where('id', $actor)->update(['status' => 'suspended']);
            $this->requestAs($actor, 'GET', $path)->assertStatus(401);
            $this->db->table('users')->where('id', $actor)->update(['status' => 'active', 'auth_version' => 2]);
            $this->requestAs($actor, 'GET', $path)->assertStatus(401);
            $this->db->table('users')->where('id', $actor)->update(['auth_version' => 1]);
            $this->withSession([])->withHeaders([])->get($path)->assertRedirectTo('/auth/login');
        }
        $this->assertSame(['tasks' => [['id' => $foreign['id'], 'title' => 'Needle foreign']]],
            $this->json($this->requestAs(8, 'GET', 'clinic/teams/chat/tasks?q=Needle'), 200)['data']);
        $this->assertSame(0, $this->db->table('team_chat_messages')->countAllResults());
        $this->assertSame(0, $this->db->table('team_chat_reads')->countAllResults());
    }

    public function testFormPostRoundTripsMultipleTasksMentionsAndReply(): void
    {
        session()->set($this->identity(1));
        $first = (new TeamTaskService())->create('clinic', ['title' => 'First task']);
        $second = (new TeamTaskService())->create('clinic', ['title' => 'Second task']);
        $parent = $this->seed(10);
        $input = $this->input(11, ['body' => '😀 @Person 2',
            'task_ids' => [(string) $first['id'], (string) $second['id']],
            'mentions' => [['user_id' => '2', 'start' => '3', 'length' => '9']],
            'reply_to_id' => (string) $parent]);
        $message = $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', $input), 200)['data']['message'];
        $this->assertSame($input['body'], $message['body']);
        $this->assertSame([['id' => $first['id'], 'title' => 'First task'],
            ['id' => $second['id'], 'title' => 'Second task']], $message['tasks']);
        $this->assertSame($first['id'], $message['task_id']);
        $this->assertSame('First task', $message['task_title']);
        $this->assertSame([['user_id' => 2, 'start' => 3, 'length' => 9, 'name' => 'Person 2']], $message['mentions']);
        $this->assertSame(['id' => $parent, 'sender_name' => 'Person 2', 'body' => 'Message 10'], $message['reply_to']);
        $this->assertSame($message, $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', $input), 200)['data']['message']);
        $history = $this->json($this->requestAs(1, 'GET', 'clinic/teams/chat/history'), 200)['data'];
        $this->assertSame($message, $history['messages'][1]);
        $stored = $this->db->table('team_chat_messages')->where('id', $message['id'])->get()->getRowArray();
        $this->assertSame([$first['id'], $second['id']], json_decode($stored['task_ids'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([['user_id' => 2, 'start' => 3, 'length' => 9]], json_decode($stored['mentions'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($parent, (int) $stored['reply_to_id']);
        $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', array_replace($input,
            ['mentions' => [['user_id' => '3', 'start' => '3', 'length' => '9']]])), 409);
        $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', array_replace($input,
            ['client_nonce' => $this->input(12)['client_nonce'], 'mentions' => [['user_id' => '2', 'start' => '1', 'length' => '9']]])), 422);
        $this->assertSame(2, $this->db->table('team_chat_messages')->countAllResults());
    }

    public function testSendAndReadRequireCsrfInEveryDepartment(): void
    {
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            foreach (['send', 'read'] as $action) {
                foreach ([[], [csrf_token() => str_repeat('0', 64)]] as $data) {
                    $json = $this->json($this->requestAs(7, 'POST', "$unit/teams/chat/$action", $data, csrf: false), 403);
                    $this->assertTrue($json['reload']);
                }
                try {
                    $result = $this->requestAs(7, 'GET', "$unit/teams/chat/$action");
                    $this->assertContains($result->response()->getStatusCode(), [404, 405]);
                } catch (\CodeIgniter\Exceptions\PageNotFoundException $e) {
                    $this->assertStringContainsString("Can't find a route", $e->getMessage());
                }
            }
        }
        $this->assertSame(0, $this->db->table('team_chat_messages')->countAllResults());
        $this->assertSame(0, $this->db->table('team_chat_reads')->countAllResults());
    }

    public function testInvalidHistoryCursorsAndFixedChronologicalPages(): void
    {
        for ($i = 1; $i <= 85; $i++) { $this->seed($i); }
        foreach (['before=0', 'before=-1', 'after=-1', 'after=1.5', 'before[]=1', 'before=01',
            'before=99999999999999999999999999', 'before=2&after=0', 'limit=100'] as $query) {
            $this->json($this->requestAs(1, 'GET', 'clinic/teams/chat/history?' . $query), 422);
        }
        $latest = $this->json($this->requestAs(1, 'GET', 'clinic/teams/chat/history'), 200)['data'];
        $this->assertSame(range(46, 85), array_column($latest['messages'], 'id'));
        $this->assertTrue($latest['has_more']);
        $this->assertSame(46, $latest['next_before']);
        $older = $this->json($this->requestAs(1, 'GET', 'clinic/teams/chat/history?before=46'), 200)['data'];
        $this->assertSame(range(6, 45), array_column($older['messages'], 'id'));
        $after = $this->json($this->requestAs(1, 'GET', 'clinic/teams/chat/history?after=0'), 200)['data'];
        $this->assertSame(range(1, 40), array_column($after['messages'], 'id'));
        $this->assertNull($after['next_before']);
        $this->assertSame(0, $this->db->table('team_chat_reads')->countAllResults());
    }

    public function testReadAcknowledgementIsMonotonicScopedAndPerUser(): void
    {
        $first = $this->seed(1);
        $last = $this->seed(2);
        $foreign = $this->seed(3, 4, 'guidance');
        foreach ([$last, $first, 0] as $cursor) {
            $read = $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/read', ['last_read_id' => $cursor]), 200)['data'];
            $this->assertSame($last, $read['last_read_id']);
            $this->assertSame(0, $read['unread_count']);
        }
        foreach ([$foreign, 999, -1, '01'] as $cursor) {
            $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/read', ['last_read_id' => $cursor]), 422);
        }
        $other = $this->json($this->requestAs(3, 'GET', 'clinic/teams/chat/history'), 200)['data'];
        $this->assertSame(0, $other['last_read_id']);
        $this->assertSame(2, $other['unread_count']);
    }

    public function testNonceRetryConflictAndOnlyActiveSameScopeTask(): void
    {
        session()->set($this->identity(1));
        $task = (new TeamTaskService())->create('clinic', ['title' => 'Active task']);
        session()->set($this->identity(4));
        $foreign = (new TeamTaskService())->create('guidance', ['title' => 'Other department']);
        session()->set($this->identity(8));
        $tenant = (new TeamTaskService())->create('clinic', ['title' => 'Other tenant']);
        $input = $this->input(changes: ['task_id' => (string) $task['id']]);
        $first = $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', $input), 200)['data']['message'];
        $retry = $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', $input), 200)['data']['message'];
        $this->assertSame($first, $retry);
        foreach ([['body' => 'Changed'], ['task_id' => '']] as $changes) {
            $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', array_replace($input, $changes)), 409);
        }
        foreach ([$foreign['id'], $tenant['id'], 999] as $id) {
            $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', $this->input(2, ['task_id' => $id])), 422);
        }
        $this->db->table('team_tasks')->where('id', $task['id'])->update(['status' => 'done']);
        $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/send', $this->input(3, ['task_id' => $task['id']])), 422);
        $this->assertSame(1, $this->db->table('team_chat_messages')->countAllResults());
    }

    public function testInitialUnreadBacklogStartsAtFirstUnseenWithoutAcknowledging(): void
    {
        for ($i = 1; $i <= 85; $i++) { $this->seed($i); }
        $this->json($this->requestAs(1, 'POST', 'clinic/teams/chat/read', ['last_read_id' => 3]), 200);
        $page = $this->requestAs(1, 'GET', 'clinic/teams/chat', json: false);
        $page->assertStatus(200);
        $dom = new \DOMDocument();
        @$dom->loadHTML($page->getBody());
        $xpath = new \DOMXPath($dom);
        $initial = json_decode($xpath->query('//*[@data-team-chat]')->item(0)->getAttribute('data-initial'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($initial['after_mode']);
        $this->assertSame(range(4, 43), array_column($initial['messages'], 'id'));
        $this->assertSame(82, $initial['unread_count']);
        $this->assertSame(3, (int) $this->db->table('team_chat_reads')->get()->getRowArray()['last_read_message_id']);
    }

    public function testViewEscapesContentAndStorageErrorsAreSanitized(): void
    {
        $attack = '<img src=x onerror="window.chatXss=1">';
        $this->db->table('people')->where('id', 2)->update(['first_name' => $attack]);
        session()->set($this->identity(1));
        $task = (new TeamTaskService())->create('clinic', ['title' => $attack]);
        $message = $this->json($this->requestAs(2, 'POST', 'clinic/teams/chat/send', $this->input(changes: ['body' => $attack, 'task_id' => $task['id']])), 200)['data']['message'];
        $this->assertSame($attack, $message['body']);
        $page = $this->requestAs(1, 'GET', 'clinic/teams/chat', json: false);
        $page->assertStatus(200);
        $this->assertStringNotContainsString($attack, $page->getBody());
        $this->assertStringContainsString('&lt;img', $page->getBody());
        $this->db->query('DROP TABLE chat_http_team_chat_messages');
        $this->db->resetDataCache();
        foreach (['GET' => ['/history', '/tasks'], 'POST' => ['/send', '/read']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $input = $method === 'GET' ? [] : ($suffix === '/read' ? ['last_read_id' => 0] : $this->input());
                $json = $this->json($this->requestAs(1, $method, 'clinic/teams/chat' . $suffix, $input), 503);
                $this->assertSame('Team chat is temporarily unavailable. Ask the administrator to verify chat migrations or retry later.', $json['message']);
                $this->assertStringNotContainsString('chat_http_', json_encode($json));
                $this->assertStringNotContainsString($attack, json_encode($json));
            }
        }
    }
}
