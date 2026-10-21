<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateTeamChat;
use App\Database\Migrations\CreateTeamTasks;
use App\Services\AuthenticatedUser;
use App\Services\TeamChatService;
use App\Services\TeamTaskService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

/** Private in-memory schema, real migrations and refreshed workstation authentication. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamChatServiceIntegrationTest extends CIUnitTestCase
{
    private TeamChatService $chat;
    private TeamTaskService $tasks;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'isolated_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
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
        require_once APPPATH . 'Database/Migrations/2026-10-19-000002_CreateTeamChat.php';
        (new CreateTeamTasks())->up();
        (new CreateTeamChat())->up();
        $this->tasks = new TeamTaskService();
        $this->chat = new TeamChatService();
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

    private function fails(int $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected RuntimeException ' . $code); }
        catch (RuntimeException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    private function input(string $body = 'Hello'): array
    {
        return ['body' => $body, 'client_nonce' => sprintf('01234567-89ab-4cde-8fab-%012d', ++$this->sequence)];
    }

    public function testContextDelegatesAuthoritativeDepartmentMembership(): void
    {
        $this->assertSame($this->tasks->context('clinic'), $this->chat->context('clinic'));
        $this->assertSame([1, 2, 3, 4, 9], array_column($this->chat->context('clinic')['members'], 'id'));
        $this->signIn(9);
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            $this->assertTrue($this->chat->context($unit)['canManage']);
            $this->assertSame($this->tasks->context($unit), $this->chat->context($unit));
            $message = $this->chat->send($unit, $this->input($unit));
            $this->assertSame([$message], $this->chat->history($unit)['messages']);
        }
        $this->signIn(8);
        $this->assertFalse($this->chat->context('facilities')['canManage']);
        $this->chat->send('facilities', $this->input());
        $this->signIn(6);
        $this->chat->send('guidance', $this->input());
        $this->fails(403, fn () => $this->chat->history('clinic'));
    }

    public function testEmptyChannelAndSummaryDoNotCreateDraftsOrReadRows(): void
    {
        $this->assertSame(['messages' => [], 'has_more' => false, 'next_before' => null,
            'last_read_id' => 0, 'unread_count' => 0, 'latest_id' => 0], $this->chat->history('clinic'));
        $this->assertSame(0, $this->db->table('team_chat_reads')->countAllResults());
        $this->assertSame(['last_read_id' => 0, 'unread_count' => 0, 'latest_id' => 0],
            $this->chat->markRead('clinic', ['last_read_id' => 0]));
    }

    public function testImmutableMessageShapeUtcAndNonceRetryContents(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Staff meeting', 'description' => 'NOT EXPOSED']);
        $input = $this->input("  Text <b>not HTML</b>\n😀  ") + ['task_id' => (string) $task['id']];
        $before = gmdate('Y-m-d H:i:s');
        $message = $this->chat->send('clinic', $input);
        $this->assertSame(['id', 'body', 'task_id', 'task_title', 'sender_user_id', 'sender_name', 'created_at', 'client_nonce'], array_keys($message));
        $this->assertSame($input['body'], $message['body']);
        $this->assertSame($task['id'], $message['task_id']);
        $this->assertSame('Staff meeting', $message['task_title']);
        $this->assertSame('Person 1', $message['sender_name']);
        $this->assertSame(1, $message['sender_user_id']);
        $this->assertGreaterThanOrEqual($before, $message['created_at']);
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $message['created_at']);
        $this->assertSame($message, $this->chat->send('clinic', $input));
        $this->assertSame($message, $this->chat->send('clinic', array_replace($input, ['client_nonce' => strtoupper($input['client_nonce'])])));
        $this->fails(409, fn () => $this->chat->send('clinic', array_replace($input, ['body' => trim($input['body'])])));
        $this->fails(409, fn () => $this->chat->send('clinic', array_replace($input, ['task_id' => null])));
        $this->tasks->move('clinic', $task['id'], ['status' => 'done', 'version' => 1]);
        $this->assertSame($message, $this->chat->send('clinic', $input));
        $this->assertSame(1, $this->db->table('team_chat_messages')->countAllResults());
        $this->signIn(2);
        $other = $this->chat->send('clinic', ['body' => 'Independent sender', 'client_nonce' => $input['client_nonce']]);
        $this->assertNotSame($message['id'], $other['id']);
        $this->assertNull($this->chat->history('clinic')['messages'][0]['client_nonce']);
    }

    public function testConcurrentNonceWinnerIsRereadAndComparedAfterFailedInsert(): void
    {
        $task = $this->tasks->create('clinic', ['title' => 'Concurrent link']);

        $attempts = 0;
        $this->db->connID->createFunction('count_chat_insert', function () use (&$attempts): int { return ++$attempts; });
        $this->db->query('CREATE TRIGGER count_chat_attempt BEFORE INSERT ON isolated_team_chat_messages
            BEGIN SELECT count_chat_insert(); END');
        foreach ([true, false] as $identical) {
            (new \ReflectionProperty($this->db, 'DBDebug'))->setValue($this->db, $identical);
            $input = $this->input('Retry text') + ['task_id' => $task['id']];
            $injected = false;
            $listener = function ($query) use ($input, $identical, &$injected): void {
                if (!$injected && str_starts_with($query->getQuery(), 'SELECT')
                    && str_contains($query->getQuery(), 'isolated_team_tasks')) {
                    $injected = true;
                    // The lookup has returned. Simulate a separately committed winner on
                    // this private in-memory connection, then resume the losing transaction.
                    $this->db->transCommit();
                    $insert = $this->db->table('team_chat_messages')->set([
                        'tenant_id' => 1, 'unit' => 'clinic', 'sender_user_id' => 1,
                        'client_nonce' => $input['client_nonce'], 'body' => $identical ? $input['body'] : 'Winner text',
                        'task_id' => $input['task_id'], 'created_at' => gmdate('Y-m-d H:i:s'),
                    ])->getCompiledInsert();
                    $this->db->connID->exec($insert);
                    $this->db->transBegin();
                }
            };
            \CodeIgniter\Events\Events::on('DBQuery', $listener);
            try {
                if ($identical) {
                    $message = $this->chat->send('clinic', $input);
                    $this->assertSame($input['body'], $message['body']);
                } else {
                    $this->fails(409, fn () => $this->chat->send('clinic', $input));
                }
            } finally {
                \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
            }
            $this->assertTrue($injected);
            $this->assertSame(0, $this->db->transDepth);
            $this->assertTrue($this->db->transStatus());
        }
        $this->assertSame(4, $attempts, 'Both requests attempted INSERT after the winner, exercising duplicate recovery.');
        $this->assertSame(2, $this->db->table('team_chat_messages')->countAllResults());
    }

    public function testTenantUnitSenderNonceAndAllReadScopes(): void
    {
        $input = $this->input('Tenant 1 secret');
        $first = $this->chat->send('clinic', $input);
        $this->signIn(11);
        $this->assertSame([], $this->chat->history('clinic')['messages']);
        $this->fails(422, fn () => $this->chat->markRead('clinic', ['last_read_id' => $first['id']]));
        $second = $this->chat->send('clinic', $input);
        $this->assertNotSame($first['id'], $second['id']);
        $this->chat->markRead('clinic', ['last_read_id' => $second['id']]);
        $this->signIn(9);
        $this->assertSame([], $this->chat->history('guidance')['messages']);
        $this->fails(422, fn () => $this->chat->markRead('guidance', ['last_read_id' => $first['id']]));
        $third = $this->chat->send('guidance', $input);
        $this->assertSame([$third], $this->chat->history('guidance')['messages']);
        $this->signIn(1);
        $history = $this->chat->history('clinic');
        $this->assertSame([$first], $history['messages']);
        $this->assertSame(0, $history['last_read_id']);
        $this->assertSame($first['id'], $history['latest_id']);
    }

    public function testSameSenderNonceCanBeUsedIndependentlyInEveryDepartment(): void
    {
        $this->signIn(9);
        $input = $this->input('Shared nonce, separate channels');
        $ids = [];
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            $message = $this->chat->send($unit, $input);
            $this->assertSame($message, $this->chat->send($unit, $input));
            $this->assertSame([$message], $this->chat->history($unit)['messages']);
            $ids[] = $message['id'];
        }
        $this->assertCount(3, array_unique($ids));
    }

    public function testTasksMustBeSameScopeAndActiveAndNeverExposeDescriptions(): void
    {
        $clinic = $this->tasks->create('clinic', ['title' => 'Clinic', 'description' => 'PRIVATE']);
        $this->signIn(11);
        $foreign = $this->tasks->create('clinic', ['title' => 'FOREIGN SECRET']);
        $this->fails(422, fn () => $this->chat->send('clinic', $this->input() + ['task_id' => $clinic['id']]));
        $this->signIn(9);
        $guidance = $this->tasks->create('guidance', ['title' => 'GUIDANCE SECRET']);
        foreach ([$guidance['id'], $foreign['id'], 9999] as $id) {
            $this->fails(422, fn () => $this->chat->send('clinic', $this->input() + ['task_id' => $id]));
        }
        $this->tasks->move('clinic', $clinic['id'], ['status' => 'done', 'version' => 1]);
        $this->fails(422, fn () => $this->chat->send('clinic', $this->input() + ['task_id' => $clinic['id']]));
        $this->assertSame(0, $this->db->table('team_chat_messages')->countAllResults());
        $this->tasks->move('clinic', $clinic['id'], ['status' => 'blocked', 'version' => 2]);
        $message = $this->chat->send('clinic', $this->input() + ['task_id' => $clinic['id']]);
        $this->assertSame('Clinic', $message['task_title']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($message));
    }

    public function testReadCursorIsMonotonicAndUnreadExcludesSelf(): void
    {
        $own = $this->chat->send('clinic', $this->input('Own'));
        $this->signIn(2);
        $first = $this->chat->send('clinic', $this->input('Other'));
        $last = $this->chat->send('clinic', $this->input('Other 2'));
        $this->signIn(1);
        $this->assertSame(2, $this->chat->history('clinic')['unread_count']);
        $this->assertSame(0, $this->chat->history('clinic')['last_read_id']);
        $this->assertSame(['last_read_id' => $first['id'], 'unread_count' => 1, 'latest_id' => $last['id']],
            $this->chat->markRead('clinic', ['last_read_id' => $first['id']]));
        foreach ([0, $own['id'], $first['id']] as $id) {
            $this->assertSame($first['id'], $this->chat->markRead('clinic', ['last_read_id' => $id])['last_read_id']);
        }
        $this->fails(422, fn () => $this->chat->markRead('clinic', ['last_read_id' => $last['id'] + 1000]));
        $this->assertSame(0, $this->chat->markRead('clinic', ['last_read_id' => $last['id']])['unread_count']);
        $this->assertSame(1, $this->db->table('team_chat_reads')->countAllResults());
        $this->signIn(2);
        $this->assertSame(1, $this->chat->history('clinic')['unread_count']);
        $this->assertSame(0, $this->chat->history('clinic')['last_read_id']);
    }

    public function testNewestBeforeAndPollingPagesAreBoundedAscendingWithoutLoss(): void
    {
        $ids = [];
        for ($i = 0; $i < 85; ++$i) { $ids[] = $this->chat->send('clinic', $this->input('Message ' . $i))['id']; }
        $latest = $ids[84];
        $page = $this->chat->history('clinic');
        $this->assertSame(array_slice($ids, 45), array_column($page['messages'], 'id'));
        $this->assertTrue($page['has_more']);
        $this->assertSame($ids[45], $page['next_before']);
        $page = $this->chat->history('clinic', ['before' => (string) $page['next_before']]);
        $this->assertSame(array_slice($ids, 5, 40), array_column($page['messages'], 'id'));
        $this->assertSame($latest, $page['latest_id']);
        $page = $this->chat->history('clinic', ['before' => $page['next_before']]);
        $this->assertSame(array_slice($ids, 0, 5), array_column($page['messages'], 'id'));
        $this->assertFalse($page['has_more']);
        $this->assertNull($page['next_before']);
        $seen = [];
        $cursor = 0;
        do {
            $page = $this->chat->history('clinic', ['after' => $cursor]);
            $batch = array_column($page['messages'], 'id');
            $seen = array_merge($seen, $batch);
            $cursor = max($batch);
            $this->assertSame($latest, $page['latest_id']);
            $this->assertNull($page['next_before']);
        } while ($page['has_more']);
        $this->assertSame($ids, $seen);
        $this->assertSame([], $this->chat->history('clinic', ['after' => $latest])['messages']);
        $this->assertSame([], $this->chat->history('clinic', ['before' => 1])['messages']);
        $this->assertSame(0, $page['last_read_id']);
    }

    public function testConcurrentReadInitializationCannotOverwriteHigherCursor(): void
    {
        $first = $this->chat->send('clinic', $this->input('000123'));
        $last = $this->chat->send('clinic', $this->input('1e200'));
        $this->assertSame('000123', $first['body']);
        $this->assertSame('1e200', $last['body']);
        $injected = false;
        $listener = function ($query) use ($last, &$injected): void {
            if (!$injected && str_starts_with($query->getQuery(), 'INSERT')
                && str_contains($query->getQuery(), 'isolated_team_chat_reads')) {
                $injected = true;
                $this->db->table('team_chat_reads')->where('tenant_id', 1)->where('unit', 'clinic')->where('user_id', 1)
                    ->update(['last_read_message_id' => $last['id']]);
            }
        };
        \CodeIgniter\Events\Events::on('DBQuery', $listener);
        try {
            $summary = $this->chat->markRead('clinic', ['last_read_id' => $first['id']]);
        } finally {
            \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
        }
        $this->assertTrue($injected);
        $this->assertSame($last['id'], $summary['last_read_id']);
        $this->assertSame($last['id'], $this->chat->markRead('clinic', ['last_read_id' => 0])['last_read_id']);
        $this->assertSame(1, $this->db->table('team_chat_reads')->countAllResults());
    }

    public function testFailedInsertIsSanitizedAndRollsBackWithoutPoisoningConnection(): void
    {
        $this->db->query("CREATE TRIGGER reject_chat BEFORE INSERT ON isolated_team_chat_messages
            BEGIN SELECT RAISE(ABORT, 'Internal storage detail'); END");
        $this->fails(503, fn () => $this->chat->send('clinic', $this->input('Never expose this body')));
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
        $this->assertSame(0, $this->db->table('team_chat_messages')->countAllResults());
        $this->db->query('DROP TRIGGER reject_chat');
        $this->assertSame('Recovered', $this->chat->send('clinic', $this->input('Recovered'))['body']);
    }

    public function testStrictCursorsAndUnsupportedInput(): void
    {
        foreach ([['before' => 0], ['before' => null], ['after' => null], ['after' => -1], ['after' => false],
            ['before' => []], ['after' => '01'], ['before' => '1e2'], ['after' => 1.0], ['after' => ' 1'],
            ['before' => 1, 'after' => 0], ['limit' => 100], ['page' => 2]] as $filters) {
            $this->fails(422, fn () => $this->chat->history('clinic', $filters));
        }
        foreach ([[], ['last_read_id' => null], ['last_read_id' => true], ['last_read_id' => '01'],
            ['last_read_id' => 0, 'user_id' => 2]] as $input) {
            $this->fails(422, fn () => $this->chat->markRead('clinic', $input));
        }
        $this->fails(422, fn () => $this->chat->send('clinic', $this->input() + ['attachments' => []]));
    }

    public function testFormerMemberAndForeignPeopleNamesCannotLeak(): void
    {
        $this->chat->send('clinic', $this->input());
        $this->signIn(2);
        $this->db->table('users')->where('id', 1)->update(['role' => 'counsellor']);
        $this->assertSame('Former member', $this->chat->history('clinic')['messages'][0]['sender_name']);
        $this->db->table('users')->where('id', 1)->update(['role' => 'clinic_staff', 'status' => 'suspended']);
        $this->assertSame('Former member', $this->chat->history('clinic')['messages'][0]['sender_name']);
        $this->db->table('users')->where('id', 1)->update(['status' => 'active']);
        $this->db->table('people')->where('id', 1)->update(['tenant_id' => 2, 'first_name' => 'FOREIGN SECRET']);
        $message = $this->chat->history('clinic')['messages'][0];
        $this->assertSame('user1', $message['sender_name']);
        $this->assertNull($message['client_nonce']);
        $this->assertStringNotContainsString('FOREIGN SECRET', json_encode($this->chat->history('clinic')));
    }

    public function testRevocationIsCheckedEvenOnNonceRetriesAndReadWrites(): void
    {
        $input = $this->input();
        $this->chat->send('clinic', $input);
        $this->db->table('users')->where('id', 1)->update(['role' => 'counsellor']);
        $this->fails(403, fn () => $this->chat->send('clinic', $input));
        $this->fails(403, fn () => $this->chat->history('clinic'));
        $this->fails(403, fn () => $this->chat->markRead('clinic', ['last_read_id' => 0]));
        $this->db->table('users')->where('id', 1)->update(['role' => 'clinic_staff', 'auth_version' => 2]);
        $this->fails(401, fn () => $this->chat->send('clinic', $input));
        $this->signIn(2);
        session()->set('tenant_id', 2);
        $this->fails(401, fn () => $this->chat->history('clinic'));
        $this->signIn(3);
        $this->db->table('users')->where('id', 3)->update(['status' => 'suspended']);
        $this->fails(401, fn () => $this->chat->context('clinic'));
        $this->signIn(10);
        $this->fails(403, fn () => $this->chat->history('clinic'));
        $this->fails(422, fn () => $this->chat->context('bmg'));
        session()->remove(['user', '_auth']);
        $this->fails(401, fn () => $this->chat->history('clinic'));
    }

    public function testMissingAndIncompleteSchemaReturn503(): void
    {
        (new CreateTeamChat())->down();
        $this->db->resetDataCache();
        foreach ([fn () => $this->chat->context('clinic'), fn () => $this->chat->history('clinic'),
            fn () => $this->chat->send('clinic', $this->input()),
            fn () => $this->chat->markRead('clinic', ['last_read_id' => 0])] as $call) {
            $this->fails(503, $call);
        }
        $this->db->query('CREATE TABLE isolated_team_chat_messages (id INTEGER PRIMARY KEY)');
        $this->db->resetDataCache();
        $this->fails(503, fn () => $this->chat->history('clinic'));
    }

    public function testAmbientTransactionsAreNotCommittedByChat(): void
    {
        $this->db->transBegin();
        $this->fails(409, fn () => $this->chat->send('clinic', $this->input()));
        $this->fails(409, fn () => $this->chat->markRead('clinic', ['last_read_id' => 0]));
        $this->assertSame(1, $this->db->transDepth);
        $this->db->transRollback();
        $this->assertSame(0, $this->db->table('team_chat_messages')->countAllResults());
    }

    public function testMigrationTypesKeysDefaultsAndCompositeTaskForeignKey(): void
    {
        $fields = $this->db->getFieldData('team_chat_messages');
        $types = [];
        foreach ($fields as $field) { $types[$field->name] = strtoupper($field->type); }
        $this->assertSame(['id', 'tenant_id', 'unit', 'sender_user_id', 'client_nonce', 'body', 'task_id', 'created_at'], array_keys($types));
        $this->assertSame('INTEGER', $types['id']);
        $this->assertSame('INT', $types['tenant_id']);
        $this->assertSame('VARCHAR', $types['unit']);
        $this->assertSame('VARCHAR', $types['client_nonce']);
        $this->assertSame('TEXT', $types['body']);
        $indexes = $this->db->getIndexData('team_chat_messages');
        $this->assertSame(['tenant_id', 'unit', 'sender_user_id', 'client_nonce'], $indexes['team_chat_nonce']->fields);
        $reads = $this->db->query('PRAGMA table_info(isolated_team_chat_reads)')->getResultArray();
        $this->assertSame([1, 2, 3, 0], array_map(static fn ($row) => (int) $row['pk'], $reads));
        $this->assertSame('0', (string) $reads[3]['dflt_value']);
        $task = $this->tasks->create('clinic', ['title' => 'Scoped']);
        $fk = $this->db->query('PRAGMA foreign_key_list(isolated_team_chat_messages)')->getResultArray();
        $taskFk = array_values(array_filter($fk, static fn ($row) => $row['table'] === 'isolated_team_tasks'));
        $this->assertSame(['tenant_id', 'unit', 'task_id'], array_column($taskFk, 'from'));
        $this->assertSame(['tenant_id', 'unit', 'id'], array_column($taskFk, 'to'));
        $this->assertSame([], $this->db->query('PRAGMA foreign_key_check')->getResultArray());
        $this->assertSame($task['id'], $this->chat->send('clinic', $this->input() + ['task_id' => $task['id']])['task_id']);
        try {
            $this->db->connID->exec("INSERT INTO isolated_team_chat_messages
                (tenant_id, unit, sender_user_id, client_nonce, body, task_id, created_at)
                VALUES (1, 'guidance', 9, '01234567-89ab-4cde-8fab-000000000999', 'Invalid scope', "
                . $task['id'] . ", '2026-10-19 00:00:00')");
            $this->fail('Composite task FK must reject a cross-department reference.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('FOREIGN KEY', $e->getMessage());
        }
        $this->assertSame(1, $this->db->table('team_chat_messages')->countAllResults());
    }
}
