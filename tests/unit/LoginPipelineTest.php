<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LoginAttemptStore;
use App\Services\LoginRateLimiter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/** Actual filter pipeline with a harmless closure instead of DB/MIS authentication. */
final class LoginPipelineTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        service('superglobals')->setCookieArray([]);
        Services::resetSingle('security');
        parent::tearDown();
    }

    private function route(LoginAttemptStore $store): void
    {
        Services::injectMock('loginRateLimiter', new LoginRateLimiter($store, new \Config\LoginSecurity()));
        $this->withRoutes([
            ['POST', '__login_probe', static fn () => 'credential handler reached', ['filter' => ['web_csrf', 'login_throttle']]],
        ]);
    }

    private function validToken(): array
    {
        $hash = service('security')->generateHash();
        $security = config('Security');
        service('superglobals')->setCookie(config('Cookie')->prefix . $security->cookieName, $hash);
        Services::resetSingle('security');
        return [$security->tokenName => $hash];
    }

    public function testInvalidCsrfStopsBeforeTheLoginLimiter(): void
    {
        $store = $this->createMock(LoginAttemptStore::class);
        $store->expects($this->never())->method('consume');
        $this->route($store);
        $response = $this->withHeaders(['Accept' => 'application/json'])->post('__login_probe', ['identifier' => 'synthetic']);
        $response->assertStatus(403);
        $response->assertDontSee('credential handler reached');
        $this->assertSame('nosniff', $response->response()->getHeaderLine('X-Content-Type-Options'));
    }

    public function testThrottledRequestStopsBeforeCredentialHandlerAndCarriesSecurityHeaders(): void
    {
        $store = $this->createMock(LoginAttemptStore::class);
        $store->expects($this->once())->method('consume')->willReturn(45);
        $this->route($store);
        $response = $this->withHeaders(['Accept' => 'application/json'])->post('__login_probe',
            $this->validToken() + ['identifier' => 'synthetic', 'password' => 'synthetic-password']);
        $response->assertStatus(429);
        $response->assertDontSee('credential handler reached');
        $this->assertSame('45', $response->response()->getHeaderLine('Retry-After'));
        $this->assertSame('nosniff', $response->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString("form-action 'self'", $response->response()->getHeaderLine('Content-Security-Policy'));
        $this->assertNotEmpty($response->response()->getHeaderLine('X-CSRF-TOKEN'));
    }

    public function testValidTokenAndAvailableBudgetReachTheHandler(): void
    {
        $store = $this->createMock(LoginAttemptStore::class);
        $store->expects($this->once())->method('consume')->willReturn(0);
        $this->route($store);
        $response = $this->withHeaders(['Accept' => 'application/json'])->post('__login_probe',
            $this->validToken() + ['identifier' => 'synthetic', 'password' => 'synthetic-password']);
        $response->assertStatus(200);
        $response->assertSee('credential handler reached');
        $this->assertSame('SAMEORIGIN', $response->response()->getHeaderLine('X-Frame-Options'));
    }
}
