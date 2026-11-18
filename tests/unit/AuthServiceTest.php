<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mis\FakeMisClient;
use App\Integrations\Mis\HttpMisClient;
use App\Integrations\Mis\MisClientInterface;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/**
 * @internal
 */
final class AuthServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';
    protected $seed      = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private AuthService $authService;
    private UserModel $userModel;
    private PersonModel $personModel;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        $this->userModel = new UserModel();
        $this->personModel = new PersonModel();

        $this->authService = new AuthService(
            misClient: new FakeMisClient(),
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    public function testStudentLoginProvisionsPersonProfileAndUser(): void
    {
        $session = $this->authService->login('20230001', 'any_password');

        $this->assertNotNull($session);
        $this->assertSame('20230001', $session['identifier']);
        $this->assertSame('JUAN DELA CRUZ', $session['name']);
        $this->assertSame('student', $session['role']);
        $this->assertTrue($session['logged_in']);

        // Verify in DB
        $person = $this->personModel->findByMisId('20230001', 'student');
        $this->assertNotNull($person);
        $this->assertSame('JUAN', $person['first_name']);
        $this->assertSame('DELA CRUZ', $person['last_name']);

        $studentProfile = (new StudentProfileModel())->findByPersonId((int) $person['id']);
        $this->assertNotNull($studentProfile);
        $this->assertSame('BSIT', $studentProfile['program']);
        $this->assertSame(3, (int) $studentProfile['year_level']);

        $user = $this->userModel->where('username', '20230001')->first();
        $this->assertNotNull($user);
        $this->assertSame('student', $user['role']);
        $this->assertNull($user['password_hash'], 'MIS-authenticated accounts must not have a local password hash.');
    }

    public function testEmployeeLoginProvisionsPersonProfileAndPreservesRole(): void
    {
        $session = $this->authService->login('20269001', 'any_password');

        $this->assertNotNull($session);
        $this->assertSame('20269001', $session['identifier']);
        $this->assertSame('ROBERTO HERNANDEZ', $session['name']);
        $this->assertSame('employee', $session['role']);
        $this->assertTrue($session['is_teaching']);

        // Verify in DB
        $person = $this->personModel->findByMisId('20269001', 'employee');
        $this->assertNotNull($person);
        $this->assertSame('ROBERTO', $person['first_name']);

        $empProfile = (new EmployeeProfileModel())->findByPersonId((int) $person['id']);
        $this->assertNotNull($empProfile);
        $this->assertSame('College of Nursing', $empProfile['department']);
        $this->assertSame(1, (int) $empProfile['is_teaching']);
    }

    public function testLocalSuperadminPasswordLogin(): void
    {
        // Insert local superadmin
        $this->userModel->insert([
            'tenant_id'     => 1,
            'username'      => 'admin@foundationu.com',
            'email'         => 'admin@foundationu.com',
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
            'role'          => 'superadmin',
            'status'        => 'active',
        ]);

        // Wrong password fails
        $fail = $this->authService->login('admin@foundationu.com', 'wrong_pass');
        $this->assertNull($fail);

        // Correct password succeeds
        $session = $this->authService->login('admin@foundationu.com', 'secret123');
        $this->assertNotNull($session);
        $this->assertSame('superadmin', $session['role']);
        $this->assertContains('clinic.queue.read', $session['permissions']);
    }

    public function testUnknownCredentialsReturnsNull(): void
    {
        $session = $this->authService->login('99999999', 'invalid');
        $this->assertNull($session);
    }

    public function testStudent422RejectionFallsBackToWrappedEmployeeLogin(): void
    {
        $requests = [];
        $client = new HttpMisClient('https://mis.example.test/sandbox', 'test-key', 5,
            static function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
                $requests[] = [$url, json_decode((string) $body, true)];
                if (str_ends_with($url, '/students/login')) {
                    return [
                        'status' => 422,
                        'body'   => json_encode([
                            'message' => 'The selected student id is invalid.',
                            'errors'  => ['student_id' => ['The selected student id is invalid.']],
                        ], JSON_THROW_ON_ERROR),
                    ];
                }
                return [
                    'status' => 200,
                    'body'   => json_encode([
                        'status' => 'success',
                        'data'   => [
                            'employee' => [
                                'id'              => 9,
                                'employee_id'     => 'EMP-FIXTURE-218',
                                'firstname'       => 'Casey',
                                'middle_name'     => 'Test',
                                'lastname'        => 'Fixture',
                                'department_code' => 'DEPT-96',
                                'department_name' => 'Fixture Department',
                                'position'        => 'Test Lecturer',
                                'email_address'   => 'casey.fixture@example.test',
                            ],
                            'access_token'  => 'tok',
                            'refresh_token' => 'ref',
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            });

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $session = $auth->login('EMP-FIXTURE-218', 'employee-secret');

        $this->assertNotNull($session);
        $this->assertCount(2, $requests);
        $this->assertSame('EMP-FIXTURE-218', $session['identifier']);
        $this->assertSame('employee', $session['role']);
        $this->assertSame('casey.fixture@example.test', $session['email']);

        $empProfile = (new EmployeeProfileModel())->findByPersonId((int) $session['person_id']);
        $this->assertNotNull($empProfile);
        $this->assertSame('Fixture Department', $empProfile['department']);
        $this->assertSame('Test Lecturer', $empProfile['position']);
    }

    public function testCaseInsensitiveEmployeeIdAndEmailSignInResolution(): void
    {
        $seenIds = [];
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willReturnCallback(function (string $id, string $password) use (&$seenIds): array {
            $seenIds[] = $id;
            return [
                'data' => [
                    'employee_id'   => 'EMP-FIXTURE-CASE',
                    'first_name'    => 'Taylor',
                    'last_name'     => 'Fixture',
                    'email_address' => 'taylor.fixture@example.test',
                ],
            ];
        });

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $first = $auth->login('emp-fixture-case', 'secret');
        $this->assertNotNull($first);
        $this->assertSame('EMP-FIXTURE-CASE', $first['identifier']);

        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        $second = $auth->login('taylor.fixture@example.test', 'secret');
        $this->assertNotNull($second);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(['emp-fixture-case', 'EMP-FIXTURE-CASE'], $seenIds);
    }

    public function testSharedMisIdBetweenStudentAndEmployeeDoesNotCollideOnUsername(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->exactly(2))->method('studentLogin')
            ->willReturnOnConsecutiveCalls(
                ['data' => ['student_id' => 'SHARED-FIXTURE-ID', 'first_name' => 'Dual', 'last_name' => 'Fixture']],
                null
            );
        $client->expects($this->once())->method('employeeLogin')
            ->willReturn(['data' => ['employee_id' => 'SHARED-FIXTURE-ID', 'first_name' => 'Dual', 'last_name' => 'Fixture']]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $student = $auth->login('SHARED-FIXTURE-ID', 'student-pass');
        $this->assertNotNull($student);
        $this->assertSame('student', $student['role']);

        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        $employee = $auth->login('SHARED-FIXTURE-ID', 'employee-pass');
        $this->assertNotNull($employee);
        $this->assertSame('employee', $employee['role']);
        $this->assertNotSame($student['id'], $employee['id']);
        $this->assertNotSame($student['person_id'], $employee['person_id']);
        $this->assertSame('employee:SHARED-FIXTURE-ID', $employee['username']);
    }
}
