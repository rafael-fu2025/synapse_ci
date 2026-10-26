<?php

declare(strict_types=1);

// Render the complete production view, without dispatching a controller or opening a database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$portals = ['main' => 'main', 'clinic' => 'clinic', 'guidance' => 'guidance', 'facilities' => 'bmg'];
$variant = $fixture['portal'] ?? 'main';
if (!isset($portals[$variant])) {
    throw new InvalidArgumentException('Unknown login fixture portal');
}
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$path = $variant === 'main' ? '/auth/login' : '/' . $variant . '/login';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());

// Never use the application's database-backed session factory or start a PHP session.
$sessionConfig = config('Session');
$driver = new \CodeIgniter\Session\Handlers\FileHandler($sessionConfig, '127.0.0.1');
$session = new \CodeIgniter\Test\Mock\MockSession($driver, $sessionConfig);
$_SESSION = [];
\Config\Services::injectMock('session', $session);
$session->set('_ci_old_input', ['get' => [], 'post' => ['identifier' => $fixture['identifier'] ?? '']]);
if (!empty($fixture['longAlert'])) {
    $session->setFlashdata('error', str_repeat('Sign-in could not be completed. Please check your university ID and contact the administrator if you still need assistance. ', 12));
    $session->setFlashdata('errors', ['Please enter your university ID.', 'Please enter your password.']);
}
helper(['url', 'form']);
echo view('auth/login', [
    'title' => 'Sign In',
    'slides' => [],
    'portal' => $portals[$variant],
    'portalMeta' => \App\Services\PortalContext::metadata($portals[$variant]),
]);
if (session_status() !== PHP_SESSION_NONE) {
    throw new RuntimeException('Login fixture unexpectedly started a native session');
}
