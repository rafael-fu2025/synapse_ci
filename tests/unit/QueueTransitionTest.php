<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrentTenant;
use App\Services\Scheduling\QueueService;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/_support/queue-race-worker.php';

/** Real InnoDB tests, deliberately independent of migrations and DatabaseTestTrait. */
final class QueueTransitionTest extends TestCase
{
    private ?BaseConnection $db = null;
    private QueueService $service;
    private const DATE = '2037-04-23';
    private const OLD = '2001-02-03 04:05:06';

    protected function setUp(): void
    {
        parent::setUp();
        try {
            \QueueIsolatedSafety::environment();
            $bootstrap = realpath(dirname(__DIR__) . '/_support/queue-isolated-bootstrap.php');
            if (!in_array($bootstrap, array_map('realpath', get_included_files()), true)) {
                throw new \RuntimeException('The isolated queue bootstrap must already have run.');
            }
            \QueueIsolatedSafety::configuration();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        // Use the actual default connection, not a test-only replacement hidden from the service.
        $db = \Config\Database::connect();
        \QueueIsolatedSafety::connection($db);
        $this->db = $db;
        CurrentTenant::set(1);
        $db->query('CREATE TABLE IF NOT EXISTS appointments (
            id INT PRIMARY KEY AUTO_INCREMENT, tenant_id INT NOT NULL, unit VARCHAR(20) NOT NULL,
            person_id INT NOT NULL DEFAULT 1, provider_user_id INT NULL, staff_assignment_id INT NULL,
            starts_at DATETIME NULL, ends_at DATETIME NULL, local_date DATE NULL,
            status VARCHAR(30) NOT NULL, type VARCHAR(30) NOT NULL DEFAULT \'routine\',
            version INT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB');
        $db->query('CREATE TABLE IF NOT EXISTS queue_entries (
            id INT PRIMARY KEY AUTO_INCREMENT, tenant_id INT NOT NULL, unit VARCHAR(20) NOT NULL,
            queue_date DATE NOT NULL, ticket_no INT NOT NULL, ticket_code VARCHAR(30) NOT NULL,
            person_id INT NOT NULL DEFAULT 1, appointment_id INT NULL,
            status VARCHAR(30) NOT NULL, priority VARCHAR(20) NOT NULL,
            called_at DATETIME NULL, called_by_user_id INT NULL, started_at DATETIME NULL,
            completed_at DATETIME NULL, skip_until DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX queue_pick (tenant_id, unit, queue_date, status)
        ) ENGINE=InnoDB');
        $db->query('DELETE FROM queue_entries');
        $db->query('DELETE FROM appointments');
        $this->service = new QueueService(null, null, $db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            while ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            $this->db->resetTransStatus();
            $this->db->query('DROP TRIGGER IF EXISTS queue_transition_fail');
            $this->db->query('DELETE FROM queue_entries');
            $this->db->query('DELETE FROM appointments');
            CurrentTenant::reset();
        }
        parent::tearDown();
    }

    private function ticket(array $overrides = []): int
    {
        static $number = 0;
        ++$number;
        $this->assertTrue($this->db->table('queue_entries')->insert(array_replace([
            'tenant_id' => 1, 'unit' => 'clinic', 'queue_date' => self::DATE,
            'ticket_no' => $number, 'ticket_code' => sprintf('C-%03d', $number),
            'person_id' => 1, 'status' => 'waiting', 'priority' => 'routine',
            'created_at' => self::OLD, 'updated_at' => self::OLD,
        ], $overrides)));
        return (int) $this->db->insertID();
    }

    private function appointment(array $overrides = []): int
    {
        $this->assertTrue($this->db->table('appointments')->insert(array_replace([
            'tenant_id' => 1, 'unit' => 'clinic', 'person_id' => 1,
            'starts_at' => self::DATE . ' 09:00:00', 'ends_at' => self::DATE . ' 09:30:00',
            'local_date' => self::DATE, 'status' => 'checked_in', 'version' => 11,
            'created_at' => self::OLD, 'updated_at' => self::OLD,
        ], $overrides)));
        return (int) $this->db->insertID();
    }

    private function row(int $id, string $table = 'queue_entries', ?BaseConnection $db = null): array
    {
        return ($db ?? $this->db)->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function rejected(callable $operation, int $code, string $message): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
            return;
        }
        $this->fail('Expected queue operation to be rejected.');
    }

    private function observer(): BaseConnection
    {
        \QueueIsolatedSafety::configuration();
        $db = \Config\Database::connect('tests', false);
        \QueueIsolatedSafety::connection($db);
        return $db;
    }

    public function testCallNextUsesPrioritySequenceAndIdAndScopesCandidates(): void
    {
        $excluded = [
            $this->ticket(['tenant_id' => 2, 'priority' => 'emergency']),
            $this->ticket(['unit' => 'guidance', 'priority' => 'emergency']),
            $this->ticket(['queue_date' => '2037-04-22', 'priority' => 'emergency']),
            $this->ticket(['status' => 'called', 'priority' => 'emergency']),
            $this->ticket(['status' => 'done', 'priority' => 'emergency']),
            $this->ticket(['status' => 'skipped', 'priority' => 'emergency']),
        ];
        $before = array_map(fn ($id) => $this->row($id), $excluded);
        $routine = $this->ticket(['ticket_no' => 1]);
        $urgentLate = $this->ticket(['priority' => 'urgent', 'ticket_no' => 9]);
        $urgentFirst = $this->ticket(['priority' => 'urgent', 'ticket_no' => 2]);
        $urgentTie = $this->ticket(['priority' => 'urgent', 'ticket_no' => 2]);
        $emergency = $this->ticket(['priority' => 'emergency', 'ticket_no' => 20]);
        foreach ([$emergency, $urgentFirst, $urgentTie, $urgentLate, $routine] as $id) {
            $result = $this->service->callNext('clinic', 7, self::DATE);
            $this->assertSame($id, (int) $result['id']);
            $this->assertSame('called', $result['status']);
            $this->assertSame(7, (int) $result['called_by_user_id']);
            $this->assertNotNull($result['called_at']);
            $this->assertSame('called', $this->row($id)['status']);
        }
        $this->assertNull($this->service->callNext('clinic', 7, self::DATE));
        $this->assertSame($before, array_map(fn ($id) => $this->row($id), $excluded));
    }

    public function testLifecycleSkipRecallAndCompletion(): void
    {
        $id = $this->ticket();
        $this->assertSame('called', $this->service->callTicket($id, 7, 'clinic')['status']);
        $before = SchedulingClock::now()->modify('+60 minutes')->format('Y-m-d H:i:s');
        $skip = $this->service->skipTicket($id, 60, 'clinic');
        $this->assertSame('skipped', $skip['status']);
        $this->assertGreaterThanOrEqual($before, $skip['skip_until']);
        $this->assertLessThanOrEqual(SchedulingClock::now()->modify('+60 minutes')->format('Y-m-d H:i:s'), $skip['skip_until']);
        $recalled = $this->service->recallTicket($id, 'clinic');
        $this->assertSame('waiting', $recalled['status']);
        foreach (['called_at', 'called_by_user_id', 'skip_until'] as $field) {
            $this->assertNull($recalled[$field]);
            $this->assertNull($this->row($id)[$field]);
        }
        // Legacy callers may omit the optional unit.
        $this->service->callTicket($id, 8);
        $this->assertSame('in_consultation', $this->service->startConsultation($id)['status']);
        $done = $this->service->completeConsultation($id);
        $this->assertSame('done', $done['status']);
        $this->assertNotNull($done['started_at']);
        $this->assertNotNull($done['completed_at']);
        $this->assertSame('done', $this->row($id)['status']);
    }

    public function testInvalidLifecycleEdgesLeaveEveryFieldUnchanged(): void
    {
        $allowed = [
            'call' => ['waiting'], 'start' => ['called', 'in_consultation'],
            'complete' => ['in_consultation', 'done'], 'skip' => ['called'], 'recall' => ['skipped'],
        ];
        foreach (['waiting', 'called', 'in_consultation', 'done', 'skipped'] as $status) {
            foreach ($allowed as $action => $states) {
                if (in_array($status, $states, true)) {
                    continue;
                }
                $id = $this->ticket(['status' => $status]);
                $before = $this->row($id);
                $this->rejected(fn () => match ($action) {
                    'call' => $this->service->callTicket($id, 7, 'clinic'),
                    'start' => $this->service->startConsultation($id, 'clinic'),
                    'complete' => $this->service->completeConsultation($id, 'clinic'),
                    'skip' => $this->service->skipTicket($id, 60, 'clinic'),
                    'recall' => $this->service->recallTicket($id, 'clinic'),
                }, 409, 'state changed');
                $this->assertSame($before, $this->row($id), "$action from $status mutated the row");
                $this->assertSame(0, $this->db->transDepth);
            }
        }
    }

    public function testNonpositiveActorsAndInvalidUnitsAreRejected(): void
    {
        $id = $this->ticket();
        $before = $this->row($id);
        foreach ([0, -1] as $actor) {
            $this->rejected(fn () => $this->service->callNext('clinic', $actor, self::DATE), 403, 'authenticated');
            $this->rejected(fn () => $this->service->callTicket($id, $actor, 'clinic'), 403, 'authenticated');
        }
        $this->rejected(fn () => $this->service->callNext('invalid', 7, self::DATE), 422, 'Invalid queue unit');
        $this->rejected(fn () => $this->service->callTicket($id, 7, 'invalid'), 422, 'Invalid queue unit');
        foreach ([0, -1, 1441] as $window) {
            $this->rejected(fn () => $this->service->skipTicket($id, $window, 'clinic'), 422, 'Recall window');
        }
        $this->assertSame($before, $this->row($id));
    }

    public function testEveryTransitionRejectsOtherTenantsWrongUnitsAndMissingTickets(): void
    {
        foreach (['foreign', 'wrong-unit', 'missing'] as $case) {
            foreach (['call', 'start', 'complete', 'skip', 'recall'] as $action) {
                $state = match ($action) {
                    'call' => 'waiting', 'complete' => 'in_consultation', 'recall' => 'skipped', default => 'called',
                };
                $id = $this->ticket(['tenant_id' => $case === 'foreign' ? 2 : 1,
                    'unit' => $case === 'wrong-unit' ? 'guidance' : 'clinic', 'status' => $state,
                    'skip_until' => '2099-01-01 00:00:00']);
                $before = $this->row($id);
                $target = $case === 'missing' ? 2147483647 : $id;
                $this->rejected(fn () => match ($action) {
                    'call' => $this->service->callTicket($target, 7, 'clinic'),
                    'start' => $this->service->startConsultation($target, 'clinic'),
                    'complete' => $this->service->completeConsultation($target, 'clinic'),
                    'skip' => $this->service->skipTicket($target, 60, 'clinic'),
                    'recall' => $this->service->recallTicket($target, 'clinic'),
                }, 404, 'not found');
                $this->assertSame($before, $this->row($id));
            }
        }
    }

    public function testExpiredOrMissingRecallDeadlineCannotReenterQueue(): void
    {
        foreach ([self::OLD, null] as $deadline) {
            $id = $this->ticket(['status' => 'skipped', 'skip_until' => $deadline]);
            $before = $this->row($id);
            $this->rejected(fn () => $this->service->recallTicket($id, 'clinic'), 409, 'expired');
            $this->assertSame($before, $this->row($id));
        }
    }

    public function testStartAndCompletionAreIdempotentWithoutTouchingTimestamps(): void
    {
        $id = $this->ticket(['status' => 'called', 'called_at' => self::OLD]);
        $this->service->startConsultation($id, 'clinic');
        $this->db->table('queue_entries')->where('id', $id)->update(['started_at' => self::OLD, 'updated_at' => self::OLD]);
        $before = $this->row($id);
        $this->assertSame($before, $this->service->startConsultation($id, 'clinic'));
        $this->assertSame($before, $this->row($id));
        $this->service->completeConsultation($id, 'clinic');
        $this->db->table('queue_entries')->where('id', $id)->update(['completed_at' => self::OLD, 'updated_at' => self::OLD]);
        $before = $this->row($id);
        $this->assertSame($before, $this->service->completeConsultation($id, 'clinic'));
        $this->assertSame($before, $this->row($id));
        $this->assertSame(self::OLD, $before['started_at']);
    }

    public function testLinkedAppointmentCompletesExactlyOnceWithOneVersionIncrement(): void
    {
        $appointment = $this->appointment();
        $id = $this->ticket(['status' => 'in_consultation', 'appointment_id' => $appointment, 'started_at' => self::OLD]);
        $result = $this->service->completeConsultation($id, 'clinic');
        $this->assertSame('done', $result['status']);
        $after = $this->row($appointment, 'appointments');
        $this->assertSame('completed', $after['status']);
        $this->assertSame(12, (int) $after['version']);
        $this->assertNotSame(self::OLD, $after['updated_at']);
        $queue = $this->row($id);
        $this->assertSame($result['completed_at'], $queue['completed_at']);
        $this->service->completeConsultation($id, 'clinic');
        $this->assertSame($after, $this->row($appointment, 'appointments'));
        $this->assertSame($queue, $this->row($id));
        $this->assertSame(0, $this->db->transDepth);
    }

    public function testLinkedAppointmentConflictsRollBackWithoutPartialCompletion(): void
    {
        foreach ([['status' => 'cancelled'], ['tenant_id' => 2], ['unit' => 'guidance'], ['deleted_at' => self::OLD]] as $conflict) {
            $appointment = $this->appointment($conflict);
            $id = $this->ticket(['status' => 'in_consultation', 'appointment_id' => $appointment]);
            $queue = $this->row($id);
            $appt = $this->row($appointment, 'appointments');
            $this->rejected(fn () => $this->service->completeConsultation($id, 'clinic'), 409, 'Linked appointment');
            $this->assertSame($queue, $this->row($id));
            $this->assertSame($appt, $this->row($appointment, 'appointments'));
            $this->assertSame(0, $this->db->transDepth);
            $this->assertTrue($this->db->transStatus());
        }
    }

    public function testSqlFailureAfterAppointmentUpdateRollsBackBothRowsAndConnectionRecovers(): void
    {
        $appointment = $this->appointment();
        $id = $this->ticket(['status' => 'in_consultation', 'appointment_id' => $appointment]);
        $queue = $this->row($id);
        $appt = $this->row($appointment, 'appointments');
        // A real server-side failure, after the service has updated the appointment.
        $this->db->query("CREATE TRIGGER queue_transition_fail BEFORE UPDATE ON queue_entries
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic queue write failure'");
        try {
            $this->service->completeConsultation($id, 'clinic');
            $this->fail('The server-side write failure must escape the service.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        } finally {
            $this->db->query('DROP TRIGGER queue_transition_fail');
        }
        $this->assertSame($queue, $this->row($id));
        $this->assertSame($appt, $this->row($appointment, 'appointments'));
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
        $this->assertSame('done', $this->service->completeConsultation($id, 'clinic')['status']);
        $this->assertSame(12, (int) $this->row($appointment, 'appointments')['version']);
    }

    public function testCallerOwnsCommitAndRollbackRestoresQueueAndAppointment(): void
    {
        $appointment = $this->appointment();
        $id = $this->ticket(['status' => 'in_consultation', 'appointment_id' => $appointment]);
        $queue = $this->row($id);
        $appt = $this->row($appointment, 'appointments');
        $observer = $this->observer();
        try {
            $this->assertTrue($this->db->transBegin());
            $this->service->completeConsultation($id, 'clinic');
            $this->assertSame(1, $this->db->transDepth, 'Service must not commit the caller transaction');
            $this->assertSame('done', $this->row($id)['status']);
            $this->assertSame('completed', $this->row($appointment, 'appointments')['status']);
            $this->assertSame($queue, $this->row($id, 'queue_entries', $observer));
            $this->assertSame($appt, $this->row($appointment, 'appointments', $observer));
            $this->assertTrue($this->db->transRollback());
            $this->assertSame($queue, $this->row($id));
            $this->assertSame($appt, $this->row($appointment, 'appointments'));
        } finally {
            $observer->close();
        }
    }

    private function workerMessage(array $worker): array
    {
        $line = fgets($worker['pipes'][1]);
        try {
            $message = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($message)) {
                return $message;
            }
        } catch (\JsonException) {
            // Report the actual startup/protocol failure, not just a JSON parser error.
        }
        stream_set_blocking($worker['pipes'][1], false);
        stream_set_blocking($worker['pipes'][2], false);
        $stdout = (string) $line . stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        $this->fail('Invalid queue worker response. stdout: ' . var_export($stdout, true)
            . '; stderr: ' . var_export($stderr, true));
    }

    public function testConcurrentCallNextOnDifferentConnectionsClaimsDistinctTickets(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the isolated concurrency test.');
        }
        $first = $this->ticket(['ticket_no' => 1]);
        $second = $this->ticket(['ticket_no' => 2]);
        $workers = [];
        try {
            $this->assertTrue($this->db->transBegin());
            $this->db->query('SELECT id FROM queue_entries WHERE id = ? FOR UPDATE', [$first]);
            for ($i = 0; $i < 2; ++$i) {
                // Match the harness's php -n runtime; workers must not load an operational php.ini.
                $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
                foreach (['mysqli', 'mbstring', 'intl'] as $extension) {
                    $command[] = '-d';
                    $command[] = 'extension=' . $extension;
                }
                $command[] = dirname(__DIR__) . '/_support/queue-race-worker.php';
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
                stream_set_timeout($pipes[1], 10);
                $ready = $this->workerMessage($workers[$i]);
                $this->assertArrayHasKey('ready', $ready);
                $workers[$i]['connection'] = (int) $ready['ready'];
            }
            $this->assertNotSame($workers[0]['connection'], $workers[1]['connection']);
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], json_encode(['operation' => 'callNext', 'date' => self::DATE], JSON_THROW_ON_ERROR) . "\n");
                fflush($worker['pipes'][0]);
            }
            // Keep the row locked until BOTH independent sessions execute their locking SELECT.
            $deadline = microtime(true) + 3;
            do {
                $queries = $this->db->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID IN (?, ?)',
                    [$workers[0]['connection'], $workers[1]['connection']])->getResultArray();
                $blocked = count(array_filter($queries, static fn ($row) => str_contains((string) $row['INFO'], 'FOR UPDATE')));
                if ($blocked === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both callers must overlap while the parent holds the first ticket lock');
            $this->assertTrue($this->db->transCommit());
            $claimed = [];
            foreach ($workers as &$worker) {
                $result = $this->workerMessage($worker);
                $this->assertArrayHasKey('id', $result);
                $claimed[] = (int) $result['id'];
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                $this->assertSame(0, proc_close($worker['process']));
                $worker['closed'] = true;
            }
            unset($worker);
            sort($claimed);
            $this->assertSame([$first, $second], $claimed);
            foreach ($claimed as $id) {
                $row = $this->row($id);
                $this->assertSame('called', $row['status']);
                $this->assertSame(7, (int) $row['called_by_user_id']);
                $this->assertNotNull($row['called_at']);
            }
            $this->assertNull($this->service->callNext('clinic', 7, self::DATE));
        } finally {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            foreach ($workers as $worker) {
                if (!empty($worker['closed'])) {
                    continue;
                }
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
        }
    }
}
