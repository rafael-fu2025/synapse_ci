<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthenticatedSession;
use App\Services\AuthenticatedUser;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Session\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionRotationHandoffTest extends CIUnitTestCase
{
    private const COOKIE = 'synapse_handoff_test';
    private const MESSAGE = 'Your session is being renewed. Please retry this request.';

    private string $directory;
    private AuthenticatedSession $secureSession;

    protected function setUp(): void
    {
        parent::setUp();
        helper('url');
        $this->directory = sys_get_temp_dir() . '/synapse-handoff-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $config = clone config('Session');
        $config->driver = FileHandler::class;
        $config->savePath = $this->directory;
        $config->cookieName = self::COOKIE;
        $config->matchIP = false;
        $config->timeToUpdate = 1;
        $config->regenerateDestroy = true;
        $cookie = config('Cookie');
        $cookie->prefix = $cookie->domain = '';
        $cookie->path = '/';
        $cookie->secure = false;
        $cookie->samesite = 'Lax';
        ini_set('session.gc_probability', '0');
        $this->secureSession = Services::session($config, false);
        // CI bypasses native session_start() in testing; use the real FileHandler here.
        $handler = new FileHandler($config, '127.0.0.1');
        $handler->setLogger(service('logger'));
        session_set_save_handler($handler, true);
        session_id('');
        session_start();
        Services::injectMock('session', $this->secureSession);
        $now = time();
        $this->secureSession->set([
            'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true],
            'tenant_id' => 1,
            '_auth' => ['session_id' => session_id(), 'started_at' => $now - 100, 'last_seen_at' => $now - 10, 'version' => 1],
            '_ci_old_input' => ['post' => ['mutation' => 'must-not-replay']],
            '__ci_last_regenerate' => $now - 100,
            'theme' => 'dark',
        ]);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function reopen(string $id): void
    {
        session_write_close();
        session_id($id);
        session_start();
        self::assertTrue(session_id() === $id, 'The retained session must reopen without replacement.');
    }

    private function assertAnonymous(): void
    {
        foreach (['user', 'tenant_id', '_auth', '_ci_old_input'] as $key) {
            self::assertTrue($this->secureSession->get($key) === null, $key . ' must not survive on a predecessor.');
        }
        self::assertSame('dark', $this->secureSession->get('theme'));
    }

    public function testRealRotationMarksOnlyAnonymousPredecessorAndPreservesSuccessorLifetime(): void
    {
        $oldId = session_id();
        $auth = $this->secureSession->get('_auth');
        $identity = $this->secureSession->get('user');
        $before = time();
        $this->secureSession->regenerate(true);
        $after = time();
        $newId = session_id();
        self::assertTrue($newId !== $oldId, 'Rotation must replace the session ID.');
        self::assertTrue($this->secureSession->get('_auth')['session_id'] === $newId, 'Only the successor receives the new binding.');
        foreach (['started_at', 'last_seen_at', 'version'] as $key) {
            self::assertSame($auth[$key], $this->secureSession->get('_auth')[$key]);
        }
        self::assertSame($identity, $this->secureSession->get('user'));
        self::assertSame(1, $this->secureSession->get('tenant_id'));
        self::assertNull($this->secureSession->get('_auth_rotated_at'));
        self::assertNull($this->secureSession->get('_ci_old_input'));
        $this->reopen($oldId);
        $this->assertAnonymous();
        $marker = $this->secureSession->get('_auth_rotated_at');
        self::assertIsInt($marker);
        self::assertGreaterThanOrEqual($before, $marker);
        self::assertLessThanOrEqual($after, $marker);
        self::assertNull((new AuthenticatedUser())->current());
        self::assertSame($marker, $this->secureSession->get('_auth_rotated_at'));
        self::assertNull($this->secureSession->get('_auth_notice'));
        $this->reopen($newId);
        self::assertTrue($this->secureSession->get('_auth')['session_id'] === session_id());
        self::assertSame($identity, $this->secureSession->get('user'));
        self::assertNull($this->secureSession->get('_auth_rotated_at'));
    }

    public static function predecessorAges(): iterable
    {
        yield 'within handoff window' => [0];
        yield 'after handoff window' => [60];
    }

    #[DataProvider('predecessorAges')]
    public function testMarkedPredecessorCannotRegenerateEvenAfterGraceExpires(int $age): void
    {
        $oldId = session_id();
        $this->secureSession->regenerate(false);
        $this->reopen($oldId);
        $marker = time() - $age;
        $this->secureSession->set('_auth_rotated_at', $marker);
        $lastRegenerated = $this->secureSession->get('__ci_last_regenerate');
        foreach ([false, true] as $destroy) {
            $this->secureSession->regenerate($destroy);
            self::assertTrue(session_id() === $oldId, 'A marked predecessor must never become a successor.');
            self::assertSame($lastRegenerated, $this->secureSession->get('__ci_last_regenerate'));
            self::assertSame($marker, $this->secureSession->get('_auth_rotated_at'));
            $this->assertAnonymous();
        }
        $this->reopen($oldId);
        $this->assertAnonymous();
        self::assertSame($marker, $this->secureSession->get('_auth_rotated_at'));
    }

    private function request(bool $xhr, string $method): IncomingRequest
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setGlobal('get', []);
        $request->setGlobal('post', $method === 'POST' ? ['mutation' => 'must-not-replay'] : []);
        foreach (['Accept', 'X-Requested-With', 'HX-Request', 'X-Up-Version', 'X-Synapse-Navigation', 'Sec-Fetch-Dest'] as $name) {
            $request->removeHeader($name);
        }
        $request->setHeader('Accept', $xhr ? 'application/json' : 'text/html');
        if ($xhr) {
            $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        }
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response(config('App')));
        return $request;
    }

    public static function recentRequests(): iterable
    {
        yield 'XHR GET at rotation' => [true, 'GET', 0];
        yield 'XHR mutation' => [true, 'POST', 5];
        yield 'document GET' => [false, 'GET', 5];
        yield 'document mutation is never redirected or replayed' => [false, 'POST', 5];
        yield 'inclusive fifteen-second boundary' => [true, 'GET', 15];
    }

    #[DataProvider('recentRequests')]
    public function testRecentPredecessorDenialIsRetryableWithoutAuthFailureNotice(bool $xhr, string $method, int $age): void
    {
        $this->secureSession->remove(['user', 'tenant_id', '_auth', '_ci_old_input']);
        // Retry the boundary observation only if the wall clock ticks during denied().
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $request = $this->request($xhr, $method);
            service('response')->setHeader('X-Synapse-Auth', 'session_expired')
                ->setHeader('X-Synapse-Login', site_url('auth/login'))->setHeader('Location', site_url('auth/login'));
            $now = time();
            $this->secureSession->set('_auth_rotated_at', $now - $age);
            $response = AuthenticatedUser::denied($request, false, AuthenticatedUser::AUTHENTICATION_MESSAGE, '/auth/login');
            if ($age !== 15 || time() === $now) {
                break;
            }
            $this->secureSession->remove('_auth_notice');
        }
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['message' => self::MESSAGE], json_decode($response->getBody(), true));
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('rotated', $response->getHeaderLine('X-Synapse-Session'));
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
        foreach (['X-Synapse-Auth', 'X-Synapse-Login', 'Location'] as $name) {
            self::assertSame('', $response->getHeaderLine($name));
        }
        foreach (['_auth_notice', '_auth_expired', 'error', 'warning', '_ci_old_input'] as $key) {
            self::assertNull($this->secureSession->get($key));
        }
        $this->assertAnonymous();
    }

    public static function expiredRequests(): iterable
    {
        yield 'XHR returns normal unauthorized JSON' => [true];
        yield 'document returns normal login redirect' => [false];
    }

    #[DataProvider('expiredRequests')]
    public function testSixteenSecondOldMarkerFallsBackToNormalLoginFailure(bool $xhr): void
    {
        $this->secureSession->remove(['user', 'tenant_id', '_auth', '_ci_old_input']);
        $this->secureSession->set('_auth_rotated_at', time() - 16);
        self::assertNull((new AuthenticatedUser())->current());
        $response = AuthenticatedUser::denied($this->request($xhr, 'GET'), false, AuthenticatedUser::AUTHENTICATION_MESSAGE, '/auth/login');
        self::assertSame($xhr ? 401 : 302, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', $response->getHeaderLine('X-Synapse-Session'));
        self::assertSame('', $response->getHeaderLine('Retry-After'));
        self::assertSame('authentication_required', $this->secureSession->get('_auth_notice'));
        if ($xhr) {
            self::assertSame(['message' => AuthenticatedUser::AUTHENTICATION_MESSAGE], json_decode($response->getBody(), true));
            self::assertSame('authentication_required', $response->getHeaderLine('X-Synapse-Auth'));
            self::assertSame(site_url('auth/login'), $response->getHeaderLine('X-Synapse-Login'));
            self::assertSame('', $response->getHeaderLine('Location'));
        } else {
            self::assertSame(site_url('auth/login'), $response->getHeaderLine('Location'));
        }
    }

    public static function invalidMarkers(): iterable
    {
        yield 'missing marker' => [null];
        yield 'numeric string is not an integer marker' => ['recent-string'];
        yield 'future timestamp is outside the grace window' => ['future'];
    }

    #[DataProvider('invalidMarkers')]
    public function testInvalidMarkerCannotClaimRetryableRotation(?string $marker): void
    {
        $this->secureSession->remove(['user', 'tenant_id', '_auth', '_ci_old_input']);
        $this->secureSession->set('_auth_rotated_at', match ($marker) {
            'recent-string' => (string) time(),
            'future' => time() + 60,
            default => null,
        });
        $response = AuthenticatedUser::denied($this->request(true, 'GET'), false, AuthenticatedUser::AUTHENTICATION_MESSAGE, '/auth/login');
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-Synapse-Session'));
        self::assertSame('', $response->getHeaderLine('Retry-After'));
    }

    public function testCgiAutomaticStartRotationPreservesAuthenticatedSuccessor(): void
    {
        $result = $this->probe(false);
        self::assertFalse($result['same_id']);
        self::assertTrue($result['authenticated']);
        self::assertTrue($result['bound_to_current_id']);
        self::assertTrue($result['same_auth_lifetime']);
        self::assertFalse($result['marked']);
        self::assertFalse($result['old_input']);
        self::assertTrue($result['unrelated_cookies_preserved']);
        self::assertContains(self::COOKIE, $result['sent_cookie_names']);
    }

    public function testCgiXhrStartSuppressesPredecessorCookieButPreservesUnrelatedCookies(): void
    {
        $oldId = session_id();
        $this->secureSession->regenerate(false);
        $this->reopen($oldId);
        // Force the handoff fixture even before the production marker is implemented.
        $this->secureSession->set('_auth_rotated_at', time());
        $result = $this->probe(true);
        self::assertTrue($result['same_id']);
        self::assertFalse($result['authenticated']);
        self::assertTrue($result['marked']);
        self::assertFalse($result['queued_session_cookie']);
        self::assertTrue($result['unrelated_cookies_preserved']);
        self::assertNotContains(self::COOKIE, $result['sent_cookie_names']);
        self::assertContains('handoff_preference', $result['sent_cookie_names']);
        self::assertContains('handoff_csrf', $result['sent_cookie_names']);
    }

    public function testCgiXhrStartStillRefreshesUnmarkedSuccessorCookie(): void
    {
        $this->secureSession->regenerate(false);
        $result = $this->probe(true);
        self::assertTrue($result['same_id']);
        self::assertTrue($result['authenticated']);
        self::assertTrue($result['bound_to_current_id']);
        self::assertTrue($result['same_auth_lifetime']);
        self::assertFalse($result['marked']);
        self::assertTrue($result['queued_session_cookie']);
        self::assertTrue($result['unrelated_cookies_preserved']);
        self::assertContains(self::COOKIE, $result['sent_cookie_names']);
    }

    private function probe(bool $xhr): array
    {
        $cgi = null;
        foreach (array_merge([dirname(PHP_BINARY)], explode(PATH_SEPARATOR, getenv('PATH') ?: '')) as $directory) {
            foreach (['php-cgi.exe', 'php-cgi'] as $name) {
                if (is_file($directory . DIRECTORY_SEPARATOR . $name)) {
                    $cgi = $directory . DIRECTORY_SEPARATOR . $name;
                    break 2;
                }
            }
        }
        if ($cgi === null || !function_exists('proc_open')) {
            self::markTestSkipped('PHP-CGI and proc_open are required for the real HTTP session-start regression.');
        }
        $id = session_id();
        $auth = $this->secureSession->get('_auth');
        unset($auth['session_id']);
        $payload = json_encode(['directory' => $this->directory, 'auth' => $auth], JSON_THROW_ON_ERROR);
        session_write_close();
        $environment = getenv();
        $environment['SCRIPT_FILENAME'] = TESTPATH . '_support/session_rotation_handoff_probe.php';
        $environment['REQUEST_METHOD'] = 'POST';
        $environment['CONTENT_TYPE'] = 'application/json';
        $environment['CONTENT_LENGTH'] = (string) strlen($payload);
        $environment['HTTP_COOKIE'] = self::COOKIE . '=' . $id;
        $environment['HTTP_X_REQUESTED_WITH'] = $xhr ? 'XMLHttpRequest' : '';
        $environment['REMOTE_ADDR'] = '127.0.0.1';
        $environment['SERVER_NAME'] = 'localhost';
        $environment['SERVER_PORT'] = '80';
        $environment['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $pipes = [];
        $process = proc_open([$cgi, '-d', 'cgi.force_redirect=0', '-d', 'session.gc_probability=0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, $environment);
        self::assertIsResource($process);
        try {
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            $deadline = microtime(true) + 10;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'The isolated CGI probe timed out.');
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            // Never include raw CGI headers or diagnostics: they may contain session IDs.
            self::assertTrue($errors === '', 'The isolated CGI probe wrote to stderr.');
            self::assertSame(0, $status['exitcode'], 'The isolated CGI probe failed.');
            $parts = preg_split('/\r?\n\r?\n/', $output, 2);
            self::assertCount(2, $parts, 'Expected CGI headers followed by JSON.');
            $result = json_decode($parts[1], true);
            self::assertIsArray($result, 'The isolated CGI probe did not return JSON.');
            self::assertArrayNotHasKey('probe_error', $result);
            preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=/mi', $parts[0], $matches);
            $result['sent_cookie_names'] = $matches[1];
            self::assertTrue($result['active']);
            self::assertTrue($result['strict_mode']);
            self::assertSame('dark', $result['theme']);
            return $result;
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
