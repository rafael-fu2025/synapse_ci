<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class PortalSeparationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $studentUserId;
    private int $nurseUserId;
    private int $counsellorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();

        $unique = uniqid();
        $userModel = new UserModel();
        $personModel = new PersonModel();

        // 1. Regular Student
        $studentPerson = (int) $personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'SEP_STU_' . $unique,
            'first_name' => 'Student',
            'last_name'  => 'Consumer',
        ]);
        $this->studentUserId = (int) $userModel->insert([
            'tenant_id' => 1,
            'person_id' => $studentPerson,
            'username'  => 'student_' . $unique,
            'role'      => 'student',
            'status'    => 'active',
        ]);

        // 2. Clinic Staff (Nurse)
        $nursePerson = (int) $personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SEP_NURSE_' . $unique,
            'first_name' => 'Elena',
            'last_name'  => 'Nurse',
        ]);
        $this->nurseUserId = (int) $userModel->insert([
            'tenant_id' => 1,
            'person_id' => $nursePerson,
            'username'  => 'nurse_' . $unique,
            'role'      => 'clinic_staff',
            'status'    => 'active',
        ]);

        // 3. Guidance Counselor
        $counsellorPerson = (int) $personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SEP_COUNS_' . $unique,
            'first_name' => 'Gabriel',
            'last_name'  => 'Counsellor',
        ]);
        $this->counsellorUserId = (int) $userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsellorPerson,
            'username'  => 'counsellor_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
    }

    public function testStudentBlockedFromClinicConsoleAndRedirectedToPortal(): void
    {
        $res = $this->withSession([
            'user' => [
                'tenant_id' => 1,
                'id'        => $this->studentUserId,
                'name'      => 'Student Consumer',
                'role'      => 'student',
                'logged_in' => true,
            ],
            'tenant_id' => 1,
        ])->get('clinic/queue');

        // Must reject student and redirect to personal health portal
        $res->assertRedirectTo('/portal');
    }

    public function testStudentBlockedFromGuidanceCasesAndRedirectedToPortal(): void
    {
        $res = $this->withSession([
            'user' => [
                'tenant_id' => 1,
                'id'        => $this->studentUserId,
                'name'      => 'Student Consumer',
                'role'      => 'student',
                'logged_in' => true,
            ],
            'tenant_id' => 1,
        ])->get('guidance/cases');

        $res->assertRedirectTo('/portal');
    }

    public function testStudentCanAccessPersonalHealthPortal(): void
    {
        $res = $this->withSession([
            'user' => [
                'tenant_id' => 1,
                'id'        => $this->studentUserId,
                'name'      => 'Student Consumer',
                'role'      => 'student',
                'logged_in' => true,
            ],
            'tenant_id' => 1,
        ])->get('portal');

        $res->assertStatus(200);
        $res->assertSee('Health Portal');
        $res->assertSee('My Appointments');
        $res->assertSee('Medical Flags');
    }

    public function testClinicStaffCanAccessClinicConsole(): void
    {
        $res = $this->withSession([
            'user' => [
                'tenant_id' => 1,
                'id'          => $this->nurseUserId,
                'name'        => 'Elena Nurse',
                'role'        => 'clinic_staff',
                'permissions' => ['clinic.queue.read', 'clinic.queue.manage'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ])->get('clinic/queue');

        $res->assertStatus(200);
        $res->assertSee('University Clinic Queue Board');
    }

    public function testClinicStaffCanAlsoAccessPersonalHealthPortalAsEmployee(): void
    {
        $res = $this->withSession([
            'user' => [
                'tenant_id' => 1,
                'id'        => $this->nurseUserId,
                'name'      => 'Elena Nurse',
                'role'      => 'clinic_staff',
                'logged_in' => true,
            ],
            'tenant_id' => 1,
        ])->get('portal');

        $res->assertStatus(200);
        $res->assertSee('Health Portal');
        // Must show console switcher banner for staff
        $res->assertSee('Clinic Staff Console');
    }

    public function testGuidanceCounsellorCanAccessGuidanceConsole(): void
    {
        $res = $this->withSession([
            'user' => [
                'tenant_id' => 1,
                'id'          => $this->counsellorUserId,
                'name'        => 'Gabriel Counsellor',
                'role'        => 'counsellor',
                'permissions' => ['guidance.cases.read'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ])->get('guidance/cases');

        $res->assertStatus(200);
        $res->assertSee('Student Counseling Cases');
    }

    public function testGuidanceCounsellorBlockedFromClinicConsole(): void
    {
        $res = $this->withSession([
            'user' => [
                'tenant_id' => 1,
                'id'        => $this->counsellorUserId,
                'name'      => 'Gabriel Counsellor',
                'role'      => 'counsellor',
                'logged_in' => true,
            ],
            'tenant_id' => 1,
        ])->get('clinic/queue');

        // Counsellor must be blocked from medical clinic queue and redirected
        $res->assertRedirectTo('/portal');
    }
}
