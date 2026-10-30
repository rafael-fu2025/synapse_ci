<?php

declare(strict_types=1);

// Deliberately not the test bootstrap: ENVIRONMENT=testing bypasses native session_start().
// This probe boots only framework services, never dotenv, routes, controllers, or a database.
if (PHP_SAPI !== 'cgi-fcgi') {
    throw new RuntimeException('This fixture requires PHP-CGI.');
}

$input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
$directory = realpath($input['directory'] ?? '');
if ($directory === false || dirname($directory) !== realpath(sys_get_temp_dir())
    || !str_starts_with(basename($directory), 'synapse-handoff-')) {
    throw new RuntimeException('The probe requires a disposable handoff fixture directory.');
}

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
define('ENVIRONMENT', 'development');
define('CI_DEBUG', false);
define('HOMEPATH', $root);
define('CONFIGPATH', $root . 'app/Config/');
define('PUBLICPATH', $root . 'public/');
require CONFIGPATH . 'Paths.php';
$paths = new Config\Paths();
foreach ([
    'APPPATH' => $paths->appDirectory, 'ROOTPATH' => $root,
    'SYSTEMPATH' => $paths->systemDirectory, 'WRITEPATH' => $paths->writableDirectory,
    'TESTPATH' => $root . 'tests', 'CIPATH' => $paths->systemDirectory . '/..',
    'FCPATH' => PUBLICPATH, 'SUPPORTPATH' => __DIR__, 'VENDORPATH' => $root . 'vendor',
] as $name => $path) {
    define($name, realpath($path) . DIRECTORY_SEPARATOR);
}
define('COMPOSER_PATH', $root . 'vendor/autoload.php');
require SYSTEMPATH . 'Boot.php';

final class SessionRotationHandoffProbeBoot extends CodeIgniter\Boot
{
    public static function bootProbe(): void
    {
        static::loadConstants();
        static::loadCommonFunctions();
        static::loadAutoloader();
        static::autoloadHelpers();
    }
}

SessionRotationHandoffProbeBoot::bootProbe();
Config\Services::injectMock('logger', new Psr\Log\NullLogger());
$config = clone config('Session');
$config->driver = CodeIgniter\Session\Handlers\FileHandler::class;
$config->savePath = $directory;
$config->cookieName = 'synapse_handoff_test';
$config->matchIP = false;
$config->timeToUpdate = 1;
$config->regenerateDestroy = true;
$cookieConfig = config('Cookie');
$cookieConfig->prefix = $cookieConfig->domain = '';
$cookieConfig->path = '/';
$cookieConfig->secure = false;
$cookieConfig->samesite = 'Lax';
$cookieConfig->raw = false;
$response = new CodeIgniter\HTTP\Response(config('App'));
Config\Services::injectMock('response', $response);
$preference = new CodeIgniter\Cookie\Cookie('handoff_preference', 'dark', [
    'path' => '/preferences', 'secure' => false, 'httponly' => true, 'samesite' => 'Strict',
]);
$csrf = new CodeIgniter\Cookie\Cookie('handoff_csrf', 'fixture', [
    'path' => '/', 'secure' => false, 'httponly' => false, 'samesite' => 'Lax',
]);
$response->setCookie($preference)->setCookie($csrf);
$incomingId = $_COOKIE[$config->cookieName] ?? null;

// The application factory dispatches AuthenticatedSession::start(), including CI's
// XHR cookie-refresh workaround and the automatic-regeneration branch for non-XHR.
$session = Config\Services::session($config, false);
$auth = $session->get('_auth');
$lifetime = $auth;
unset($lifetime['session_id']);
$store = $response->getCookieStore();
$result = [
    'active' => session_status() === PHP_SESSION_ACTIVE,
    'strict_mode' => ini_get('session.use_strict_mode') === '1',
    'same_id' => session_id() === $incomingId,
    'authenticated' => $session->get('user') !== null || $session->get('tenant_id') !== null || $auth !== null,
    'bound_to_current_id' => is_array($auth) && ($auth['session_id'] ?? null) === session_id(),
    'same_auth_lifetime' => $lifetime === $input['auth'],
    'marked' => is_int($session->get('_auth_rotated_at')),
    'old_input' => $session->get('_ci_old_input') !== null,
    'theme' => $session->get('theme'),
    'queued_session_cookie' => $store->has($config->cookieName),
    'unrelated_cookies_preserved' => $store->has('handoff_preference') && $store->has('handoff_csrf')
        && $store->get('handoff_preference') === $preference && $store->get('handoff_csrf') === $csrf,
];
session_write_close();
$response->setJSON($result)->send();
