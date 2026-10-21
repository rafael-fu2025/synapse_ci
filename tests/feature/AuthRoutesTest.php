<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class AuthRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
    }

    public function testLoginPageRendersSuccessfully(): void
    {
        $result = $this->get('auth/login');

        $result->assertStatus(200);
        $result->assertSee('Welcome back');
        $result->assertSee('ID Number');
        $result->assertSee('Connect with us');
    }

    public function testUnauthenticatedHomeRedirectsToLogin(): void
    {
        $result = $this->get('/');

        $result->assertRedirectTo('/auth/login');
    }

    public function testAuthenticatedHomeRendersDashboard(): void
    {
        $username = 'Test Administrator';
        $id = (int) (new UserModel())->insert([
            'tenant_id' => 1, 'username' => $username . uniqid(),
            'role' => 'superadmin', 'status' => 'active',
        ]);
        // A real linked person supplies the display name rather than forged session data.
        $personId = (int) (new \App\Models\PersonModel())->insert([
            'tenant_id' => 1, 'kind' => 'employee', 'mis_id' => uniqid('AUTH_ROUTE_'),
            'first_name' => 'Test', 'last_name' => 'Administrator',
        ]);
        (new UserModel())->update($id, ['person_id' => $personId]);
        $result = $this->withSession([
            'user' => [
                'id'          => $id,
                'tenant_id'   => 1,
                'name'        => 'Test Administrator',
                'username'    => 'admin@foundationu.com',
                'role'        => 'superadmin',
                'permissions' => ['clinic.queue.read'],
                'logged_in'   => true,
            ],
            'tenant_id' => 1,
        ])->get('/');

        $result->assertStatus(200);
        $result->assertSee('Operations Overview');
        $result->assertSee('Test Administrator');
    }
}
