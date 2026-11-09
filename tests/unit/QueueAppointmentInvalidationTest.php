<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Clinic\EncounterService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Scheduling\PersonalQueueInvalidation;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** Real no-show/authorization and owner code; only a guarded in-memory identity DB and queue doubles. */
final class QueueAppointmentInvalidationTest extends CIUnitTestCase
{
    private ?BaseConnection $identityDb = null;

    protected function setUp(): void
    {
        $bootstrap = realpath(dirname(__DIR__) . '/_support/fresh-framework-bootstrap.php');
        if (getenv('QUEUE_INVALIDATION_ISOLATED') !== '1'
            || !in_array($bootstrap, array_map('realpath', get_included_files()), true)) {
            throw new RuntimeException('Use the no-dotenv queue-invalidation-phpunit.xml configuration.');
        }
        parent::setUp();
        $config = config('Database');
        if ($config->defaultGroup !== 'tests' || $config->tests['DBDriver'] !== 'SQLite3'
            || $config->tests['database'] !== ':memory:' || $config->tests['DBPrefix'] !== 'queue_hint_') {
            throw new RuntimeException('Only the isolated in-memory identity fixture is permitted.');
        }
        $this->identityDb = Database::connect();
        foreach ([
            'tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)',
            'users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER, deleted_at TEXT)',
            'people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, deleted_at TEXT)',
            'employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)',
            'permissions (id INTEGER PRIMARY KEY, code TEXT)',
            'role_permissions (role TEXT, permission_id INTEGER)',
        ] as $definition) {
            [$table, $columns] = explode(' ', $definition, 2);
            $this->identityDb->query('CREATE TABLE ' . $this->identityDb->prefixTable($table) . ' ' . $columns);
        }
        $this->identityDb->table('tenants')->insert(['id' => 7, 'is_active' => 1]);
        $this->identityDb->table('people')->insert(['id' => 55, 'tenant_id' => 7, 'first_name' => 'Fixture', 'last_name' => 'Staff']);
        foreach ([3 => 'clinic_staff', 4 => 'counsellor'] as $id => $role) {
            $this->identityDb->table('users')->insert(['id' => $id, 'tenant_id' => 7, 'person_id' => 55,
                'username' => 'fixture_' . $id, 'role' => $role, 'status' => 'active', 'auth_version' => 1]);
        }
        foreach (['clinic.appointments.write', 'clinic.queue.manage', 'guidance.schedule.manage', 'guidance.queue.manage'] as $index => $permission) {
            $this->identityDb->table('permissions')->insert(['id' => $index + 1, 'code' => $permission]);
            $this->identityDb->table('role_permissions')->insert(['role' => $index < 2 ? 'clinic_staff' : 'counsellor', 'permission_id' => $index + 1]);
        }
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        $this->identityDb?->close();
        parent::tearDown();
    }

    private function fixture(string $unit = 'clinic', bool $linked = false): object
    {
        CurrentTenant::set(7);
        $state = (object) [
            'depth' => 0, 'healthy' => true, 'commit' => true, 'failAppointmentWrite' => false,
            'order' => [], 'sent' => [], 'publishedAtDepth' => [],
            'actor' => ['id' => $unit === 'clinic' ? 3 : 4, 'tenant_id' => 7, 'role' => $unit === 'clinic' ? 'clinic_staff' : 'counsellor'],
            'appointment' => ['id' => 20, 'tenant_id' => 7, 'unit' => $unit, 'person_id' => 88,
                'local_date' => '2020-01-02', 'starts_at' => '2020-01-02 09:00:00',
                'status' => $linked ? 'checked_in' : 'confirmed', 'version' => 5],
            'entries' => $linked ? [['id' => 12, 'tenant_id' => 7, 'unit' => $unit, 'person_id' => 88,
                'appointment_id' => 20, 'queue_date' => '2020-01-02', 'status' => 'waiting']] : [],
        ];
        $state->originalAppointment = $state->appointment;
        $state->originalEntries = $state->entries;
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->willReturnCallback(static fn ($name) => match ($name) {
            'transDepth' => $state->depth,
            'DBDriver' => 'MySQLi',
            'dateFormat' => ['datetime' => 'Y-m-d H:i:s', 'date' => 'Y-m-d'],
            default => null,
        });
        $db->method('prefixTable')->willReturnArgument(0);
        $db->method('protectIdentifiers')->willReturnArgument(0);
        $db->method('transStatus')->willReturnCallback(static fn () => $state->healthy);
        $db->method('transBegin')->willReturnCallback(static function () use ($state) { ++$state->depth; return true; });
        $db->method('transCommit')->willReturnCallback(static function () use ($state) {
            $state->order[] = 'commit';
            if ($state->commit) --$state->depth;
            CurrentTenant::set(99); // Delivery must retain the row's tenant/person, not ambient context.
            return $state->commit;
        });
        $db->method('transRollback')->willReturnCallback(static function () use ($state) {
            $state->order[] = 'rollback';
            $state->depth = 0;
            $state->appointment = $state->originalAppointment;
            $state->entries = $state->originalEntries;
            return true;
        });
        $db->method('error')->willReturn(['code' => 0]);
        $appointment = $this->createMock(BaseResult::class);
        $appointment->method('getRowArray')->willReturnCallback(static fn () => $state->appointment);
        $entries = $this->createMock(BaseResult::class);
        $entries->method('getResultArray')->willReturnCallback(static fn () => $state->entries);
        $tenant = $this->createMock(BaseResult::class);
        $tenant->method('getRowArray')->willReturn(['id' => 7]);
        $db->method('query')->willReturnCallback(static function ($sql, $params = []) use ($appointment, $entries, $tenant, $unit) {
            if (str_starts_with($sql, 'SET TRANSACTION')) return true;
            if (str_contains($sql, 'FROM appointments')) {
                self::assertSame([20, 7, $unit], $params);
                return $appointment;
            }
            if (str_contains($sql, 'FROM queue_entries')) {
                self::assertSame([7, 20], $params);
                return $entries;
            }
            if (str_contains($sql, 'FROM tenants')) return $tenant;
            throw new RuntimeException('Unexpected queue fixture query');
        });
        $appointmentBuilder = $this->createMock(BaseBuilder::class);
        $appointmentBuilder->method('where')->willReturnSelf();
        $appointmentBuilder->method('update')->willReturnCallback(static function (array $changes) use ($state) {
            $state->order[] = 'appointment-write';
            if ($state->failAppointmentWrite) return false;
            $state->appointment = array_replace($state->appointment, $changes);
            return true;
        });
        $queueBuilder = $this->createMock(BaseBuilder::class);
        $queueBuilder->method('where')->willReturnSelf();
        $queueBuilder->method('update')->willReturnCallback(static function (array $changes) use ($state) {
            $state->order[] = 'queue-write';
            $state->entries[0] = array_replace($state->entries[0], $changes);
            return true;
        });
        $clinical = $this->createMock(BaseBuilder::class);
        $clinical->method('where')->willReturnSelf();
        $clinical->method('countAllResults')->willReturn(0);
        $db->method('table')->willReturnCallback(static fn ($table) => match ($table) {
            'appointments' => $appointmentBuilder,
            'queue_entries' => $queueBuilder,
            default => $clinical,
        });
        $db->method('affectedRows')->willReturn(1);
        $state->db = $db;
        $state->queue = new QueueService(db: $db, invalidate: static function (...$hint) use ($state) {
            $state->order[] = 'publish';
            $state->sent[] = $hint;
            $state->publishedAtDepth[] = $state->depth;
        });
        return $state;
    }

    private function noShow(object $state): array
    {
        return $state->queue->markAppointmentNoShow($state->appointment['unit'], 20, 5, $state->actor);
    }

    private function appointmentHint(string $unit = 'clinic'): array
    {
        return [7, $unit, '2020-01-02', 88, PersonalQueueInvalidation::APPOINTMENTS_EVENT];
    }

    public static function units(): iterable
    {
        yield 'clinic' => ['clinic'];
        yield 'guidance' => ['guidance'];
    }

    #[DataProvider('units')]
    public function testNoTicketNoShowPublishesAppointmentOnlyHintAfterItsCommit(string $unit): void
    {
        $state = $this->fixture($unit);
        $result = $this->noShow($state);
        self::assertSame('no_show', $result['status']);
        self::assertSame(6, $result['version']);
        self::assertSame(['appointment-write', 'commit', 'publish'], $state->order);
        self::assertSame([$this->appointmentHint($unit)], $state->sent);
        self::assertSame([0], $state->publishedAtDepth);
        self::assertSame(99, CurrentTenant::id());
    }

    #[DataProvider('units')]
    public function testLinkedNoShowKeepsOnlyTheLegacyFourValueQueueHint(string $unit): void
    {
        $state = $this->fixture($unit, true);
        $result = $state->queue->markAppointmentNoShow($unit, 20, 5, $state->actor, 12);
        self::assertSame('no_show', $result['status']);
        self::assertSame('no_show', $state->entries[0]['status']);
        self::assertSame(['queue-write', 'appointment-write', 'commit', 'publish'], $state->order);
        self::assertSame([[7, $unit, '2020-01-02', 88]], $state->sent);
        self::assertSame([0], $state->publishedAtDepth);
    }

    public function testCapturedAppointmentEventSurvivesUntilTheExternalOwnerCommits(): void
    {
        $state = $this->fixture();
        $state->depth = 1;
        $hints = ['old' => ['discarded']];
        $state->queue->captureInvalidations(fn () => $this->noShow($state), $hints);
        self::assertSame([$this->appointmentHint()], array_values($hints));
        self::assertSame(['appointment-write'], $state->order);
        self::assertSame([], $state->sent);
        self::assertSame(1, $state->depth);
        self::assertTrue($state->db->transCommit());
        $state->queue->publishCommittedInvalidations($hints);
        self::assertSame(['appointment-write', 'commit', 'publish'], $state->order);
        self::assertSame([$this->appointmentHint()], $state->sent);
        self::assertSame([0], $state->publishedAtDepth);
    }

    public static function ownerOutcomes(): iterable
    {
        foreach (['clinic', 'guidance'] as $unit) {
            foreach (['commit', 'rollback', 'failed-commit'] as $outcome) {
                yield $unit . ' ' . $outcome => [$unit, $outcome];
            }
        }
    }

    #[DataProvider('ownerOutcomes')]
    public function testJoinedNoTicketNoShowWaitsForTheOutermostOwningTransaction(string $unit, string $outcome): void
    {
        $state = $this->fixture($unit);
        $state->commit = $outcome !== 'failed-commit';
        $owner = (new ReflectionClass($unit === 'clinic' ? EncounterService::class : GuidanceCaseService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($owner, 'db'))->setValue($owner, $state->db);
        (new ReflectionProperty($owner, 'queueService'))->setValue($owner, $state->queue);
        $transaction = new ReflectionMethod($owner, 'transaction');
        try {
            $transaction->invoke($owner, function () use ($state, $owner, $transaction, $unit, $outcome): array {
                $result = $unit === 'guidance'
                    ? $transaction->invoke($owner, fn () => $this->noShow($state))
                    : $this->noShow($state);
                self::assertSame([], $state->sent);
                self::assertSame(1, $state->depth);
                if ($outcome === 'rollback') throw new RuntimeException('outer mutation failed');
                return $result;
            });
            self::assertSame('commit', $outcome);
        } catch (RuntimeException $error) {
            self::assertNotSame('commit', $outcome, $error->getMessage());
        }
        self::assertSame(match ($outcome) {
            'commit' => ['appointment-write', 'commit', 'publish'],
            'rollback' => ['appointment-write', 'rollback'],
            default => ['appointment-write', 'commit', 'rollback'],
        }, $state->order);
        self::assertSame($outcome === 'commit' ? [$this->appointmentHint($unit)] : [], $state->sent);
        self::assertSame($outcome === 'commit' ? [0] : [], $state->publishedAtDepth);
        self::assertNull((new ReflectionProperty($state->queue, 'captureInvalidation'))->getValue($state->queue));
        self::assertSame([], (new ReflectionProperty($state->queue, 'invalidations'))->getValue($state->queue));
        // An unrelated later owner commit must not flush rolled-back hints.
        $state->commit = true;
        CurrentTenant::set(7);
        $transaction->invoke($owner, static fn () => []);
        self::assertSame($outcome === 'commit' ? [$this->appointmentHint($unit)] : [], $state->sent);
    }

    public function testFailedCaptureDiscardsAppointmentEventAndUncapturedJoinNeverRetainsIt(): void
    {
        $state = $this->fixture();
        $state->depth = 1;
        $hints = [];
        try {
            $state->queue->captureInvalidations(function () use ($state): void {
                $this->noShow($state);
                throw new RuntimeException('later failure');
            }, $hints);
            self::fail('Capture must fail');
        } catch (RuntimeException $error) {
            self::assertSame('later failure', $error->getMessage());
        }
        self::assertSame([], $hints);
        self::assertSame([], $state->sent);
        $state->db->transRollback();
        $state->depth = 1;
        $this->noShow($state); // No capture: the unknown external owner alone decides commit/rollback.
        self::assertSame([], $state->sent);
        self::assertSame([], (new ReflectionProperty($state->queue, 'invalidations'))->getValue($state->queue));
        $state->db->transRollback();
        (new ReflectionMethod($state->queue, 'transaction'))->invoke($state->queue, static fn () => null);
        self::assertSame([], $state->sent);
    }

    public function testFailedOwnCommitAndAppointmentWriteNeverPublish(): void
    {
        foreach (['commit', 'appointment-write'] as $failure) {
            $state = $this->fixture('clinic', $failure === 'appointment-write');
            $state->commit = $failure !== 'commit';
            $state->failAppointmentWrite = $failure === 'appointment-write';
            try {
                $this->noShow($state);
                self::fail('Mutation must fail');
            } catch (RuntimeException $error) {
                self::assertStringContainsString($failure === 'commit' ? 'Unable to commit queue' : 'Appointment changed', $error->getMessage());
            }
            self::assertSame([], $state->sent);
            self::assertSame('rollback', end($state->order));
            self::assertSame($state->originalAppointment, $state->appointment);
            self::assertSame($state->originalEntries, $state->entries);
            self::assertSame([], (new ReflectionProperty($state->queue, 'invalidations'))->getValue($state->queue));
        }
    }

    public function testStaleAndAlreadyNoShowAppointmentsDoNotWriteOrPublish(): void
    {
        foreach ([['version' => 6], ['status' => 'no_show']] as $changes) {
            $state = $this->fixture();
            $state->appointment = array_replace($state->appointment, $changes);
            try {
                $this->noShow($state);
                self::fail('Unchanged appointment must be rejected');
            } catch (RuntimeException $error) {
                self::assertSame(409, $error->getCode());
            }
            self::assertSame(['rollback'], $state->order);
            self::assertSame([], $state->sent);
        }
    }

    public function testRevokedSchedulingPermissionCannotWriteOrPublishDespiteSubmittedGrants(): void
    {
        $state = $this->fixture();
        $state->actor['permissions'] = ['clinic.appointments.write'];
        $this->identityDb->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', 1)->delete();
        try {
            $this->noShow($state);
            self::fail('Fresh authorization must reject the revoked grant');
        } catch (DomainException $error) {
            self::assertSame(403, $error->getCode());
        }
        self::assertSame(['rollback'], $state->order);
        self::assertSame([], $state->sent);
        self::assertSame($state->originalAppointment, $state->appointment);
    }
}
