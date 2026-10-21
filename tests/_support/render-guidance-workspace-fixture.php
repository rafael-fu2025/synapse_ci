<?php
// Synthetic, CLI-only view rendering. No controller, model, DB or note decryption.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, '/guidance/cases/10'), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['tenant_id' => 1, 'user' => ['id' => 7, 'person_id' => 1, 'name' => 'Synthetic Counsellor', 'username' => 'fixture', 'role' => 'counsellor', 'permissions' => ['referrals.create'], 'logged_in' => true]]);
$status = in_array($fixture['status'] ?? '', ['active', 'monitoring', 'closed'], true) ? $fixture['status'] : 'active';
$active = !empty($fixture['active']) && $status !== 'closed';
$case = ['id' => 10, 'person_id' => 1, 'case_number' => 'SYNTHETIC-010', 'status' => $status, 'title' => 'Campus adjustment and wellbeing support', 'category' => 'personal', 'opened_at' => '2026-10-17 08:00:00'];
$session = ['id' => 21, 'session_number' => 1, 'session_type' => 'regular', 'status' => 'completed', 'started_at' => '2026-10-17 09:00:00', 'counsellor_name' => 'Synthetic Counsellor'];
$package = ['student' => null, 'employee' => ['department' => 'Synthetic Department', 'position' => 'Synthetic Employee'],
    'patient' => ['kind' => 'employee', 'first_name' => 'Synthetic', 'last_name' => 'Client', 'mis_id' => 'FIXTURE-001'],
    'sessions' => [$session], 'followups' => [['id' => 30, 'action_plan' => 'Synthetic follow-up commitment for layout verification only.', 'status' => 'pending', 'due_date' => '2030-10-20']]];
$activeSession = $active ? array_replace($session, ['id' => 22, 'session_number' => 2, 'status' => 'in_progress']) : null;
echo view('guidance/cases/view', compact('case', 'package', 'activeSession') + ['title' => $case['title'], 'decryptedNote' => null,
    'breadcrumbs' => [['label' => 'Guidance', 'url' => site_url('/guidance')], ['label' => $case['case_number']]]]);
