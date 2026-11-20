<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mis\FakeMisClient;
use App\Integrations\Mis\HttpMisClient;
use App\Integrations\Mis\MisAccountException;
use App\Integrations\Mis\MisClientInterface;
use App\Integrations\Mis\MisInactiveAccountException;
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

    /**
     * The upstream identifier is authoritative and may be zero padded. A padded difference is the
     * same employee, so an authenticated sign-in must not be rejected for formatting.
     */
    public function testIdentifierPaddingDifferenceStillAuthenticates(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willReturn([
            'data' => [
                'employee_id' => '012345',
                'first_name'  => 'Casey',
                'last_name'   => 'Fixture',
            ],
        ]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $session = $auth->login('12345', 'secret');

        $this->assertNotNull($session, 'Zero padding must not fail an authenticated sign-in.');
        $this->assertSame('012345', $session['identifier']);
        $this->assertSame('employee', $session['role']);
    }

    /**
     * A different identity is a failed authentication, not a service outage: the sign-in was
     * simply not verified for the identifier that was entered.
     */
    public function testDifferentReturnedIdentifierIsRejectedWithoutAnOutage(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willReturn([
            'data' => ['employee_id' => '999999', 'first_name' => 'Casey', 'last_name' => 'Fixture'],
        ]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->assertNull($auth->login('12345', 'secret'));
    }

    /**
     * An upstream that answered but could not verify the account must reach the caller as an
     * unverifiable account, so the actor is told to contact HR rather than to check the network.
     */
    public function testUnverifiableAccountPropagatesInsteadOfBecomingARejectedPassword(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willThrowException(new MisAccountException());

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->expectException(MisAccountException::class);
        $auth->login('12345', 'secret');
    }

    /**
     * The MIS employee directory omits an account that is not active, and the live MIS systems
     * report exactly that condition to the employee. An employee the directory does not list is
     * therefore told to contact HR, in the words the university systems use.
     */
    public function testEmployeeAbsentFromTheDirectoryReportsAnInactiveAccount(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willThrowException(new MisAccountException());
        $client->method('searchEmployees')->willReturn([
            'status'  => 'success',
            'message' => 'Success',
            'data'    => ['data' => [], 'limit' => 25, 'current_page' => 1, 'max_page' => 0],
        ]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->expectException(MisInactiveAccountException::class);
        $this->expectExceptionMessage('Your MIS account is not active. Please contact the HR Department regarding this issue.');
        $auth->login('12345', 'secret');
    }

    /**
     * An identifier the directory does list is an active employee, so an unverifiable answer is a
     * transient upstream failure and must not be reported as an inactive account.
     */
    public function testEmployeeListedInTheDirectoryKeepsTheUnverifiableAccountError(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willThrowException(new MisAccountException());
        $client->method('searchEmployees')->willReturn([
            'status'  => 'success',
            'message' => 'Success',
            'data'    => [
                'data' => [['employee_id' => '12345', 'first_name' => 'Casey', 'last_name' => 'Fixture']],
                'limit' => 25, 'current_page' => 1, 'max_page' => 1,
            ],
        ]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->expectException(MisAccountException::class);
        $this->expectExceptionMessage('MIS could not verify this account.');
        $auth->login('12345', 'secret');
    }

    /**
     * A directory that cannot be read is unknown, not empty. Reporting it as an inactive account
     * would send a valid employee to HR for the wrong reason.
     */
    public function testUnreadableDirectoryKeepsTheUnverifiableAccountError(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willThrowException(new MisAccountException());
        $client->method('searchEmployees')->willReturn([
            'data' => [], 'limit' => 25, 'current_page' => 1, 'max_page' => 0,
        ]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->expectException(MisAccountException::class);
        $this->expectExceptionMessage('MIS could not verify this account.');
        $auth->login('12345', 'secret');
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

    public function testStaffOnlyModeSkipsStudentNamespace(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->never())->method('studentLogin');
        $client->expects($this->once())->method('employeeLogin')
            ->with('EMP-STAFF-ONLY', 'secret')
            ->willReturn([
                'data' => [
                    'employee_id' => 'EMP-STAFF-ONLY',
                    'first_name'  => 'Elena',
                    'last_name'   => 'Clinician',
                ],
            ]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $session = $auth->login('EMP-STAFF-ONLY', 'secret', false);
        $this->assertNotNull($session);
        $this->assertSame('employee', $session['role']);
        $this->assertSame('EMP-STAFF-ONLY', $session['identifier']);
    }

    public function testSyncedEmployeeSkipsStudentNamespaceOnSharedPortalAndFallsBackOnRejection(): void
    {
        $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SYNCED-EMP-77',
            'first_name' => 'Maria',
            'last_name'  => 'Reyes',
        ]);

        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->never())->method('studentLogin');
        $client->expects($this->once())->method('employeeLogin')
            ->with('SYNCED-EMP-77', 'emp-pass')
            ->willReturn([
                'data' => [
                    'employee_id' => 'SYNCED-EMP-77',
                    'first_name'  => 'Maria',
                    'last_name'   => 'Reyes',
                ],
            ]);

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $session = $auth->login('SYNCED-EMP-77', 'emp-pass');
        $this->assertNotNull($session);
        $this->assertSame('employee', $session['role']);

        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        // If employeeLogin rejects credentials, shared portal still falls back to studentLogin.
        $fallbackCalls = [];
        $fallbackClient = $this->createMock(MisClientInterface::class);
        $fallbackClient->expects($this->once())->method('employeeLogin')->willReturnCallback(function () use (&$fallbackCalls): ?array {
            $fallbackCalls[] = 'employee';
            return null;
        });
        $fallbackClient->expects($this->once())->method('studentLogin')->willReturnCallback(function () use (&$fallbackCalls): ?array {
            $fallbackCalls[] = 'student';
            return null;
        });

        $fallbackAuth = new AuthService(
            misClient: $fallbackClient,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->assertNull($fallbackAuth->login('SYNCED-EMP-77', 'wrong-pass'));
        $this->assertSame(['employee', 'student'], $fallbackCalls);
    }

    public function testDirectoryLookupThrowableIsCaughtAndKeepsUnverifiableAccountError(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willThrowException(new MisAccountException());
        $client->method('searchEmployees')->willThrowException(new \RuntimeException('Directory transport blew up'));

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->expectException(MisAccountException::class);
        $this->expectExceptionMessage('MIS could not verify this account.');
        $auth->login('12345', 'secret');
    }

    public function testDerivedEmailIsDisplayOnlyAndDuplicateEmailsNeverHijackAnotherMisId(): void
    {
        // 1. MIS login without an explicit email must not persist a derived UniversityEmail in DB.
        $session = $this->authService->login('20230001', 'any_password');
        $this->assertNotNull($session);
        $this->assertSame('juan.delacruz@foundationu.com', $session['email']);

        $rawPerson = $this->db->table('people')->where('id', $session['person_id'])->get()->getRowArray();
        $rawUser = $this->db->table('users')->where('id', $session['id'])->get()->getRowArray();
        $this->assertNull($rawPerson['email'], 'Derived display emails must not be persisted in people.email.');
        $this->assertNull($rawUser['email'], 'Derived display emails must not be persisted in users.email.');

        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        // 2. If two people share an email in DB (e.g., legacy persisted derived email), email login
        // must never silently map Person B to Person A's mis_id.
        $personA = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'DUP-A-001',
            'first_name' => 'Alex',
            'last_name'  => 'Santos',
            'email'      => 'alex.santos@foundationu.com',
        ]);
        $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'DUP-B-002',
            'first_name' => 'Alex',
            'last_name'  => 'Santos',
            'email'      => 'alex.santos@foundationu.com',
        ]);
        $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $personA,
            'username'  => 'DUP-A-001',
            'email'     => 'alex.santos@foundationu.com',
            'role'      => 'student',
            'status'    => 'active',
        ]);

        $seenIds = [];
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturnCallback(function (string $id) use (&$seenIds): ?array {
            $seenIds[] = $id;
            return null;
        });
        $client->method('employeeLogin')->willReturnCallback(function (string $id) use (&$seenIds): ?array {
            $seenIds[] = $id;
            return null;
        });

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->assertNull($auth->login('alex.santos@foundationu.com', 'secret'));
        $this->assertNotContains('DUP-A-001', $seenIds, 'Ambiguous email in people must never resolve to another person MIS ID.');
    }

    public function testBlockedEmployeeDoesNotFallBackToStudentNamespaceAfterMisAuth(): void
    {
        $personId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SUSPENDED-EMP-1',
            'first_name' => 'Suspended',
            'last_name'  => 'Staff',
        ]);
        $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $personId,
            'username'  => 'legacy_suspended_emp_1',
            'role'      => 'clinic_staff',
            'status'    => 'suspended',
        ]);

        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->once())->method('employeeLogin')
            ->with('SUSPENDED-EMP-1', 'secret')
            ->willReturn([
                'data' => [
                    'employee_id' => 'SUSPENDED-EMP-1',
                    'first_name'  => 'Suspended',
                    'last_name'   => 'Staff',
                ],
            ]);
        $client->expects($this->never())->method('studentLogin');

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->assertNull($auth->login('SUSPENDED-EMP-1', 'secret'));
    }

    public function testMismatchedStudentReturnedIdDoesNotFallBackToEmployeeNamespace(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->once())->method('studentLogin')
            ->with('20239999', 'secret')
            ->willReturn([
                'data' => [
                    'student_id' => '99999999',
                    'first_name' => 'Wrong',
                    'last_name'  => 'Person',
                ],
            ]);
        $client->expects($this->never())->method('employeeLogin');

        $auth = new AuthService(
            misClient: $client,
            userModel: $this->userModel,
            personModel: $this->personModel,
            studentProfileModel: new StudentProfileModel(),
            employeeProfileModel: new EmployeeProfileModel()
        );

        $this->assertNull($auth->login('20239999', 'secret'));
    }
}
