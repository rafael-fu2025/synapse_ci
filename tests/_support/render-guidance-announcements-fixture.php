<?php

declare(strict_types=1);

// CLI-only actual-view renderer. Intentionally no Composer/CI bootstrap, dotenv,
// controllers, models, connection factory, PHP sessions, or database access.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($fixture)) { throw new InvalidArgumentException('Expected fixture options'); }
$root = dirname(__DIR__, 2);
$origin = (string) ($fixture['origin'] ?? 'http://127.0.0.1');
if (!preg_match('~^http://127\.0\.0\.1(?::\d+)?$~D', $origin)) {
    throw new InvalidArgumentException('Only loopback fixture origins are permitted');
}
$fixturePath = (string) ($fixture['path'] ?? '/guidance/announcements');
parse_str(parse_url($fixturePath, PHP_URL_QUERY) ?? '', $fixtureGet);
$unit = str_starts_with($fixturePath, '/clinic/') ? 'clinic' : 'guidance';
$csrfHash = (string) ($fixture['csrf'] ?? 'synthetic-token-0');

function esc($value, string $context = 'html'): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function site_url(string $path = ''): string { return $GLOBALS['origin'] . '/' . ltrim($path, '/'); }
function base_url(string $path = ''): string { return site_url($path); }
function current_url(bool $returnObject = false): string { return $GLOBALS['origin'] . $GLOBALS['fixturePath']; }
function csrf_token(): string { return 'fixture_csrf'; }
function csrf_hash(): string { return $GLOBALS['csrfHash']; }
function csrf_field(): string { return '<input type="hidden" name="fixture_csrf" value="' . esc(csrf_hash()) . '">'; }
function old(string $key, $default = null) { return $default; }
function session(?string $key = null) {
    static $session;
    $session ??= new class {
        public function get(?string $key = null) {
            $values = ['tenant_id' => 0, 'user' => ['id' => 0, 'name' => 'Synthetic Guidance actor', 'role' => 'superadmin', 'logged_in' => true]];
            return $key === null ? $values : ($values[$key] ?? null);
        }
        public function getFlashdata(string $key) { return null; }
    };
    return $key === null ? $session : $session->get($key);
}
function service(string $name) {
    if ($name !== 'request') { throw new RuntimeException('Unexpected service dependency: ' . $name); }
    return new class {
        public function getGet(?string $key = null) { return $key === null ? $GLOBALS['fixtureGet'] : ($GLOBALS['fixtureGet'][$key] ?? null); }
    };
}
function view(string $name, array $data = [], array $options = []): string {
    return (new GuidanceAnnouncementsView())->render($name, $data);
}

final class GuidanceAnnouncementsView {
    private array $sections = [];
    private array $stack = [];
    private ?string $layout = null;
    private array $data = [];

    public function extend(string $name): string {
        if (!in_array($name, ['layouts/app', 'layouts/modal_form'], true)) {
            throw new RuntimeException('Unexpected layout: ' . $name);
        }
        $this->layout = $name;
        return '';
    }
    public function section(string $name): string {
        $this->stack[] = $name;
        ob_start();
        return '';
    }
    public function endSection(): string {
        if ($this->stack === []) { throw new RuntimeException('Unbalanced section'); }
        $this->sections[array_pop($this->stack)] = ob_get_clean();
        return '';
    }
    public function renderSection(string $name): string { return $this->sections[$name] ?? ''; }
    public function include(string $name): string { return view($name, $this->data); }
    private function template(string $name): string {
        if (!in_array($name, ['announcements/index', 'announcements/form', 'announcements/_targeting',
            'announcements/_targeting_summary', 'announcements/_pagination', 'partials/table_pagination',
            'partials/alerts', 'layouts/modal_form'], true)) {
            throw new RuntimeException('Unexpected view dependency: ' . $name);
        }
        extract($this->data, EXTR_SKIP);
        ob_start();
        require $GLOBALS['root'] . '/app/Views/' . $name . '.php';
        return ob_get_clean();
    }
    public function render(string $name, array $data): string {
        $this->data = $data;
        $html = $this->template($name);
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            if ($layout === 'layouts/app') { return $this->shell(); }
            $html = $this->template($layout);
        }
        if ($this->stack !== []) { throw new RuntimeException('Unclosed view section'); }
        return $html;
    }
    private function shell(): string {
        $theme = ($GLOBALS['fixture']['theme'] ?? $GLOBALS['fixtureGet']['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $title = esc($this->data['title'] ?? 'Guidance announcements');
        $owner = 'announcements.staff.' . $GLOBALS['unit'];
        $listUrl = site_url('/' . $GLOBALS['unit'] . '/announcements');
        $width = !empty($GLOBALS['fixtureGet']['container']) ? 'max-width:350px;' : '';
        $styles = $this->renderSection('styles');
        $actions = $this->renderSection('page_actions');
        $content = $this->renderSection('content');
        $scripts = $this->renderSection('scripts');
        $appLayout = file_get_contents($GLOBALS['root'] . '/app/Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $appLayout, $confirmation)) {
            throw new RuntimeException('Actual shared confirmation markup not found');
        }
        // Minimal app shell only: actual modal layout, view partials, and frontend
        // owners below are exercised; unrelated header/realtime services are absent.
        $js = ['custom-dropdown', 'datepicker', 'datetimepicker', 'loading', 'form-loading', 'csrf',
            'toast', 'confirm', 'form-modal', 'responsive-tables', 'page-lifecycle', 'navigation',
            'refresh-regions', 'live-filters', 'background-actions'];
        $assets = '';
        foreach ($js as $name) { $assets .= '<script src="/assets/js/' . $name . '.js"></script>'; }
        return '<!doctype html><html lang="en" data-theme="' . $theme . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="icon" href="data:,"><title>' . $title . '</title>'
            . '<meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="' . esc(csrf_hash()) . '">'
            . '<meta name="csrf-header" content="X-Fixture-CSRF"><meta name="synapse-refresh-regions-enabled" content="1">'
            . '<meta name="synapse-refresh-region-owners" content="' . $owner . '">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="/assets/css/style.css">'
            . '<style>#main.main{margin:0;padding:16px;width:100%;min-height:100vh}.fixture-container{width:100%;' . $width . '}'
            . '#sidebar{display:none}.fixture-page-heading{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}'
            . '@media(max-width:400px){#main.main{padding:10px}}</style>'
            . '<div id="page-styles" hidden>' . $styles . '</div>'
            . '<script defer src="/assets/vendor/alpine/alpine.min.js"></script></head>'
            . '<body data-navigation-scope="synthetic-announcements-' . $GLOBALS['unit'] . '">'
            . '<header id="header" hidden>Database-free announcement fixture</header>'
            . '<aside id="sidebar"><nav class="sidebar-nav"><a class="nav-link active" href="' . esc($listUrl) . '">Announcements</a></nav></aside>'
            . '<main id="main" class="main"><div class="fixture-container"><div class="fixture-page-heading"><h1 class="h4">' . $title . '</h1>'
            . $actions . '</div>' . $content . '</div></main>' . $confirmation[1]
            . '<script>window.fixtureRefreshEvents=[];for(const name of ["refresh_committed","refresh_failed","refresh_canceled","refresh_security_escalated"]){document.addEventListener(name,event=>fixtureRefreshEvents.push({name,detail:event.detail}));}</script>'
            . '<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . $assets
            . '<div id="page-scripts" hidden>' . $scripts . '</div>'
            . '<div class="navigation-feedback"><div id="navigation-status" class="visually-hidden" role="status"></div>'
            . '<button id="navigation-retry" hidden>Retry navigation</button><a id="navigation-fallback" hidden>Open full page</a></div></body></html>';
    }
}

$baseUrl = site_url('/' . $unit . '/announcements');
$body = "Synthetic <script>alert('never execute')</script> & \"quoted\" <img src=x onerror=alert(1)>\nSecond line remains plain text.\n\n" . str_repeat('UnbrokenGuidanceBody', 75);
$notices = [];
for ($i = 0; $i < 75; $i++) {
    $id = 41 + $i;
    $status = ['draft', 'published', 'archived', 'draft'][$i % 4];
    $notice = ['id' => $id, 'title' => $i < 4 ? ['Draft <campus> & "advisory"', 'Published campus notice', 'Archived campus notice', 'Ordinary draft'][$i] : 'Synthetic notice ' . $id,
        'body' => $body, 'audience' => $i % 2 === 0 ? 'students' : 'all', 'status' => $status,
        'version' => $i === 0 ? 7 : 3, 'is_pinned_advisory' => $i === 0 ? 1 : 0,
        'starts_at' => $i === 0 ? '2030-01-31 00:30:00' : null, 'ends_at' => $i === 0 ? '2030-02-03 15:59:00' : null,
        'published_at' => in_array($status, ['published', 'archived'], true) ? '2030-01-15 01:00:00' : null,
        'created_at' => '2030-01-01 00:00:00', 'updated_at' => '2030-01-15 01:00:00',
        'target_year_levels' => $i === 0 ? '[1,3]' : null,
        'target_student_categories' => $i === 0 ? '["new","graduating"]' : null];
    $notices[$id] = array_replace($notice, $fixture['overrides'][(string) $id] ?? []);
}
$pagination = static function (int $total, int $page): array {
    $pages = max(1, (int) ceil($total / 25));
    return ['page' => max(1, min($pages, $page)), 'pages' => $pages, 'per_page' => 25, 'filtered' => $total, 'total' => $total];
};
if (!empty($fixtureGet['empty'])) { $notices = []; }
$rawQ = is_string($fixtureGet['q'] ?? null) ? trim($fixtureGet['q']) : '';
$statusFilter = is_string($fixtureGet['status'] ?? null) && in_array($fixtureGet['status'], ['all', 'draft', 'published', 'archived'], true) ? $fixtureGet['status'] : 'all';
$audienceFilter = is_string($fixtureGet['audience'] ?? null) && in_array($fixtureGet['audience'], ['', 'all', 'students', 'employees'], true) ? $fixtureGet['audience'] : '';
$pinnedFilter = ($fixtureGet['pinned'] ?? '') === '1' ? '1' : '';
$filters = ['q' => $rawQ, 'status' => $statusFilter, 'audience' => $audienceFilter, 'pinned' => $pinnedFilter];

$totalUnfiltered = count($notices);
$nonStatusFiltered = array_values(array_filter(array_values($notices), static function (array $n) use ($filters): bool {
    if ($filters['q'] !== '' && stripos($n['title'] . "\n" . $n['body'], $filters['q']) === false) {
        return false;
    }
    if ($filters['audience'] !== '' && $n['audience'] !== $filters['audience']) {
        return false;
    }
    if ($filters['pinned'] === '1' && empty($n['is_pinned_advisory'])) {
        return false;
    }
    return true;
}));
$counts = ['all' => count($nonStatusFiltered), 'draft' => 0, 'published' => 0, 'archived' => 0];
foreach ($nonStatusFiltered as $n) {
    if (isset($counts[$n['status']])) {
        $counts[$n['status']]++;
    }
}
$filteredNotices = $filters['status'] === 'all'
    ? $nonStatusFiltered
    : array_values(array_filter($nonStatusFiltered, static fn (array $n): bool => $n['status'] === $filters['status']));

$page = $pagination(count($filteredNotices), (int) ($fixtureGet['page'] ?? 1));
$page['filtered'] = count($filteredNotices);
$page['total'] = $totalUnfiltered;
$data = ['title' => ucfirst($unit) . ' Announcements', 'unit' => $unit, 'baseUrl' => $baseUrl,
    'formModalPage' => false, 'announcements' => array_slice($filteredNotices, ($page['page'] - 1) * 25, 25),
    'pagination' => $page, 'filters' => $filters, 'counts' => $counts];
if (isset($fixtureGet['reads'])) {
    $reads = [];
    if (empty($fixtureGet['empty'])) {
        for ($i = 1; $i <= 55; $i++) { $reads[] = ['first_name' => 'Synthetic <reader>', 'last_name' => 'Person ' . $i, 'read_at' => '2030-01-31 02:00:00']; }
    }
    $readPage = $pagination(count($reads), (int) ($fixtureGet['read_page'] ?? 1));
    $data['readHistory'] = ['announcement' => $notices[(int) $fixtureGet['reads']] ?? ['id' => 42, 'title' => 'Published campus notice'],
        'reads' => array_slice($reads, ($readPage['page'] - 1) * 25, 25), 'pagination' => $readPage];
}
$view = 'announcements/index';
if (preg_match('~/announcements/(?:create|([0-9]+)/edit)(?:\?|$)~', $fixturePath, $match)) {
    $announcement = isset($match[1]) && $match[1] !== '' ? ($notices[(int) $match[1]] ?? null) : null;
    $view = 'announcements/form';
    $data = ['unit' => $unit, 'announcement' => $announcement,
        'title' => $announcement === null ? 'New Announcement Draft' : 'Edit Announcement Draft',
        'formAction' => $baseUrl . ($announcement === null ? '/store' : '/' . $announcement['id'] . '/update'),
        'cancelUrl' => $baseUrl, 'formModalPage' => true];
}
echo view($view, $data);
