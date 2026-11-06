<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Api\TokenService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/** Guarded multi-connection refresh coverage on a runner-owned disposable MySQL schema. */
final class ApiTokenConcurrencyTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\\Database\\Seeds\\PermissionsAndRolesSeeder';

    private int $personId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('MySQLi', $this->db->DBDriver);
        self::assertSame(HUMUS_FRESH_DATABASE_VERIFIED, $this->db->query('SELECT DATABASE() AS name')->getRowArray()['name']);
        CurrentTenant::set(1);
        $this->personId = (int) (new PersonModel($this->db))->insert([
            'tenant_id' => 1, 'kind' => 'student', 'mis_id' => 'refresh-race-' . bin2hex(random_bytes(6)),
            'first_name' => 'Refresh', 'last_name' => 'Race',
        ]);
        $this->userId = (int) (new UserModel($this->db))->insert([
            'tenant_id' => 1, 'person_id' => $this->personId, 'username' => 'refresh-race-' . bin2hex(random_bytes(6)),
            'role' => 'student', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function race(string $refreshToken): array
    {
        $workers = [];
        try {
            foreach ([1, 2] as $_) {
                $process = proc_open([PHP_BINARY, ROOTPATH . 'tests/_support/api-token-race-worker.php'],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, ROOTPATH);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                stream_set_timeout($pipes[2], 20);
                fwrite($pipes[0], json_encode([
                    'database' => config('Database')->tests,
                    'refresh_token' => $refreshToken,
                ], JSON_THROW_ON_ERROR) . "\n");
                fflush($pipes[0]);
                self::assertSame("READY\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fflush($pipes[0]);
                fclose($pipes[0]);
            }
            $results = [];
            foreach ($workers as [, $pipes]) {
                $line = fgets($pipes[1]);
                self::assertNotFalse($line, 'Refresh worker timed out or exited without a result.');
                $stderr = stream_get_contents($pipes[2]);
                self::assertSame('', $stderr, 'Refresh worker reported an exception: ' . $stderr);
                $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertContains($result['status'] ?? null, ['rotated', 'replayed']);
                $results[] = $result;
            }
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                $status = proc_get_status($process);
                if ($status['running']) proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public function testFailedSuccessorInsertRollsBackRetirement(): void
    {
        $identity = (new AuthenticatedUser())->forIdentity($this->userId, 1);
        self::assertNotNull($identity);
        $service = new TokenService(db: $this->db);
        $issued = $service->issue($identity, 'rollback-test', '127.0.0.1');
        $trigger = 'reject_refresh_successor_' . bin2hex(random_bytes(4));
        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON api_tokens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic successor rejection'");
        try {
            try {
                $service->rotate($issued['refresh_token']);
                self::fail('A failed successor insert must surface as a service failure.');
            } catch (\Throwable $error) {
                self::assertSame('Refresh successor could not be issued.', $error->getMessage());
            }
        } finally {
            $this->db->query("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $rows = $this->db->table('api_tokens')->where('user_id', $this->userId)->get()->getResultArray();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['rotated_at']);
        self::assertNull($rows[0]['revoked_at']);
        self::assertSame(0, $this->db->transDepth);
    }

    public function testConcurrentRefreshHasOneWinnerAndReplayRevokesItsSuccessor(): void
    {
        $identity = (new AuthenticatedUser())->forIdentity($this->userId, 1);
        self::assertNotNull($identity);
        $issued = (new TokenService(db: $this->db))->issue($identity, 'concurrency-test', '127.0.0.1');

        $results = $this->race($issued['refresh_token']);
        self::assertSame(1, count(array_filter($results, static fn (array $row): bool => $row['status'] === 'rotated')));
        self::assertSame(1, count(array_filter($results, static fn (array $row): bool => $row['status'] === 'replayed')));

        $rows = $this->db->table('api_tokens')->where('user_id', $this->userId)->orderBy('id')->get()->getResultArray();
        self::assertCount(2, $rows, 'Concurrent reuse must never create two successors.');
        self::assertNotNull($rows[0]['rotated_at']);
        self::assertNotNull($rows[0]['revoked_at']);
        self::assertNotNull($rows[1]['revoked_at'], 'The losing replay is a compromise signal for the whole family.');

        $winner = array_values(array_filter($results, static fn (array $row): bool => $row['status'] === 'rotated'))[0];
        self::assertNull((new TokenService(db: $this->db))->rotate($winner['token']['refresh_token']));
        self::assertCount(2, $this->db->table('api_tokens')->where('user_id', $this->userId)->get()->getResultArray());
    }
}
