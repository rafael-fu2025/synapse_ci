<?php

declare(strict_types=1);

// Render the real Clinic and Guidance dashboards with invented aggregate counts.
// No CI, Composer, dotenv, sessions, controllers, database or records are loaded.
namespace App\Services\Scheduling {
    final class SchedulingClock
    {
        public static function today(): string { return '2031-11-23'; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $input = stream_get_contents(STDIN, 16385);
    if (strlen($input) > 16384) { throw new \InvalidArgumentException('Fixture input is too large.'); }
    $fixture = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($fixture) || array_diff(array_keys($fixture), ['origin', 'dashboard', 'rangeKey', 'days', 'series', 'theme']) !== []) {
        throw new \InvalidArgumentException('Only synthetic chart fixture options are allowed.');
    }
    $origin = (string) ($fixture['origin'] ?? '');
    if (!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $origin)
        || (parse_url($origin, PHP_URL_PORT) ?? 0) > 65535) {
        throw new \InvalidArgumentException('Only a loopback fixture origin is allowed.');
    }
    $dashboard = $fixture['dashboard'] ?? 'clinic';
    $rangeKey = $fixture['rangeKey'] ?? '7d';
    $theme = $fixture['theme'] ?? 'light';
    $days = $fixture['days'] ?? [];
    $series = $fixture['series'] ?? [];
    if (!in_array($dashboard, ['clinic', 'guidance'], true)
        || !in_array($rangeKey, ['7d', '30d', 'sy'], true) || !in_array($theme, ['light', 'dark'], true)
        || !is_array($days) || !is_array($series) || count($days) !== count($series) || count($days) > 30) {
        throw new \InvalidArgumentException('Invalid synthetic chart data.');
    }
    foreach ($days as $label) {
        if (!is_string($label) || strlen($label) > 64) { throw new \InvalidArgumentException('Invalid fixture label.'); }
    }
    foreach ($series as $count) {
        if (!is_int($count) || $count < 0 || $count > 1000) { throw new \InvalidArgumentException('Invalid fixture count.'); }
    }
    function esc($value, string $context = 'html'): string {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    function site_url(string $path = ''): string { return $GLOBALS['origin'] . '/' . ltrim($path, '/'); }
    function base_url(string $path = ''): string { return site_url($path); }

    final class ClinicDashboardFixtureView
    {
        private array $sections = [];
        private array $stack = [];
        public function extend(string $name): string {
            if ($name !== 'layouts/app') { throw new \RuntimeException('Unexpected fixture layout.'); }
            return '';
        }
        public function section(string $name): string {
            if (!in_array($name, ['content', 'page_actions', 'styles', 'scripts'], true)) {
                throw new \RuntimeException('Unexpected fixture section.');
            }
            $this->stack[] = $name;
            ob_start();
            return '';
        }
        public function endSection(): string {
            $this->sections[array_pop($this->stack)] = ob_get_clean();
            return '';
        }
        public function render(array $data, string $dashboard): array {
            $view = match ($dashboard) {
                'clinic' => '/app/Views/clinic/dashboard.php',
                'guidance' => '/app/Views/guidance/dashboard.php',
                default => throw new \InvalidArgumentException('Unexpected fixture dashboard.'),
            };
            extract($data, EXTR_SKIP);
            ob_start();
            require dirname(__DIR__, 2) . $view;
            ob_end_clean();
            if ($this->stack !== []) { throw new \RuntimeException('Unbalanced fixture sections.'); }
            return $this->sections;
        }
    }

    $sections = (new ClinicDashboardFixtureView())->render([
        'user' => ['name' => 'Synthetic Workstation Staff', 'role' => 'superadmin'],
        'inventoryInstalled' => true, 'inventoryAlerts' => [], 'isolationAlerts' => [],
        'activeIsolationCount' => 0, 'medicinesCount' => 0,
        'queueWaiting' => 0, 'queueCalled' => 0, 'queueCompleted' => 0,
        'encountersCount' => 0, 'appointmentsToday' => 0, 'recentEncounters' => [],
        'activeQueuePreview' => [], 'todaySchedule' => [], 'openEncountersCount' => 0,
        'pendingAppointmentsCount' => 0, 'schoolYear' => '2031-2032',
        'triageCounts' => ['emergency' => 1, 'urgent' => 2, 'routine' => 3],
        'categoryCounts' => ['academic' => 2, 'personal' => 3, 'career' => 1],
        'recentCases' => [],
        'chartDays' => $days, 'chartSeries' => $series, 'rangeKey' => $rangeKey,
    ], $dashboard);
    echo json_encode($sections, JSON_THROW_ON_ERROR);
}
