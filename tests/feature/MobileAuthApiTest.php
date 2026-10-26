<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Api\ApiContext;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/** Mobile bearer-token authentication contract: login, refresh rotation, logout, and gates. */
final class MobileAuthApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\\Database\\Seeds\\PermissionsAndRolesSeeder';

    private string $password;

    /** @var list<int> */
    private array $people = [];
    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        // Fixture credential generated per run; no reusable password value ships in source.
        $this->password = 'Fixture-' . bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        ApiContext::clear();
        CurrentTenant::reset();
        $db = Database::connect();
        if ($this->users !== []) {
            $db->table('api_tokens')->whereIn('user_id', $this->users)->delete();
            $db->table('users')->whereIn('id', $this->users)->delete();
        }
        if ($this->people !== []) {
            $db->table('people')->whereIn('id', $this->people)->delete();
        }
        parent::tearDown();
    }

    /** @return array{id: int, identifier: string} */
    private function account(string $role): array
    {
        $unique = uniqid('mobile_api_', true);
        $personId = (int) (new PersonModel())->insert([
            'tenant_id' => 1,
            'kind' => $role === 'student' ? 'student' : 'employee',
            'mis_id' => $unique,
            'first_name' => 'Mobile',
            'last_name' => 'Fixture',
        ]);
        $userId = (int) (new UserModel())->insert([
            'tenant_id' => 1,
            'person_id' => $personId,
            'username' => $unique,
            'role' => $role,
            'status' => 'active',
            'password_hash' => password_hash($this->password, PASSWORD_DEFAULT),
        ]);
        $this->people[] = $personId;
        $this->users[] = $userId;
        return ['id' => $userId, 'identifier' => $unique];
    }

    private function login(array $account, ?string $password = null): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody((string) json_encode(['identifier' => $account['identifier'], 'password' => $password ?? $this->password]))
            ->post('api/v1/auth/login');
    }

    /** @return array<string, mixed> */
    private function json(\CodeIgniter\Test\TestResponse $response): array
    {
        $decoded = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function testLoginIssuesTokensAndMeExposesIdentity(): void
    {
        $account = $this->account('student');
        $result = $this->login($account);
        $result->assertStatus(200);

        $payload = $this->json($result);
        $token = $payload['token'] ?? [];
        $this->assertSame('Bearer', $token['token_type'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($token['access_token'] ?? ''));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($token['refresh_token'] ?? ''));
        $this->assertSame($account['id'], $payload['user']['id'] ?? null);
        $this->assertSame('student', $payload['user']['role'] ?? null);
        $this->assertArrayNotHasKey('logged_in', $payload['user'] ?? []);

        $me = $this->withHeaders($this->bearer((string) $token['access_token']))->get('api/v1/me');
        $me->assertStatus(200);
        $identity = $this->json($me);
        $this->assertSame($account['id'], $identity['user']['id'] ?? null);
        $this->assertTrue($identity['entitlements']['portal'] ?? false);
        $this->assertSame([], $identity['entitlements']['staff_queue_units'] ?? null);
    }

    public function testInvalidCredentialsReturnGenericUnauthorized(): void
    {
        $account = $this->account('student');
        $result = $this->login($account, 'definitely-wrong-' . bin2hex(random_bytes(4)));
        $result->assertStatus(401);
        $this->assertSame('invalid_credentials', $this->json($result)['error']['code'] ?? null);
    }

    public function testLoginValidationReturnsFieldErrors(): void
    {
        $result = $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody('{}')->post('api/v1/auth/login');
        $result->assertStatus(422);
        $error = $this->json($result)['error'] ?? [];
        $this->assertSame('validation_failed', $error['code'] ?? null);
        $this->assertArrayHasKey('identifier', $error['fields'] ?? []);
        $this->assertArrayHasKey('password', $error['fields'] ?? []);
    }

    public function testProtectedEndpointsRequireBearerToken(): void
    {
        $anonymous = $this->get('api/v1/me');
        $anonymous->assertStatus(401);
        $this->assertSame('invalid_token', $this->json($anonymous)['error']['code'] ?? null);

        $garbage = $this->withHeaders($this->bearer('not-a-real-token'))->get('api/v1/me');
        $garbage->assertStatus(401);
    }

    public function testRefreshRotatesOnceAndFamilyRevokesOnReplay(): void
    {
        $account = $this->account('student');
        $first = $this->json($this->login($account))['token'];

        $second = $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody((string) json_encode(['refresh_token' => $first['refresh_token']]))
            ->post('api/v1/auth/refresh');
        $second->assertStatus(200);
        $rotated = $this->json($second)['token'];
        $this->assertNotSame($first['access_token'], $rotated['access_token']);

        // The retired access and refresh credentials are dead immediately.
        $this->withHeaders($this->bearer((string) $first['access_token']))
            ->get('api/v1/me')->assertStatus(401);

        $replay = $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody((string) json_encode(['refresh_token' => $first['refresh_token']]))
            ->post('api/v1/auth/refresh');
        $replay->assertStatus(401);

        // Replay is a compromise signal: the successor family dies with it.
        $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody((string) json_encode(['refresh_token' => $rotated['refresh_token']]))
            ->post('api/v1/auth/refresh')->assertStatus(401);
    }

    public function testLogoutEndsTheSessionFamily(): void
    {
        $account = $this->account('student');
        $token = $this->json($this->login($account))['token'];

        $this->withHeaders($this->bearer((string) $token['access_token']))
            ->post('api/v1/auth/logout')->assertStatus(204);
        $this->withHeaders($this->bearer((string) $token['access_token']))
            ->get('api/v1/me')->assertStatus(401);
    }

    public function testExpiredAccessTokenIsRejected(): void
    {
        $account = $this->account('student');
        $token = $this->json($this->login($account))['token'];

        Database::connect()->table('api_tokens')
            ->where('token_hash', hash('sha256', (string) $token['access_token']))
            ->update(['access_expires_at' => '2000-01-01 00:00:00']);

        $this->withHeaders($this->bearer((string) $token['access_token']))
            ->get('api/v1/me')->assertStatus(401);
    }

    public function testSessionCookieCannotAuthenticateApiRequests(): void
    {
        $account = $this->account('student');
        $token = $this->json($this->login($account))['token'];
        $cookie = config('Session')->cookieName;

        $superglobals = service('superglobals');
        $superglobals->setCookie($cookie, 'browser-session-value');
        try {
            $mixed = $this->withHeaders($this->bearer((string) $token['access_token']))
                ->get('api/v1/me');
        } finally {
            $superglobals->unsetCookie($cookie);
        }
        $mixed->assertStatus(401);
    }

    public function testStaffRolesExposeQueueEntitlements(): void
    {
        $account = $this->account('clinic_staff');
        $token = $this->json($this->login($account))['token'];

        $me = $this->withHeaders($this->bearer((string) $token['access_token']))->get('api/v1/me');
        $me->assertStatus(200);
        $this->assertSame(['clinic'], $this->json($me)['entitlements']['staff_queue_units'] ?? null);
    }
}
