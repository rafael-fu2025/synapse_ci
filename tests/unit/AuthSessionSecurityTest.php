<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Integrations\Mis\FakeMisClient;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Isolated auth schema: never migrates or repairs an existing application DB. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthSessionSecurityTest extends CIUnitTestCase
{
    private UserModel $users;
    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $db = Database::connect();
        // Minimal authentication schema, not a substitute for MySQL migration tests.
        $db->query('CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT, name TEXT,
            is_active INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, password_hash TEXT,
            last_login_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $db->query('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT)');
        $db->query('CREATE TABLE role_permissions (role TEXT, permission_id INTEGER)');
        require_once APPPATH . 'Database/Migrations/2026-10-18-000001_AddUserAuthVersion.php';
        (new \App\Database\Migrations\AddUserAuthVersion())->up();
        $db->table('tenants')->insert(['id' => 1, 'slug' => 'auth-test', 'name' => 'Auth Test',
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        CurrentTenant::set(1);
        $this->users = new UserModel();
        $this->id = (int) $this->users->insert([
            'tenant_id' => 1, 'username' => 'owner', 'role' => 'superadmin', 'status' => 'active',
            'password_hash' => password_hash('initial-password', PASSWORD_DEFAULT),
        ]);
        $this->assertNotNull((new AuthService(misClient: new FakeMisClient()))->login('owner', 'initial-password'));
    }

    public function testRealLoginAndLogoutRotateWithStrictModeAndFreshMetadata(): void
    {
        $saved = $_SESSION;
        $directory = WRITEPATH . 'cache/auth-login-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        try {
            $config = config('Session');
            $config->savePath = $directory;
            $session = \Config\Services::session($config, false);
            $handler = new \CodeIgniter\Session\Handlers\FileHandler($config, '127.0.0.1');
            $handler->setLogger(service('logger'));
            session_set_save_handler($handler, true);
            ini_set('session.use_strict_mode', '1');
            session_name('synapse_security_login_test');
            session_start();
            \Config\Services::injectMock('session', $session);
            $parentId = session_id();
            $saved['_auth'] = ['started_at' => time() - 600, 'last_seen_at' => time() - 100,
                'version' => 1, 'session_id' => $parentId];
            $saved['theme'] = 'dark';
            $_SESSION = $saved;
            $beforeLogin = time();
            $auth = new AuthService(misClient: new FakeMisClient());
            $this->assertNotNull($auth->login('owner', 'initial-password'));
            $loginId = session_id();
            $this->assertNotSame($parentId, $loginId);
            $this->assertGreaterThanOrEqual($beforeLogin, session('_auth')['started_at']);
            $this->assertSame($loginId, session('_auth')['session_id']);
            $this->assertSame($parentId, $saved['_auth']['session_id']);
            $this->assertSame(1, session('_auth')['version']);
            $this->assertNotNull((new AuthenticatedUser())->current());
            $auth->logout();
            $logoutId = session_id();
            $this->assertNotSame($loginId, $logoutId);
            session_write_close();
            foreach ([$parentId, $loginId, $logoutId] as $id) {
                session_id($id);
                session_start();
                $this->assertNull(session('user'));
                $this->assertNull(session('_auth'));
                $this->assertNull(session('tenant_id'));
                $this->assertSame('dark', session('theme'));
                session_write_close();
            }
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testOriginalInventoryFixtureShapesFailBeforeAuthMetadataOrSql(): void
    {
        $auth = session('_auth');
        $db = Database::connect();
        $lastQuery = $db->getLastQuery();
        foreach ([
            ['role' => 'superadmin', 'logged_in' => true, 'permissions' => []],
            ['id' => 1, 'role' => 'student', 'logged_in' => true],
        ] as $originalUser) {
            foreach ([null, $auth] as $metadata) {
                session()->set(['tenant_id' => 1, 'user' => $originalUser, '_auth' => $metadata]);
                $this->assertNull((new AuthenticatedUser())->current());
                $this->assertSame($lastQuery, $db->getLastQuery());
                $this->assertNull(session('user'));
            }
        }
    }

    public function testIdleAndAbsoluteExpiryAndLegacySessionsFailClosed(): void
    {
        $identity = $_SESSION;
        foreach ([
            ['started_at' => time() - 1800, 'last_seen_at' => time() - 1800],
            ['started_at' => time() - 43200, 'last_seen_at' => time()],
            ['started_at' => time() + 10, 'last_seen_at' => time() + 10],
            ['last_seen_at' => 'not-a-time'],
            ['session_id' => 'predecessor-id'],
        ] as $invalid) {
            $_SESSION = $identity;
            session()->set('theme', 'dark');
            session()->set('_auth', array_replace(session('_auth'), $invalid));
            $this->assertNull((new AuthenticatedUser())->current());
            $this->assertNull(session('_auth'));
            $this->assertNull(session('tenant_id'));
            $this->assertSame('dark', session('theme'));
        }
        $_SESSION = $identity;
        session()->remove('_auth');
        $this->assertNull((new AuthenticatedUser())->current());
    }

    public static function timeoutTypes(): iterable
    {
        yield 'idle timeout' => [true];
        yield 'absolute timeout' => [false];
    }

    private function expireSession(bool $idle): void
    {
        $now = time();
        $auth = session('_auth');
        $auth['started_at'] = $now - ($idle
            ? config('Session')->authIdleTimeout + 10
            : config('Session')->authAbsoluteTimeout + 10);
        $auth['last_seen_at'] = $idle ? $auth['started_at'] : $now;
        session()->set('_auth', $auth);
    }

    private function denialRequest(bool $json = false): IncomingRequest
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('GET');
        $request->setGlobal('get', []);
        foreach (['X-Requested-With', 'Accept', 'HX-Request', 'X-Up-Version'] as $header) {
            $request->removeHeader($header);
        }
        if ($json) {
            $request->setHeader('Accept', 'application/json');
        }
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response(config('App')));
        return $request;
    }

    #[DataProvider('timeoutTypes')]
    public function testConfirmedTimeoutSurvivesRepeatedChecksAndGetDenial(bool $idle): void
    {
        $this->expireSession($idle);
        session()->set('theme', 'dark');
        $authority = new AuthenticatedUser();
        for ($check = 0; $check < 2; $check++) {
            $this->assertNull($authority->current());
            $this->assertNull(session('user'));
            $this->assertNull(session('_auth'));
            $this->assertNull(session('tenant_id'));
            $this->assertTrue(session()->getFlashdata('_auth_expired'));
        }
        $this->assertSame('dark', session('theme'));

        // A marker already aged by a request must be retained for the login redirect.
        $_SESSION['__ci_vars']['_auth_expired'] = 'old';
        $response = AuthenticatedUser::denied($this->denialRequest(), false, 'Please sign in.', '/auth/login');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(site_url('auth/login'), $response->getHeaderLine('Location'));
        $this->assertTrue(session()->getFlashdata('_auth_expired'));
        $this->assertSame('new', $_SESSION['__ci_vars']['_auth_expired']);
        $this->assertNull(session('error'));
    }

    #[DataProvider('timeoutTypes')]
    public function testConfirmedTimeoutOverridesJsonDenialMessage(bool $idle): void
    {
        $this->expireSession($idle);
        $this->assertNull((new AuthenticatedUser())->current());
        $response = AuthenticatedUser::denied($this->denialRequest(true), false, 'Please sign in.', '/auth/login');
        $expected = 'Your session has expired. Please sign in again to continue.';
        $this->assertSame($expected, AuthenticatedUser::EXPIRED_MESSAGE);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => $expected], json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertFalse($response->hasHeader('Location'));
    }

    public function testFreshUnauthenticatedSessionDoesNotReportExpiry(): void
    {
        AuthenticatedUser::clear();
        $this->assertNull((new AuthenticatedUser())->current());
        $this->assertNull(session()->getFlashdata('_auth_expired'));
        $response = AuthenticatedUser::denied($this->denialRequest(true), false, 'Please sign in.', '/auth/login');
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Please sign in.'], json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        AuthenticatedUser::denied($this->denialRequest(), false, 'Please sign in.', '/auth/login');
        $this->assertSame('Please sign in.', session()->getFlashdata('error'));
        $this->assertNull(session('_auth_expired'));
    }

    public function testMalformedMetadataDoesNotReportExpiryEvenWithOldTimestamps(): void
    {
        $identity = $_SESSION;
        $now = time();
        foreach ([
            null,
            ['started_at' => $now - 50000],
            array_replace($identity['_auth'], ['started_at' => (string) ($now - 50000), 'last_seen_at' => $now - 50000]),
            array_replace($identity['_auth'], ['started_at' => $now - 50000, 'last_seen_at' => 'not-a-time']),
            array_replace($identity['_auth'], ['started_at' => $now - 50000, 'last_seen_at' => $now - 50000, 'version' => 0]),
            array_replace($identity['_auth'], ['started_at' => $now - 50000, 'last_seen_at' => $now - 50000, 'session_id' => 'predecessor-id']),
            array_replace($identity['_auth'], ['started_at' => $now, 'last_seen_at' => $now - 50000]),
            array_replace($identity['_auth'], ['last_seen_at' => $now + 100]),
        ] as $metadata) {
            $_SESSION = $identity;
            session()->set('_auth', $metadata);
            $this->assertNull((new AuthenticatedUser())->current());
            $this->assertNull(session('user'));
            $this->assertNull(session('_auth'));
            $this->assertNull(session('_auth_expired'));
            $this->assertNull(session()->getFlashdata('_auth_expired'));
        }
    }

    public function testLoginConsumesExpiryMarkerAndRendersWarningInsteadOfGenericError(): void
    {
        Database::connect()->query('CREATE TABLE carousel_slides (id INTEGER PRIMARY KEY,
            tenant_id INTEGER, is_active INTEGER, sort_order INTEGER)');
        $this->denialRequest();
        $this->expireSession(true);
        session()->setFlashdata('error', 'Please sign in.');

        $html = (new AuthController())->login();

        $this->assertNull(session('user'));
        $this->assertNull(session('_auth_expired'));
        $this->assertNull(session('error'));
        $this->assertSame(AuthenticatedUser::EXPIRED_MESSAGE, session()->getFlashdata('warning'));
        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString(AuthenticatedUser::EXPIRED_MESSAGE, $html);
        $this->assertStringNotContainsString('Please sign in.', $html);
        $this->assertStringNotContainsString('alert-danger', $html);
    }

    public function testSuccessfulLoginClearsExpiryMarker(): void
    {
        $this->expireSession(true);
        $this->assertNull((new AuthenticatedUser())->current());
        $this->assertTrue(session()->getFlashdata('_auth_expired'));
        $this->assertNotNull((new AuthService(misClient: new FakeMisClient()))->login('owner', 'initial-password'));
        $this->assertNull(session('_auth_expired'));
        $this->assertNull(session()->getFlashdata('_auth_expired'));
        $this->assertNotNull((new AuthenticatedUser())->current());
    }

    public function testActivityRefreshesOnlyIdleClockAndUsesConfiguredLimits(): void
    {
        $auth = session('_auth');
        $auth['started_at'] = time() - 100;
        $auth['last_seen_at'] = time() - 20;
        session()->set('_auth', $auth);
        $this->assertNotNull((new AuthenticatedUser())->current());
        $this->assertSame($auth['started_at'], session('_auth')['started_at']);
        $this->assertGreaterThan($auth['last_seen_at'], session('_auth')['last_seen_at']);
        config('Session')->authAbsoluteTimeout = 100;
        $this->assertNull((new AuthenticatedUser())->current());
    }

    public function testConfiguredIdleLimitAndNonPositiveLimitsFailClosed(): void
    {
        $original = $_SESSION;
        $auth = session('_auth');
        $auth['last_seen_at'] = time() - 20;
        $auth['started_at'] = time() - 30;
        session()->set('_auth', $auth);
        config('Session')->authIdleTimeout = 20;
        $this->assertNull((new AuthenticatedUser())->current());
        $_SESSION = $original;
        config('Session')->authIdleTimeout = 0;
        $this->assertNull((new AuthenticatedUser())->current());
    }

    public function testPasswordChangedDuringLoginCannotBindTheNewVersion(): void
    {
        $staleUser = $this->users->find($this->id);
        $this->users->update($this->id, ['password_hash' => password_hash('changed-during-login', PASSWORD_DEFAULT)]);
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
        $establish = new \ReflectionMethod(AuthService::class, 'establishUserSession');
        $this->assertNull($establish->invoke(new AuthService(misClient: new FakeMisClient()), $staleUser));
        $this->assertNull(session('user'));
    }

    public function testMigrationBackfillsExistingUsersAndCanBeReversed(): void
    {
        $migration = new \App\Database\Migrations\AddUserAuthVersion();
        $migration->down();
        $this->assertFalse(Database::connect()->fieldExists('auth_version', 'users'));
        $migration->up();
        $this->assertSame(1, (int) $this->users->find($this->id)['auth_version']);
    }

    public function testVersionMismatchRejectsEvenWithForgedCachedVersion(): void
    {
        Database::connect()->table('users')->where('id', $this->id)->update(['auth_version' => 2]);
        $user = session('user');
        $user['auth_version'] = 2;
        session()->set('user', $user);
        $this->assertNull((new AuthenticatedUser())->current());
        $this->assertNull(session('_auth_expired'));
        $this->assertNull(session()->getFlashdata('_auth_expired'));
    }

    public function testPasswordReplacementInvalidatesAllCopiesAndFreshLoginWorks(): void
    {
        $old = $_SESSION;
        $this->users->update($this->id, ['password_hash' => password_hash('replacement-password', PASSWORD_DEFAULT)]);
        $this->assertSame(2, (int) $this->users->find($this->id)['auth_version']);
        foreach ([$old, $old] as $deviceCopy) {
            $_SESSION = $deviceCopy;
            $this->assertNull((new AuthenticatedUser())->current());
        }
        $auth = new AuthService(misClient: new FakeMisClient());
        $this->assertNull($auth->login('owner', 'initial-password'));
        $this->assertNotNull($auth->login('owner', 'replacement-password'));
        $this->assertNotNull((new AuthenticatedUser())->current());
        $this->users->update($this->id, ['password_hash' => null]);
        $this->assertSame(3, (int) $this->users->find($this->id)['auth_version']);
        $this->assertNull((new AuthenticatedUser())->current());
    }

    public function testRolesAndPermissionsRemainFreshWithoutBumpingVersion(): void
    {
        $db = Database::connect();
        $db->table('permissions')->insert(['code' => 'clinic.queue.read', 'name' => 'Queue', 'module' => 'clinic']);
        $permission = $db->insertID();
        $db->table('role_permissions')->insert(['role' => 'superadmin', 'permission_id' => $permission]);
        $this->assertSame(['clinic.queue.read'], (new AuthenticatedUser())->current()['permissions']);
        $db->table('role_permissions')->where('permission_id', $permission)->delete();
        $this->assertSame([], (new AuthenticatedUser())->current()['permissions']);
        $db->query('CREATE TABLE people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT,
            last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $db->query('CREATE TABLE employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $db->table('people')->insert(['id' => 1, 'tenant_id' => 1, 'first_name' => 'Fresh', 'last_name' => 'Role']);
        $this->users->update($this->id, ['person_id' => 1, 'role' => 'clinic_admin']);
        $refreshed = (new AuthenticatedUser())->current();
        $this->assertSame('clinic_admin', $refreshed['role']);
        $this->assertSame('Fresh Role', $refreshed['name']);
        $this->assertSame(1, (int) $this->users->find($this->id)['auth_version']);
    }

    public function testLogoutClearsMetadataAndPreservesPreferencesWithoutRevokingOtherDevices(): void
    {
        session()->set('theme', 'dark');
        session()->setFlashdata('_auth_expired', true);
        (new AuthService(misClient: new FakeMisClient()))->logout();
        $this->assertNull(session('_auth_expired'));
        $this->assertNull(session()->getFlashdata('_auth_expired'));
        $this->assertNull(session('user'));
        $this->assertNull(session('_auth'));
        $this->assertSame('dark', session('theme'));
        $this->assertSame(1, (int) $this->users->find($this->id)['auth_version']);
    }
}
