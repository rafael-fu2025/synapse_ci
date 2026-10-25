<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\WebCsrfFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebCsrfFilterTest extends CIUnitTestCase
{
    public static function logoutRequests(): iterable
    {
        foreach (['', 'index.php'] as $indexPage) {
            foreach (['missing', 'mismatch', 'valid'] as $token) {
                foreach (['native', 'ajax', 'json', 'htmx'] as $client) {
                    yield "$indexPage/$token/$client" => [$indexPage, $token, $client];
                }
            }
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('logoutRequests')]
    public function testLogoutRecoveryPreservesAuthentication(string $indexPage, string $token, string $client): void
    {
        helper(['url', 'form']);
        $app = config(App::class);
        $app->indexPage = $indexPage;
        $security = config('Security');
        $regenerate = $security->regenerate;
        $cookie = str_repeat('a', 32);
        service('superglobals')->setCookieArray([$security->cookieName => $cookie]);
        Services::resetSingle('security');
        $request = new IncomingRequest($app, new SiteURI($app, 'auth/logout'), null, new UserAgent());
        $request->setMethod('POST');
        $request->setGlobal('get', ['return' => 'https://evil.invalid']);
        self::assertSame(($indexPage === '' ? '' : '/' . $indexPage) . '/auth/logout', $request->getUri()->getPath());
        $request->setGlobal('post', $token === 'missing' ? [] : [csrf_token() => $token === 'valid' ? csrf_hash() : str_repeat('b', 32)]);
        if ($client === 'ajax') $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        if ($client === 'json') $request->setHeader('Accept', 'application/json');
        if ($client === 'htmx') $request->setHeader('HX-Request', 'true');
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response($app));
        $state = ['user' => ['id' => 42, 'role' => 'student'], '_auth' => ['version' => 7], 'tenant_id' => 3, 'theme' => 'dark'];
        session()->set($state);
        $id = session_id();

        self::assertSame('auth/logout', trim($request->getUri()->getRoutePath(), '/'));
        $response = (new WebCsrfFilter())->before($request);

        foreach ($state as $key => $value) self::assertSame($value, session($key));
        self::assertSame($id, session_id());
        self::assertSame($regenerate, $security->regenerate);
        if ($token === 'valid') {
            self::assertNull($response);
            self::assertNull(session()->getFlashdata('logout_warning'));
            return;
        }
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame($cookie, $response->getCookie($security->cookieName)->getValue());
        self::assertSame(csrf_hash(), $response->getCookie($security->cookieName)->getValue());
        if ($client === 'native') {
            self::assertSame(303, $response->getStatusCode());
            self::assertSame(site_url('auth/logout/confirm'), $response->getHeaderLine('Location'));
            self::assertStringContainsString('Sign out was not completed', session()->getFlashdata('logout_warning'));
        } else {
            self::assertSame(403, $response->getStatusCode());
            self::assertFalse($response->hasHeader('Location'));
            $json = json_decode($response->getBody(), true);
            self::assertFalse($json['success']);
            self::assertTrue($json['reload']);
            self::assertSame(csrf_hash(), $json['csrf']['hash']);
            self::assertNull(session()->getFlashdata('logout_warning'));
        }
    }

    public static function otherWrites(): iterable
    {
        yield ['PUT', 'auth/logout'];
        yield ['DELETE', 'auth/logout'];
        yield ['POST', 'auth/logout/confirm'];
        yield ['POST', 'other/auth/logout'];
    }

    #[RunInSeparateProcess]
    #[DataProvider('otherWrites')]
    public function testRecoveryIsLimitedToLogoutPost(string $method, string $path): void
    {
        service('superglobals')->setCookieArray([]);
        Services::resetSingle('security');
        $app = config(App::class);
        $request = new IncomingRequest($app, new SiteURI($app, $path), null, new UserAgent());
        $request->setMethod($method);
        $request->setGlobal('post', []);
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response($app));
        $response = (new WebCsrfFilter())->before($request);
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));
        self::assertNull(session()->getFlashdata('logout_warning'));
    }

    public static function loginRequests(): iterable
    {
        yield 'native login' => ['', false];
        yield 'index.php login' => ['index.php', false];
        yield 'AJAX login' => ['index.php', true];
    }

    #[RunInSeparateProcess]
    #[DataProvider('loginRequests')]
    public function testInvalidLoginTokenRecoversWithoutProcessingCredentials(string $indexPage, bool $ajax): void
    {
        $cookies = service('superglobals')->getCookieArray();
        try {
            service('superglobals')->setCookieArray([]);
            Services::resetSingle('security');
            $app = config(App::class);
            $app->indexPage = $indexPage;
            helper('url');
            $request = new IncomingRequest($app, new SiteURI($app, 'auth/login'), null, new UserAgent());
            $request->setMethod('POST');
            $request->setGlobal('post', ['identifier' => 'fixture', 'password' => 'must-not-be-retained']);
            if ($ajax) $request->setHeader('X-Requested-With', 'XMLHttpRequest');
            Services::injectMock('request', $request);
            Services::injectMock('response', new Response($app));
            session()->set('_ci_old_input', ['post' => ['password' => 'stale-secret']]);

            $response = (new WebCsrfFilter())->before($request);

            self::assertNotNull($response);
            self::assertNull(session('user'));
            self::assertStringNotContainsString('must-not-be-retained', (string) $response->getBody());
            if ($ajax) {
                self::assertSame(403, $response->getStatusCode());
                self::assertTrue(json_decode($response->getBody(), true)['reload']);
                self::assertFalse($response->hasHeader('Location'));
            } else {
                self::assertSame(303, $response->getStatusCode());
                self::assertSame(site_url('auth/login'), $response->getHeaderLine('Location'));
                self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
                self::assertNull(session('_ci_old_input'));
                self::assertStringContainsString('Please sign in again', session()->getFlashdata('warning'));
                self::assertNotNull($response->getCookie(config('Security')->cookieName));
            }
        } finally {
            service('superglobals')->setCookieArray($cookies);
            Services::resetSingle('security');
            Services::resetSingle('request');
            Services::resetSingle('response');
            Services::resetSingle('redirectresponse');
        }
    }
    #[RunInSeparateProcess]
    public function testRejectedNativeFormGetsFreshCookieAndActionableNoMutationMessage(): void
    {
        $config = config('Security');
        $original = $config->regenerate;
        $config->regenerate = false;
        $previousCookie = service('superglobals')->getCookieArray();
        try {
            service('superglobals')->setCookieArray([]);
            Services::resetSingle('security');
            $app = config(App::class);
            $request = new IncomingRequest($app, new SiteURI($app, 'http://localhost/clinic/appointments/check-in/11'), null, new UserAgent());
            $request->setMethod('POST');
            $request->setGlobal('post', []);
            Services::injectMock('request', $request);
            Services::injectMock('response', new Response($app));

            $response = (new WebCsrfFilter())->before($request);

            self::assertNotNull($response);
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('This request was not processed', $response->getBody());
            self::assertStringContainsString('reload this page and retry', $response->getBody());
            self::assertNotNull($response->getCookie(config('Security')->cookieName));
            self::assertSame(32, strlen($response->getCookie(config('Security')->cookieName)->getValue()));
        } finally {
            service('superglobals')->setCookieArray($previousCookie);
            $config->regenerate = $original;
            Services::resetSingle('security');
            Services::resetSingle('request');
            Services::resetSingle('response');
        }
    }
}
