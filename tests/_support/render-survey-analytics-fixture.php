<?php

declare(strict_types=1);

// CLI-only production view and shell with synthetic data; no controllers, models or DB.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
config(\Config\Realtime::class)->enabled = false;
$app = config('App');
$app->baseURL = $fixture['origin'] . '/';
$app->indexPage = '';
$_SERVER['HTTP_HOST'] = parse_url($fixture['origin'], PHP_URL_HOST);
$_SERVER['REQUEST_METHOD'] = 'GET';
$path = '/guidance/surveys/1/responses';
$request = new \CodeIgniter\HTTP\IncomingRequest($app, new \CodeIgniter\HTTP\SiteURI($app, $path), null, new \CodeIgniter\HTTP\UserAgent());
\Config\Services::injectMock('request', $request);
\Config\Services::injectMock('uri', $request->getUri());
helper(['url', 'form']);
session()->set(['tenant_id' => 0, 'user' => ['id' => 0, 'name' => 'Synthetic Admin', 'username' => 'fixture', 'role' => 'superadmin']]);

$singleOptions = [
    'An individual appointment with a guidance counsellor to discuss study habits and planning for the next semester',
    'A group workshop',
    'No preference',
];
$multipleOptions = [
    'Follow-up resources for students using the CampusWellbeingAndAcademicSupportResourceCollectionWithoutAbbreviations',
    'Peer support',
    'Other resources',
];
$questions = [
    ['type' => 'single', 'label' => 'Preferred support', 'options' => $singleOptions],
    ['type' => 'multiple', 'label' => 'Useful resources', 'options' => $multipleOptions],
    ['type' => 'rating', 'label' => 'Overall satisfaction', 'options' => []],
    ['type' => 'single', 'label' => 'Optional follow-up', 'options' => ['Yes', 'No']],
];
$total = empty($fixture['empty']) ? 8 : 0;
$analytics = [
    'total_submissions' => $total,
    'overall_avg_rating' => $total ? 4.125 : null,
    'daily_trend' => [],
    'questions' => [
        $questions[0] + ['total_answers' => 8, 'counts' => array_combine($singleOptions, [5, 3, 0])],
        // Multiple selections intentionally total more than the number of respondents.
        $questions[1] + ['total_answers' => 8, 'counts' => array_combine($multipleOptions, [8, 3, 0])],
        $questions[2] + ['total_answers' => 8, 'avg_rating' => 4.125, 'counts' => [5 => 3, 4 => 4, 3 => 0, 2 => 1, 1 => 0]],
        $questions[3] + ['total_answers' => 0, 'counts' => ['Yes' => 0, 'No' => 0]],
    ],
];
if ($total === 0) {
    foreach ($analytics['questions'] as &$question) {
        $question['total_answers'] = 0;
        $question['counts'] = array_fill_keys(array_keys($question['counts']), 0);
        unset($question['avg_rating']);
    }
    unset($question);
}
$items = [];
for ($id = 1; $id <= $total; $id++) {
    $items[] = ['id' => $id, 'first_name' => 'Synthetic', 'last_name' => 'Respondent ' . $id, 'submitted_at' => '2026-10-20 08:00:00'];
}
echo view('surveys/responses', [
    'title' => 'Survey Analytics',
    'survey' => ['id' => 1, 'title' => 'Campus wellbeing', 'description' => 'Support preferences and satisfaction.', 'status' => 'published', 'audience' => 'students', 'questions' => $questions],
    'analytics' => $analytics,
    'items' => $items,
    'pagination' => ['page' => 1, 'per_page' => 25, 'filtered' => $total, 'total' => $total, 'pages' => 1],
    'baseUrl' => site_url($path),
    'canExportResponses' => false,
]);
