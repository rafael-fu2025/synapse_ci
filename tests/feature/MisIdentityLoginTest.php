<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Mis\FakeMisClient;
use App\Integrations\Mis\HttpMisClient;
use App\Integrations\Mis\MisClientInterface;
use App\Integrations\Mis\MisUnavailableException;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/** No network: synthetic contract fixtures, not verified campus MIS payloads. */
final class MisIdentityLoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\\Database\\Seeds\\PermissionsAndRolesSeeder';

    private array $tenants = [];
    private array $environment = [];
    private int $tenantId;
    private string $misId;
    private const PASSWORD = 'MIS-password-must-never-persist';
    private const TOKEN = 'MIS-access-token-must-never-persist';
    private const UNAVAILABLE_MESSAGE = 'MIS sign-in is currently unavailable. Connect to the university network and try again, or contact support.';

    protected function setUp(): void
    {
        // Must precede DatabaseTestTrait migrations and all fixture writes.
        \Tests\Support\InventoryTestDatabase::verify();
        parent::setUp();
        AuthenticatedUser::clear();
        session()->remove(['error', 'success', 'warning', '_ci_old_input']);
        $this->tenantId = $this->tenant();
        $this->misId = uniqid('MIS_ID_');
        $this->setEnvironment('SYNAPSE_TENANT_ID', (string) $this->tenantId);
        CurrentTenant::set($this->tenantId);
        Services::injectMock('misClient', $this->createMock(MisClientInterface::class));
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        session()->remove(['theme', 'error', 'success', 'warning', '_ci_old_input', '_ci_previous_url']);
        Services::resetSingle('misClient');
        Services::resetSingle('renderer');
        Services::resetSingle('security');
        service('superglobals')->setCookieArray([]);
        try {
            foreach ($this->tenants as $id) {
                foreach (['users', 'student_profiles', 'employee_profiles', 'people', 'tenants'] as $table) {
                    $this->db->table($table)->where($table === 'tenants' ? 'id' : 'tenant_id', $id)->delete();
                }
            }
        } finally {
            foreach ($this->environment as $key => [$process, $env, $server]) {
                putenv($process === false ? $key : $key . '=' . $process);
                if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
                if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
            }
            CurrentTenant::reset();
            parent::tearDown();
        }
    }

    private function setEnvironment(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->environment)) {
            $this->environment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    private function tenant(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('mis_login_'), 'name' => 'Isolated MIS login',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        $this->tenants[] = $id;
        return $id;
    }

    private function person(string $kind, ?int $tenant = null): int
    {
        return (int) (new PersonModel())->insert(['tenant_id' => $tenant ?? $this->tenantId,
            'kind' => $kind, 'mis_id' => $this->misId, 'first_name' => 'Imported',
            'middle_name' => 'Retained', 'last_name' => 'Person']);
    }

    private function user(int $person, string $role = 'employee'): int
    {
        return (int) (new UserModel())->insert(['tenant_id' => $this->tenantId, 'person_id' => $person,
            'username' => $this->misId, 'role' => $role, 'status' => 'active']);
    }

    private function payload(string $kind, array $fields = []): array
    {
        return ['response' => 'success', 'access_token' => self::TOKEN, 'data' => ['data' => $fields + [
            $kind . 'Id' => $this->misId, 'firstName' => 'Official', 'lastName' => 'Identity',
            'role' => 'superadmin', 'permissions' => ['rbac.manage'], 'password' => self::PASSWORD,
        ], 'refresh_token' => self::TOKEN]];
    }

    private function client(?array $student, ?array $employee, ?string $unavailableAt = null): MisClientInterface
    {
        $client = $this->createMock(MisClientInterface::class);
        $calls = [];
        $client->expects($this->once())->method('studentLogin')->willReturnCallback(function (string $id, string $password) use (&$calls, $student, $unavailableAt) {
            $this->assertSame($this->misId, $id);
            $this->assertSame(self::PASSWORD, $password);
            $calls[] = 'student';
            if ($unavailableAt === 'student') throw new MisUnavailableException();
            return $student;
        });
        $client->method('employeeLogin')->willReturnCallback(function (string $id, string $password) use (&$calls, $employee, $student, $unavailableAt) {
            $this->assertSame(['student'], $calls, 'Student authentication must run first and only once.');
            $this->assertNull($student, 'Employee fallback is only allowed for invalid student credentials.');
            $this->assertSame($this->misId, $id);
            $this->assertSame(self::PASSWORD, $password);
            $calls[] = 'employee';
            if ($unavailableAt === 'employee') throw new MisUnavailableException();
            return $employee;
        });
        $client->expects($student !== null || $unavailableAt === 'student' ? $this->never() : $this->once())->method('employeeLogin');
        Services::injectMock('misClient', $client);
        return $client;
    }

    private function counts(): array
    {
        $counts = [];
        foreach (['people', 'users', 'student_profiles', 'employee_profiles', 'health_profiles',
            'clinic_encounters', 'clinic_vitals', 'clinic_treatments', 'counselling_cases', 'counselling_notes'] as $table) {
            $counts[$table] = $this->db->table($table)->countAllResults();
        }
        return $counts;
    }

    private function assertNoSecrets(): void
    {
        $stored = session()->get();
        foreach (['people', 'users', 'student_profiles', 'employee_profiles'] as $table) {
            $stored[$table] = $this->db->table($table)->where('tenant_id', $this->tenantId)->get()->getResultArray();
        }
        $serialized = serialize($stored);
        $this->assertStringNotContainsString(self::PASSWORD, $serialized);
        $this->assertStringNotContainsString(self::TOKEN, $serialized);
        foreach ($stored['users'] as $user) $this->assertEmpty($user['password_hash']);
    }

    public static function kinds(): iterable
    {
        yield 'student' => ['student'];
        yield 'employee' => ['employee'];
    }

    #[DataProvider('kinds')]
    public function testImportedPersonGainsOnlyOneBaseUserAfterSuccessfulNestedMisLogin(string $kind): void
    {
        $person = $this->person($kind);
        $before = $this->counts();
        $this->assertSame(0, $this->db->table('users')->where('person_id', $person)->countAllResults());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $payload = $this->payload($kind, ['departmentName' => 'Official Department', 'employmentStatus' => 'active']);
            $client = $this->client($kind === 'student' ? $payload : null, $kind === 'employee' ? $payload : null);
            $identity = (new AuthService(misClient: $client))->login($this->misId, self::PASSWORD);
            $this->assertNotNull($identity);
            $this->assertSame($person, $identity['person_id']);
            $this->assertSame($kind, $identity['role']);
            $this->assertSame($this->misId, $identity['identifier']);
            $this->assertSame($this->misId, $identity['username']);
            $this->assertNotContains('rbac.manage', $identity['permissions']);
            $this->assertNoSecrets();
            AuthenticatedUser::clear();
            CurrentTenant::set($this->tenantId);
        }
        $after = $this->counts();
        $before['users']++;
        $before[$kind . '_profiles']++;
        $this->assertSame($before, $after, 'Login must not duplicate imported people or create clinical records.');
        $user = $this->db->table('users')->where('person_id', $person)->get()->getRowArray();
        $this->assertSame($this->misId, $user['username']);
        if ($kind === 'employee') {
            $profile = $this->db->table('employee_profiles')->where('person_id', $person)->get()->getRowArray();
            $this->assertSame('Official Department', $profile['department']);
            $this->assertSame('active', $profile['employment_status']);
        }
    }

    #[DataProvider('kinds')]
    public function testNewMisIdentitiesReceiveOnlyTheBaseRole(string $kind): void
    {
        $payload = $this->payload($kind);
        $this->client($kind === 'student' ? $payload : null, $kind === 'employee' ? $payload : null);
        $identity = (new AuthService())->login($this->misId, self::PASSWORD);
        $this->assertNotNull($identity);
        $this->assertSame($kind, $identity['role']);
        $this->assertSame($this->misId, $identity['username']);
        $this->assertSame(1, $this->db->table('people')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertNoSecrets();
    }

    #[DataProvider('kinds')]
    public function testPartialMisProfilePreservesImportedFields(string $kind): void
    {
        $person = $this->person($kind);
        $fields = $kind === 'student'
            ? ['program' => 'BSIT', 'year_level' => 3, 'section' => 'A', 'department' => 'Imported Department',
                            'enrollment_type' => 'continuing', 'is_graduating' => 1]
            : ['department' => 'Imported Department', 'position' => 'Professor', 'is_teaching' => 1, 'employment_status' => 'on_leave'];
        $model = $kind === 'student' ? new StudentProfileModel() : new EmployeeProfileModel();
        $model->insert(['tenant_id' => $this->tenantId, 'person_id' => $person] + $fields);
        $beforePerson = $this->db->table('people')->where('id', $person)->get()->getRowArray();
        $beforeProfile = $this->db->table($kind . '_profiles')->where('person_id', $person)->get()->getRowArray();
        $payload = ['data' => ['data' => [$kind . 'Id' => $this->misId]]];
        $this->client($kind === 'student' ? $payload : null, $kind === 'employee' ? $payload : null);
        $this->assertNotNull((new AuthService())->login($this->misId, self::PASSWORD));
        $afterPerson = $this->db->table('people')->where('id', $person)->get()->getRowArray();
        foreach (['first_name', 'middle_name', 'last_name'] as $field) $this->assertSame($beforePerson[$field], $afterPerson[$field], $field);
        $afterProfile = $this->db->table($kind . '_profiles')->where('person_id', $person)->get()->getRowArray();
        foreach (array_keys($fields) as $field) $this->assertSame($beforeProfile[$field], $afterProfile[$field], $field);
    }

    public function testStudentAudienceRefreshClearsExplicitUnknownsButPreservesOmission(): void
    {
        $person = $this->person('student');
        $model = new StudentProfileModel();
        $profileId = (int) $model->insert([
            'tenant_id' => $this->tenantId, 'person_id' => $person,
            'enrollment_type' => 'continuing', 'is_graduating' => 1,
        ]);
        foreach ([
            [[], 'continuing', 1],
            [['status' => 'unknown', 'student_status' => 'graduating'], 'continuing', 1],
            [['student_type' => 'unknown', 'is_graduating' => 'unknown'], null, null],
            [['enrollment_type' => null, 'is_graduating' => null], null, null],
            [['student_type' => 'new', 'enrollment_type' => 'transferee'], null, 1],
            [['student_type' => null, 'enrollment_type' => 'continuing'], null, 1],
            [['enrollment_type' => 'unknown'], null, 1],
            [['student_type' => ''], null, 1],
            [['is_graduating' => 2], 'continuing', null],
            [['is_graduating' => ''], 'continuing', null],
            [['is_graduating' => false], 'continuing', 0],
            [['student_type' => ' TRANSFEREE ', 'is_graduating' => false], 'transferee', 0],
        ] as [$fields, $type, $graduating]) {
            // Every case starts eligible so a clear cannot pass due to an earlier clear.
            $model->update($profileId, ['enrollment_type' => 'continuing', 'is_graduating' => 1]);
            $payload = $this->payload('student', $fields);
            $this->client($payload, null);
            $this->assertNotNull((new AuthService())->login($this->misId, self::PASSWORD));
            $profile = (new StudentProfileModel())->findByPersonId($person);
            $this->assertSame($type, $profile['enrollment_type']);
            $this->assertSame($graduating, $profile['is_graduating'] === null ? null : (int) $profile['is_graduating']);
            $metadata = (new PersonModel())->find($person);
            $mapped = (new \App\Integrations\Mis\MisProfileMapper())->mapStudent($payload);
            $this->assertSame($mapped['raw_hash'], $metadata['mis_payload_hash']);
            $this->assertNotEmpty($metadata['mis_synced_at']);
            $this->assertNoSecrets();
            AuthenticatedUser::clear();
            CurrentTenant::set($this->tenantId);

            // A subsequent omitted field preserves the result, including NULL or false.
            $this->client($this->payload('student'), null);
            $this->assertNotNull((new AuthService())->login($this->misId, self::PASSWORD));
            $afterOmission = $model->findByPersonId($person);
            foreach (['enrollment_type', 'is_graduating'] as $field) {
                $this->assertSame($profile[$field], $afterOmission[$field], $field);
            }
            $this->assertNoSecrets();
            AuthenticatedUser::clear();
            CurrentTenant::set($this->tenantId);
        }
    }

    public function testNewFourthYearStudentDoesNotInferAudienceClassification(): void
    {
        $this->client($this->payload('student', ['year_level' => 4, 'status' => 'new', 'student_status' => 'graduating']), null);
        $identity = (new AuthService())->login($this->misId, self::PASSWORD);
        $this->assertNotNull($identity);
        $profile = (new StudentProfileModel())->findByPersonId((int) $identity['person_id']);
        $this->assertSame(4, (int) $profile['year_level']);
        $this->assertNull($profile['enrollment_type']);
        $this->assertNull($profile['is_graduating']);
        $this->assertNoSecrets();
    }

    public function testImportedEmployeeDoesNotGainAUserOnInvalidCredentials(): void
    {
        $this->person('employee');
        $before = $this->counts();
        $this->client(null, null);
        $this->assertNull((new AuthService())->login($this->misId, self::PASSWORD));
        $this->assertSame($before, $this->counts());
        $this->assertNull(session('user'));
        $this->assertNoSecrets();
    }

    public function testMatchingUsesTenantKindAndMisIdNotJustMisId(): void
    {
        $otherTenant = $this->tenant();
        $other = $this->person('employee', $otherTenant);
        $wrongKind = $this->person('student');
        $target = $this->person('employee');
        $snapshots = $this->db->table('people')->whereIn('id', [$other, $wrongKind])->orderBy('id')->get()->getResultArray();
        $this->client(null, $this->payload('employee'));
        $identity = (new AuthService())->login($this->misId, self::PASSWORD);
        $this->assertNotNull($identity);
        $this->assertSame($target, $identity['person_id']);
        $this->assertSame($this->tenantId, $identity['tenant_id']);
        $this->assertSame($snapshots, $this->db->table('people')->whereIn('id', [$other, $wrongKind])->orderBy('id')->get()->getResultArray());
        $this->assertSame(0, $this->db->table('users')->whereIn('person_id', [$other, $wrongKind])->countAllResults());
    }

    public static function unavailableStages(): iterable
    {
        yield 'student unavailable' => ['student'];
        yield 'employee unavailable' => ['employee'];
    }

    #[DataProvider('unavailableStages')]
    public function testMisOutagePropagatesWithoutProvisioningOrFallback(string $stage): void
    {
        $before = $this->counts();
        $this->client(null, null, $stage);
        try {
            (new AuthService())->login($this->misId, self::PASSWORD);
            $this->fail('MIS outages must not be treated as invalid credentials.');
        } catch (MisUnavailableException $exception) {
            $this->assertSame((new MisUnavailableException())->getMessage(), $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame($before, $this->counts());
        $this->assertNull(session('user'));
        $this->assertNoSecrets();
    }

    public static function invalidProfiles(): iterable
    {
        foreach (['student', 'employee'] as $kind) {
            yield "$kind empty" => [$kind, []];
            yield "$kind missing ID" => [$kind, ['data' => ['firstName' => 'No', 'lastName' => 'Identifier']]];
            yield "$kind malformed envelope" => [$kind, ['data' => 'not-a-profile']];
            yield "$kind array ID" => [$kind, ['data' => [$kind . 'Id' => ['invalid']]]];
            yield "$kind mismatched ID" => [$kind, ['data' => [$kind . 'Id' => 'DIFFERENT-OFFICIAL-ID', 'firstName' => 'Other', 'lastName' => 'Identity']]];
            yield "$kind missing first name" => [$kind, ['data' => ['lastName' => 'Incomplete']]];
            yield "$kind missing last name" => [$kind, ['data' => ['firstName' => 'Incomplete']]];
        }
    }

    #[DataProvider('invalidProfiles')]
    public function testMalformedOrIncompleteNewIdentityIsUnavailableWithoutProvisioning(string $kind, array $payload): void
    {
        if (is_array($payload['data'] ?? null)
            && (($payload['data']['firstName'] ?? null) === 'Incomplete'
                || ($payload['data']['lastName'] ?? null) === 'Incomplete')) {
            $payload['data'][$kind . 'Id'] = $this->misId;
        }
        $before = $this->counts();
        $this->client($kind === 'student' ? $payload : null, $kind === 'employee' ? $payload : null);
        try {
            (new AuthService())->login($this->misId, self::PASSWORD);
            $this->fail('An unusable authoritative identity must fail closed.');
        } catch (MisUnavailableException $exception) {
            $this->assertSame((new MisUnavailableException())->getMessage(), $exception->getMessage());
        }
        $this->assertSame($before, $this->counts());
        $this->assertNull(session('user'));
        $this->assertNoSecrets();
    }

    public static function blockedAccounts(): iterable
    {
        yield 'suspended user' => ['users', ['status' => 'suspended']];
        yield 'locked user' => ['users', ['status' => 'locked']];
        yield 'deleted user' => ['users', ['deleted_at' => '2026-01-01 00:00:00']];
        yield 'deleted person' => ['people', ['deleted_at' => '2026-01-01 00:00:00']];
    }

    #[DataProvider('blockedAccounts')]
    public function testBlockedAccountsAreNeverRevivedOrRefreshed(string $table, array $changes): void
    {
        $person = $this->person('employee');
        $user = $this->user($person, 'clinic_staff');
        $this->db->table($table)->where('id', $table === 'users' ? $user : $person)->update($changes);
        $before = [];
        foreach (['people', 'users', 'employee_profiles'] as $name) $before[$name] = $this->db->table($name)->where('tenant_id', $this->tenantId)->get()->getResultArray();
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->never())->method('studentLogin');
        $client->expects($this->never())->method('employeeLogin');
        Services::injectMock('misClient', $client);
        $this->assertNull((new AuthService())->login($this->misId, self::PASSWORD));
        foreach ($before as $name => $rows) $this->assertSame($rows, $this->db->table($name)->where('tenant_id', $this->tenantId)->get()->getResultArray(), $name);
        $this->assertNull(session('user'));
    }

    #[DataProvider('blockedAccounts')]
    public function testBlockedLinkedAccountWithDifferentUsernameIsNotRevivedAfterMisAuthentication(string $table, array $changes): void
    {
        $person = $this->person('employee');
        $user = $this->user($person, 'clinic_staff');
        // Bypass the username precheck while retaining the same authoritative MIS ID.
        $this->db->table('users')->where('id', $user)->update(['username' => 'legacy_' . $this->misId]);
        $this->db->table($table)->where('id', $table === 'users' ? $user : $person)->update($changes);
        $before = [];
        foreach (['people', 'users', 'employee_profiles'] as $name) $before[$name] = $this->db->table($name)->where('tenant_id', $this->tenantId)->get()->getResultArray();
        $this->client(null, $this->payload('employee'));
        $this->assertNull((new AuthService())->login($this->misId, self::PASSWORD));
        foreach ($before as $name => $rows) $this->assertSame($rows, $this->db->table($name)->where('tenant_id', $this->tenantId)->get()->getResultArray(), $name);
        $this->assertNull(session('user'));
        $this->assertNoSecrets();
    }

    public static function staffRoles(): iterable
    {
        yield 'clinic' => ['clinic_staff', 'clinic/queue'];
        yield 'guidance' => ['counsellor', 'guidance/cases'];
        yield 'facilities' => ['facilities_op', 'facilities'];
    }

    #[DataProvider('staffRoles')]
    public function testAssignedStaffRoleKeepsWorkstationAndPersonalPortalAccess(string $role, string $workstation): void
    {
        $person = $this->person('employee');
        $this->user($person, $role);
        $this->client(null, $this->payload('employee'));
        $identity = (new AuthService())->login($this->misId, self::PASSWORD);
        $this->assertNotNull($identity);
        $this->assertSame($role, $identity['role']);
        foreach ([$workstation, 'portal'] as $path) {
            $this->withHeaders([])->withSession(['tenant_id' => $this->tenantId, 'user' => $identity, 'theme' => 'dark'])->get($path)->assertStatus(200);
            $this->assertSame($role, session('user')['role']);
        }
        $this->assertNoSecrets();
    }

    private function postLogin(bool $csrf = true, array $credentials = [])
    {
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession(['theme' => 'dark', '_ci_previous_url' => site_url('auth/login')]
                + ($csrf ? ['_ci_old_input' => ['post' => ['password' => self::PASSWORD]]] : []))
            ->post('auth/login', $credentials + ['identifier' => $this->misId, 'password' => self::PASSWORD]
                + ($csrf ? [config('Security')->tokenName => $hash] : []));
    }

    #[DataProvider('kinds')]
    public function testHttpOfficialNumberLoginLinksImportedPersonAndOpensPersonalPortal(string $kind): void
    {
        $person = $this->person($kind);
        $payload = $this->payload($kind);
        $this->client($kind === 'student' ? $payload : null, $kind === 'employee' ? $payload : null);
        $this->postLogin()->assertRedirectTo('/portal');
        $identity = session('user');
        $this->assertSame($person, $identity['person_id']);
        $this->assertSame($kind, $identity['role']);
        $this->assertSame($this->misId, $identity['identifier']);
        $this->assertSame('dark', session('theme'));
        $this->assertSame(1, $this->db->table('users')->where('tenant_id', $this->tenantId)->where('person_id', $person)->countAllResults());
        $this->assertNull(session('_ci_old_input'));
        $this->assertNoSecrets();
        $this->withHeaders([])->withSession(['tenant_id' => $this->tenantId, 'user' => $identity])->get('portal')->assertStatus(200);
    }

    public function testHttpMissingCsrfCannotCallMisOrProvision(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->never())->method('studentLogin');
        $client->expects($this->never())->method('employeeLogin');
        Services::injectMock('misClient', $client);
        $before = $this->counts();
        $this->postLogin(false)->assertStatus(403);
        $this->assertSame($before, $this->counts());
        $this->assertNull(session('user'));
        $this->assertNull(session('_ci_old_input'));
        $this->assertSame('dark', session('theme'));
        $this->assertNoSecrets();
    }

    #[DataProvider('unavailableStages')]
    public function testHttpMisOutageRedirectsWithSafeUnavailableMessageAndNoOldInput(string $stage): void
    {
        $before = $this->counts();
        $this->client(null, null, $stage);
        $result = $this->postLogin();
        $result->assertRedirectTo('/auth/login');
        $this->assertSame(self::UNAVAILABLE_MESSAGE, session('error'));
        $this->assertStringNotContainsString('Invalid ID or password', (string) session('error'));
        $this->assertStringNotContainsString(self::PASSWORD, $result->getBody());
        $this->assertStringNotContainsString(self::TOKEN, $result->getBody());
        $this->assertNull(session('_ci_old_input'));
        $this->assertNull(session('user'));
        $this->assertSame('dark', session('theme'));
        $this->assertSame($before, $this->counts());
        $this->assertNoSecrets();
    }

    #[DataProvider('kinds')]
    public function testHttpMalformedMisIdentityIsSafeUnavailableNotInvalidCredentials(string $kind): void
    {
        $before = $this->counts();
        $payload = ['data' => ['data' => ['firstName' => 'Missing', 'lastName' => 'Identifier']]];
        $this->client($kind === 'student' ? $payload : null, $kind === 'employee' ? $payload : null);
        $this->postLogin()->assertRedirectTo('/auth/login');
        $this->assertSame(self::UNAVAILABLE_MESSAGE, session('error'));
        $this->assertSame('dark', session('theme'));
        $this->assertNull(session('_ci_old_input'));
        $this->assertNull(session('user'));
        $this->assertSame($before, $this->counts());
        $this->assertNoSecrets();
    }

    public function testHttpInvalidCredentialsAndMalformedInputKeepThemeWithoutFlashingCredentials(): void
    {
        foreach ([[], ['identifier' => ['bad'], 'password' => [self::PASSWORD]]] as $credentials) {
            if ($credentials === []) {
                $this->client(null, null);
            } else {
                $client = $this->createMock(MisClientInterface::class);
                $client->expects($this->never())->method('studentLogin');
                $client->expects($this->never())->method('employeeLogin');
                Services::injectMock('misClient', $client);
            }
            $before = $this->counts();
            $this->postLogin(true, $credentials)->assertRedirectTo('/auth/login');
            $this->assertNull(session('_ci_old_input'));
            $this->assertNull(session('user'));
            $this->assertSame('dark', session('theme'));
            $this->assertSame($before, $this->counts());
            $this->assertNoSecrets();
        }
    }

    public function testLocalBreakglassLoginDoesNotCallMis(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->never())->method('studentLogin');
        $client->expects($this->never())->method('employeeLogin');
        Services::injectMock('misClient', $client);
        (new UserModel())->insert(['tenant_id' => $this->tenantId, 'username' => $this->misId,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'role' => 'superadmin', 'status' => 'active']);
        $service = new AuthService();
        $this->assertNull($service->login($this->misId, 'wrong-local-password'));
        $identity = $service->login($this->misId, self::PASSWORD);
        $this->assertNotNull($identity);
        $this->assertSame('superadmin', $identity['role']);
        $this->assertNull($identity['person_id']);
        $this->assertStringNotContainsString(self::PASSWORD, serialize(session()->get()));
    }

    public function testFactoryRequiresExplicitFakeOptInAndDisabledLoginFailsClosed(): void
    {
        $this->setEnvironment('FUMIS_ENABLED', 'false');
        $this->setEnvironment('FUMIS_FAKE_ENABLED', 'false');
        $disabled = Services::misClient(false);
        $this->assertSame('App\\Integrations\\Mis\\DisabledMisClient', $disabled::class);
        foreach (['studentLogin', 'employeeLogin'] as $method) {
            try {
                $disabled->$method($this->misId, self::PASSWORD);
                $this->fail('Disabled MIS must not authenticate demo credentials.');
            } catch (MisUnavailableException $exception) {
                $this->assertSame((new MisUnavailableException())->getMessage(), $exception->getMessage());
            }
        }
        $this->setEnvironment('FUMIS_FAKE_ENABLED', 'true');
        $this->assertInstanceOf(FakeMisClient::class, Services::misClient(false));
        $this->setEnvironment('FUMIS_ENABLED', 'true');
        // Construct only; never send credentials or perform an HTTP request.
        $this->assertInstanceOf(HttpMisClient::class, Services::misClient(false));
    }
}
