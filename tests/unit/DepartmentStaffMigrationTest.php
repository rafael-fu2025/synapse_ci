<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAppointmentRequests;
use App\Database\Migrations\AddDepartmentStaffAssignments;
use App\Database\Migrations\CreatePeopleAndProfilesTable;
use App\Database\Migrations\CreateSchedulingAndQueueTables;
use App\Database\Migrations\CreateTenantsTable;
use App\Database\Migrations\CreateUsersAndRbacTables;
use App\Models\AppointmentModel;
use App\Models\AvailabilityExceptionModel;
use App\Models\ProviderAvailabilityModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;
use Tests\Support\InventoryTestDatabase;

foreach ([
    '2026-10-14-000001_CreateTenantsTable',
    '2026-10-14-000002_CreatePeopleAndProfilesTable',
    '2026-10-14-000003_CreateUsersAndRbacTables',
    '2026-10-14-000005_CreateSchedulingAndQueueTables',
    '2026-10-15-000001_AddAppointmentRequests',
    '2026-10-16-000001_AddDepartmentStaffAssignments',
] as $migration) {
    require_once APPPATH . 'Database/Migrations/' . $migration . '.php';
}

/** No DatabaseTestTrait or main-schema migrations: every table has a random scratch prefix. */
final class DepartmentStaffMigrationTest extends CIUnitTestCase
{
    private ?BaseConnection $scratch = null;
    private AddDepartmentStaffAssignments $migration;
    private const NOW = '2026-10-01 09:00:00';

    protected function setUp(): void
    {
        // Must precede parent setup, connections, and all DDL. Rejects default/DSN/failover.
        InventoryTestDatabase::verify();
        parent::setUp();
        $configuration = config('Database')->tests;
        $configuration['DBPrefix'] = 'dsm_' . bin2hex(random_bytes(5)) . '_';
        $this->scratch = Database::connect($configuration, false);
        $actual = $this->scratch->query('SELECT DATABASE() AS name')->getRowArray();
        $this->assertSame($configuration['database'], $actual['name']);
        $forge = Database::forge($this->scratch);
        foreach ([CreateTenantsTable::class, CreatePeopleAndProfilesTable::class, CreateUsersAndRbacTables::class,
            CreateSchedulingAndQueueTables::class, AddAppointmentRequests::class] as $class) {
            (new $class($forge))->up();
        }
        $this->migration = new AddDepartmentStaffAssignments($forge);
        CurrentTenant::set(1);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->scratch !== null) {
                // Drop only this test's tables, in FK dependency order; never disable FK checks.
                $forge = Database::forge($this->scratch);
                foreach (['queue_entries', 'daily_ticket_counters', 'appointments', 'availability_exceptions',
                    'provider_availability', 'department_staff', 'health_profiles', 'employee_profiles',
                    'student_profiles', 'role_permissions', 'permissions', 'users', 'people', 'tenants'] as $table) {
                    $forge->dropTable($table, true);
                }
                $this->scratch->close();
            }
        } finally {
            CurrentTenant::reset();
            parent::tearDown();
        }
    }

    private function insert(string $table, array $data): int
    {
        $this->assertTrue($this->scratch->table($table)->insert($data));
        return (int) $this->scratch->insertID();
    }

    private function person(array $overrides = []): int
    {
        return $this->insert('people', $overrides + ['tenant_id' => 1, 'kind' => 'employee',
            'mis_id' => bin2hex(random_bytes(5)), 'first_name' => 'Avery', 'last_name' => 'Staff',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
    }

    private function user(?int $person, array $overrides = []): int
    {
        return $this->insert('users', $overrides + ['tenant_id' => 1, 'person_id' => $person,
            'username' => bin2hex(random_bytes(5)), 'role' => 'clinic_staff', 'status' => 'active',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
    }

    private function window(int $user, string $unit): int
    {
        return $this->insert('provider_availability', ['tenant_id' => 1, 'provider_user_id' => $user,
            'unit' => $unit, 'day_of_week' => 5, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
            'slot_duration_minutes' => 20, 'is_active' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
    }

    private function exception(int $user): int
    {
        return $this->insert('availability_exceptions', ['tenant_id' => 1, 'provider_user_id' => $user,
            'exception_date' => '2026-10-16', 'is_unavailable' => 0, 'custom_start_time' => '10:00:00',
            'custom_end_time' => '11:00:00', 'reason' => 'Preserve original custom hours',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
    }

    private function rows(string $table): array
    {
        return $this->scratch->table($table)->orderBy('id')->get()->getResultArray();
    }

    private function assertPreflightRefuses(string $message): void
    {
        try {
            $this->migration->up();
            $this->fail('Invalid legacy identities must fail before DDL.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertFalse($this->scratch->tableExists('department_staff'));
        foreach (['appointments', 'provider_availability', 'availability_exceptions'] as $table) {
            $this->assertFalse($this->scratch->fieldExists('staff_assignment_id', $table));
        }
        $this->assertNotEmpty($this->scratch->query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
            [$this->scratch->prefixTable('availability_exceptions'), 'uniq_provider_exception_date'])->getResultArray());
    }

    public function testPreservesOriginalRowsClonesExceptionsAndRerunsWithoutDuplicates(): void
    {
        $person = $this->person();
        $user = $this->user($person, ['role' => 'admin']); // Row units, not roles, determine enrollment.
        $this->window($user, 'guidance');
        $this->window($user, 'clinic');
        $exception = $this->exception($user);
        $this->insert('appointments', ['tenant_id' => 1, 'person_id' => $person, 'unit' => 'clinic',
            'provider_user_id' => $user, 'starts_at' => '2026-10-16 09:00:00', 'ends_at' => '2026-10-16 09:20:00',
            'local_date' => '2026-10-16', 'status' => 'confirmed', 'reason' => 'Historical reason',
            'version' => 7, 'deleted_at' => '2026-10-16 12:00:00',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $before = [];
        foreach (['appointments', 'provider_availability', 'availability_exceptions', 'users', 'people'] as $table) {
            $before[$table] = $this->rows($table);
        }
        $this->migration->up();
        // Regression: the removed unique key was also the tenant FK's leading index.
        foreach (['ds_exception_tenant' => 'tenant_id', 'ds_exception_legacy_provider' => 'provider_user_id'] as $index => $column) {
            $support = $this->scratch->query('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',
                [$this->scratch->prefixTable('availability_exceptions'), $index])->getResultArray();
            $this->assertCount(1, $support);
            $this->assertSame($column, $support[0]['COLUMN_NAME']);
            $this->assertSame(1, (int) $support[0]['NON_UNIQUE']);
        }
        foreach (['tenant_id' => 'tenants', 'provider_user_id' => 'users'] as $column => $parent) {
            $legacyFk = $this->scratch->query('SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME=?',
                [$this->scratch->prefixTable('availability_exceptions'), $column, $this->scratch->prefixTable($parent)])->getRowArray();
            $this->assertNotNull($legacyFk, 'Both legacy foreign keys must survive replacing the unique index.');
        }
        $assignments = $this->rows('department_staff');
        $this->assertCount(2, $assignments);
        $this->assertSame(['clinic', 'guidance'], array_column($assignments, 'unit'));
        foreach ($assignments as $assignment) {
            $this->assertSame($person, (int) $assignment['person_id']);
            $this->assertSame('active', $assignment['status']);
            $this->assertNull($assignment['enrolled_by_user_id']);
        }
        foreach (['appointments', 'provider_availability'] as $table) {
            $after = $this->rows($table);
            $this->assertCount(count($before[$table]), $after);
            foreach ($after as $i => $row) {
                $assignmentId = $row['staff_assignment_id'];
                $this->assertNotNull($assignmentId);
                unset($row['staff_assignment_id']);
                $this->assertSame($before[$table][$i], $row);
            }
        }
        $exceptions = $this->rows('availability_exceptions');
        $this->assertCount(2, $exceptions);
        $this->assertSame($exception, (int) $exceptions[0]['id']);
        foreach ($exceptions as $row) {
            $this->assertNotNull($row['staff_assignment_id']);
            unset($row['staff_assignment_id']);
            $row['id'] = $before['availability_exceptions'][0]['id'];
            $this->assertSame($before['availability_exceptions'][0], $row);
        }
        $this->assertSame($before['users'], $this->rows('users'));
        $this->assertSame($before['people'], $this->rows('people'));
        $snapshot = [$this->rows('appointments'), $this->rows('provider_availability'), $exceptions, $assignments];
        $this->migration->up();
        $this->assertSame($snapshot, [$this->rows('appointments'), $this->rows('provider_availability'),
            $this->rows('availability_exceptions'), $this->rows('department_staff')]);
    }

    public function testDeletedUserAndDeletedEmployeeRetainInactiveHistory(): void
    {
        foreach ([['2026-10-02 00:00:00', null], [null, '2026-10-03 00:00:00']] as [$userDeleted, $personDeleted]) {
            $person = $this->person(['deleted_at' => $personDeleted]);
            $user = $this->user($person, ['deleted_at' => $userDeleted, 'role' => 'counsellor']);
            $this->exception($user);
        }
        $this->migration->up();
        $this->assertSame(['inactive', 'inactive'], array_column($this->rows('department_staff'), 'status'));
        $this->assertSame(['guidance', 'guidance'], array_column($this->rows('department_staff'), 'unit'));
        $this->assertCount(2, $this->rows('availability_exceptions'));
    }

    public function testExceptionOnlyClinicRoleInfersClinicWithoutCreatingAnAccount(): void
    {
        $user = $this->user($this->person());
        $this->exception($user);
        $this->migration->up();
        $this->assertSame('clinic', $this->rows('department_staff')[0]['unit']);
        $this->assertCount(1, $this->rows('users'));
    }

    public function testAmbiguousExceptionOnlyRoleAbortsBeforeDdl(): void
    {
        $this->exception($this->user($this->person(), ['role' => 'admin']));
        $this->assertPreflightRefuses('exception-only');
    }

    public function testMissingEmployeeLinkAbortsBeforeDdl(): void
    {
        $this->window($this->user(null), 'clinic');
        $this->assertPreflightRefuses('same-tenant employee person link');
    }

    public function testNonemployeeLinkAbortsBeforeDdl(): void
    {
        $this->window($this->user($this->person(['kind' => 'student'])), 'clinic');
        $this->assertPreflightRefuses('same-tenant employee person link');
    }

    public function testCrossTenantPersonLinkAbortsBeforeDdl(): void
    {
        $tenant = $this->insert('tenants', ['slug' => 'other', 'name' => 'Other',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->window($this->user($this->person(['tenant_id' => $tenant])), 'clinic');
        $this->assertPreflightRefuses('same-tenant employee person link');
    }

    public function testCrossTenantProviderReferenceAbortsBeforeDdl(): void
    {
        $tenant = $this->insert('tenants', ['slug' => 'other', 'name' => 'Other',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $user = $this->user($this->person(['tenant_id' => $tenant]), ['tenant_id' => $tenant]);
        $this->window($user, 'clinic');
        $this->assertPreflightRefuses('same-tenant employee person link');
    }

    public function testResumesAfterDdlWasAppliedButBackfillWasNotCompleted(): void
    {
        $user = $this->user($this->person());
        $this->window($user, 'clinic');
        $this->window($user, 'guidance');
        $this->exception($user);
        $this->migration->up();
        // Simulate the durable DDL state left after a rolled-back/failed DML phase.
        $this->scratch->table('availability_exceptions')->where('id >', 1)->delete();
        $this->scratch->table('availability_exceptions')->update(['staff_assignment_id' => null]);
        $this->scratch->table('provider_availability')->update(['staff_assignment_id' => null]);
        $this->scratch->table('department_staff')->emptyTable();
        $this->migration->up();
        $this->assertCount(2, $this->rows('department_staff'));
        $this->assertCount(2, $this->rows('availability_exceptions'));
        foreach ($this->rows('provider_availability') as $row) {
            $this->assertNotNull($row['staff_assignment_id']);
        }
    }

    public function testAssignmentModelQueriesUsePersonIdentityAcrossUnitsAndSupportUserlessRows(): void
    {
        // Ensure a legacy user ID cannot accidentally equal either assignment ID.
        $this->user(null);
        $this->user(null);
        $person = $this->person();
        $user = $this->user($person);
        $this->window($user, 'clinic');
        $this->window($user, 'guidance');
        $this->exception($user);
        $this->migration->up();
        [$clinic, $guidance] = $this->rows('department_staff');
        $appointment = $this->insert('appointments', ['tenant_id' => 1, 'person_id' => $person,
            'unit' => 'guidance', 'provider_user_id' => null, 'staff_assignment_id' => $guidance['id'],
            'starts_at' => '2026-10-16 09:00:00', 'ends_at' => '2026-10-16 09:20:00',
            'local_date' => '2026-10-16', 'status' => 'confirmed', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $model = new AppointmentModel($this->scratch);
        $this->assertTrue($model->hasConflict((int) $clinic['id'], '2026-10-16 09:10:00', '2026-10-16 09:30:00'));
        $this->assertFalse($model->hasConflict((int) $clinic['id'], '2026-10-16 09:20:00', '2026-10-16 09:40:00'));
        $this->assertFalse($model->hasConflict((int) $clinic['id'], '2026-10-16 09:10:00', '2026-10-16 09:30:00', $appointment));
        $this->assertSame('Avery Staff', $model->getForDay('guidance', '2026-10-16')[0]['provider_name']);
        $windows = new ProviderAvailabilityModel($this->scratch);
        $this->assertCount(1, $windows->getActiveForProvider((int) $guidance['id'], 5));
        $this->assertSame([], $windows->getActiveForProvider($user, 5));
        $exceptions = new AvailabilityExceptionModel($this->scratch);
        $this->assertNotNull($exceptions->findException((int) $guidance['id'], '2026-10-16'));
        $this->assertNull($exceptions->findException($user, '2026-10-16'));
        // Unmigrated history retains its account label and still blocks the same person.
        $this->scratch->table('appointments')->where('id', $appointment)
            ->update(['staff_assignment_id' => null, 'provider_user_id' => $user]);
        $this->assertTrue($model->hasConflict((int) $clinic['id'], '2026-10-16 09:10:00', '2026-10-16 09:30:00'));
        $legacyUser = $this->scratch->table('users')->where('id', $user)->get()->getRowArray();
        $this->assertSame($legacyUser['username'], $model->getForDay('guidance', '2026-10-16')[0]['provider_name']);
        $this->scratch->table('appointments')->where('id', $appointment)
            ->update(['staff_assignment_id' => $guidance['id'], 'provider_user_id' => null]);
        $this->scratch->table('provider_availability')->update(['provider_user_id' => null]);
        $this->scratch->table('availability_exceptions')->update(['provider_user_id' => null]);
        $this->migration->up();
        $this->assertCount(2, $this->rows('availability_exceptions'));
        try {
            $this->migration->down();
            $this->fail('Down must not discard userless history.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward-only', $e->getMessage());
        }
        $this->assertSame($appointment, (int) $this->rows('appointments')[0]['id']);
    }
}
