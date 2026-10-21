<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\AuthFilter;
use App\Filters\PortalAccessFilter;
use App\Integrations\Mis\FakeMisClient;
use App\Integrations\Mis\MisClientInterface;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class SessionAuthorizationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $userId;
    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
        $this->personId = (int) (new PersonModel())->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => uniqid('AUTH_PERSON_'),
            'first_name' => 'Current', 'last_name' => 'Identity',
        ]);
        $this->userId = (int) (new UserModel())->insert([
            'tenant_id' => 1, 'person_id' => $this->personId, 'username' => uniqid('AUTH_USER_'),
            'role' => 'clinic_staff', 'status' => 'active',
        ]);
        $this->signIn();
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function signIn(): void
    {
        session()->set('_auth', ['started_at' => time(), 'last_seen_at' => time(),
            'version' => (int) (new UserModel())->find($this->userId)['auth_version'], 'session_id' => session_id()]);
        session()->set(['tenant_id' => 1, 'user' => [
            'id' => $this->userId, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['rbac.manage'], 'password' => 'secret',
        ]]);
    }

    public function testCachedPrivilegesAndSecretsAreReplaced(): void
    {
        $user = (new AuthenticatedUser())->current();
        $this->assertSame('clinic_staff', $user['role']);
        $this->assertSame('Current Identity', $user['name']);
        $this->assertSame((new UserModel())->getPermissionsForRole('clinic_staff'), $user['permissions']);
        $this->assertArrayNotHasKey('password', $user);
        $this->assertSame($user, session('user'));
        $this->assertSame(1, CurrentTenant::id());
    }

    public function testMalformedAndMissingTenantIdentityFailsClosed(): void
    {
        foreach ([null, 0, -1, [], true, '1x', 1.5] as $tenant) {
            $this->signIn();
            session()->set('tenant_id', $tenant);
            $this->assertNull((new AuthenticatedUser())->current());
            $this->assertNull(session('user'));
        }
        $this->signIn();
        session()->set('user', ['id' => $this->userId, 'logged_in' => true]);
        $this->assertNull((new AuthenticatedUser())->current());
        $this->signIn();
        $user = session('user');
        $user['tenant_id'] = 2;
        session()->set('user', $user);
        $this->assertNull((new AuthenticatedUser())->current());
        foreach ([0, -1, [], true, '1x'] as $id) {
            $this->signIn();
            $user = session('user');
            $user['id'] = $id;
            session()->set('user', $user);
            $this->assertNull((new AuthenticatedUser())->current());
        }
    }

    public function testSuspensionAndDeletionInvalidateWithoutClearingTheme(): void
    {
        session()->set('theme', 'dark');
        (new UserModel())->update($this->userId, ['status' => 'suspended']);
        $this->assertNull((new AuthenticatedUser())->current());
        $this->assertNull(session('tenant_id'));
        $this->assertSame('dark', session('theme'));
        (new UserModel())->update($this->userId, ['status' => 'active']);
        $this->signIn();
        (new UserModel())->delete($this->userId);
        $this->assertNull((new AuthenticatedUser())->current());
    }

    public function testDeletedOrCrossTenantLinkedPersonInvalidates(): void
    {
        (new PersonModel())->delete($this->personId);
        $this->assertNull((new AuthenticatedUser())->current());
        $this->db->table('people')->where('id', $this->personId)->update(['deleted_at' => null]);
        $this->signIn();
        $slug = uniqid('auth_tenant_');
        $this->db->table('tenants')->insert([
            'slug' => $slug, 'name' => 'Other tenant', 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $otherTenant = (int) $this->db->insertID();
        // A mismatched linkage is denied even if both rows exist.
        $this->db->table('people')->where('id', $this->personId)->update(['tenant_id' => $otherTenant]);
        $this->assertNull((new AuthenticatedUser())->current());
        $this->assertNull((new AuthenticatedUser())->forIdentity($this->userId, $otherTenant));
    }

    public function testPermissionRevocationIsVisibleOnSameRequestObject(): void
    {
        $request = service('request');
        $request->setMethod('GET');
        $request->setHeader('Accept', 'application/json');
        $filter = new AuthFilter();
        $this->assertNull($filter->before($request, ['clinic.queue.read']));
        $permission = $this->db->table('permissions')->where('code', 'clinic.queue.read')->get()->getRowArray();
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $permission['id'])->delete();
        try {
            $response = $filter->before($request, ['clinic.queue.read']);
            $this->assertSame(403, $response->getStatusCode());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        } finally {
            $this->db->table('role_permissions')->insert(['role' => 'clinic_staff', 'permission_id' => $permission['id']]);
        }
    }

    public function testRoleChangeRefreshesRightsAndIndependentPortalFilter(): void
    {
        $request = service('request');
        $request->setMethod('GET');
        $request->setHeader('Accept', 'application/json');
        $filter = new PortalAccessFilter();
        $this->assertNull($filter->before($request, ['clinic']));
        (new UserModel())->update($this->userId, ['role' => 'student']);
        $this->assertSame(403, $filter->before($request, ['clinic'])->getStatusCode());
        $this->assertSame('student', session('user')['role']);
        $this->assertSame((new UserModel())->getPermissionsForRole('student'), session('user')['permissions']);
        (new UserModel())->update($this->userId, ['status' => 'locked']);
        $this->assertSame(401, $filter->before($request, ['clinic'])->getStatusCode());
    }

    public function testLocalWrongPasswordAndBlockedIdentifiersNeverReachMis(): void
    {
        $client = $this->createMock(MisClientInterface::class);
        $client->expects($this->never())->method('studentLogin');
        $client->expects($this->never())->method('employeeLogin');
        $model = new UserModel();
        $model->update($this->userId, ['password_hash' => password_hash('correct', PASSWORD_DEFAULT)]);
        $row = $model->find($this->userId);
        $auth = new AuthService(misClient: $client);
        $this->assertNull($auth->login($row['username'], 'wrong'));
        $model->update($this->userId, ['status' => 'suspended']);
        $this->assertNull($auth->login($row['username'], 'correct'));
        $model->delete($this->userId);
        $this->assertNull($auth->login($row['username'], 'correct'));
        $this->assertNull($model->withDeleted()->find($this->userId)['last_login_at']);
    }

    private function employeeMisClient(): MisClientInterface
    {
        $payload = (new FakeMisClient())->employeeLogin('20269001', 'fake');
        $person = (new PersonModel())->find($this->personId);
        $payload['data']['employee_id'] = $person['mis_id'];
        $payload['data']['role'] = 'superadmin';
        $client = $this->createMock(MisClientInterface::class);
        $client->method('studentLogin')->willReturn(null);
        $client->method('employeeLogin')->willReturn($payload);
        return $client;
    }

    public function testMisAliasCannotRefreshOrReactivateBlockedLinkedAccount(): void
    {
        $client = $this->employeeMisClient();
        $model = new UserModel();
        $model->update($this->userId, ['status' => 'suspended', 'username' => uniqid('legacy_')]);
        $before = (new PersonModel())->find($this->personId);
        $auth = new AuthService(misClient: $client);
        $this->assertNull($auth->login($before['mis_id'], 'fake'));
        $this->assertSame($before, (new PersonModel())->find($this->personId));
        $this->assertNull($model->find($this->userId)['last_login_at']);
        $this->assertSame(0, $this->db->table('employee_profiles')->where('person_id', $this->personId)->countAllResults());
        $model->update($this->userId, ['status' => 'active']);
        $model->delete($this->userId);
        $this->assertNull($auth->login($before['mis_id'], 'fake'));
        $this->assertSame($before, (new PersonModel())->find($this->personId));
        $this->assertNull($model->withDeleted()->find($this->userId)['last_login_at']);
    }

    public function testMisRefreshPreservesLocallyAssignedRoleAndDropsMisSecrets(): void
    {
        (new UserModel())->update($this->userId, ['role' => 'clinic_admin']);
        $auth = new AuthService(misClient: $this->employeeMisClient());
        $result = $auth->login((new PersonModel())->find($this->personId)['mis_id'], 'fake');
        $this->assertNotNull($result);
        $this->assertSame($this->userId, $result['id']);
        $this->assertSame('clinic_admin', $result['role']);
        $this->assertSame((new UserModel())->getPermissionsForRole('clinic_admin'), $result['permissions']);
        $this->assertArrayNotHasKey('password_hash', $result);
        $this->assertArrayNotHasKey('access_token', $result);
        $this->assertNotNull((new UserModel())->find($this->userId)['last_login_at']);
    }

    public function testLogoutDoesNotQueryRevokedIdentityAndPreservesTheme(): void
    {
        $auth = new AuthService(misClient: new FakeMisClient());
        (new UserModel())->delete($this->userId);
        session()->set('theme', 'dark');
        $lastQuery = $this->db->getLastQuery();
        $auth->logout();
        $this->assertSame($lastQuery, $this->db->getLastQuery());
        $this->assertNull(session('user'));
        $this->assertNull(session('tenant_id'));
        $this->assertSame('dark', session('theme'));
    }

    public function testUnknownRoleAndMissingPersonAreDeniedButBreakGlassIsSupported(): void
    {
        (new UserModel())->update($this->userId, ['role' => 'invented_admin']);
        $this->assertNull((new AuthenticatedUser())->current());
        (new UserModel())->update($this->userId, ['role' => 'clinic_staff', 'person_id' => null]);
        $this->assertNull((new AuthenticatedUser())->forIdentity($this->userId, 1));
        (new UserModel())->update($this->userId, ['role' => 'superadmin']);
        $this->assertNotNull((new AuthenticatedUser())->forIdentity($this->userId, 1));
    }
}
