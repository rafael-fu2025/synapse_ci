<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Migrations\AddTeamApiIdempotencyKeys;
use App\Database\Migrations\CreateTeamChat;
use App\Database\Migrations\CreateTeamEvents;
use App\Database\Migrations\CreateTeamTasks;
use App\Services\Api\ApiContext;
use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Real bearer routes on a private in-memory schema; no development database is used. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamApiHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /** @var array<int,string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'teamapi_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        config('TeamWorkspace')->enabled = true;
        $this->db = Database::connect();
        require_once ROOTPATH . 'tests/_support/TeamsNotificationFixture.php';
        \Tests\Support\TeamsNotificationFixture::install($this->db);
        $this->db->query('CREATE TABLE teamapi_tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE teamapi_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT,
            last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE teamapi_employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE teamapi_users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER,
            username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE teamapi_permissions (id INTEGER PRIMARY KEY, code TEXT)');
        $this->db->query('CREATE TABLE teamapi_role_permissions (role TEXT, permission_id INTEGER)');
        $this->db->query('CREATE TABLE teamapi_api_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            user_id INTEGER, auth_version INTEGER, token_hash TEXT, refresh_hash TEXT, family_id TEXT, device_label TEXT,
            created_ip TEXT, last_used_at TEXT, access_expires_at TEXT, refresh_expires_at TEXT, rotated_at TEXT,
            revoked_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        $roles = [1 => 'clinic_staff', 2 => 'clinic_admin', 3 => 'counsellor', 4 => 'facilities_op',
            5 => 'student', 6 => 'superadmin', 7 => 'clinic_staff'];
        foreach ($roles as $id => $role) {
            $tenant = $id === 7 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'kind' => 'employee',
                'first_name' => 'API', 'last_name' => 'User ' . $id, 'mis_id' => 'api-' . $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'api-user-' . $id, 'role' => $role, 'status' => 'active', 'auth_version' => 1]);
            $raw = str_pad(dechex($id), 64, (string) ($id % 10), STR_PAD_LEFT);
            $this->tokens[$id] = $raw;
            $this->db->table('api_tokens')->insert([
                'tenant_id' => $tenant, 'user_id' => $id, 'auth_version' => 1,
                'token_hash' => hash('sha256', $raw), 'refresh_hash' => hash('sha256', 'refresh-' . $raw),
                'family_id' => str_pad((string) $id, 32, '0'), 'access_expires_at' => '2099-01-01 00:00:00',
                'refresh_expires_at' => '2099-01-02 00:00:00', 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        require_once APPPATH . 'Database/Migrations/2026-10-18-000002_CreateTeamTasks.php';
        require_once APPPATH . 'Database/Migrations/2026-10-19-000001_CreateTeamEvents.php';
        require_once APPPATH . 'Database/Migrations/2026-10-19-000002_CreateTeamChat.php';
        require_once APPPATH . 'Database/Migrations/2026-10-25-000001_AddTeamChatReferences.php';
        require_once APPPATH . 'Database/Migrations/2026-11-06-001000_AddTeamApiIdempotencyKeys.php';
        (new CreateTeamTasks())->up();
        (new CreateTeamEvents())->up();
        (new CreateTeamChat())->up();
        (new \App\Database\Migrations\AddTeamChatReferences())->up();
        (new AddTeamApiIdempotencyKeys())->up();
    }

    protected function tearDown(): void
    {
        config('TeamWorkspace')->enabled = false;
        ApiContext::clear();
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function headers(int $user, bool $json = false): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokens[$user]] + ($json ? ['Content-Type' => 'application/json'] : []);
    }

    private function json($response, int $status): array
    {
        $response->assertStatus($status);
        $cache = $response->response()->getHeaderLine('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $decoded = json_decode((string) $response->getJSON(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    private function postJson(int $user, string $path, array $body, bool $contentType = true)
    {
        return $this->withHeaders($this->headers($user, $contentType))
            ->withBody((string) json_encode($body, JSON_THROW_ON_ERROR))->post($path);
    }

    public function testBearerOnlyRolloutAndUnitAuthorization(): void
    {
        $this->json($this->get('api/v1/team/clinic'), 401);
        config('TeamWorkspace')->enabled = false;
        $this->json($this->withHeaders($this->headers(1))->get('api/v1/team/clinic'), 404);
        config('TeamWorkspace')->enabled = true;

        foreach (['clinic' => 1, 'guidance' => 3, 'facilities' => 4] as $unit => $user) {
            $body = $this->json($this->withHeaders($this->headers($user))->get('api/v1/team/' . $unit), 200);
            $this->assertSame($unit, $body['data']['unit']);
        }
        $this->json($this->withHeaders($this->headers(1))->get('api/v1/team/guidance'), 403);
        $this->json($this->withHeaders($this->headers(5))->get('api/v1/team/clinic'), 403);
    }

    public function testMembersAreSearchableBoundedAndRejectUnknownQueries(): void
    {
        $body = $this->json($this->withHeaders($this->headers(1))
            ->get('api/v1/team/clinic/members?q=User&limit=1&page=2'), 200);
        $this->assertCount(1, $body['data']);
        $this->assertSame(1, $body['meta']['limit']);
        $this->assertGreaterThanOrEqual(2, $body['meta']['total']);
        $this->json($this->withHeaders($this->headers(1))->get('api/v1/team/clinic/members?tenant_id=2'), 422);
        $this->json($this->withHeaders($this->headers(1))->get('api/v1/team/clinic/members?limit=101'), 422);
    }

    public function testTaskCreateRequiresStrictJsonAndAtomicallyReplaysNonce(): void
    {
        $path = 'api/v1/team/clinic/tasks';
        $nonce = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $valid = ['title' => 'Bearer task', 'description' => '', 'status' => 'todo', 'priority' => 'normal',
            'assignee_user_id' => 1, 'due_date' => null, 'checklist' => [['text' => 'Step', 'done' => false]],
            'client_nonce' => $nonce];
        $this->json($this->postJson(1, $path, $valid, false), 400);
        $this->json($this->postJson(1, $path, $valid + ['tenant_id' => 2]), 422);
        $this->json($this->postJson(1, $path, array_diff_key($valid, ['client_nonce' => true])), 422);

        $first = $this->json($this->postJson(1, $path, $valid), 201);
        $again = $this->json($this->postJson(1, $path, $valid), 201);
        $this->assertSame($first, $again);
        $this->assertSame(1, $this->db->table('team_tasks')->countAllResults());
        $this->assertSame(1, $this->db->table('team_task_activity')->countAllResults());
        $conflict = $this->json($this->postJson(1, $path, array_replace($valid, ['title' => 'Changed'])), 409);
        $this->assertSame('idempotency_conflict', $conflict['error']['code']);
        $this->assertSame([
            'id', 'title', 'status', 'priority', 'assignee', 'due_date', 'checklist_progress',
            'version', 'can_edit', 'created_at', 'updated_at',
        ], array_keys($first['data']));
    }

    public function testCalendarUnionDetailMutationsAndIdempotencyContract(): void
    {
        $task = $this->json($this->postJson(1, 'api/v1/team/clinic/tasks', [
            'title' => 'Due task', 'due_date' => '2026-12-10',
            'client_nonce' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        ]), 201)['data'];
        $path = 'api/v1/team/clinic/calendar/events';
        $eventInput = [
            'title' => 'Calendar event', 'description' => 'Operational only', 'location' => 'Room 1',
            'starts_at' => '2026-12-10T09:00', 'ends_at' => '2026-12-10T10:00', 'participant_ids' => [1],
            'client_nonce' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        ];
        $first = $this->json($this->postJson(1, $path, $eventInput), 201);
        $again = $this->json($this->postJson(1, $path, $eventInput), 201);
        $this->assertSame($first, $again);
        $this->assertMatchesRegularExpression('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $first['data']['starts_at']);
        $this->assertArrayNotHasKey('tenant_id', $first['data']);
        $this->json($this->postJson(1, $path, array_replace($eventInput, ['title' => 'Changed'])), 409);

        $list = $this->json($this->withHeaders($this->headers(1))
            ->get('api/v1/team/clinic/calendar?date=2026-12-10&view=agenda'), 200);
        $types = array_values(array_unique(array_column($list['data'], 'type')));
        sort($types, SORT_STRING);
        $this->assertSame(['event', 'task_deadline'], $types);
        $this->assertSame('2026-12-10', array_values(array_filter($list['data'], static fn ($item) => $item['type'] === 'task_deadline'))[0]['due_date']);
        foreach (['date', 'view', 'scope', 'start_date', 'end_date', 'total_events', 'total_tasks', 'truncated'] as $field) {
            $this->assertArrayHasKey($field, $list['meta']);
        }

        $eventId = $first['data']['id'];
        $detail = $this->json($this->withHeaders($this->headers(1))
            ->get("api/v1/team/clinic/calendar/events/$eventId"), 200);
        $this->assertSame('Operational only', $detail['data']['description']);
        $stale = $this->json($this->postJson(1, "api/v1/team/clinic/calendar/events/$eventId/cancel", ['version' => 99]), 409);
        $this->assertSame('stale_version', $stale['error']['code']);
        $this->json($this->withHeaders($this->headers(7))->get("api/v1/team/clinic/calendar/events/$eventId"), 404);
    }

    public function testChatHistorySearchSendNonceAndMonotonicReadContract(): void
    {
        $task = $this->json($this->postJson(1, 'api/v1/team/clinic/tasks', [
            'title' => 'Chat searchable', 'client_nonce' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        ]), 201)['data'];
        $sendPath = 'api/v1/team/clinic/chat/messages';
        $send = [
            'body' => 'Hello team', 'client_nonce' => '12345678-1234-4234-8234-123456789abc',
            'task_ids' => [$task['id']], 'mentions' => [], 'reply_to_id' => null,
        ];
        $first = $this->json($this->postJson(1, $sendPath, $send), 201);
        $again = $this->json($this->postJson(1, $sendPath, $send), 201);
        $this->assertSame($first, $again);
        $this->assertSame(1, $this->db->table('team_chat_messages')->countAllResults());
        $this->json($this->postJson(1, $sendPath, array_replace($send, ['body' => 'Changed'])), 409);

        $history = $this->json($this->withHeaders($this->headers(1))->get('api/v1/team/clinic/chat/messages'), 200);
        $this->assertSame(['messages', 'has_more', 'next_before', 'last_read_id', 'unread_count', 'latest_id'], array_keys($history['data']));
        $messageId = $first['data']['id'];
        $around = $this->json($this->withHeaders($this->headers(1))->get("api/v1/team/clinic/chat/messages?around=$messageId"), 200);
        $this->assertSame($messageId, $around['data']['messages'][0]['id']);
        $search = $this->json($this->withHeaders($this->headers(1))->get('api/v1/team/clinic/chat/tasks?q=search'), 200);
        $this->assertSame(40, $search['meta']['limit']);
        $this->assertSame($task['id'], $search['data'][0]['id']);
        $read = $this->json($this->postJson(1, 'api/v1/team/clinic/chat/read', ['last_read_id' => $messageId]), 200);
        $this->assertSame($messageId, $read['data']['last_read_id']);
        $this->json($this->postJson(1, 'api/v1/team/clinic/chat/read', ['last_read_id' => 0]), 200);
        $this->assertSame($messageId, (int) $this->db->table('team_chat_reads')->get()->getRowArray()['last_read_message_id']);
    }

    public function testTaskCollectionDetailCommentVersionAndTenantIsolationContracts(): void
    {
        $nonce = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $created = $this->json($this->postJson(1, 'api/v1/team/clinic/tasks', [
            'title' => 'Contract task', 'client_nonce' => $nonce,
        ]), 201)['data'];
        $id = $created['id'];
        $list = $this->json($this->withHeaders($this->headers(1))->get('api/v1/team/clinic/tasks?page=1'), 200);
        $this->assertArrayHasKey('counts', $list['meta']);
        $this->assertSame('team', $list['meta']['scope']);
        $this->assertTrue($list['data'][0]['can_edit']);
        $detail = $this->json($this->withHeaders($this->headers(1))->get("api/v1/team/clinic/tasks/$id"), 200);
        foreach (['description', 'checklist', 'comments', 'activity', 'comments_total', 'comments_limit', 'activity_total', 'activity_limit'] as $field) {
            $this->assertArrayHasKey($field, $detail['data']);
        }
        $comment = ['body' => 'Once only', 'client_nonce' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
        $one = $this->json($this->postJson(1, "api/v1/team/clinic/tasks/$id/comments", $comment), 201);
        $two = $this->json($this->postJson(1, "api/v1/team/clinic/tasks/$id/comments", $comment), 201);
        $this->assertSame($one, $two);
        $this->assertSame(1, $this->db->table('team_task_comments')->countAllResults());

        $stale = $this->json($this->postJson(1, "api/v1/team/clinic/tasks/$id/status", ['status' => 'done', 'version' => 99]), 409);
        $this->assertSame('stale_version', $stale['error']['code']);
        $this->json($this->withHeaders($this->headers(7))->get("api/v1/team/clinic/tasks/$id"), 404);
        $this->json($this->withHeaders($this->headers(6))->get("api/v1/team/guidance/tasks/$id"), 404);
    }
}
