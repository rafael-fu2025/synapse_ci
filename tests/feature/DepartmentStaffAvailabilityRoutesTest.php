<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Mis\MisClientInterface;
use App\Models\DepartmentStaffModel;
use App\Models\EmployeeProfileModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\InventoryTestDatabase;

final class DepartmentStaffAvailabilityRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\AppointmentFixtureCleanup;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $tenant;
    private array $admin;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        $mis = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees', 'fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $mis->expects($this->never())->method($method);
        }
        Services::injectMock('misClient', $mis);
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('department_http_'), 'name' => 'Department HTTP fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $this->tenant;
        CurrentTenant::set($this->tenant);
        $this->admin = $this->employee('Administration', 'superadmin')['user'];
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        Services::resetSingle('security');
        Services::resetSingle('misClient');
        service('superglobals')->setCookieArray([]);
        $ids = array_column($this->db->table('department_staff')->whereIn('person_id', $this->appointmentFixturePeople)->get()->getResultArray(), 'id');
        if ($ids !== []) {
            $this->db->table('availability_exceptions')->whereIn('staff_assignment_id', $ids)->delete();
            $this->db->table('provider_availability')->whereIn('staff_assignment_id', $ids)->delete();
            $this->db->table('department_staff')->whereIn('id', $ids)->delete();
        }
        $this->cleanupAppointmentFixtures();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function employee(string $department, ?string $role = null, string $position = 'Stored position', string $status = 'active', ?int $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $unique = uniqid('department_http_', true);
        $person = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => $unique, 'first_name' => 'Department', 'last_name' => $unique]);
        $this->appointmentFixturePeople[] = $person;
        (new EmployeeProfileModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'department' => $department, 'position' => $position, 'employment_status' => $status]);
        $user = null;
        if ($role !== null) {
            $id = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
                'username' => $unique, 'role' => $role, 'status' => 'active']);
            $this->appointmentFixtureUsers[] = $id;
            $user = (new UserModel())->find($id) + ['logged_in' => true, 'permissions' => []];
        }
        return ['person_id' => $person, 'user' => $user, 'name' => 'Department ' . $unique];
    }

    private function identity(array $actor): array
    {
        return ['tenant_id' => $this->tenant, 'user' => $actor];
    }

    private function payload(array $ids): array
    {
        return ['staff_assignment_ids' => $ids, 'days' => ['Monday', 'Tuesday'], 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration_minutes' => '30'];
    }

    private function submit(string $unit, array $data, string $operation = 'template', ?array $actor = null, bool $csrf = true)
    {
        $token = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
        Services::resetSingle('security');
        if ($csrf) {
            $data[config('Security')->tokenName] = $token;
        }
        return $this->withSession($this->identity($actor ?? $this->admin))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post($unit . '/appointments/availability/' . $operation, $data);
    }

    private function rows(): array
    {
        return $this->db->table('provider_availability')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
    }

    public function testRosterUsesSeparateEnrollmentAndAssignmentTemplateFormsWithoutAccountBlocker(): void
    {
        foreach (['clinic' => ' hEaLtH SeRvIcEs ', 'guidance' => ' GUIDANCE '] as $unit => $department) {
            $person = $this->employee($department, position: '<script>storedPosition()</script>');
            $other = $this->employee($unit === 'clinic' ? 'Guidance' : 'Health Services');
            $excluded = $this->employee('Health Services Office');
            $before = $this->db->table('users')->orderBy('id')->get()->getResultArray();
            Services::resetSingle('renderer');
            $response = $this->withSession($this->identity($this->admin))->withHeaders([])->get($unit . '/appointments/availability');
            $response->assertStatus(200);
            $body = $response->response()->getBody();
            $this->assertStringContainsString($person['name'], $body);
            $this->assertStringContainsString('Not enrolled for scheduling', $body);
            $this->assertStringNotContainsString('No account', $body);
            $this->assertStringContainsString(esc('<script>storedPosition()</script>'), $body);
            $this->assertStringNotContainsString('<script>storedPosition()</script>', $body);
            $this->assertSame(1, preg_match('/<table[^>]*data-department-staff[^>]*>.*?<\/table>/s', $body, $matches));
            $this->assertStringNotContainsString($other['name'], $matches[0]);
            $this->assertStringNotContainsString($excluded['name'], $matches[0]);
            $this->assertStringContainsString(esc(site_url('/' . $unit . '/appointments/availability/enroll')), $body);
            $this->assertStringContainsString('name="person_ids[]"', $body);
            $this->assertStringNotContainsString('name="provider_user_ids[]"', $body);
            $this->submit($unit, ['person_ids' => [$person['person_id']]], 'enroll')->assertStatus(200);
            Services::resetSingle('renderer');
            $response = $this->withSession($this->identity($this->admin))->withHeaders([])->get($unit . '/appointments/availability');
            $response->assertStatus(200);
            $this->assertStringContainsString('name="staff_assignment_ids[]"', $response->response()->getBody());
            $this->assertSame($before, $this->db->table('users')->orderBy('id')->get()->getResultArray());
        }
    }

    public function testEnrollmentAndTemplateAjaxContractAndRetriesWithoutLoginInBothUnits(): void
    {
        foreach (['clinic' => 'Health Services', 'guidance' => 'Guidance'] as $unit => $department) {
            $person = $this->employee($department);
            $manager = $this->employee('Administration', $unit === 'clinic' ? 'clinic_admin' : 'guidance_admin')['user'];
            $beforeUsers = $this->db->table('users')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
            $beforeGrants = $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray();
            $firstAssignment = null;
            foreach ([[1, 0], [0, 1]] as [$createdCount, $skippedCount]) {
                $response = $this->submit($unit, ['person_ids' => [$person['person_id'], (string) $person['person_id']]], 'enroll', $manager);
                $response->assertStatus(200);
                $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($json['success']);
                $this->assertSame(site_url('/' . $unit . '/appointments/availability'), $json['redirect']);
                $this->assertSame(config('Security')->tokenName, $json['csrf']['name']);
                $this->assertNotEmpty($json['csrf']['hash']);
                $assignments = (new DepartmentStaffModel())->scoped()->where('unit', $unit)->where('person_id', $person['person_id'])->findAll();
                $this->assertCount(1, $assignments);
                $this->assertSame('active', $assignments[0]['status']);
                if ($firstAssignment === null) {
                    $firstAssignment = $assignments[0];
                } else {
                    $this->assertSame($firstAssignment, $assignments[0]);
                }
                $this->assertSame($beforeUsers, $this->db->table('users')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray());
                $this->assertSame($beforeGrants, $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray());
                $this->assertMatchesRegularExpression('/(?:\b' . $createdCount . '\s+created\b|\bcreated\s*[:=]?\s*' . $createdCount . '\b)/i', $json['message']);
                $this->assertMatchesRegularExpression('/(?:\b' . $skippedCount . '\s+skipped\b|\bskipped\s*[:=]?\s*' . $skippedCount . '\b)/i', $json['message']);
            }
            $assignment = (new DepartmentStaffModel())->scoped()->where('unit', $unit)->where('person_id', $person['person_id'])->first();
            $this->assertSame((int) $manager['id'], (int) $assignment['enrolled_by_user_id']);
            $this->submit($unit, $this->payload([$assignment['id']]))->assertStatus(200);
            $before = $this->rows();
            $response = $this->submit($unit, $this->payload([$assignment['id']]));
            $response->assertStatus(200);
            $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($json['success']);
            $this->assertSame(site_url('/' . $unit . '/appointments'), $json['redirect']);
            $this->assertSame(config('Security')->tokenName, $json['csrf']['name']);
            $this->assertSame($before, $this->rows());
            foreach (array_filter($before, static fn (array $row): bool => $row['unit'] === $unit) as $row) {
                $this->assertSame((int) $assignment['id'], (int) $row['staff_assignment_id']);
                $this->assertNull($row['provider_user_id']);
            }
            $this->assertSame(0, $this->db->table('users')->where('person_id', $person['person_id'])->countAllResults());
        }
    }

    public function testCsrfAndManagerChecksCoverBothWriteEndpoints(): void
    {
        foreach (['clinic' => 'Health Services', 'guidance' => 'Guidance'] as $unit => $department) {
            $person = $this->employee($department);
            $staff = $this->employee($department, $unit === 'clinic' ? 'clinic_staff' : 'counsellor')['user'];
            $this->submit($unit, ['person_ids' => [$person['person_id']]], 'enroll', $staff)->assertStatus(403);
            $staff['role'] = 'superadmin';
            $staff['permissions'] = ['*', 'rbac.manage'];
            foreach (['enroll' => ['person_ids' => [$person['person_id']]], 'template' => $this->payload([PHP_INT_MAX])] as $operation => $payload) {
                $response = $this->submit($unit, $payload, $operation, csrf: false);
                $response->assertStatus(403);
                $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertFalse($json['success']);
                $this->assertTrue($json['reload']);
                $this->assertNotEmpty($json['csrf']['hash']);
                $this->submit($unit, $payload, $operation, $staff)->assertStatus(403);
            }
            $this->assertSame([], $this->rows());
            $this->assertSame(0, (new DepartmentStaffModel())->scoped()->countAllResults());
        }
    }

    public function testEnrollmentRejectsForeignWrongDepartmentNonEmployeeAndMalformedBatchesAtomically(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('enrollment_foreign_'), 'name' => 'Foreign enrollment fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreignTenant = (int) $this->db->insertID();
        $this->appointmentFixtureTenants[] = $foreignTenant;
        foreach (['clinic' => 'Health Services', 'guidance' => 'Guidance'] as $unit => $department) {
            $existing = $this->employee($department);
            $this->submit($unit, ['person_ids' => [$existing['person_id']]], 'enroll')->assertStatus(200);
            $valid = $this->employee($department);
            $foreign = $this->employee($department, tenant: $foreignTenant);
            $other = $this->employee($unit === 'clinic' ? 'Guidance' : 'Health Services');
            $nonEmployee = $this->employee($department);
            (new PersonModel())->update($nonEmployee['person_id'], ['kind' => 'student']);
            $inactive = $this->employee($department, status: 'inactive');
            $deleted = $this->employee($department);
            (new PersonModel())->delete($deleted['person_id']);
            $beforeAssignments = $this->db->table('department_staff')->whereIn('tenant_id', [$this->tenant, $foreignTenant])->orderBy('id')->get()->getResultArray();
            $beforeUsers = $this->db->table('users')->whereIn('tenant_id', [$this->tenant, $foreignTenant])->orderBy('id')->get()->getResultArray();
            $beforeGrants = $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray();
            $payloads = [[], ['person_ids' => null], ['person_ids' => []], ['person_ids' => (string) $valid['person_id']]];
            foreach ([null, true, false, [], ['nested' => $valid['person_id']], '', '0', '-1', '1.5', '1e2',
                ' ' . $valid['person_id'], (string) PHP_INT_MAX . '0', PHP_INT_MAX,
                $foreign['person_id'], $other['person_id'], $nonEmployee['person_id'], $inactive['person_id'], $deleted['person_id']] as $badId) {
                // A valid, unenrolled employee precedes every bad member to detect partial writes.
                $payloads[] = ['person_ids' => [$valid['person_id'], $existing['person_id'], $badId]];
            }
            foreach ($payloads as $payload) {
                $response = $this->submit($unit, $payload, 'enroll');
                $response->assertStatus(422);
                $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertFalse($json['success']);
                $this->assertNotEmpty($json['message']);
                $this->assertSame(config('Security')->tokenName, $json['csrf']['name']);
                $this->assertNotEmpty($json['csrf']['hash']);
                $this->assertSame($beforeAssignments, $this->db->table('department_staff')->whereIn('tenant_id', [$this->tenant, $foreignTenant])->orderBy('id')->get()->getResultArray());
                $this->assertSame($beforeUsers, $this->db->table('users')->whereIn('tenant_id', [$this->tenant, $foreignTenant])->orderBy('id')->get()->getResultArray());
                $this->assertSame($beforeGrants, $this->db->table('role_permissions')->orderBy('role')->orderBy('permission_id')->get()->getResultArray());
                $this->assertSame([], $this->rows());
            }
            $this->assertSame(0, $this->db->table('users')->where('person_id', $valid['person_id'])->countAllResults());
        }
    }

    public function testMalformedAndForgedSelectionsRejectWholeRequest(): void
    {
        foreach (['clinic' => 'Health Services', 'guidance' => 'Guidance'] as $unit => $department) {
            $person = $this->employee($department);
            $other = $this->employee('Administration');
            foreach ([[], ['person_ids' => []], ['person_ids' => '1'], ['person_ids' => [$person['person_id'], '1e2']],
                ['person_ids' => [$person['person_id'], $other['person_id']]]] as $payload) {
                $this->submit($unit, $payload, 'enroll')->assertStatus(422);
                $this->assertSame(0, (new DepartmentStaffModel())->scoped()->where('unit', $unit)->countAllResults());
            }
            (new AppointmentService())->enrollDepartmentStaff($unit, [$person['person_id']], $this->admin);
            $id = (new DepartmentStaffModel())->scoped()->where('person_id', $person['person_id'])->first()['id'];
            foreach ([['staff_assignment_ids' => []], ['staff_assignment_ids' => '1'], ['staff_assignment_ids' => [$id, PHP_INT_MAX]],
                ['days' => ['Monday', 'not-a-weekday']], ['days' => ['Monday', ['Tuesday']]]] as $values) {
                $this->submit($unit, array_replace($this->payload([$id]), $values))->assertStatus(422);
                $this->assertSame([], $this->rows());
            }
            $legacy = $this->payload([$id]);
            unset($legacy['staff_assignment_ids']);
            $legacy['provider_user_ids'] = [$id];
            $this->submit($unit, $legacy)->assertStatus(422);
        }
    }
}
