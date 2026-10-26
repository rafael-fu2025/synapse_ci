<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;

/** Real cancellation service, models, outbox and application routes; no migrations or external DB. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AppointmentCancellationSqliteTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'cancel_sqlite_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000];
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = config('Database')->default = $settings;
        $this->db = new AppointmentCancellationSqliteConnection($settings);
        // Register the same private connection for static service/model lookups, before any SQL.
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
            ->setValue(null, ['tests' => $this->db, 'default' => $this->db]);
        $this->db->initialize();
        $this->assertSame('SQLite3', Database::connect()->DBDriver);
        $this->assertSame(':memory:', Database::connect()->database);
        $this->assertSame($this->db, Database::connect('default'));
        require_once ROOTPATH . 'tests/_support/TeamsNotificationFixture.php';
        \Tests\Support\TeamsNotificationFixture::install($this->db);
        // Outbox delivery is a separate worker concern; recording remains enabled and real.
        config('WorkflowAlerts')->enabled = false;
        $this->schema();
        $this->seed();
        helper(['form', 'url']);
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function schema(): void
    {
        $definitions = [
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL REFERENCES cancel_sqlite_tenants(id),
                kind TEXT NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL, mis_id TEXT, email TEXT,
                created_at TEXT, updated_at TEXT, deleted_at TEXT, UNIQUE(tenant_id, id)',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL REFERENCES cancel_sqlite_tenants(id),
                person_id INTEGER, username TEXT NOT NULL UNIQUE, email TEXT, role TEXT NOT NULL, status TEXT NOT NULL,
                auth_version INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT, deleted_at TEXT,
                UNIQUE(tenant_id, id), FOREIGN KEY(tenant_id, person_id) REFERENCES cancel_sqlite_people(tenant_id, id)',
            'employee_profiles' => 'person_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, is_teaching INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY(tenant_id, person_id), FOREIGN KEY(tenant_id, person_id) REFERENCES cancel_sqlite_people(tenant_id, id)',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE',
            'role_permissions' => 'role TEXT NOT NULL, permission_id INTEGER NOT NULL REFERENCES cancel_sqlite_permissions(id),
                PRIMARY KEY(role, permission_id)',
            'appointments' => "id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES cancel_sqlite_tenants(id),
                unit TEXT NOT NULL CHECK(unit IN ('clinic','guidance')), person_id INTEGER NOT NULL,
                provider_user_id INTEGER, staff_assignment_id INTEGER, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL,
                local_date TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN
                    ('requested','scheduled','confirmed','checked_in','completed','cancelled','no_show')),
                type TEXT NOT NULL, reason TEXT, cancellation_reason TEXT CHECK(length(cancellation_reason) <= 255),
                booked_by_user_id INTEGER, confirmed_by_user_id INTEGER, confirmed_at TEXT,
                assigned_by_user_id INTEGER, assigned_at TEXT, version INTEGER NOT NULL DEFAULT 1 CHECK(version > 0),
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE(tenant_id, id), FOREIGN KEY(tenant_id, person_id) REFERENCES cancel_sqlite_people(tenant_id, id),
                FOREIGN KEY(tenant_id, provider_user_id) REFERENCES cancel_sqlite_users(tenant_id, id)",
            'workflow_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES cancel_sqlite_tenants(id),
                unit TEXT NOT NULL, event_key TEXT NOT NULL CHECK(length(event_key) = 64), event_kind TEXT NOT NULL,
                subject_kind TEXT NOT NULL, subject_id INTEGER NOT NULL, actor_user_id INTEGER,
                occurrence_key TEXT NOT NULL CHECK(length(occurrence_key) BETWEEN 1 AND 96),
                occurred_at TEXT NOT NULL, created_at TEXT NOT NULL, available_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0, processed_at TEXT, last_error TEXT,
                UNIQUE(tenant_id, event_key), FOREIGN KEY(tenant_id, actor_user_id) REFERENCES cancel_sqlite_users(tenant_id, id)',
        ];
        foreach ($definitions as $table => $columns) {
            $this->db->query('CREATE TABLE ' . $this->db->prefixTable($table) . ' (' . $columns . ')');
        }
        $this->db->query('ALTER TABLE cancel_sqlite_notifications ADD COLUMN workflow_event_id INTEGER REFERENCES cancel_sqlite_workflow_events(id)');
    }

    private function seed(): void
    {
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => 'student', 2 => 'clinic_staff', 3 => 'counsellor', 4 => 'student', 5 => 'clinic_staff'] as $id => $role) {
            $tenant = $id === 5 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant,
                'kind' => $role === 'student' ? 'student' : 'employee', 'first_name' => 'Fixture', 'last_name' => (string) $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'cancel_user_' . $id, 'role' => $role, 'status' => 'active']);
        }
        foreach ([1 => ['portal.appointments.create', 'student'], 2 => ['clinic.appointments.write', 'clinic_staff'],
            3 => ['guidance.schedule.manage', 'counsellor']] as $id => [$code, $role]) {
            $this->db->table('permissions')->insert(['id' => $id, 'code' => $code]);
            $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]);
        }
        $this->db->table('notifications')->insert(['tenant_id' => 1, 'recipient_person_id' => 1,
            'type' => 'fixture_existing', 'created_at' => '2026-10-01 00:00:00']);
    }

    private function actor(int $id): array
    {
        return $this->db->table('users')->where('id', $id)->get()->getRowArray() + ['logged_in' => true];
    }

    private function appointment(string $unit, string $status = 'requested', int $person = 1): int
    {
        $id = (new AppointmentModel())->insert(['tenant_id' => 1, 'unit' => $unit, 'person_id' => $person,
            'local_date' => '2099-01-01', 'starts_at' => '2099-01-01 09:00:00', 'ends_at' => '2099-01-01 09:30:00',
            'status' => $status, 'type' => $unit === 'clinic' ? 'routine' : 'initial', 'reason' => 'Original request', 'version' => 1]);
        $this->assertNotFalse($id);
        return (int) $id;
    }

    private function state(): array
    {
        $state = [];
        foreach (['appointments', 'workflow_events', 'notifications'] as $table) {
            $state[$table] = $this->db->table($table)->orderBy('id')->get()->getResultArray();
        }
        return $state;
    }

    private function submitCancellation(int $actor, string $unit, bool $personal, int $id, array $data)
    {
        foreach (['renderer', 'response', 'redirectresponse'] as $service) Services::resetSingle($service);
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        $path = $personal ? 'portal/appointments/' . $unit : $unit . '/appointments';
        return $this->withSession(['user' => $this->actor($actor), 'tenant_id' => 1,
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'application/x-www-form-urlencoded'])
            ->post($path . '/cancel/' . $id, $data + [config('Security')->tokenName => $hash]);
    }

    public static function contexts(): iterable
    {
        yield 'clinic patient' => ['clinic', true, 1];
        yield 'guidance patient' => ['guidance', true, 1];
        yield 'clinic staff' => ['clinic', false, 2];
        yield 'guidance staff' => ['guidance', false, 3];
    }

    private function invalidPayloads(): array
    {
        return ['missing' => [], 'null' => ['reason' => null], 'empty' => ['reason' => ''],
            'spaces' => ['reason' => '   '], 'ASCII whitespace' => ['reason' => "\t\r\n"],
            'Unicode whitespace' => ['reason' => "\u{00A0}\u{2003}\u{2028}\u{2029}\u{3000}"],
            'zero width' => ['reason' => "\u{200B}"], 'BOM' => ['reason' => "\u{FEFF}"],
            '256 ASCII characters' => ['reason' => str_repeat('a', 256)],
            '256 Unicode characters' => ['reason' => str_repeat('界', 256)],
            'invalid UTF-8' => ['reason' => "bad\xC3\x28"], 'NUL' => ['reason' => "bad\0"],
            'control' => ['reason' => "bad\x01"], 'DEL' => ['reason' => "bad\x7F"],
            'C1' => ['reason' => "bad\u{0085}"], 'array' => ['reason' => ['nested']]];
    }

    private function assertCancelled(int $id, string $unit, bool $personal, int $actor, string $reason): void
    {
        $row = (new AppointmentModel())->find($id);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(2, (int) $row['version']);
        $this->assertSame($reason, $row['cancellation_reason']);
        $events = $this->db->table('workflow_events')->where('subject_id', $id)->get()->getResultArray();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(1, (int) $event['tenant_id']);
        $this->assertSame($unit, $event['unit']);
        $this->assertSame('appointments', $event['subject_kind']);
        $this->assertSame($personal ? 'appointment_cancelled_staff' : 'appointment_cancelled_patient', $event['event_kind']);
        $this->assertSame($actor, (int) $event['actor_user_id']);
        $this->assertSame('2', $event['occurrence_key']);
        $this->assertNull($event['processed_at']);
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
    }

    #[DataProvider('contexts')]
    public function testServiceRejectsInvalidReasonsWithoutWritesAndPersistsValidReasons(string $unit, bool $personal, int $actor): void
    {
        $service = new AppointmentService();
        $id = $this->appointment($unit);
        $before = $this->state();
        foreach ($this->invalidPayloads() as $label => $payload) {
            if (is_array($payload['reason'] ?? null)) continue; // HTTP-only malformed scalar contract.
            try {
                $service->cancelAppointment($id, $payload['reason'] ?? '', $unit, $this->actor($actor), $personal);
                $this->fail('Accepted invalid reason: ' . $label);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Cancellation reason must', $e->getMessage(), $label);
            }
            $this->assertSame($before, $this->state(), $label);
            $this->assertSame(0, $this->db->transDepth);
        }
        $this->assertSame(0, $this->db->isolationStatements);
        foreach (['0', 'Schedule conflict', str_repeat('a', 255), str_repeat('界', 255)] as $reason) {
            foreach (['requested', 'scheduled', 'confirmed'] as $status) {
                $id = $this->appointment($unit, $status);
                $this->assertTrue($service->cancelAppointment($id, " \u{00A0}" . $reason . "\u{3000} ", $unit, $this->actor($actor), $personal));
                $this->assertCancelled($id, $unit, $personal, $actor, $reason);
            }
        }
        $this->assertSame(12, $this->db->isolationStatements);
        $this->assertSame(12, $this->db->lockedReads);
        $this->assertSame($before['notifications'], $this->state()['notifications']);
    }

    #[DataProvider('contexts')]
    public function testOutboxStorageFailureRollsBackRealAppointmentWrites(string $unit, bool $personal, int $actor): void
    {
        $id = $this->appointment($unit);
        $before = $this->state();
        $this->db->query("CREATE TRIGGER cancel_event_failure BEFORE INSERT ON cancel_sqlite_workflow_events
            BEGIN SELECT RAISE(ABORT, 'Fixture outbox storage failure'); END");
        $failure = null;
        try {
            (new AppointmentService())->cancelAppointment($id, 'Patient requested cancellation', $unit, $this->actor($actor), $personal);
        } catch (\Throwable $e) {
            $failure = $e;
        }
        $this->assertNotNull($failure, 'The real outbox insert must fail.');
        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertSame('Unable to record workflow event.', $failure->getMessage());
        $this->assertSame(503, $failure->getCode());
        $this->assertSame(1, $this->db->isolationStatements);
        $this->assertSame(1, $this->db->lockedReads);
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
        $this->assertSame($before, $this->state());
        $this->db->query('DROP TRIGGER cancel_event_failure');
        $this->assertTrue((new AppointmentService())->cancelAppointment($id, 'Retry with a valid reason', $unit, $this->actor($actor), $personal));
        $this->assertCancelled($id, $unit, $personal, $actor, 'Retry with a valid reason');
    }

    #[DataProvider('contexts')]
    public function testRealRoutesRejectMissingAndInvalidReasonsWithoutFallbackAndPersistValidReasons(string $unit, bool $personal, int $actor): void
    {
        $id = $this->appointment($unit);
        $before = $this->state();
        foreach ($this->invalidPayloads() as $label => $payload) {
            $response = $this->submitCancellation($actor, $unit, $personal, $id, $payload);
            $this->assertSame(422, $response->response()->getStatusCode(), $label . ': ' . $response->getBody());
            $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($json['success']);
            $this->assertStringContainsString($label === 'array' ? 'Invalid reason value.' : 'Cancellation reason must', $json['message']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $this->assertSame($before, $this->state(), $label);
        }
        foreach (['0', 'Schedule conflict', str_repeat('界', 255)] as $reason) {
            $id = $this->appointment($unit);
            $response = $this->submitCancellation($actor, $unit, $personal, $id, ['reason' => " \u{00A0}" . $reason . "\u{3000} "]);
            $this->assertSame(200, $response->response()->getStatusCode(), $response->getBody());
            $this->assertTrue(json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR)['success']);
            $this->assertCancelled($id, $unit, $personal, $actor, $reason);
        }
        $this->assertSame($before['notifications'], $this->state()['notifications']);
    }
}

/** Test-only dialect adapter; this fixture makes no MySQL locking/concurrency claims. */
final class AppointmentCancellationSqliteConnection extends Connection
{
    public int $isolationStatements = 0;
    public int $lockedReads = 0;

    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        // All reads, writes and BEGIN/COMMIT/ROLLBACK still execute on the private SQLite connection.
        if ($sql === 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') {
            $this->isolationStatements++;
            return parent::query('PRAGMA read_uncommitted = 0');
        }
        if (str_ends_with($sql, ' FOR UPDATE')) {
            $this->lockedReads++;
            $sql = substr($sql, 0, -strlen(' FOR UPDATE'));
        }
        return parent::query($sql, $binds, $setEscapeFlags, $queryClass);
    }
}

// CI derives these driver class names from the connection class name.
final class AppointmentCancellationSqliteResult extends \CodeIgniter\Database\SQLite3\Result
{
}

final class AppointmentCancellationSqliteBuilder extends \CodeIgniter\Database\SQLite3\Builder
{
}
