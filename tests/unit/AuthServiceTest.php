<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Mis\FakeMisClient;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class AuthServiceTest extends CIUnitTestCase
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
}
