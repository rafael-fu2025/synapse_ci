<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ProfileSettingsController;
use App\Models\HealthProfileModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmergencyContactSettingsTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ec_test_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = config('Database')->default = $settings;
        $this->db = Database::connect('tests');
        config(\Config\Realtime::class)->enabled = false;

        $this->db->query('CREATE TABLE ec_test_tenants (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL, deleted_at TEXT)');
        $this->db->query('CREATE TABLE ec_test_people (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, kind TEXT NOT NULL, first_name TEXT NOT NULL, middle_name TEXT, last_name TEXT NOT NULL, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE ec_test_employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE ec_test_student_profiles (person_id INTEGER, tenant_id INTEGER, program TEXT, year_level INTEGER, section TEXT, department TEXT)');
        $this->db->query('CREATE TABLE ec_test_users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, person_id INTEGER, username TEXT NOT NULL, email TEXT, role TEXT NOT NULL, status TEXT NOT NULL, auth_version INTEGER NOT NULL DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE ec_test_permissions (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
        $this->db->query('CREATE TABLE ec_test_role_permissions (role TEXT NOT NULL, permission_id INTEGER NOT NULL)');
        $this->db->query('CREATE TABLE ec_test_health_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, blood_type TEXT, allergies TEXT, chronic_conditions TEXT, emergency_contact_name TEXT, emergency_contact_phone TEXT, emergency_contact_relationship TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');

        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        helper(['url', 'form']);
        CurrentTenant::set(1);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        CurrentTenant::reset();
        $this->db->close();
        foreach (['request', 'response', 'renderer'] as $name) {
            Services::resetSingle($name);
        }
        parent::tearDown();
    }

    private function person(int $id, int $tenant = 1, string $kind = 'student'): array
    {
        $person = [
            'id' => $id, 'tenant_id' => $tenant, 'kind' => $kind,
            'first_name' => 'First' . $id, 'middle_name' => '', 'last_name' => 'Last' . $id,
            'mis_id' => 'ID-' . $id, 'email' => 'user' . $id . '@example.test',
        ];
        $this->db->table('people')->insert($person);
        return $person;
    }

    private function user(int $id, int $personId, int $tenant = 1, string $role = 'student'): array
    {
        $user = [
            'id' => $id, 'tenant_id' => $tenant, 'person_id' => $personId,
            'username' => 'user_' . $id, 'email' => 'user' . $id . '@example.test',
            'role' => $role, 'status' => 'active', 'auth_version' => 1,
        ];
        $this->db->table('users')->insert($user);
        return $user;
    }

    private function login(array $user): void
    {
        AuthenticatedUser::clear();
        CurrentTenant::set((int) $user['tenant_id']);
        session()->set([
            'tenant_id' => (int) $user['tenant_id'],
            'user' => $user + ['logged_in' => true],
            '_auth' => [
                'started_at' => time(),
                'last_seen_at' => time(),
                'version' => 1,
                'session_id' => session_id(),
            ],
        ]);
        self::assertNotNull((new AuthenticatedUser())->current());
    }

    private function controller(string $method = 'GET', array $post = [], bool $ajax = true): ProfileSettingsController
    {
        $request = new IncomingRequest(config('App'), service('uri'), null, new UserAgent());
        $request->setMethod($method);
        $request->setGlobal('post', $post);
        if ($ajax) {
            $request->setHeader('X-Requested-With', 'XMLHttpRequest');
            $request->setHeader('Accept', 'application/json');
        }
        $response = new Response(config('App'));
        Services::injectMock('request', $request);
        Services::injectMock('response', $response);
        $controller = new ProfileSettingsController();
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    public function testServiceUpdatesEmergencyContactPreservingExistingHealthData(): void
    {
        $this->person(1);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('health_profiles')->insert([
            'tenant_id' => 1, 'person_id' => 1,
            'blood_type' => 'O+', 'allergies' => 'Penicillin', 'chronic_conditions' => 'Asthma',
            'emergency_contact_name' => 'Old Name', 'emergency_contact_phone' => '111',
            'emergency_contact_relationship' => 'Parent', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $service = new PatientHistoryService();
        $updated = $service->updateEmergencyContact(1, [
            'emergency_contact_name' => 'Maria Santos',
            'emergency_contact_phone' => '+63 917 123 4567',
            'emergency_contact_relationship' => 'Mother',
        ]);

        self::assertSame('Maria Santos', $updated['emergency_contact_name']);
        self::assertSame('+63 917 123 4567', $updated['emergency_contact_phone']);
        self::assertSame('Mother', $updated['emergency_contact_relationship']);
        // Clinical data MUST be preserved verbatim!
        self::assertSame('O+', $updated['blood_type']);
        self::assertSame('Penicillin', $updated['allergies']);
        self::assertSame('Asthma', $updated['chronic_conditions']);
    }

    public function testServiceInsertsWhenNoHealthProfileExists(): void
    {
        $this->person(2);
        self::assertNull((new HealthProfileModel())->findByPersonId(2));

        $service = new PatientHistoryService();
        $created = $service->updateEmergencyContact(2, [
            'emergency_contact_name' => 'Juan Reyes',
            'emergency_contact_phone' => '0920-555-1234',
            'emergency_contact_relationship' => 'Spouse',
        ]);

        self::assertNotNull($created);
        self::assertSame('Juan Reyes', $created['emergency_contact_name']);
        self::assertSame('0920-555-1234', $created['emergency_contact_phone']);
        self::assertSame('Spouse', $created['emergency_contact_relationship']);
        self::assertNull($created['blood_type']);
        self::assertNull($created['allergies']);
    }

    public function testServiceClearsFieldsWhenEmptyOrWhitespaceSupplied(): void
    {
        $this->person(3);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('health_profiles')->insert([
            'tenant_id' => 1, 'person_id' => 3,
            'blood_type' => 'B+',
            'emergency_contact_name' => 'Prior Contact', 'emergency_contact_phone' => '999',
            'emergency_contact_relationship' => 'Sibling', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $service = new PatientHistoryService();
        $cleared = $service->updateEmergencyContact(3, [
            'emergency_contact_name' => '   ',
            'emergency_contact_phone' => '',
            'emergency_contact_relationship' => null,
        ]);

        self::assertNull($cleared['emergency_contact_name']);
        self::assertNull($cleared['emergency_contact_phone']);
        self::assertNull($cleared['emergency_contact_relationship']);
        self::assertSame('B+', $cleared['blood_type'], 'Clinical data is not cleared.');
    }

    public function testControllerIndexPassesHealthProfileToSettingsView(): void
    {
        $person = $this->person(4);
        $user = $this->user(4, 4);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('health_profiles')->insert([
            'tenant_id' => 1, 'person_id' => 4,
            'emergency_contact_name' => 'Dr. Alberto Santos', 'emergency_contact_phone' => '0918-000-1111',
            'emergency_contact_relationship' => 'Guardian', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->login($user);
        $html = $this->controller()->index();
        self::assertIsString($html);
        self::assertStringContainsString('Emergency contact', $html);
        self::assertStringContainsString('Dr. Alberto Santos', $html);
        self::assertStringContainsString('0918-000-1111', $html);
        self::assertStringContainsString('Guardian', $html);
    }

    public function testControllerUpdateEmergencyContactAjaxSuccessWithCsrf(): void
    {
        $person = $this->person(5);
        $user = $this->user(5, 5);
        $this->login($user);

        $payload = [
            'emergency_contact_name' => 'Elena Dela Cruz',
            'emergency_contact_relationship' => 'Sister',
            'emergency_contact_phone' => '+63 928 765 4321',
        ];

        $controller = $this->controller('POST', $payload, true);
        $response = $controller->updateEmergencyContact();
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame('Emergency contact updated.', $data['message']);
        self::assertSame('Elena Dela Cruz', $data['emergency_contact']['emergency_contact_name']);
        self::assertSame('Sister', $data['emergency_contact']['emergency_contact_relationship']);
        self::assertSame('+63 928 765 4321', $data['emergency_contact']['emergency_contact_phone']);
        self::assertArrayHasKey('csrf', $data);

        $saved = (new HealthProfileModel())->findByPersonId(5);
        self::assertSame('Elena Dela Cruz', $saved['emergency_contact_name']);
        self::assertSame('+63 928 765 4321', $saved['emergency_contact_phone']);
    }

    public function testControllerUpdateEmergencyContactValidationLengthAndControls(): void
    {
        $this->person(6);
        $user = $this->user(6, 6);
        $this->login($user);

        // Name exceeding 150 characters
        $controller = $this->controller('POST', [
            'emergency_contact_name' => str_repeat('A', 151),
        ], true);
        $res = $controller->updateEmergencyContact();
        self::assertSame(422, $res->getStatusCode());

        // Phone exceeding 50 characters
        $controller = $this->controller('POST', [
            'emergency_contact_phone' => str_repeat('1', 51),
        ], true);
        $res = $controller->updateEmergencyContact();
        self::assertSame(422, $res->getStatusCode());

        // Relationship exceeding 50 characters
        $controller = $this->controller('POST', [
            'emergency_contact_relationship' => str_repeat('R', 51),
        ], true);
        $res = $controller->updateEmergencyContact();
        self::assertSame(422, $res->getStatusCode());

        // Control characters rejected
        $controller = $this->controller('POST', [
            'emergency_contact_name' => "Malicious\x00Name",
        ], true);
        $res = $controller->updateEmergencyContact();
        self::assertSame(422, $res->getStatusCode());
    }

    public function testControllerUpdateEmergencyContactRejectsUnauthenticatedAndNonPersonalRole(): void
    {
        AuthenticatedUser::clear();
        $controller = $this->controller('POST', ['emergency_contact_name' => 'Name'], true);
        $res = $controller->updateEmergencyContact();
        self::assertSame(401, $res->getStatusCode());

        // Break-glass superadmin without a linked student/employee person cannot manage emergency contact
        $adminUser = [
            'id' => 77, 'tenant_id' => 1, 'person_id' => null,
            'username' => 'admin_77', 'email' => 'admin77@example.test',
            'role' => 'superadmin', 'status' => 'active', 'auth_version' => 1,
        ];
        $this->db->table('users')->insert($adminUser);
        $this->login($adminUser);
        $controller = $this->controller('POST', ['emergency_contact_name' => 'Name'], true);
        $res = $controller->updateEmergencyContact();
        self::assertSame(403, $res->getStatusCode());
    }

    public function testControllerUpdateEmergencyContactCannotMutateAnotherTenantOrPerson(): void
    {
        $this->person(8, 1);
        $actorUser = $this->user(8, 8, 1);
        $this->login($actorUser);

        $victimPerson = $this->person(9, 2);

        // Attempting to inject forged person_id or tenant_id in POST body
        $controller = $this->controller('POST', [
            'person_id' => 9,
            'tenant_id' => 2,
            'emergency_contact_name' => 'Hacker Contact',
        ], true);
        $res = $controller->updateEmergencyContact();
        self::assertSame(200, $res->getStatusCode());

        // Victim in tenant 2 MUST NOT be touched
        CurrentTenant::set(2);
        self::assertNull((new HealthProfileModel())->findByPersonId(9));

        // Actor in tenant 1 gets updated
        CurrentTenant::set(1);
        $actorHealth = (new HealthProfileModel())->findByPersonId(8);
        self::assertSame('Hacker Contact', $actorHealth['emergency_contact_name']);
    }
}
