<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Commands\CreateTestAccounts;
use App\Integrations\Mis\MisClientInterface;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Services\LocalTestAccounts;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class LocalTestAccountsTest extends TestCase
{
    private BaseConnection $db;
    private LocalTestAccounts $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('SYNAPSE_LOCAL_TEST_ACCOUNTS_ISOLATED') || ENVIRONMENT !== 'testing') {
            self::markTestSkipped('Run with tests/_support/local-test-accounts-phpunit.xml.');
        }
        // No configured DB is used: explicitly construct and inject only in-memory SQLite.
        $settings = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DSN' => '', 'failover' => [], 'foreignKeys' => true, 'DBDebug' => true];
        config('Database')->tests = $settings;
        config('Database')->defaultGroup = 'tests';
        $this->db = Database::connect('tests');
        Services::injectMock('logger', new NullLogger());
        $_SESSION = [];
        Services::injectMock('session', $this->getMockBuilder(\CodeIgniter\Session\Session::class)
            ->disableOriginalConstructor()->onlyMethods(['regenerate'])->getMock());
        \Tests\Support\LocalTestAccountsSchema::create($this->db);
        CurrentTenant::set(1);
        $this->service = new LocalTestAccounts($this->db, 'development');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            parent::tearDown();
            return;
        }
        AuthenticatedUser::clear();
        CurrentTenant::reset();
        $this->db->close();
        (new \ReflectionProperty(Database::class, 'instances'))->setValue(null, []);
        Services::reset();
        parent::tearDown();
    }


    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['tenants', 'people', 'users', 'student_profiles', 'employee_profiles', 'permissions', 'role_permissions'] as $table) {
            $snapshot[$table] = $this->db->table($table)->get()->getResultArray();
        }
        return $snapshot;
    }

    private function assertRefused(callable $operation, string $message): void
    {
        $before = $this->snapshot();
        try {
            $operation();
            self::fail('Expected refusal.');
        } catch (DomainException | RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertStringNotContainsString('SELECT ', $error->getMessage());
            self::assertStringNotContainsString('INSERT ', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testDryRunAndCompleteIdentitiesAndPasswordHashes(): void
    {
        $before = $this->snapshot();
        $preview = $this->service->run();
        self::assertSame($before, $this->snapshot());
        self::assertArrayNotHasKey('password', $preview);
        self::assertCount(15, $preview['accounts']);
        self::assertSame(['would_create'], array_values(array_unique(array_column($preview['accounts'], 'status'))));
        $result = $this->service->run(true);
        self::assertGreaterThanOrEqual(20, strlen($result['password']));
        self::assertCount(15, $result['accounts']);
        self::assertSame(15, $this->db->table('people')->countAllResults());
        self::assertSame(14, $this->db->table('employee_profiles')->countAllResults());
        self::assertSame(1, $this->db->table('student_profiles')->countAllResults());
        self::assertSame([
            'test.superadmin', 'test.clinic.admin', 'test.clinic.staff', 'test.clinic.staff2',
            'test.guidance.admin', 'test.guidance.supervisor', 'test.counsellor', 'test.counsellor2',
            'test.bmg.admin', 'test.facilities.op', 'test.facilities.op2', 'test.audit.reader',
            'test.report.viewer', 'test.student', 'test.employee',
        ], array_column($result['accounts'], 'username'));
        self::assertSame(['superadmin', 'clinic_admin', 'clinic_staff', 'clinic_staff', 'guidance_admin',
            'guidance_supervisor', 'counsellor', 'counsellor', 'bmg_admin', 'facilities_op', 'facilities_op',
            'audit_reader', 'report_viewer', 'student', 'employee'], array_column($result['accounts'], 'role'));
        foreach ($result['accounts'] as $account) {
            self::assertTrue($account['newly_created']);
            self::assertSame('created', $account['status']);
            $user = $this->db->table('users')->where('id', $account['user_id'])->get()->getRowArray();
            $person = $this->db->table('people')->where('id', $user['person_id'])->get()->getRowArray();
            self::assertSame($account['person_id'], (int) $person['id']);
            self::assertSame(1, (int) $user['auth_version']);
            self::assertSame('active', $user['status']);
            self::assertTrue(password_verify($result['password'], $user['password_hash']));
            self::assertSame($account['username'] . '@synapse.invalid', $user['email']);
            self::assertSame($user['email'], $person['email']);
            self::assertSame('Test', $person['first_name']);
            self::assertSame($account['mis_id'], $person['mis_id']);
            self::assertStringStartsWith('SYNAPSE-TEST-', $person['mis_id']);
            self::assertSame(1, (int) $person['tenant_id']);
            self::assertNotEmpty($user['created_at']);
            self::assertNotEmpty($person['updated_at']);
            $table = $account['kind'] === 'student' ? 'student_profiles' : 'employee_profiles';
            $profile = $this->db->table($table)->where('person_id', $person['id'])->get()->getRowArray();
            self::assertSame(1, (int) $profile['tenant_id']);
            if ($account['kind'] === 'student') {
                self::assertSame(['BSIT', 3, 'TEST', 'CCS'], [$profile['program'], (int) $profile['year_level'], $profile['section'], $profile['department']]);
            } else {
                $department = match (true) {
                    str_starts_with($account['username'], 'test.clinic.') => 'Health Services',
                    str_starts_with($account['username'], 'test.guidance.'), str_starts_with($account['username'], 'test.counsellor') => 'Guidance',
                    str_starts_with($account['username'], 'test.facilities.'), $account['role'] === 'bmg_admin' => 'Facilities',
                    $account['role'] === 'employee' => 'Test Employee',
                    default => 'Test Administration',
                };
                self::assertSame($department, $profile['department']);
            }
        }
        self::assertSame(0, $this->db->table('role_permissions')->countAllResults());
    }

    public function testExactDepartmentsMatchAppointmentRosterAndStaffAssignmentWithoutAutomaticEnrollment(): void
    {
        $this->db->query('CREATE TABLE department_staff (id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL REFERENCES tenants(id), person_id INTEGER NOT NULL REFERENCES people(id),
            unit TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $result = $this->service->run(true);
        self::assertSame(0, $this->db->table('department_staff')->countAllResults());
        $accounts = array_column($result['accounts'], null, 'username');
        $actor = ['id' => $accounts['test.superadmin']['user_id'], 'role' => 'superadmin', 'tenant_id' => 1];
        $appointments = new \App\Services\Scheduling\AppointmentService();
        $expected = [
            'clinic' => ['Health Services', ['test.clinic.admin', 'test.clinic.staff', 'test.clinic.staff2']],
            'guidance' => ['Guidance', ['test.guidance.admin', 'test.guidance.supervisor', 'test.counsellor', 'test.counsellor2']],
        ];
        foreach ($expected as $unit => [$department, $usernames]) {
            $roster = $appointments->departmentStaff($unit, $actor);
            self::assertCount(count($usernames), $roster);
            $byPerson = array_column($roster, null, 'person_id');
            foreach ($usernames as $username) {
                $personId = $accounts[$username]['person_id'];
                self::assertArrayHasKey($personId, $byPerson);
                self::assertSame($department, $byPerson[$personId]['department']);
                self::assertSame($unit === 'clinic' ? 'health services' : 'guidance',
                    strtolower(trim($byPerson[$personId]['department'])));
                self::assertTrue($byPerson[$personId]['can_enroll']);
                self::assertFalse($byPerson[$personId]['eligible']);
                self::assertNull($byPerson[$personId]['staff_assignment_id']);
            }
        }
        self::assertSame(0, $this->db->table('department_staff')->countAllResults());

        // Enrollment is explicit test setup, never performed by LocalTestAccounts.
        foreach ($expected as $unit => [$department, $usernames]) {
            foreach ($usernames as $username) {
                $personId = $accounts[$username]['person_id'];
                $this->db->table('department_staff')->insert(['tenant_id' => 1, 'person_id' => $personId,
                    'unit' => $unit, 'status' => 'active', 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
                $assignment = $appointments->staffAssignment($unit, (int) $this->db->insertID());
                self::assertSame($personId, (int) $assignment['person_id']);
                self::assertSame($unit, $assignment['unit']);
            }
        }
    }

    public function testRerunPreservesEveryRowAndExposesNoPassword(): void
    {
        $this->service->run(true);
        $before = $this->snapshot();
        foreach ([false, true] as $create) {
            $result = $this->service->run($create);
            self::assertArrayNotHasKey('password', $result);
            self::assertSame(['skipped'], array_values(array_unique(array_column($result['accounts'], 'status'))));
            self::assertNotContains(true, array_column($result['accounts'], 'newly_created'));
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testExistingIdentitiesAndGrantsAreUnchanged(): void
    {
        $this->db->table('people')->insert(['tenant_id' => 1, 'kind' => 'employee', 'mis_id' => 'EXISTING-SYNTHETIC',
            'first_name' => 'Existing', 'last_name' => 'Fixture', 'email' => 'existing@synapse.invalid',
            'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
        $this->db->table('users')->insert(['tenant_id' => 1, 'person_id' => $this->db->insertID(),
            'username' => 'existing', 'role' => 'clinic_staff', 'password_hash' => password_hash('existing-secret', PASSWORD_DEFAULT),
            'auth_version' => 9, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
        $this->db->table('permissions')->insert(['id' => 1, 'code' => 'test.existing']);
        $this->db->table('role_permissions')->insert(['role' => 'clinic_staff', 'permission_id' => 1]);
        $before = $this->snapshot();
        $this->service->run(true);
        $after = $this->snapshot();
        foreach ($before as $table => $rows) {
            self::assertSame($rows, array_slice($after[$table], 0, count($rows)));
        }
        self::assertSame($before['role_permissions'], $after['role_permissions']);
    }

    public function testResetChangesOnlyFixturePasswordsAndRevokesSessionsOnEveryRun(): void
    {
        $this->testExistingIdentitiesAndGrantsAreUnchanged();
        $before = $this->snapshot();
        foreach ([2, 3] as $version) {
            $result = $this->service->run(false, 1, 'password123');
            self::assertArrayNotHasKey('password', $result);
            self::assertCount(15, $result['accounts']);
            self::assertSame(['password_reset'], array_values(array_unique(array_column($result['accounts'], 'status'))));
            self::assertNotContains(true, array_column($result['accounts'], 'newly_created'));
            $after = $this->snapshot();
            self::assertSame($before['users'][0], $after['users'][0]);
            foreach (array_slice($after['users'], 1) as $i => $user) {
                self::assertTrue(password_verify('password123', $user['password_hash']));
                self::assertSame($version, (int) $user['auth_version']);
                foreach (['password_hash', 'auth_version', 'updated_at'] as $field) {
                    unset($user[$field]);
                }
                $original = $before['users'][$i + 1];
                foreach (['password_hash', 'auth_version', 'updated_at'] as $field) {
                    unset($original[$field]);
                }
                self::assertSame($original, $user);
            }
            unset($after['users']);
            $unchanged = $before;
            unset($unchanged['users']);
            self::assertSame($unchanged, $after);
        }
    }

    public function testResetRequiresAllFixturesAndRollsBackUpdateFailures(): void
    {
        $this->assertRefused(fn () => $this->service->run(false, 1, 'password123'), 'all 15 exact fixtures');
        $created = $this->service->run(true);
        $last = $created['accounts'][14];
        $this->db->query("CREATE TRIGGER reject_reset BEFORE UPDATE ON users WHEN NEW.username = 'test.employee'
            BEGIN SELECT RAISE(ABORT, 'sensitive driver details'); END");
        $this->assertRefused(fn () => $this->service->run(false, 1, 'password123'), 'no accounts were committed');
        $this->db->query('DROP TRIGGER reject_reset');
        $this->db->table('users')->where('id', $last['user_id'])->delete();
        $this->db->table('employee_profiles')->where('person_id', $last['person_id'])->delete();
        $this->db->table('people')->where('id', $last['person_id'])->delete();
        $this->assertRefused(fn () => $this->service->run(false, 1, 'password123'), 'missing test.employee');
    }

    public function testResetRejectsInvalidPasswordsAndCreateCombination(): void
    {
        foreach (['', 'short', str_repeat('x', 73), "validpass\0"] as $password) {
            $this->assertRefused(fn () => $this->service->run(false, 1, $password), '8 to 72 bytes');
        }
        $this->assertRefused(fn () => $this->service->run(true, 1, 'password123'), 'mutually exclusive');
    }

    public function testAllAccountsLoginLocallyWithoutAnyMisCall(): void
    {
        $result = $this->service->run(true);
        $mis = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees', 'fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $mis->expects(self::never())->method($method);
        }
        $auth = new AuthService(misClient: $mis, userModel: new UserModel($this->db));
        foreach ($result['accounts'] as $account) {
            CurrentTenant::set(1);
            self::assertNull($auth->login($account['username'], 'wrong-password'));
            $identity = $auth->login($account['username'], $result['password']);
            self::assertNotNull($identity, $account['username']);
            self::assertSame($account['role'], $identity['role']);
            self::assertSame($account['person_id'], $identity['person_id']);
            self::assertSame($account['email'], $identity['email']);
            self::assertSame($account['mis_id'], $identity['identifier']);
            self::assertArrayNotHasKey('password_hash', $identity);
        }
    }

    public static function collisions(): iterable
    {
        yield 'deleted username' => ['users', ['username' => 'test.employee', 'deleted_at' => '2020-01-01']];
        yield 'suspended email' => ['users', ['username' => 'other', 'email' => 'test.employee@synapse.invalid', 'status' => 'suspended']];
        yield 'case-insensitive username' => ['users', ['username' => 'TEST.EMPLOYEE']];
        yield 'MIS ID even wrong kind' => ['people', ['mis_id' => 'SYNAPSE-TEST-EMPLOYEE', 'kind' => 'student']];
        yield 'deleted person email' => ['people', ['mis_id' => 'OTHER', 'email' => 'test.employee@synapse.invalid', 'deleted_at' => '2020-01-01']];
        yield 'cross-field identifier' => ['users', ['username' => 'test.employee@synapse.invalid']];
    }

    #[DataProvider('collisions')]
    public function testCollisionPreflightsEntireSetBeforeAnyInsert(string $table, array $values): void
    {
        $defaults = $table === 'users' ? ['role' => 'employee'] : ['kind' => 'employee', 'first_name' => 'Test', 'last_name' => 'Collision'];
        $this->db->table($table)->insert($values + $defaults + ['tenant_id' => 1, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
        foreach ([false, true] as $create) {
            $this->assertRefused(fn () => $this->service->run($create), 'collision for test.employee');
        }
    }

    public static function changedFixtures(): iterable
    {
        yield ['users', ['role' => 'superadmin']];
        yield ['users', ['status' => 'locked']];
        yield ['users', ['deleted_at' => '2020-01-01']];
        yield ['users', ['person_id' => null]];
        yield ['users', ['password_hash' => null]];
        yield ['people', ['first_name' => 'Not Test']];
        yield ['people', ['deleted_at' => '2020-01-01']];
        yield ['employee_profiles', ['department' => 'Other']];
    }

    #[DataProvider('changedFixtures')]
    public function testAlteredFixtureIsNeverRepaired(string $table, array $changes): void
    {
        $result = $this->service->run(true);
        $account = $result['accounts'][14];
        $key = $table === 'employee_profiles' ? 'person_id' : 'id';
        $id = $table === 'users' ? $account['user_id'] : $account['person_id'];
        $this->db->table($table)->where($key, $id)->update($changes);
        $this->assertRefused(fn () => $this->service->run(true), 'collision for test.employee');
        $this->assertRefused(fn () => $this->service->run(false, 1, 'password123'), 'collision for test.employee');
    }

    public function testMissingOrOppositeProfileIsNotAcceptedAsComplete(): void
    {
        $result = $this->service->run(true);
        $personId = $result['accounts'][14]['person_id'];
        $profile = $this->db->table('employee_profiles')->where('person_id', $personId)->get()->getRowArray();
        $this->db->table('employee_profiles')->where('person_id', $personId)->delete();
        $this->assertRefused(fn () => $this->service->run(true), 'collision for test.employee');
        $this->db->table('employee_profiles')->insert($profile);
        $this->db->table('student_profiles')->insert(['tenant_id' => 1, 'person_id' => $personId,
            'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
        $this->assertRefused(fn () => $this->service->run(true), 'collision for test.employee');
    }

    public function testInsertionFailureRollsBackPeopleProfilesAndUsers(): void
    {
        $this->db->query("CREATE TRIGGER reject_last BEFORE INSERT ON users WHEN NEW.username = 'test.employee'
            BEGIN SELECT RAISE(ABORT, 'sensitive SQL driver details'); END");
        $this->assertRefused(fn () => $this->service->run(true), 'no accounts were committed');
        self::assertSame(0, $this->db->table('people')->countAllResults());
    }

    public function testPartiallyExistingSetOnlyCreatesMissingAndPreservesExistingHash(): void
    {
        $first = $this->service->run(true);
        $last = $first['accounts'][14];
        $this->db->table('users')->where('id', $last['user_id'])->delete();
        $this->db->table('employee_profiles')->where('person_id', $last['person_id'])->delete();
        $this->db->table('people')->where('id', $last['person_id'])->delete();
        $before = $this->snapshot();
        $next = $this->service->run(true);
        self::assertNotSame($first['password'], $next['password']);
        self::assertSame(1, count(array_filter($next['accounts'], static fn (array $row): bool => $row['newly_created'])));
        self::assertSame($before['users'], array_slice($this->snapshot()['users'], 0, 14));
        self::assertTrue(password_verify($next['password'], $this->snapshot()['users'][14]['password_hash']));
    }

    public function testEnvironmentGatesAndTrustedInjection(): void
    {
        foreach ([null, 'production', 'testing', 'Development', ''] as $environment) {
            $service = new LocalTestAccounts($this->db, $environment);
            $this->assertRefused(fn () => $service->run(true), 'development environment');
            $this->assertRefused(fn () => $service->run(false, 1, 'password123'), 'development environment');
        }
        self::assertCount(15, $this->service->run()['accounts']);
    }

    public function testMissingInactiveDeletedAndInvalidTenantsAreRefused(): void
    {
        foreach ([0, -1, 999] as $tenant) {
            $this->assertRefused(fn () => $this->service->run(true, $tenant), $tenant <= 0 ? 'positive tenant' : 'missing or inactive');
        }
        foreach ([['is_active' => 0], ['is_active' => 1, 'deleted_at' => '2020-01-01']] as $change) {
            $this->db->table('tenants')->where('id', 1)->update($change);
            $this->assertRefused(fn () => $this->service->run(true), 'missing or inactive');
            $this->assertRefused(fn () => $this->service->run(false, 1, 'password123'), 'missing or inactive');
        }
    }

    public function testExplicitTenantSelection(): void
    {
        $this->db->table('tenants')->insert(['id' => 2, 'slug' => 'other', 'name' => 'Test Other', 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
        CurrentTenant::set(2);
        self::assertSame(2, $this->service->run()['tenant_id']);
        $this->service->run(true, 2);
        foreach ($this->snapshot()['users'] as $user) {
            self::assertSame(2, (int) $user['tenant_id']);
        }
        $this->assertRefused(fn () => $this->service->run(true, 1), 'collision');
    }

    public function testConnectionConfigurationGatesWithoutConnecting(): void
    {
        foreach (['localhost', '127.0.0.1', '::1'] as $host) {
            LocalTestAccounts::assertLocalConfiguration(['DBDriver' => 'MySQLi', 'hostname' => $host]);
        }
        LocalTestAccounts::assertLocalConfiguration(['DBDriver' => 'SQLite3']);
        $bad = [];
        foreach (['remote.invalid', '127.0.0.2', 'localhost.remote.invalid', 'LOCALHOST', ' localhost', 'localhost:3306', ''] as $host) {
            $bad[] = ['DBDriver' => 'MySQLi', 'hostname' => $host];
        }
        $bad[] = ['DBDriver' => 'Postgre', 'hostname' => 'localhost'];
        $bad[] = ['DBDriver' => 'MySQLi', 'hostname' => 'localhost', 'DSN' => 'MySQLi://user:secret@remote.invalid/db'];
        $bad[] = ['DBDriver' => 'MySQLi', 'hostname' => 'localhost', 'failover' => [['hostname' => 'remote.invalid']]];
        $bad[] = ['DBDriver' => 'SQLite3', 'failover' => [['hostname' => 'localhost']]];
        foreach ($bad as $settings) {
            $this->assertRefused(fn () => LocalTestAccounts::assertLocalConfiguration($settings), 'literal loopback');
        }
        // The non-injected path must reject before BaseConnection eagerly opens a failover.
        config('Database')->tests = $bad[count($bad) - 2];
        $this->assertRefused(fn () => (new LocalTestAccounts(environment: 'development'))->run(true), 'literal loopback');
        // An injected connection is independently gated too.
        (new \ReflectionProperty($this->db, 'DSN'))->setValue($this->db, 'remote DSN');
        $this->assertRefused(fn () => $this->service->run(true), 'literal loopback');
        $this->assertRefused(fn () => $this->service->run(false, 1, 'password123'), 'literal loopback');
    }

    public function testCommandRefusesActualTestingEnvironmentWithNonzeroExit(): void
    {
        $command = new CreateTestAccounts(new NullLogger(), Services::commands());
        // CI's CLI writes to STDERR, rather than prompting or throwing a driver exception.
        self::assertSame(EXIT_ERROR, $command->run([]));
        self::assertSame(0, $this->db->table('users')->countAllResults());
    }
}
