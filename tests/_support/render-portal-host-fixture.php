<?php

declare(strict_types=1);

// CLI-only production shell renderer; no authentication or database side effects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$app = config('App');
$app->baseURL = 'http://localhost:' . $fixture['port'] . '/';
$app->indexPage = 'index.php';
$uri = new \CodeIgniter\HTTP\SiteURI($app, $fixture['path']);
$uri->setHost($fixture['host']);
$uri->setPort($fixture['port']);
$request = new \CodeIgniter\HTTP\IncomingRequest($app, $uri, null, new \CodeIgniter\HTTP\UserAgent());
$_SERVER['HTTP_HOST'] = $fixture['host'] . ':' . $fixture['port'];
$request->setGlobal('server', ['HTTP_HOST' => $_SERVER['HTTP_HOST']]);
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $uri);
helper(['url', 'form']);
$role = $fixture['role'];
$permissions = match ($role) {
    'clinic_admin' => ['clinic.announcements.manage', 'rbac.manage'],
    'guidance_admin' => ['guidance.announcements.manage', 'guidance.surveys.manage', 'rbac.manage'],
    'guidance_supervisor' => ['guidance.announcements.manage', 'guidance.surveys.manage'],
    'audit_reader' => ['audit.read', 'reports.read'],
    'report_viewer' => ['reports.read'],
    default => [],
};
session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'person_id' => 1, 'tenant_id' => 1,
    'role' => $role, 'name' => 'Host Fixture', 'username' => 'fixture', 'permissions' => $permissions, 'logged_in' => true]]);
echo view('layouts/app', ['title' => 'Host navigation fixture']);
