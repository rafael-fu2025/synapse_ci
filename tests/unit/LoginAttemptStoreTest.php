<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DatabaseLoginAttemptStore;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/** Full migrations, real InnoDB locks, and synthetic counters only. */
final class LoginAttemptStoreTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    private DatabaseLoginAttemptStore $store;
    private const NOW = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('login_attempt_buckets')->emptyTable();
        $this->store = new DatabaseLoginAttemptStore($this->db);
    }

    private function buckets(string $identity = 'a', int $ipLimit = 60, int $identityLimit = 5): array
    {
        return [
            ['key' => hash('sha256', 'synthetic-ip'), 'limit' => $ipLimit, 'seconds' => 60],
            ['key' => hash('sha256', 'synthetic-ip-identity-' . $identity), 'limit' => $identityLimit, 'seconds' => 300],
        ];
    }

    public function testIdentityLimitExpiresWithoutSlidingOnRejectedRequests(): void
    {
        $buckets = $this->buckets();
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(0, $this->store->consume($buckets, self::NOW));
        }
        $this->assertSame(300, $this->store->consume($buckets, self::NOW));
        $this->assertSame(250, $this->store->consume($buckets, self::NOW + 50));
        $pair = $this->db->table('login_attempt_buckets')->where('bucket_hash', $buckets[1]['key'])->get()->getRowArray();
        $this->assertSame(5, (int) $pair['attempts']);
        $this->assertSame(self::NOW + 300, (int) $pair['expires_at']);
        $this->assertSame(0, $this->store->consume($buckets, self::NOW + 300));
        $this->assertSame(1, (int) $this->db->table('login_attempt_buckets')->where('bucket_hash', $buckets[1]['key'])->get()->getRowArray()['attempts']);
    }

    public function testBlockedIpCannotCreateNewIdentityBuckets(): void
    {
        $this->assertSame(0, $this->store->consume($this->buckets('a', 2, 99), self::NOW));
        $this->assertSame(0, $this->store->consume($this->buckets('b', 2, 99), self::NOW));
        $this->assertSame(60, $this->store->consume($this->buckets('c', 2, 99), self::NOW));
        $this->assertSame(59, $this->store->consume($this->buckets('d', 2, 99), self::NOW + 1));
        $this->assertSame(3, $this->db->table('login_attempt_buckets')->countAllResults());
        $this->assertSame(0, $this->store->consume($this->buckets('c', 2, 99), self::NOW + 60));
    }

    public function testCleanupIsBoundedAndDoesNotDeleteUnexpiredCounters(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->db->table('login_attempt_buckets')->insert([
                'bucket_hash' => hash('sha256', 'expired-' . $i), 'attempts' => 3,
                'expires_at' => self::NOW - 86401,
            ]);
        }
        $this->assertSame(0, $this->store->consume($this->buckets(), self::NOW));
        $this->assertSame(3, $this->db->table('login_attempt_buckets')->countAllResults());
        $this->assertSame(1, $this->db->table('login_attempt_buckets')->where('expires_at <', self::NOW - 86400)->countAllResults());
        $this->assertSame(0, $this->store->consume($this->buckets(), self::NOW + 1));
        $this->assertSame(2, $this->db->table('login_attempt_buckets')->countAllResults());
    }

    public function testCallerTransactionIsRefusedWithoutCommittingIt(): void
    {
        $this->db->transBegin();
        try {
            $this->store->consume($this->buckets(), self::NOW);
            $this->fail('Nested login limiter transaction was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('independent', $e->getMessage());
            $this->assertSame(1, $this->db->transDepth);
            $this->assertSame(0, $this->db->table('login_attempt_buckets')->countAllResults());
        } finally {
            $this->db->transRollback();
        }
    }

    public function testStoreFailureRollsBackAndSameConnectionCanRecover(): void
    {
        $this->db->query("CREATE TRIGGER login_counter_failure BEFORE UPDATE ON login_attempt_buckets
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic counter failure'");
        try {
            try {
                $this->store->consume($this->buckets(), self::NOW);
                $this->fail('Synthetic storage failure was not propagated.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Unable to persist login limit.', $e->getMessage());
            }
            $this->assertSame(0, $this->db->transDepth);
            $this->assertSame(0, $this->db->table('login_attempt_buckets')->countAllResults());
        } finally {
            $this->db->query('DROP TRIGGER login_counter_failure');
        }
        $this->assertSame(0, $this->store->consume($this->buckets(), self::NOW));
    }

    public function testMigrationRetryPreservesCountersAndExpectedSchema(): void
    {
        $this->store->consume($this->buckets(), self::NOW);
        $before = $this->db->table('login_attempt_buckets')->orderBy('bucket_hash')->get()->getResultArray();
        require_once APPPATH . 'Database/Migrations/2026-10-19-000005_CreateLoginAttemptBuckets.php';
        (new \App\Database\Migrations\CreateLoginAttemptBuckets(\Config\Database::forge($this->db)))->up();
        $this->assertSame($before, $this->db->table('login_attempt_buckets')->orderBy('bucket_hash')->get()->getResultArray());
        $fields = $this->db->query('SHOW COLUMNS FROM login_attempt_buckets')->getResultArray();
        $this->assertSame(['bucket_hash', 'attempts', 'expires_at'], array_column($fields, 'Field'));
    }

    public function testConcurrentLastAttemptAllowsExactlyOneWorker(): void
    {
        $workers = [];
        $buckets = $this->buckets('race', 60, 1);
        $this->db->table('login_attempt_buckets')->insert([
            'bucket_hash' => $buckets[0]['key'], 'attempts' => 0, 'expires_at' => self::NOW + 60,
        ]);
        $this->db->transBegin();
        $this->db->query('SELECT bucket_hash FROM login_attempt_buckets WHERE bucket_hash = ? FOR UPDATE', [$buckets[0]['key']]);
        try {
            for ($i = 0; $i < 2; ++$i) {
                $process = proc_open([PHP_BINARY, TESTPATH . '_support/login-rate-race-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[$i] = ['process' => $process, 'pipes' => $pipes, 'closed' => false];
                stream_set_timeout($pipes[1], 10);
                $ready = json_decode((string) fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
                $this->assertArrayHasKey('ready', $ready);
                $workers[$i]['id'] = $ready['ready'];
            }
            $this->assertNotSame($workers[0]['id'], $workers[1]['id']);
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], json_encode(['buckets' => $buckets, 'now' => self::NOW], JSON_THROW_ON_ERROR) . "\n");
                fflush($worker['pipes'][0]);
            }
            $deadline = microtime(true) + 3;
            do {
                $rows = $this->db->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID IN (?, ?)',
                    [$workers[0]['id'], $workers[1]['id']])->getResultArray();
                $blocked = count(array_filter($rows, static fn (array $row): bool => str_contains((string) $row['INFO'], 'INSERT INTO')
                    && str_contains((string) $row['INFO'], 'login_attempt_buckets')));
                if ($blocked === 2) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both workers must overlap at the IP bucket mutex.');
            $this->db->transCommit();
            $waits = [];
            foreach ($workers as &$worker) {
                $line = fgets($worker['pipes'][1]);
                $reply = json_decode((string) $line, true);
                $this->assertIsArray($reply, (string) stream_get_contents($worker['pipes'][2]));
                $waits[] = $reply['wait'];
                foreach ($worker['pipes'] as $pipe) { fclose($pipe); }
                $exit = proc_close($worker['process']);
                $worker['closed'] = true;
                $this->assertSame(0, $exit);
            }
            unset($worker);
            sort($waits);
            $this->assertSame([0, 300], $waits);
            $pair = $this->db->table('login_attempt_buckets')->where('bucket_hash', $buckets[1]['key'])->get()->getRowArray();
            $this->assertSame(1, (int) $pair['attempts']);
        } finally {
            if ($this->db->transDepth > 0) { $this->db->transRollback(); }
            foreach ($workers as $worker) {
                if ($worker['closed']) { continue; }
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                proc_close($worker['process']);
            }
        }
    }
}
