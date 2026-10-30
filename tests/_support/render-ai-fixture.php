<?php
// CLI-only presentation fixture: no application bootstrap, sessions, models or database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Common.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
const FIXTURE_ORIGIN = 'http://127.0.0.1:47991';

// Common.php supplies the real esc() without booting the application. Only
// URL/CSRF/layout helpers are deliberately inert presentation fixtures.
function site_url(string $path): string { return FIXTURE_ORIGIN . '/' . ltrim($path, '/'); }
function base_url(string $path): string { return site_url($path); }
function csrf_field(): string { return '<input type="hidden" name="csrf_fixture" value="initial-token">'; }
function view(string $name, array $data): string { return (new AiFixtureView())->render($name, $data); }
final class AiFixtureView
{
    public function extend(string $layout): string { return ''; }
    public function section(string $section): string { return ''; }
    public function endSection(): string { return ''; }
    public function render(string $name, array $data): string
    {
        if (!in_array($name, ['ai/index', 'ai/panel', 'ai/evidence'], true)) {
            throw new InvalidArgumentException('Only AI presentation views may be rendered.');
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require dirname(__DIR__, 2) . '/app/Views/' . $name . '.php';
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
$attack = '\"><img src="/xss-probe" onerror="window.aiFixtureXss=1"><script>window.aiFixtureXss=1</script>&';
$text = static fn (string $label): string => $label . (!empty($fixture['hostile']) ? ' ' . $attack : ' (synthetic aggregate fixture)');
$evidence = [
    'period' => ['label' => $text('Current complete month')],
    'comparison_period' => ['label' => $text('Previous complete month')],
    'metrics' => [['current_value' => 40, 'previous_value' => 32, 'absolute_change' => 8, 'relative_change_percent' => 25]],
    'limitations' => [$text('Aggregate limitations')],
];
$program = [
    'title' => $text('Operations planning'), 'objective' => $text('Objective'), 'audience' => $text('Department staff'),
    'activities' => [$text('Activity')], 'resource_assumptions' => [$text('Resource assumption')],
    'evaluation_measures' => [$text('Evaluation measure')], 'limitations' => [$text('Program limitation')],
    'required_reviewer' => $text('Operations reviewer'),
    'references' => [['url' => 'https://example.invalid/reference?label=" onmouseover="window.aiFixtureXss=1', 'title' => $text('Reference title')]],
];
$status = $fixture['jobStatus'] ?? null;
$jobs = $status === null ? [] : [[
    'id' => 31, 'status' => $status, 'profile_revision' => $fixture['jobRevision'] ?? 'fixture-r1',
    'created_at' => '2026-09-01 00:00:00', 'reason' => !empty($fixture['hostile']) ? $attack : null,
]];
$insights = empty($fixture['insight']) ? [] : [[
    'id' => 47, 'version' => $fixture['version'] ?? 7, 'status' => $fixture['insightStatus'] ?? 'draft',
    'created_at' => '2026-09-01 00:00:00',
    'draft' => [
        'provenance' => ['model' => $text('Fixture model'), 'prompt_version' => $text('Fixture prompt')],
        'narrative' => ['summary' => $text('Summary'), 'interpretation' => $text('Interpretation'),
            'abstained' => false, 'alternative_explanations' => [$text('Alternative explanation')]],
        'evidence' => $evidence, 'programs' => [$program],
    ],
]];
$data = [
    'profile' => ['revision' => 'fixture-r1'], 'ready' => $fixture['ready'] ?? true, 'reason' => $fixture['reason'] ?? null,
    'jobs' => $jobs, 'insights' => $insights,
    'canGenerate' => $fixture['canGenerate'] ?? true, 'canReview' => $fixture['canReview'] ?? true,
    'canApprove' => $fixture['canApprove'] ?? true,
];
echo json_encode([
    'index' => view('ai/index', $data), 'panel' => view('ai/panel', $data),
    'baseline' => view('ai/evidence', ['evidence' => $evidence]), 'attack' => $attack,
], JSON_THROW_ON_ERROR);
