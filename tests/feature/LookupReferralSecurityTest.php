<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PatientLookupController;
use App\Controllers\ReferralController;
use App\Integrations\Mis\MisClientInterface;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\View\View;
use Config\Services;
use Tests\Support\InventoryTestDatabase;

/** Controller HTTP contracts; route/filter/CSRF integration belongs to the parent harness. */
final class LookupReferralSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private array $clinic;
    private array $guidance;
    private int $patient;
    private string $term;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
        $mis = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees', 'fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $mis->expects($this->never())->method($method);
        }
        Services::injectMock('misClient', $mis);
        $this->clinic = $this->user('clinic_staff');
        $this->guidance = $this->user('counsellor');
        $this->term = uniqid('lookup_security_');
        $this->patient = (int) (new PersonModel())->insert([
            'tenant_id' => 1, 'kind' => 'student', 'mis_id' => $this->term,
            'first_name' => 'Lookup', 'middle_name' => 'Local', 'last_name' => 'Patient',
            'address' => 'SECRET ADDRESS', 'date_of_birth' => '2000-01-01',
            'email' => 'private@example.test', 'phone' => 'PRIVATE', 'mis_payload_hash' => str_repeat('a', 64),
        ]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        Services::resetSingle('response');
        Services::resetSingle('renderer');
        Services::resetSingle('misClient');
        parent::tearDown();
    }

    private function user(string $role): array
    {
        $person = (int) (new PersonModel())->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => uniqid('lookup_staff_'),
            'first_name' => 'Lookup', 'last_name' => 'Staff',
        ]);
        $id = (int) (new UserModel())->insert([
            'tenant_id' => 1, 'person_id' => $person, 'username' => uniqid('lookup_user_'),
            'role' => $role, 'status' => 'active',
        ]);
        return ['id' => $id, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['*']];
    }

    private function invoke(string $class, string $method, ?array $actor, array $query = [], array $post = [], bool $ajax = true, array $args = [])
    {
        Services::resetSingle('response');
        AuthenticatedUser::clear();
        if ($actor !== null) {
            session()->set(['tenant_id' => 1, 'user' => $actor]);
        }
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getGet')->willReturnCallback(static fn ($key = null) => $key === null ? $query : ($query[$key] ?? null));
        $request->method('getPost')->willReturnCallback(static fn ($key = null) => $key === null ? $post : ($post[$key] ?? null));
        $request->method('isAJAX')->willReturn($ajax);
        $request->method('getHeaderLine')->willReturn('');
        $controller = new $class();
        foreach (['request' => $request, 'response' => service('response')] as $property => $value) {
            (new \ReflectionProperty($class, $property))->setValue($controller, $value);
        }
        return $controller->{$method}(...$args);
    }

    public function testLookupIsReadOnlyLocalMinimalAndTenantScoped(): void
    {
        $this->db->table('tenants')->insert([
            'slug' => uniqid('lookup_foreign_'), 'name' => 'Lookup isolation fixture', 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $foreignTenant = (int) $this->db->insertID();
        (new PersonModel())->insert([
            'tenant_id' => $foreignTenant, 'kind' => 'student', 'mis_id' => $this->term,
            'first_name' => 'Foreign', 'last_name' => 'Patient',
        ]);
        $admin = $this->user('superadmin');
        $before = [];
        foreach (['people', 'student_profiles', 'employee_profiles'] as $table) {
            $before[$table] = $this->db->table($table)->countAllResults();
        }
        foreach ([$this->clinic, $this->guidance, $admin] as $actor) {
            $response = $this->invoke(PatientLookupController::class, 'search', $actor, ['q' => $this->term]);
            $this->assertSame(200, $response->getStatusCode());
            $rows = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)['results'];
            $this->assertCount(1, $rows);
            $this->assertSame($this->patient, (int) $rows[0]['id']);
            $this->assertSame(['id', 'mis_id', 'kind', 'first_name', 'middle_name', 'last_name'], array_keys($rows[0]));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
        foreach ($before as $table => $count) {
            $this->assertSame($count, $this->db->table($table)->countAllResults());
        }
        $response = $this->invoke(PatientLookupController::class, 'search', $this->clinic, ['q' => uniqid('no_local_match_')]);
        $this->assertSame(['results' => []], json_decode($response->getBody(), true));
    }

    public function testLookupRejectsBadQueriesAndNonstaffDespiteForgedSession(): void
    {
        foreach ([null, [], 'x', str_repeat('a', 151)] as $q) {
            $response = $this->invoke(PatientLookupController::class, 'search', $this->clinic, ['q' => $q]);
            $this->assertSame(422, $response->getStatusCode());
        }
        foreach (['student', 'employee', 'bmg_admin', 'audit_reader'] as $role) {
            $response = $this->invoke(PatientLookupController::class, 'search', $this->user($role), ['q' => $this->term]);
            $this->assertSame(403, $response->getStatusCode());
        }
        $response = $this->invoke(PatientLookupController::class, 'search', null, ['q' => $this->term]);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    public function testFreshGrantRevocationAndSuspensionDenyLookup(): void
    {
        $permission = $this->db->table('permissions')->where('code', 'clinic.patients.read')->get()->getRowArray();
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $permission['id'])->delete();
        $this->assertSame(403, $this->invoke(PatientLookupController::class, 'search', $this->clinic, ['q' => $this->term])->getStatusCode());
        foreach (['guidance.cases.read', 'guidance.cases.create'] as $code) {
            $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            $this->db->table('role_permissions')->where('role', 'counsellor')->where('permission_id', $permission['id'])->delete();
        }
        // Counsellor's clinic.patients.read grant is not a substitute for guidance authority.
        $this->assertSame(403, $this->invoke(PatientLookupController::class, 'search', $this->guidance, ['q' => $this->term])->getStatusCode());
        (new UserModel())->update($this->guidance['id'], ['status' => 'suspended']);
        $this->assertSame(403, $this->invoke(PatientLookupController::class, 'search', $this->guidance, ['q' => $this->term])->getStatusCode());
    }

    public function testInboxDefaultsToOwnDepartmentAndRejectsBadOrOppositeTarget(): void
    {
        $viewData = [];
        $renderer = $this->createMock(View::class);
        $renderer->method('setData')->willReturnCallback(static function ($data) use (&$viewData, $renderer) {
            $viewData = $data;
            return $renderer;
        });
        $renderer->method('render')->willReturn('inbox');
        Services::injectMock('renderer', $renderer);
        foreach ([[$this->clinic, 'clinic', 'guidance'], [$this->guidance, 'guidance', 'clinic']] as [$actor, $own, $other]) {
            $this->assertSame('inbox', $this->invoke(ReferralController::class, 'index', $actor, [], [], true, [$own]));
            $this->assertSame($own, $viewData['unit']);
            $this->assertSame(403, $this->invoke(ReferralController::class, 'index', $actor, ['target' => $other])->getStatusCode());
            foreach (['invalid', '', []] as $bad) {
                $this->assertSame(422, $this->invoke(ReferralController::class, 'index', $actor, ['target' => $bad])->getStatusCode());
            }
        }
    }

    public function testCreateRedirectsToSourceAndDeniedMutationsNeverRedirect(): void
    {
        $payload = ['patient_person_id' => $this->patient, 'reason_code' => 'academic_distress',
            'priority' => 'routine', 'reason_description' => 'Brief shared support request.',
            'request_key' => bin2hex(random_bytes(32))];
        $response = $this->invoke(ReferralController::class, 'store', $this->clinic, [], $payload, true, ['clinic']);
        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getBody(), true);
        $this->assertSame(site_url('/clinic/referrals?tab=sent'), $json['redirect']);
        $this->assertNotEmpty($json['csrf']['hash']);
        foreach ([true, false] as $ajax) {
            $response = $this->invoke(ReferralController::class, 'store', $this->clinic, [],
                array_replace($payload, ['source_module' => 'guidance', 'target_module' => 'clinic']), $ajax, ['clinic']);
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('', $response->getHeaderLine('Location'));
            if ($ajax) {
                $this->assertNotEmpty(json_decode($response->getBody(), true)['csrf']['hash']);
            } else {
                $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
            }
        }
        $this->assertSame(422, $this->invoke(ReferralController::class, 'store', $this->clinic, [],
            array_replace($payload, ['patient_person_id' => ['bad']]), true, ['clinic'])->getStatusCode());
        $this->assertSame(404, $this->invoke(ReferralController::class, 'acknowledge', $this->guidance, [], [], true, [PHP_INT_MAX, 'guidance'])->getStatusCode());
    }
}
