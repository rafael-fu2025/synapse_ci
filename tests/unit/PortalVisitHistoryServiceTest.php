<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrentTenant;
use App\Services\PortalContext;
use App\Services\PortalVisitHistoryService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

/** Private SQLite fixtures only: no migrations, sessions, or operational connection. */
final class PortalVisitHistoryServiceTest extends CIUnitTestCase
{
    private BaseConnection $fixture;
    private array $actor;

    protected function setUp(): void
    {
        parent::setUp();
        // A prefix also exercises every raw SQL table reference.
        $this->fixture = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'history_', 'DBDebug' => true], false);
        foreach ([
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, status TEXT, deleted_at TEXT',
            'clinic_encounters' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, attending_user_id INTEGER, appointment_id INTEGER,
                status TEXT, started_at TEXT, completed_at TEXT, deleted_at TEXT, chief_complaint TEXT, assessment_notes TEXT, disposition TEXT, disposition_notes TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT, deleted_at TEXT, title TEXT, case_number TEXT, summary_notes TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, counsellor_user_id INTEGER, appointment_id INTEGER,
                status TEXT, started_at TEXT, ended_at TEXT, deleted_at TEXT',
            'counselling_notes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, session_id INTEGER, notes_cipher TEXT, risk_level TEXT',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')');
        }
        foreach ([[88, 7], [89, 7], [90, 8], [100, 7], [101, 8]] as [$id, $tenant]) {
            $this->fixture->table('people')->insert(['id' => $id, 'tenant_id' => $tenant,
                'first_name' => $id === 100 ? '  Ana  Maria ' : 'PRIVATE-PATIENT', 'last_name' => $id === 100 ? ' Santos ' : 'PRIVATE-SURNAME']);
        }
        foreach ([[3, 7, 88], [4, 7, 89], [5, 8, 90], [10, 7, 100], [11, 8, 101]] as [$id, $tenant, $person]) {
            $this->fixture->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person,
                'username' => 'staff-' . $id, 'status' => 'active']);
        }
        $this->actor = ['id' => 3, 'person_id' => 88, 'tenant_id' => 7, 'logged_in' => true,
            'role' => 'student', 'permissions' => ['portal.records.read']];
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function snapshot(array $filters = []): array
    {
        return (new PortalVisitHistoryService($this->fixture, fn () => $this->actor, static fn () => 7))->snapshot($filters);
    }

    private function clinic(array $changes = []): int
    {
        $this->fixture->table('clinic_encounters')->insert(array_replace([
            'tenant_id' => 7, 'person_id' => 88, 'attending_user_id' => 10, 'appointment_id' => null,
            'status' => 'completed', 'started_at' => '2026-10-20 09:00:00', 'completed_at' => '2026-10-20 09:30:00',
            'chief_complaint' => 'SECRET-COMPLAINT', 'assessment_notes' => 'SECRET-DIAGNOSIS',
            'disposition' => 'SECRET-DISPOSITION', 'disposition_notes' => 'SECRET-NOTES',
        ], $changes));
        return (int) $this->fixture->insertID();
    }

    private function guidanceCase(array $changes = []): int
    {
        $this->fixture->table('counselling_cases')->insert(array_replace([
            'tenant_id' => 7, 'person_id' => 88, 'status' => 'active',
            'title' => 'SECRET-TITLE', 'case_number' => 'SECRET-CASE-NUMBER', 'summary_notes' => 'SECRET-SUMMARY',
        ], $changes));
        return (int) $this->fixture->insertID();
    }

    private function guidance(array $changes = []): int
    {
        $case = $changes['case_id'] ?? $this->guidanceCase();
        $this->fixture->table('counselling_sessions')->insert(array_replace([
            'tenant_id' => 7, 'case_id' => $case, 'counsellor_user_id' => 10, 'appointment_id' => null,
            'status' => 'completed', 'started_at' => '2026-10-21 23:45:00', 'ended_at' => '2026-10-22 00:30:00',
        ], $changes));
        $id = (int) $this->fixture->insertID();
        $this->fixture->table('counselling_notes')->insert(['tenant_id' => 7, 'session_id' => $id,
            'notes_cipher' => 'SECRET-CIPHERTEXT', 'risk_level' => 'SECRET-RISK']);
        return $id;
    }

    public function testExactPatientSafeProjectionIncludesBothAttendanceTypesAndClosedReferrals(): void
    {
        $this->clinic();
        $this->clinic(['status' => 'referred', 'appointment_id' => 444]);
        $this->guidance();
        $this->guidance(['appointment_id' => 444]);
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            $snapshot = $this->snapshot();
        } finally {
            date_default_timezone_set($originalTimezone);
        }
        self::assertSame(['visits', 'filters', 'pagination'], array_keys($snapshot));
        self::assertSame(['service' => 'all'], $snapshot['filters']);
        self::assertSame(['page' => 1, 'pages' => 1, 'per_page' => 25, 'total' => 4, 'from' => 1, 'to' => 4], $snapshot['pagination']);
        self::assertSame([
            ['unit' => 'guidance', 'started_at' => '2026-10-21 23:45:00', 'completed_at' => '2026-10-22 00:30:00',
                'staff_name' => 'Ana Maria Santos', 'attendance' => 'appointment', 'summary' => 'Completed guidance session', 'status' => 'completed'],
            ['unit' => 'guidance', 'started_at' => '2026-10-21 23:45:00', 'completed_at' => '2026-10-22 00:30:00',
                'staff_name' => 'Ana Maria Santos', 'attendance' => 'walk_in', 'summary' => 'Completed guidance session', 'status' => 'completed'],
            ['unit' => 'clinic', 'started_at' => '2026-10-20 09:00:00', 'completed_at' => '2026-10-20 09:30:00',
                'staff_name' => 'Ana Maria Santos', 'attendance' => 'appointment', 'summary' => 'Completed clinic visit', 'status' => 'completed'],
            ['unit' => 'clinic', 'started_at' => '2026-10-20 09:00:00', 'completed_at' => '2026-10-20 09:30:00',
                'staff_name' => 'Ana Maria Santos', 'attendance' => 'walk_in', 'summary' => 'Completed clinic visit', 'status' => 'completed'],
        ], $snapshot['visits']);
        self::assertStringNotContainsString('SECRET', json_encode($snapshot));
        self::assertStringNotContainsString('PRIVATE', json_encode($snapshot));
        foreach ($snapshot['visits'] as $visit) {
            self::assertSame(['unit', 'started_at', 'completed_at', 'staff_name', 'attendance', 'summary', 'status'], array_keys($visit));
        }
    }

    public function testTenantPersonSoftDeleteAndCompletionScopesAreIndependent(): void
    {
        $this->clinic();
        $this->guidance(); // A completed session in an active case still belongs in history.
        foreach ([['tenant_id' => 8], ['person_id' => 89], ['deleted_at' => '2026-10-23 00:00:00'],
            ['status' => 'in_progress'], ['status' => 'cancelled'], ['completed_at' => null],
            ['status' => 'referred', 'completed_at' => null]] as $change) $this->clinic($change);
        foreach ([['tenant_id' => 8], ['deleted_at' => '2026-10-23 00:00:00'], ['ended_at' => null],
            ['status' => 'in_progress'], ['status' => 'scheduled'], ['status' => 'cancelled']] as $change) $this->guidance($change);
        foreach ([['tenant_id' => 8], ['person_id' => 89], ['deleted_at' => '2026-10-23 00:00:00']] as $change) {
            $this->guidance(['case_id' => $this->guidanceCase($change)]);
        }
        self::assertSame(2, $this->snapshot()['pagination']['total']);
        $this->actor['id'] = 4;
        $this->actor['person_id'] = 89;
        self::assertSame(2, $this->snapshot()['pagination']['total']);
        $this->actor = array_replace($this->actor, ['id' => 5, 'person_id' => 90, 'tenant_id' => 8]);
        $this->clinic(['tenant_id' => 8, 'person_id' => 90]);
        $this->guidance(['tenant_id' => 8, 'case_id' => $this->guidanceCase(['tenant_id' => 8, 'person_id' => 90])]);
        $other = new PortalVisitHistoryService($this->fixture, fn () => $this->actor, static fn () => 8);
        self::assertSame(2, $other->snapshot()['pagination']['total']);
    }

    public function testStableMixedPaginationUsesSourceAndIdAndDoesNotDedupeAppointments(): void
    {
        $expected = [];
        foreach (['clinic', 'guidance'] as $unit) {
            $branch = [];
            for ($i = 1; $i <= 30; $i++) {
                $end = '2026-10-22 10:' . sprintf('%02d', $i) . ':00';
                $changes = ['started_at' => '2026-10-22 09:00:00', 'appointment_id' => 123];
                if ($unit === 'clinic') $this->clinic($changes + ['completed_at' => $end]);
                else $this->guidance($changes + ['ended_at' => $end]);
                array_unshift($branch, [$unit, $end]);
            }
            array_push($expected, ...$branch);
        }
        $actual = [];
        for ($page = 1; $page <= 3; $page++) {
            $snapshot = $this->snapshot(['page' => (string) $page]);
            self::assertSame(['page' => $page, 'pages' => 3, 'per_page' => 25, 'total' => 60,
                'from' => ($page - 1) * 25 + 1, 'to' => min($page * 25, 60)], $snapshot['pagination']);
            foreach ($snapshot['visits'] as $visit) $actual[] = [$visit['unit'], $visit['completed_at']];
            self::assertSame($snapshot, $this->snapshot(['page' => $page]));
        }
        self::assertSame($expected, $actual);
        self::assertSame($this->snapshot(['page' => 3]), $this->snapshot(['page' => '1000000']));
        foreach (['clinic', 'guidance'] as $unit) {
            $snapshot = $this->snapshot(['service' => $unit, 'page' => 9]);
            self::assertSame(['service' => $unit], $snapshot['filters']);
            self::assertSame(['page' => 2, 'pages' => 2, 'per_page' => 25, 'total' => 30, 'from' => 26, 'to' => 30], $snapshot['pagination']);
            self::assertSame(array_fill(0, 5, $unit), array_column($snapshot['visits'], 'unit'));
        }
        $this->clinic(['started_at' => '2026-10-23 09:00:00']);
        self::assertSame('2026-10-23 09:00:00', $this->snapshot()['visits'][0]['started_at']);
    }

    public function testEmptyPaginationAndCurrentTenantDefault(): void
    {
        CurrentTenant::set(7);
        $service = new PortalVisitHistoryService($this->fixture, fn () => $this->actor);
        self::assertSame(['visits' => [], 'filters' => ['service' => 'all'],
            'pagination' => ['page' => 1, 'pages' => 1, 'per_page' => 25, 'total' => 0, 'from' => 0, 'to' => 0]], $service->snapshot(['page' => 999]));
        CurrentTenant::set(8);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $service->snapshot();
    }

    public function testMissingDeletedOrCrossTenantStaffNeverLeakNamesOrRemoveVisits(): void
    {
        $this->fixture->table('users')->insert(['id' => 12, 'tenant_id' => 7, 'person_id' => null, 'username' => '  Username only  ', 'status' => 'active']);
        $this->fixture->table('users')->insert(['id' => 13, 'tenant_id' => 7, 'person_id' => 101, 'username' => 'SECRET-CROSS-TENANT', 'status' => 'active']);
        $this->fixture->table('users')->insert(['id' => 14, 'tenant_id' => 7, 'person_id' => 999, 'username' => 'SECRET-MISSING-PERSON', 'status' => 'active']);
        foreach ([999, 11, 12, 13, 14] as $staff) {
            $this->clinic(['attending_user_id' => $staff]);
            $this->guidance(['counsellor_user_id' => $staff]);
        }
        self::assertSame(10, $this->snapshot()['pagination']['total']);
        self::assertSame(2, count(array_filter($this->snapshot()['visits'], static fn ($row) => $row['staff_name'] === 'Username only')));
        self::assertSame(8, count(array_filter($this->snapshot()['visits'], static fn ($row) => $row['staff_name'] === 'Not recorded')));
        $this->clinic();
        $this->guidance();
        $this->fixture->table('users')->where('id', 10)->update(['deleted_at' => '2026-10-23 00:00:00']);
        self::assertNotContains('Ana Maria Santos', array_column($this->snapshot()['visits'], 'staff_name'));
        $this->fixture->table('users')->where('id', 10)->update(['deleted_at' => null]);
        $this->fixture->table('people')->where('id', 100)->update(['deleted_at' => '2026-10-23 00:00:00']);
        self::assertNotContains('staff-10', array_column($this->snapshot()['visits'], 'staff_name'));
        self::assertNotContains('Ana Maria Santos', array_column($this->snapshot()['visits'], 'staff_name'));
        self::assertStringNotContainsString('SECRET', json_encode($this->snapshot()));
    }

    public function testBlankStaffNamesUseUsernameThenStaticFallback(): void
    {
        $this->clinic();
        $this->fixture->table('people')->where('id', 100)->update(['first_name' => ' ', 'last_name' => null]);
        self::assertSame('staff-10', $this->snapshot()['visits'][0]['staff_name']);
        $this->fixture->table('users')->where('id', 10)->update(['username' => ' ']);
        self::assertSame('Not recorded', $this->snapshot()['visits'][0]['staff_name']);
    }

    public function testFiltersRejectSelectorsUnknownKeysAndNoncanonicalPages(): void
    {
        $invalid = [['service' => null], ['service' => 'Clinic'], ['service' => 'all UNION SELECT'], ['service' => []],
            ['person_id' => 89], ['tenant_id' => 8], ['sort' => 'id'], [0 => 'clinic']];
        foreach ([null, 0, -1, '0', '01', '+1', ' 1', '1 ', '1.0', 1.0, true, false, [], '1e2', '1000001', '9999999999999999999999', "1\n"] as $page) {
            $invalid[] = ['page' => $page];
        }
        foreach ($invalid as $filters) {
            try {
                $this->snapshot($filters);
                self::fail('Accepted invalid filters: ' . json_encode($filters));
            } catch (InvalidArgumentException $error) {
                self::assertSame(422, $error->getCode());
            }
        }
    }

    public function testUnauthenticatedAndForgedIdentitiesFailClosed(): void
    {
        $service = new PortalVisitHistoryService($this->fixture, static fn () => null, static fn () => 7);
        try {
            $service->snapshot();
            self::fail('Accepted anonymous caller.');
        } catch (RuntimeException $error) {
            self::assertSame(401, $error->getCode());
        }
        $valid = $this->actor;
        foreach ([['logged_in' => false], ['logged_in' => 1], ['person_id' => 0], ['person_id' => '88junk'],
            ['person_id' => []], ['person_id' => 89], ['person_id' => 90], ['id' => 4], ['id' => null],
            ['tenant_id' => 8], ['tenant_id' => '7'], ['role' => 'invented'], ['role' => []],
            ['permissions' => []], ['permissions' => 'portal.records.read']] as $change) {
            $this->actor = array_replace($valid, $change);
            try {
                $this->snapshot();
                self::fail('Accepted forged identity: ' . json_encode($change));
            } catch (RuntimeException $error) {
                self::assertSame(403, $error->getCode());
            }
        }
        $this->actor = $valid;
        foreach (['users' => ['status' => 'inactive'], 'people' => ['deleted_at' => '2026-10-23 00:00:00']] as $table => $change) {
            $this->fixture->table($table)->where('id', $table === 'users' ? 3 : 88)->update($change);
            try {
                $this->snapshot();
                self::fail('Accepted invalid current ' . $table);
            } catch (RuntimeException $error) {
                self::assertSame(403, $error->getCode());
            }
            if ($table === 'users') $this->fixture->table('users')->where('id', 3)->update(['status' => 'active']);
        }
    }

    public function testInvalidTenantContextsAndDeletedCurrentUsersFailClosed(): void
    {
        foreach ([0, -1, 8, '7', null] as $tenant) {
            $service = new PortalVisitHistoryService($this->fixture, fn () => $this->actor, static fn () => $tenant);
            try {
                $service->snapshot();
                self::fail('Accepted invalid tenant context.');
            } catch (RuntimeException $error) {
                self::assertSame(403, $error->getCode());
            }
        }
        $this->fixture->table('users')->where('id', 3)->update(['deleted_at' => '2026-10-23 00:00:00']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->snapshot();
    }

    public function testSnapshotIsReadOnlyAcrossAllFixtureTables(): void
    {
        $this->clinic();
        $this->guidance();
        $tables = ['users', 'people', 'clinic_encounters', 'counselling_cases', 'counselling_sessions', 'counselling_notes'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $this->fixture->table($table)->orderBy('id')->get()->getResultArray();
        $this->snapshot();
        $this->snapshot(['service' => 'clinic', 'page' => 999]);
        $this->snapshot(['service' => 'guidance']);
        foreach ($tables as $table) {
            self::assertSame($before[$table], $this->fixture->table($table)->orderBy('id')->get()->getResultArray());
        }
    }

    public function testEveryAllowedMainPortalRoleCanReadOnlyOwnHistoryWithoutAppointmentPermission(): void
    {
        $this->clinic();
        $this->clinic(['person_id' => 89]);
        foreach (PortalContext::allowedRoles(PortalContext::PORTAL_MAIN) as $role) {
            $this->actor['role'] = $role;
            self::assertSame(1, $this->snapshot()['pagination']['total']);
        }
    }
}
