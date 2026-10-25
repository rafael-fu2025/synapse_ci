<?php

// CLI-only: render the real application shell without a controller, MIS or database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
// Notification tests drive transport status with synthetic events, never a live ticket/socket.
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . '/' . trim($fixture['basePath'] ?? '', '/') . '/';
$app->baseURL = rtrim($app->baseURL, '/') . '/';
$app->indexPage = $fixture['indexPage'] ?? '';
if (!empty($fixture['dedicatedHosts'])) {
    $sites = config('PortalSites');
    $sites->families = ['fixture' => ['main' => parse_url($fixture['origin'], PHP_URL_HOST),
        'clinic' => 'clinic.notification.invalid', 'guidance' => 'guidance.notification.invalid',
        'bmg' => 'bmg.notification.invalid']];
    $sites->aliases = [];
}
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, '/notification-fixture'), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set('user', ['id' => 1, 'person_id' => 11, 'name' => 'Browser Fixture',
    'username' => 'browser-fixture', 'identifier' => 'FIXTURE', 'role' => 'student', 'logged_in' => true]);
$destinations = \App\Services\NotificationService::destinationUrls();
// Model the fixed destination contract while the coordinated backend change is pending.
foreach ([
    'clinicAppointments' => ['clinic', 'clinic/appointments'],
    'guidanceAppointments' => ['guidance', 'guidance/appointments'],
    'clinicReferralSent' => ['clinic', 'clinic/referrals?tab=sent'],
    'guidanceReferralSent' => ['guidance', 'guidance/referrals?tab=sent'],
    'guidanceCases' => ['guidance', 'guidance/cases'],
    'clinicInventory' => ['clinic', 'clinic/inventory'],
    'facilitiesOperations' => ['bmg', 'facilities'],
] as $key => [$portal, $path]) {
    $destinations[$key] ??= \App\Services\PortalContext::urlFor($portal, $path);
}
// Explicit fixture configuration also exercises keys supplied by a coordinated backend change.
foreach ($fixture['referralHistoryPaths'] ?? [] as $unit => $path) {
    if (!in_array($unit, ['clinic', 'guidance'], true)) { throw new \InvalidArgumentException('Invalid fixture unit.'); }
    $destinations[$unit . 'ReferralHistory'] = \App\Services\PortalContext::urlFor($unit, $path);
}
echo view('layouts/app', ['title' => 'Notification browser fixture', 'formModalPage' => true,
    'notificationDestinations' => $destinations]);
