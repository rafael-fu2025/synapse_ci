<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Models\CarouselSlideModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\Carousel\CarouselService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use ReflectionProperty;

/** No migrations or live DB: timeout fixtures and explicit SQL doubles only. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthFailureContractTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $_SESSION = [];
        $this->request();
    }

    private function request(array $headers = [], array $query = [], string $method = 'GET'): IncomingRequest
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setGlobal('get', $query);
        foreach (['Accept', 'X-Requested-With', 'HX-Request', 'X-Up-Version', 'X-Synapse-Navigation', 'Sec-Fetch-Dest'] as $name) {
            $request->removeHeader($name);
        }
        foreach ($headers as $name => $value) $request->setHeader($name, $value);
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response(config('App')));
        return $request;
    }

    private function identity(): void
    {
        session()->set(['user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true], 'tenant_id' => 1,
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()],
            'theme' => 'dark']);
    }

    private function expire(bool $idle = true): void
    {
        $this->identity();
        $auth = session('_auth');
        $auth['started_at'] = time() - ($idle ? config('Session')->authIdleTimeout : config('Session')->authAbsoluteTimeout) - 10;
        $auth['last_seen_at'] = $idle ? $auth['started_at'] : time();
        session()->set('_auth', $auth);
        self::assertNull((new AuthenticatedUser())->current());
    }

    private function controller(bool $render = false, bool $failRender = false): AuthController
    {
        $controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
        if ($render) {
            $result = $this->getMockBuilder(BaseResult::class)->disableOriginalConstructor()->getMock();
            $result->method('getResult')->willReturn([]);
            $builder = $this->getMockBuilder(BaseBuilder::class)->disableOriginalConstructor()->getMock();
            foreach (['where', 'orderBy', 'limit'] as $method) $builder->method($method)->willReturnSelf();
            $builder->method('getTable')->willReturn('carousel_slides');
            if ($failRender) {
                $builder->method('get')->willThrowException(new \RuntimeException('Slides unavailable'));
            } else {
                $builder->method('get')->willReturn($result);
            }
            $db = $this->getMockBuilder(BaseConnection::class)->disableOriginalConstructor()->getMock();
            $db->method('table')->willReturn($builder);
            $slides = new CarouselSlideModel($db);
            (new ReflectionProperty(AuthController::class, 'carouselService'))->setValue($controller, new CarouselService($slides));
        }
        return $controller;
    }

    private function assertUnauthorized($response, string $reason): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame($reason, $response->getHeaderLine('X-Synapse-Auth'));
        self::assertSame(site_url('auth/login'), $response->getHeaderLine('X-Synapse-Login'));
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['message' => AuthenticatedUser::noticeMessage($reason)], json_decode($response->getBody(), true));
    }

    public static function backgrounds(): iterable
    {
        yield 'xhr' => [['X-Requested-With' => 'XMLHttpRequest'], []];
        yield 'json' => [['Accept' => 'application/json'], []];
        yield 'htmx' => [['HX-Request' => 'true'], []];
        yield 'unpoly' => [['X-Up-Version' => '3.0'], []];
        yield 'synapse navigation' => [['X-Synapse-Navigation' => '1', 'Accept' => 'text/html'], []];
        yield 'fragment' => [[], ['fragment' => '1']];
        yield 'fetch' => [['Sec-Fetch-Dest' => 'empty'], []];
        yield 'iframe' => [['Sec-Fetch-Dest' => 'iframe'], []];
    }

    #[DataProvider('backgrounds')]
    public function testBackgroundLoginCannotRenderOrConsumePendingTimeout(array $headers, array $query): void
    {
        $this->expire();
        // Simulate the compatibility flash having aged out during background polling.
        session()->remove('_auth_expired');
        for ($poll = 0; $poll < 3; $poll++) {
            self::assertNull((new AuthenticatedUser())->current());
            $this->request($headers, $query);
            $this->assertUnauthorized($this->controller()->login(), 'session_expired');
            self::assertSame('session_expired', session('_auth_notice'));
            self::assertNull(session('warning'));
            self::assertNull(session('user'));
        }
        self::assertSame('dark', session('theme'));
        $this->request(['Sec-Fetch-Dest' => 'document']);
        $html = $this->controller(true)->login();
        self::assertStringContainsString(AuthenticatedUser::EXPIRED_MESSAGE, $html);
        self::assertStringContainsString('alert-warning', $html);
        self::assertNull(session('_auth_notice'));
        $this->request();
        self::assertStringNotContainsString(AuthenticatedUser::EXPIRED_MESSAGE, $this->controller(true)->login());
    }

    public static function noticeReasons(): iterable
    {
        yield ['session_expired'];
        yield ['reauthentication_required'];
        yield ['authentication_required'];
    }

    #[DataProvider('noticeReasons')]
    public function testRenderedWarningDoesNotRepeatOnNextDocumentRequest(string $reason): void
    {
        session()->set('_auth_notice', $reason);
        $message = AuthenticatedUser::noticeMessage($reason);
        $this->request(['Sec-Fetch-Dest' => 'document']);
        self::assertStringContainsString($message, $this->controller(true)->login());
        self::assertNull(session('_auth_notice'));
        self::assertSame($message, session()->getFlashdata('warning'));

        // CI retains new flashdata as old for the next request; reading it does not consume it.
        $_SESSION['__ci_vars']['warning'] = 'old';
        $this->request(['Sec-Fetch-Dest' => 'document']);
        $html = $this->controller(true)->login();
        self::assertStringNotContainsString($message, $html);
        self::assertStringNotContainsString('alert-warning', $html);
        self::assertNull(session('warning'));
        self::assertNull(session('_auth_notice'));
    }

    public function testAbsoluteTimeoutSurvivesRedirectAndExtraChecks(): void
    {
        $this->expire(false);
        $response = AuthenticatedUser::denied($this->request(), false, 'Please sign in.', '/auth/login');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(site_url('auth/login'), $response->getHeaderLine('Location'));
        self::assertSame('', $response->getHeaderLine('X-Synapse-Auth'));
        session()->remove('_auth_expired');
        self::assertNull((new AuthenticatedUser())->current());
        $request = $this->request(['Accept' => 'application/json']);
        $this->assertUnauthorized(AuthenticatedUser::denied($request, false, 'Please sign in.', '/auth/login'), 'session_expired');
    }

    public static function invalidAuthentication(): iterable
    {
        yield 'missing metadata' => ['_auth', null];
        yield 'invalid binding' => ['_auth', ['started_at' => 1, 'last_seen_at' => 1, 'version' => 1, 'session_id' => 'wrong']];
        yield 'invalid timestamps' => ['_auth', ['started_at' => '1', 'last_seen_at' => 1, 'version' => 1, 'session_id' => '']];
        yield 'malformed identity' => ['user', ['logged_in' => true]];
        yield 'missing tenant' => ['tenant_id', null];
    }

    #[DataProvider('invalidAuthentication')]
    public function testInvalidAuthenticationNeverClaimsExpiry(string $key, mixed $value): void
    {
        $this->identity();
        session()->set($key, $value);
        for ($check = 0; $check < 3; $check++) self::assertNull((new AuthenticatedUser())->current());
        self::assertNull(session('_auth_expired'));
        $request = $this->request(['Accept' => 'application/json']);
        $this->assertUnauthorized(AuthenticatedUser::denied($request, false, 'Please sign in.', '/auth/login'), 'reauthentication_required');
        $this->request();
        $html = $this->controller(true)->login();
        self::assertStringContainsString(AuthenticatedUser::REAUTHENTICATION_MESSAGE, $html);
        self::assertStringNotContainsString(AuthenticatedUser::EXPIRED_MESSAGE, $html);
        self::assertNull(session('_auth_notice'));
    }

    public function testRevokedDatabaseIdentityRequiresReauthentication(): void
    {
        $this->identity();
        $result = $this->getMockBuilder(BaseResult::class)->disableOriginalConstructor()->getMock();
        $result->method('getRowArray')->willReturnOnConsecutiveCalls(['id' => 1], null);
        $builder = $this->getMockBuilder(BaseBuilder::class)->disableOriginalConstructor()->getMock();
        $builder->method('where')->willReturnSelf();
        $builder->method('get')->willReturn($result);
        $db = $this->getMockBuilder(BaseConnection::class)->disableOriginalConstructor()->getMock();
        $db->expects($this->exactly(2))->method('table')->willReturn($builder);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $saved = $instances->getValue();
        $instances->setValue(null, ['tests' => $db, 'default' => $db]);
        try {
            self::assertNull((new AuthenticatedUser())->current());
            self::assertSame('reauthentication_required', session('_auth_notice'));
            self::assertNull(session('_auth_expired'));
            $this->request(['HX-Request' => 'true']);
            $this->assertUnauthorized($this->controller()->login(), 'reauthentication_required');
        } finally {
            $instances->setValue(null, $saved);
        }
    }

    public function testMissingCookieIsGenericButInitialLoginHasNoWarning(): void
    {
        $this->request();
        $html = $this->controller(true)->login();
        self::assertNull(session('_auth_notice'));
        self::assertStringNotContainsString('alert-warning', $html);
        $request = $this->request(['Accept' => 'application/json']);
        $this->assertUnauthorized(AuthenticatedUser::denied($request, false, AuthenticatedUser::AUTHENTICATION_MESSAGE, 'https://evil.invalid/login'), 'authentication_required');
        self::assertSame('authentication_required', session('_auth_notice'));
        $this->request();
        $html = $this->controller(true)->login();
        self::assertStringContainsString(AuthenticatedUser::AUTHENTICATION_MESSAGE, $html);
        self::assertStringNotContainsString(AuthenticatedUser::EXPIRED_MESSAGE, $html);
        self::assertNull(session('_auth_notice'));
    }

    public function testMutationAndAuthenticatedDenialsDoNotAdvertiseLogin(): void
    {
        foreach ([[false, false], [true, false], [true, true]] as [$authenticated, $ajax]) {
            $request = $this->request($ajax ? ['Accept' => 'application/json'] : [], [], 'POST');
            $response = AuthenticatedUser::denied($request, $authenticated, 'Denied.', '/auth/login');
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('', $response->getHeaderLine('Location'));
            self::assertSame('', $response->getHeaderLine('X-Synapse-Auth'));
            self::assertSame('', $response->getHeaderLine('X-Synapse-Login'));
        }
        $request = $this->request(['Accept' => 'application/json'], [], 'POST');
        $this->assertUnauthorized(AuthenticatedUser::denied($request, false, AuthenticatedUser::AUTHENTICATION_MESSAGE, '/auth/login'), 'authentication_required');
        // Even a reused response must not leak auth headers into a permissions denial.
        $response = AuthenticatedUser::denied($request, true, 'Forbidden.', '/portal');
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-Synapse-Auth'));
        self::assertSame('', $response->getHeaderLine('X-Synapse-Login'));
    }

    public function testFailedLoginRenderDoesNotAcknowledgePendingNotice(): void
    {
        $this->expire();
        $this->request();
        try {
            $this->controller(true, true)->login();
            self::fail('Expected the slides query double to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Slides unavailable', $exception->getMessage());
        }
        self::assertSame('session_expired', session('_auth_notice'));
        $this->request();
        self::assertStringContainsString(AuthenticatedUser::EXPIRED_MESSAGE, $this->controller(true)->login());
        self::assertNull(session('_auth_notice'));
    }

    public function testCanonicalLoginHeaderPreservesConfiguredApplicationPath(): void
    {
        config('App')->baseURL = 'https://example.com/campus/';
        config('App')->indexPage = 'index.php';
        $request = $this->request(['Accept' => 'application/json'], ['next' => 'https://evil.invalid']);
        $response = AuthenticatedUser::denied($request, false, AuthenticatedUser::AUTHENTICATION_MESSAGE, 'https://evil.invalid');
        $this->assertUnauthorized($response, 'authentication_required');
        self::assertSame('/campus/index.php/auth/login', parse_url($response->getHeaderLine('X-Synapse-Login'), PHP_URL_PATH));
        self::assertStringNotContainsString('evil.invalid', $response->getHeaderLine('X-Synapse-Login'));
    }

    public function testLogoutClearsPendingNoticeWithoutDatabase(): void
    {
        $this->expire();
        $auth = (new ReflectionClass(AuthService::class))->newInstanceWithoutConstructor();
        $auth->logout();
        self::assertNull(session('_auth_notice'));
        self::assertNull(session('_auth_expired'));
        self::assertSame('dark', session('theme'));
    }
}
