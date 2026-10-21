<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Mis\FakeMisClient;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

final class SessionHardeningTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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
        Services::injectMock('misClient', new FakeMisClient());
        $this->personId = (int) (new PersonModel())->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => uniqid('HARDEN_PERSON_'),
            'first_name' => 'Test', 'last_name' => 'Staff',
        ]);
        $this->userId = (int) (new UserModel())->insert([
            'tenant_id' => 1, 'person_id' => $this->personId, 'username' => uniqid('HARDEN_USER_'),
            'role' => 'clinic_staff', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function identity(): array
    {
        return ['_auth' => ['started_at' => time(), 'last_seen_at' => time(),
            'version' => (int) (new UserModel())->find($this->userId)['auth_version'], 'session_id' => session_id()],
            'theme' => 'dark', 'tenant_id' => 1, 'user' => [
            'id' => $this->userId, 'tenant_id' => 1, 'logged_in' => true,
            'role' => 'superadmin', 'permissions' => ['rbac.manage'],
        ]];
    }

    public function testSuspendedSessionRedirectsAndLoginPageDoesNotLoop(): void
    {
        (new UserModel())->update($this->userId, ['status' => 'suspended']);
        $this->withSession($this->identity())->get('/')->assertRedirectTo('/auth/login');
        $this->assertNull(session('user'));
        $this->assertSame('dark', session('theme'));
        $this->withSession($this->identity())->get('auth/login')->assertStatus(200);
        $this->assertNull(session('user'));
    }

    public function testAjaxJsonHtmxAndFragmentsReturnUnauthorizedWithoutRedirect(): void
    {
        foreach ([['X-Requested-With' => 'XMLHttpRequest'], ['Accept' => 'application/json'], ['HX-Request' => 'true'], []] as $headers) {
            $path = $headers === [] ? 'clinic/patients?fragment=1' : 'clinic/patients';
            $result = $this->withHeaders($headers)->withSession([])->get($path);
            $result->assertStatus(401);
            $result->assertJSONFragment(['message' => 'Please sign in to continue.']);
            $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
            $this->assertSame('', $result->response()->getHeaderLine('Location'));
        }
    }

    public function testForgedRoleCannotEnterAnotherPortalOrUseCachedPermission(): void
    {
        $this->withSession($this->identity())->get('guidance/cases')->assertRedirectTo('/portal');
        $this->withHeaders(['Accept' => 'application/json'])->withSession($this->identity())
            ->get('admin/carousel')->assertStatus(403);
    }

    public function testDeniedMutationIsForbiddenWithoutExecutingController(): void
    {
        // This endpoint is permission gated; denial must happen before carousel writes.
        $before = $this->db->table('carousel_slides')->orderBy('id')->get()->getResultArray();
        $result = $this->withSession($this->identity())->post('admin/carousel/store', ['title' => 'must not be stored']);
        $result->assertStatus(403);
        $this->assertSame('', $result->response()->getHeaderLine('Location'));
        $this->assertSame($before, $this->db->table('carousel_slides')->orderBy('id')->get()->getResultArray());
    }

    public function testSoftDeletedPersonInvalidatesNextFeatureRequest(): void
    {
        $this->withSession($this->identity())->get('portal')->assertStatus(200);
        (new PersonModel())->delete($this->personId);
        $this->withHeaders(['Accept' => 'application/json'])->withSession($this->identity())->get('portal')->assertStatus(401);
        $this->assertNull(session('user'));
    }

    public function testFailedAndArrayCredentialsAreNotFlashed(): void
    {
        foreach ([['identifier' => 'not-in-fake-directory', 'password' => 'secret-not-for-flash'],
            ['identifier' => ['bad'], 'password' => ['secret-not-for-flash']]] as $credentials) {
            $token = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $token);
            Services::resetSingle('security');
            $this->withSession([])->withHeaders([])->post('auth/login', $credentials + [csrf_token() => $token])->assertRedirect();
            $this->assertNull(session('_ci_old_input'));
            $this->assertNull(session('user'));
        }
    }
}
