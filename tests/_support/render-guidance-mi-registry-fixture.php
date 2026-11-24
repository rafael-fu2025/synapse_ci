<?php

declare(strict_types=1);

// CLI-only actual-view rendering. Never bootstrap CI, Composer, dotenv,
// sessions, controllers, services, a database or a real user's records.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$input = stream_get_contents(STDIN, 262145);
if (strlen($input) > 262144) {
    throw new InvalidArgumentException('Synthetic MI input exceeds its bound.');
}
$fixture = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
$allowed = ['origin', 'path', 'theme', 'csrf', 'regionalEnabled', 'assignments', 'students', 'programs',
    'yearLevels', 'activePersonIds', 'statusFilter', 'programFilter', 'pagination', 'miGateEnabled', 'canAssign'];
if (!is_array($fixture) || array_diff(array_keys($fixture), $allowed) !== []) {
    throw new InvalidArgumentException('Expected only isolated synthetic MI registry options.');
}
$root = dirname(__DIR__, 2);
$origin = (string) ($fixture['origin'] ?? 'http://127.0.0.1');
if (!preg_match('~^http://127\.0\.0\.1(?::[0-9]{1,5})?$~D', $origin)
    || (parse_url($origin, PHP_URL_PORT) ?? 80) < 1
    || (parse_url($origin, PHP_URL_PORT) ?? 80) > 65535) {
    throw new InvalidArgumentException('Only an HTTP loopback synthetic origin is allowed.');
}
$fixturePath = (string) ($fixture['path'] ?? '/guidance/assessments/mi');
if (strlen($fixturePath) > 2048 || !preg_match('~^/guidance/assessments/mi(?:\?[^\r\n#]*)?$~D', $fixturePath)
    || preg_match('/%(?![0-9a-f]{2})/i', $fixturePath)) {
    throw new InvalidArgumentException('Only the MI registry view and bounded query are allowed.');
}
$csrfHash = (string) ($fixture['csrf'] ?? 'mi-registry-token-0');
if (!preg_match('/^mi-registry-token-[0-9]{1,4}$/D', $csrfHash)) {
    throw new InvalidArgumentException('Only synthetic CSRF tokens are allowed.');
}
$theme = $fixture['theme'] ?? 'light';
if (!in_array($theme, ['light', 'dark'], true)) {
    throw new InvalidArgumentException('Unexpected synthetic theme.');
}
$regionalEnabled = ($fixture['regionalEnabled'] ?? true) === true;

function esc($value, string $context = 'html'): string
{
    if (!in_array($context, ['html', 'attr'], true)) {
        throw new RuntimeException('Unexpected escaping dependency: ' . $context);
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function site_url(string $path = ''): string { return $GLOBALS['origin'] . '/' . ltrim($path, '/'); }
function base_url(string $path = ''): string { return site_url($path); }
function current_url(bool $returnObject = false): string { return $GLOBALS['origin'] . $GLOBALS['fixturePath']; }
function csrf_token(): string { return 'fixture_csrf'; }
function csrf_hash(): string { return $GLOBALS['csrfHash']; }
function csrf_field(): string
{
    return '<input type="hidden" name="fixture_csrf" value="' . esc(csrf_hash(), 'attr') . '">';
}
function config(string $name)
{
    if (ltrim($name, '\\') !== 'Config\\RefreshRegions') {
        throw new RuntimeException('Unexpected config dependency: ' . $name);
    }
    return new class($GLOBALS['regionalEnabled']) {
        // The real MI assignment response has no regional refresh protocol.
        public array $owners = [];
        public function __construct(public bool $enabled) {}
        public function enables(string $owner): bool { return false; }
    };
}

function view(string $name, array $data = [], array $options = []): string
{
    if ($options !== [] && $options !== ['saveData' => false]) {
        throw new RuntimeException('Unexpected view options.');
    }
    if ($name === 'guidance/assessments/mi_index') {
        return (new GuidanceMiRegistryFixtureView())->render($data);
    }
    if ($name !== 'partials/table_pagination') {
        throw new RuntimeException('Unexpected view dependency: ' . $name);
    }
    // Isolated partial scope: no application renderer, layout or helper loader.
    return (static function (array $variables): string {
        extract($variables, EXTR_SKIP);
        ob_start();
        require $GLOBALS['root'] . '/app/Views/partials/table_pagination.php';
        return ob_get_clean();
    })($data);
}

function synthetic_mi_rows(string $name, array $rows): array
{
    if (count($rows) > ($name === 'students' ? 40 : 100)) {
        throw new InvalidArgumentException('Synthetic row bound exceeded: ' . $name);
    }
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new InvalidArgumentException('Expected synthetic MI row.'); }
        $personId = $row[$name === 'students' ? 'id' : 'person_id'] ?? null;
        if (!is_int($personId) || $personId < 93001 || $personId > 93999) {
            throw new InvalidArgumentException('Only invented fixture student IDs are allowed.');
        }
        $id = $row['id'] ?? null;
        if (!is_int($id) || isset($ids[$id]) || ($name !== 'students' && ($id < 95001 || $id > 95999))) {
            throw new InvalidArgumentException('Invalid or repeated synthetic MI row ID.');
        }
        $ids[$id] = true;
        foreach (['first_name', 'last_name'] as $key) {
            if (!is_string($row[$key] ?? null) || !str_starts_with($row[$key], 'synthetic_')) {
                throw new InvalidArgumentException('Only visibly synthetic student names are allowed.');
            }
        }
        if (!str_starts_with((string) ($row['mis_id'] ?? ''), 'SYN-')) {
            throw new InvalidArgumentException('Only synthetic MIS references are allowed.');
        }
        if (!in_array($row['program'] ?? '', ['', null, 'BS Computer Science', 'BS Nursing', 'BS Psychology'], true)
            || !in_array((string) ($row['year_level'] ?? ''), ['', '1', '2', '3', '4'], true)) {
            throw new InvalidArgumentException('Unexpected synthetic program or year.');
        }
        foreach ($row as $value) {
            if (is_string($value) && strlen($value) > 2048) {
                throw new InvalidArgumentException('Synthetic MI value exceeds its bound.');
            }
        }
        if ($name === 'assignments') {
            if (!in_array($row['status'] ?? '', ['assigned', 'in_progress', 'completed', 'cancelled'], true)) {
                throw new InvalidArgumentException('Unexpected synthetic assignment status.');
            }
            if (($row['submission_id'] ?? null) !== null
                && (($row['status'] ?? '') !== 'completed' || !is_int($row['submission_id'])
                    || $row['submission_id'] < 94001 || $row['submission_id'] > 94999)) {
                throw new InvalidArgumentException('Only completed synthetic submission references are allowed.');
            }
        }
    }
    return array_values($rows);
}

final class GuidanceMiRegistryFixtureView
{
    private array $sections = [];
    private array $stack = [];
    private bool $appLayout = false;

    public function extend(string $name): string
    {
        if ($name !== 'layouts/app') { throw new RuntimeException('Unexpected layout dependency: ' . $name); }
        $this->appLayout = true;
        return '';
    }
    public function section(string $name): string
    {
        if (!in_array($name, ['page_actions', 'styles', 'content', 'scripts'], true) || in_array($name, $this->stack, true)) {
            throw new RuntimeException('Unexpected view section: ' . $name);
        }
        $this->stack[] = $name;
        ob_start();
        return '';
    }
    public function endSection(): string
    {
        if ($this->stack === []) { throw new RuntimeException('Unbalanced view section.'); }
        $this->sections[array_pop($this->stack)] = ob_get_clean();
        return '';
    }
    public function include(string $name): string
    {
        if ($name !== 'partials/table_pagination') { throw new RuntimeException('Unexpected view dependency: ' . $name); }
        return view($name);
    }
    public function renderSection(string $name): string { return $this->sections[$name] ?? ''; }
    public function render(array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $GLOBALS['root'] . '/app/Views/guidance/assessments/mi_index.php';
        ob_end_clean();
        if (!$this->appLayout || $this->stack !== []) { throw new RuntimeException('Unbalanced MI registry layout.'); }
        // Read only the existing shared confirmation markup; never execute app.php.
        $layout = file_get_contents($GLOBALS['root'] . '/app/Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $layout, $confirmation)) {
            throw new RuntimeException('Actual shared confirmation markup was not found.');
        }
        $assets = '';
        foreach (['custom-dropdown', 'datepicker', 'loading', 'form-loading', 'csrf', 'toast', 'confirm', 'live-filters',
            'page-styles', 'form-modal', 'responsive-tables', 'page-lifecycle', 'navigation', 'refresh-regions', 'background-actions'] as $name) {
            $assets .= '<script src="/assets/js/' . $name . '.js"></script>';
        }
        $title = esc($data['pageTitle']);
        return '<!doctype html><html lang="en" data-theme="' . $GLOBALS['theme'] . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="icon" href="data:,"><title>' . $title . '</title>'
            . '<meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="' . esc(csrf_hash(), 'attr') . '">'
            . '<meta name="csrf-header" content="X-Fixture-CSRF">'
            . '<meta name="synapse-refresh-regions-enabled" content="' . ($GLOBALS['regionalEnabled'] ? '1' : '0') . '">'
            . '<meta name="synapse-refresh-region-owners" content="">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="/assets/css/style.css"></head>'
            . '<body class="app-shell" data-navigation-scope="synthetic-guidance-mi-registry">'
            // Actual scoped page CSS/JS come exclusively from the actual view's sections.
            . '<div id="page-styles" hidden>' . $this->renderSection('styles') . '</div>'
            . '<header id="header" class="header"><div class="header-left"><span class="header-context">Synthetic Guidance</span></div>'
            . '<div></div><button type="button" class="header-action" id="fixtureThemeToggle" aria-label="Toggle fixture theme"><i class="bi bi-circle-half" aria-hidden="true"></i></button></header>'
            . '<aside id="sidebar" class="sidebar"><div class="sidebar-brand-row"><div class="sidebar-brand"><span class="sidebar-brand-copy"><strong>SYNTHETIC</strong><span>Guidance test fixture</span></span></div></div>'
            . '<nav class="sidebar-nav"><ul class="nav-menu"><li class="nav-item"><a class="nav-link active" aria-current="page" href="' . esc(site_url('guidance/assessments/mi'), 'attr') . '">'
            . '<span class="nav-icon"><i class="bi bi-ui-checks-grid" aria-hidden="true"></i></span><span class="nav-text">MI Registry</span></a></li></ul></nav></aside>'
            . '<main id="main" class="main"><div class="main-content"><div class="pagetitle d-flex justify-content-between align-items-center mb-3">'
            . '<div class="page-title-copy"><h1 class="h3 mb-0">' . $title . '</h1></div><div class="page-actions">' . $this->renderSection('page_actions') . '</div></div>'
            . '<section class="section"><div id="synthetic-mi-registry-notice" class="alert alert-secondary py-2 small" role="note">'
            . '<strong>SYNTHETIC TEST ONLY.</strong> Invented students, assignments and completed references. Saves change memory only; no real records, sessions or database are used. Results and PDF links lead to labeled read-only test pages, not real assessment reports.</div>'
            . $this->renderSection('content') . '</section></div></main>' . $confirmation[1]
            . '<script>window.fixtureMiRegistryEvents=[];window.fixtureEventOverflow=0;'
            . 'for(const name of ["synapse:form-saved","refresh_committed","refresh_failed","refresh_canceled","refresh_security_escalated","synapse:page-load"]){'
            . 'document.addEventListener(name,event=>{if(fixtureMiRegistryEvents.length<48)fixtureMiRegistryEvents.push({name,owner:event.detail?.owner,regions:event.detail?.regions,status:event.detail?.status});else fixtureEventOverflow++;});}</script>'
            . '<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . $assets
            . '<script>document.getElementById("fixtureThemeToggle").addEventListener("click",()=>document.documentElement.setAttribute("data-theme",document.documentElement.getAttribute("data-theme")==="dark"?"light":"dark"));</script>'
            . '<div id="page-scripts" hidden>' . $this->renderSection('scripts') . '</div>'
            . '<div class="navigation-feedback"><div id="navigation-status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>'
            . '<button id="navigation-retry" class="btn btn-sm btn-primary" type="button" hidden>Retry navigation</button>'
            . '<a id="navigation-fallback" class="btn btn-sm btn-outline-secondary" data-navigation="false" hidden>Open the full page</a></div></body></html>';
    }
}

$students = synthetic_mi_rows('students', $fixture['students'] ?? []);
$assignments = synthetic_mi_rows('assignments', $fixture['assignments'] ?? []);
$programs = $fixture['programs'] ?? [];
if (!is_array($programs) || count($programs) > 3 || count(array_unique($programs)) !== count($programs)
    || array_diff($programs, ['BS Computer Science', 'BS Nursing', 'BS Psychology']) !== []) {
    throw new InvalidArgumentException('Unexpected synthetic program list.');
}
$yearLevels = $fixture['yearLevels'] ?? ['1', '2', '3', '4'];
if ($yearLevels !== ['1', '2', '3', '4']) { throw new InvalidArgumentException('Unexpected synthetic year list.'); }
$statusFilter = $fixture['statusFilter'] ?? '';
$programFilter = $fixture['programFilter'] ?? '';
if (!in_array($statusFilter, ['', 'assigned', 'in_progress', 'completed', 'cancelled'], true)
    || !in_array($programFilter, array_merge([''], $programs), true)) {
    throw new InvalidArgumentException('Unexpected synthetic registry filter.');
}
$query = [];
foreach (explode('&', (string) (parse_url($fixturePath, PHP_URL_QUERY) ?? '')) as $pair) {
    if ($pair === '') { continue; }
    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
    $key = urldecode($key);
    $value = urldecode($value);
    if (array_key_exists($key, $query) || !in_array($key, ['program', 'status', 'per_page', 'page'], true)
        || ($key === 'program' && !in_array($value, array_merge([''], $programs), true))
        || ($key === 'status' && !in_array($value, ['', 'assigned', 'in_progress', 'completed', 'cancelled'], true))
        || ($key === 'per_page' && !in_array($value, ['20', '25', '50', '100'], true))
        || ($key === 'page' && !preg_match('/^-?(?:0|[1-9][0-9]{0,3})$/D', $value))) {
        throw new InvalidArgumentException('Unexpected synthetic registry query.');
    }
    $query[$key] = $value;
}
$activePersonIds = $fixture['activePersonIds'] ?? [];
if (!is_array($activePersonIds) || count($activePersonIds) > 40) { throw new InvalidArgumentException('Invalid synthetic active IDs.'); }
$studentIds = array_column($students, 'id');
foreach ($activePersonIds as $id) {
    if (!is_int($id) || !in_array($id, $studentIds, true)) { throw new InvalidArgumentException('Only known synthetic active student IDs are allowed.'); }
}
foreach ($assignments as $row) {
    if (!in_array($row['person_id'], $studentIds, true)) { throw new InvalidArgumentException('Assignment references an unknown synthetic student.'); }
}
$pagination = $fixture['pagination'] ?? ['per_page' => 25, 'filtered' => count($assignments), 'total' => count($assignments), 'pages' => 1, 'page' => 1];
foreach (['per_page', 'filtered', 'total', 'pages', 'page'] as $key) {
    if (!is_int($pagination[$key] ?? null)) { throw new InvalidArgumentException('Invalid synthetic pagination.'); }
}
if (!in_array($pagination['per_page'], [20, 25, 50, 100], true) || $pagination['filtered'] < 0
    || $pagination['total'] < $pagination['filtered'] || $pagination['total'] > 200
    || $pagination['pages'] !== max(1, (int) ceil($pagination['filtered'] / $pagination['per_page']))
    || $pagination['page'] < 1 || $pagination['page'] > $pagination['pages']
    || count($assignments) !== max(0, min($pagination['per_page'], $pagination['filtered'] - ($pagination['page'] - 1) * $pagination['per_page']))) {
    throw new InvalidArgumentException('Inconsistent synthetic pagination.');
}
echo view('guidance/assessments/mi_index', [
    'title' => 'Multiple Intelligence Assessments', 'pageTitle' => 'Multiple Intelligence (80-Item) Registry',
    'assignments' => $assignments, 'students' => $students, 'programs' => array_values($programs), 'yearLevels' => $yearLevels,
    'activePersonIds' => array_values(array_unique($activePersonIds)), 'statusFilter' => $statusFilter, 'programFilter' => $programFilter,
    'pagination' => $pagination, 'miGate' => null, 'miGateEnabled' => ($fixture['miGateEnabled'] ?? true) === true,
    'canAssign' => ($fixture['canAssign'] ?? true) === true,
    'breadcrumbs' => [['label' => 'Guidance', 'url' => site_url('/guidance')], ['label' => 'Multiple Intelligence', 'url' => '']],
]);
