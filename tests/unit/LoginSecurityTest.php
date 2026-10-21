<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\LoginThrottleFilter;
use App\Services\LoginAttemptStore;
use App\Services\LoginRateLimiter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\LoginSecurity;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

final class RecordingLoginStore implements LoginAttemptStore
{
    public array $calls = [];
    public int $wait = 0;
    public bool $unavailable = false;

    public function consume(array $buckets, int $now): int
    {
        $this->calls[] = [$buckets, $now];
        if ($this->unavailable) {
            throw new \RuntimeException('private SQL password identifier details');
        }
        return $this->wait;
    }
}

/** All store interactions are injected; no database connections or credentials. */
final class LoginSecurityTest extends CIUnitTestCase
{
    private RecordingLoginStore $store;
    private LoginRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new RecordingLoginStore();
        $this->limiter = new LoginRateLimiter($this->store, $this->settings());
    }

    private function settings(): LoginSecurity
    {
        $config = new LoginSecurity();
        $config->ipAttempts = 60;
        $config->ipWindowSeconds = 60;
        $config->identityAttempts = 5;
        $config->identityWindowSeconds = 300;
        return $config;
    }

    public function testBudgetsUseHashedIdentityAndAddressNotPasswords(): void
    {
        $this->assertSame(0, $this->limiter->consume('192.0.2.1', 'Student@Example.edu', 1000));
        [$buckets, $now] = $this->store->calls[0];
        $this->assertSame(1000, $now);
        $this->assertSame([60, 5], array_column($buckets, 'limit'));
        $this->assertSame([60, 300], array_column($buckets, 'seconds'));
        foreach ($buckets as $bucket) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $bucket['key']);
        }
        $this->assertNotSame($buckets[0]['key'], $buckets[1]['key']);
        $this->assertStringNotContainsString('Student', serialize($this->store->calls));
        $this->assertStringNotContainsString('192.0.2.1', serialize($this->store->calls));
        $this->assertCount(3, (new \ReflectionMethod(LoginRateLimiter::class, 'consume'))->getParameters());
    }

    public function testEquivalentIdentityAndIpSpellingsShareLimits(): void
    {
        foreach (['192.0.2.1', '::ffff:192.0.2.1'] as $ip) {
            $this->limiter->consume($ip, '  Student@Example.edu  ', 1000);
        }
        $this->assertSame($this->store->calls[0], $this->store->calls[1]);
        $this->limiter->consume('2001:db8:0:0:0:0:0:1', 'STUDENT@example.edu', 1000);
        $this->limiter->consume('2001:db8::1', 'student@example.edu', 1000);
        $this->assertSame($this->store->calls[2], $this->store->calls[3]);
    }

    public function testIdentityAndIpChangesAreScopedWithoutLosingLeadingZeroes(): void
    {
        foreach ([['192.0.2.1', '00012'], ['192.0.2.1', '12'], ['192.0.2.2', '00012']] as [$ip, $id]) {
            $this->limiter->consume($ip, $id, 1000);
        }
        [$a, $b, $c] = array_column($this->store->calls, 0);
        $this->assertSame($a[0], $b[0]);
        $this->assertNotSame($a[1]['key'], $b[1]['key']);
        $this->assertNotSame($a[0]['key'], $c[0]['key']);
        $this->assertNotSame($a[1]['key'], $c[1]['key']);
    }

    public function testMalformedIdentifiersUseOneBoundedBudget(): void
    {
        foreach ([null, [], '', str_repeat('a', 401), "i\x00d", "\xff"] as $identifier) {
            $this->limiter->consume('192.0.2.1', $identifier, 1000);
        }
        $this->assertCount(1, array_unique(array_map('serialize', $this->store->calls)));
    }

    public function testInvalidIpNeverCallsStorage(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->limiter->consume('spoofed-client', 'student', 1000);
        } finally {
            $this->assertSame([], $this->store->calls);
        }
    }

    public static function invalidLimits(): iterable
    {
        foreach (['ipAttempts', 'identityAttempts'] as $name) {
            foreach ([0, -1, 10001] as $value) { yield [$name, $value]; }
        }
        foreach (['ipWindowSeconds', 'identityWindowSeconds'] as $name) {
            foreach ([0, -1, 86401] as $value) { yield [$name, $value]; }
        }
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidConfigurationFailsClosed(string $name, int $value): void
    {
        $config = $this->settings();
        $config->$name = $value;
        $this->expectException(\RuntimeException::class);
        new LoginRateLimiter($this->store, $config);
    }

    private function request(string $method = 'POST', bool $json = true)
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setGlobal('server', ['REMOTE_ADDR' => '192.0.2.1']);
        $request->setGlobal('post', ['identifier' => 'student', 'password' => 'synthetic-password']);
        $request->removeHeader('Accept');
        if ($json) { $request->setHeader('Accept', 'application/json'); }
        return $request;
    }

    public function testAllowedAttemptContinuesAndClearsOldCredentialInput(): void
    {
        session()->set('_ci_old_input', ['password' => 'synthetic-password']);
        $this->assertNull((new LoginThrottleFilter($this->limiter))->before($this->request()));
        $this->assertCount(1, $this->store->calls);
        $this->assertNull(session()->get('_ci_old_input'));
    }

    public function testUntrustedForwardedHeaderCannotChooseTheClientBucket(): void
    {
        $app = config('App');
        $saved = $app->proxyIPs;
        $app->proxyIPs = [];
        try {
            $request = $this->request();
            $request->setHeader('X-Forwarded-For', '203.0.113.99');
            $this->assertSame('192.0.2.1', $request->getIPAddress());
            $this->assertNull((new LoginThrottleFilter($this->limiter))->before($request));
            $actual = $this->store->calls[0][0];
            $this->limiter->consume('192.0.2.1', 'student', 1000);
            $this->assertSame($actual, $this->store->calls[1][0]);
        } finally {
            $app->proxyIPs = $saved;
        }
    }

    public function testGetLoginDoesNotConsumeAttemptBudget(): void
    {
        $this->assertNull((new LoginThrottleFilter($this->limiter))->before($this->request('GET')));
        $this->assertSame([], $this->store->calls);
    }

    public function testBlockedJsonUses429RetryAfterNoStoreAndRefreshedCsrf(): void
    {
        $this->store->wait = 123;
        $response = (new LoginThrottleFilter($this->limiter))->before($this->request());
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('123', $response->getHeaderLine('Retry-After'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(csrf_hash(), $response->getHeaderLine('X-CSRF-TOKEN'));
        $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($data['success']);
        $this->assertSame(csrf_hash(), $data['csrf']['hash']);
        $this->assertStringNotContainsString('student', $response->getBody());
        $this->assertStringNotContainsString('synthetic-password', $response->getBody());
    }

    public function testBlockedBrowserGets429RatherThanAFalseSuccessRedirect(): void
    {
        $this->store->wait = 60;
        $response = (new LoginThrottleFilter($this->limiter))->before($this->request('POST', false));
        $this->assertSame(429, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Location'));
        $this->assertStringContainsString('Return to sign in', $response->getBody());
    }

    public function testStorageFailureIsGeneric503AndNeverBypassesLimit(): void
    {
        $this->store->unavailable = true;
        $response = (new LoginThrottleFilter($this->limiter))->before($this->request());
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('60', $response->getHeaderLine('Retry-After'));
        $this->assertStringNotContainsString('SQL', $response->getBody());
        $this->assertStringNotContainsString('password', $response->getBody());
        $this->assertStringNotContainsString('identifier', $response->getBody());
    }
}
