<?php

declare(strict_types=1);

// CLI-only actual-view rendering. Stronger than guidance-read-bootstrap's
// no-dotenv boot: no framework/Composer, database, sessions or app services load.
// The Node caller also confines PHP open_basedir to Views and this support folder.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$input = stream_get_contents(STDIN, 262145);
if (strlen($input) > 262144) {
    throw new InvalidArgumentException('Synthetic Call Slips input exceeds its bound.');
}
$fixture = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
$allowed = ['origin', 'path', 'csrf', 'regionalEnabled', 'theme', 'callSlips', 'prefillPerson'];
if (!is_array($fixture) || array_diff(array_keys($fixture), $allowed) !== []) {
    throw new InvalidArgumentException('Expected only synthetic Call Slips options.');
}
$root = dirname(__DIR__, 2);
$origin = (string) ($fixture['origin'] ?? 'http://127.0.0.1');
if (!preg_match('~^http://127\.0\.0\.1(?::[0-9]{1,5})?$~D', $origin)
    || (parse_url($origin, PHP_URL_PORT) ?? 80) < 1
    || (parse_url($origin, PHP_URL_PORT) ?? 80) > 65535) {
    throw new InvalidArgumentException('Only a loopback synthetic origin is allowed.');
}
$fixturePath = (string) ($fixture['path'] ?? '/guidance/call-slips');
if (!preg_match('~^/guidance/call-slips(?:\?[^\r\n#]*)?$~D', $fixturePath)) {
    throw new InvalidArgumentException('Only the Call Slips list view is allowed.');
}
parse_str(parse_url($fixturePath, PHP_URL_QUERY) ?? '', $fixtureGet);
if (array_diff(array_keys($fixtureGet), ['page', 'per_page', 'status', 'person_id', 'episode_id', 'empty']) !== []) {
    throw new InvalidArgumentException('Unexpected synthetic Call Slips query.');
}
$csrfHash = (string) ($fixture['csrf'] ?? 'callslips-token-0');
$regionalEnabled = ($fixture['regionalEnabled'] ?? true) === true;

// Fail closed on new helper/config/view dependencies instead of importing code
// that could initialize the real application or read credentials.
function esc($value, string $context = 'html'): string
{
    if (!in_array($context, ['html', 'attr'], true)) {
        throw new RuntimeException('Unexpected escaping context: ' . $context);
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function site_url(string $path = ''): string { return $GLOBALS['origin'] . '/' . ltrim($path, '/'); }
function base_url(string $path = ''): string { return site_url($path); }
// The actual view casts URI context to string; retain its full synthetic query without CI.
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
        public array $owners = ['guidance.call-slips'];
        public function __construct(public bool $enabled) {}
        public function enables(string $owner): bool
        {
            return $this->enabled && in_array($owner, $this->owners, true);
        }
    };
}
function service(string $name)
{
    if ($name !== 'request') { throw new RuntimeException('Unexpected service dependency: ' . $name); }
    return new class {
        public function getGet(?string $name = null)
        {
            return $name === null ? $GLOBALS['fixtureGet'] : ($GLOBALS['fixtureGet'][$name] ?? null);
        }
    };
}

function fixture_page(int $total, int $filtered): array
{
    $query = $GLOBALS['fixtureGet'];
    $integer = static function (string $key, int $default) use ($query): int {
        if (!array_key_exists($key, $query)) return $default;
        $value = $query[$key];
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,6}\z/D', $value)) {
            throw new InvalidArgumentException('Invalid synthetic pagination parameter: ' . $key, 422);
        }
        return (int) $value;
    };
    $requested = $integer('page', 1);
    $size = $integer('per_page', 25);
    if ($requested > 1000000 || !in_array($size, [20, 25, 50, 100], true)) {
        throw new InvalidArgumentException('Invalid synthetic pagination range.', 422);
    }
    $pages = max(1, (int) ceil($filtered / $size));
    $page = min($requested, $pages);
    return ['page' => $page, 'per_page' => $size, 'total' => $total, 'filtered' => $filtered, 'pages' => $pages,
        'from' => $filtered === 0 ? 0 : ($page - 1) * $size + 1, 'to' => min($page * $size, $filtered)];
}

function view(string $name, array $data = [], array $options = []): string
{
    $allowed = ['pagination', 'filters', 'paginationPageParam', 'paginationPerPageParam', 'paginationExtraQuery',
        'paginationLabel', 'paginationLiveFilters', 'paginationFragment', 'paginationCardFooter', 'baseUrl'];
    if ($name !== 'partials/table_pagination' || array_diff(array_keys($data), $allowed) !== [] || $options !== []) {
        throw new RuntimeException('Unexpected view dependency: ' . $name);
    }
    extract($data, EXTR_SKIP);
    ob_start();
    require $GLOBALS['root'] . '/app/Views/partials/table_pagination.php';
    return (string) ob_get_clean();
}

final class GuidanceCallSlipsFixtureView
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
        if (!in_array($name, ['page_actions', 'styles', 'content', 'scripts'], true)) {
            throw new RuntimeException('Unexpected section dependency: ' . $name);
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
        throw new RuntimeException('Unexpected view dependency: ' . $name);
    }
    public function renderSection(string $name): string { return $this->sections[$name] ?? ''; }
    public function render(array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $GLOBALS['root'] . '/app/Views/guidance/call_slips/index.php';
        ob_end_clean();
        if (!$this->appLayout || $this->stack !== []) {
            throw new RuntimeException('Unbalanced or unexpected Call Slips layout.');
        }
        return $this->shell($data);
    }
    private function shell(array $data): string
    {
        $theme = ($GLOBALS['fixture']['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $title = esc($data['pageTitle']);
        $appLayout = file_get_contents($GLOBALS['root'] . '/app/Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $appLayout, $confirmation)) {
            throw new RuntimeException('Actual shared confirmation markup was not found.');
        }
        // Header/sidebar are inert synthetic shell anchors. All feature markup,
        // page sections, CSS, widgets and lifecycle owners are the actual files.
        $assets = '';
        foreach (['custom-dropdown', 'datepicker', 'datetimepicker', 'loading', 'form-loading', 'csrf',
            'toast', 'confirm', 'person-lookup-popover', 'live-filters', 'page-styles', 'form-modal',
            'responsive-tables', 'page-lifecycle', 'navigation', 'refresh-regions', 'background-actions'] as $name) {
            $assets .= '<script src="/assets/js/' . $name . '.js"></script>';
        }
        return '<!doctype html><html lang="en" data-theme="' . $theme . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="icon" href="data:,"><title>' . $title . '</title>'
            . '<meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="' . esc(csrf_hash(), 'attr') . '">'
            . '<meta name="csrf-header" content="X-Fixture-CSRF">'
            . '<meta name="synapse-refresh-regions-enabled" content="' . ($GLOBALS['regionalEnabled'] ? '1' : '0') . '">'
            . '<meta name="synapse-refresh-region-owners" content="guidance.call-slips">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="/assets/css/style.css">'
            . '<script defer src="/assets/vendor/alpine/alpine.min.js"></script></head>'
            . '<body class="app-shell" data-navigation-scope="synthetic-guidance-call-slips">'
            . '<div id="page-styles" hidden>' . $this->renderSection('styles') . '</div>'
            . '<header id="header" class="header"><div class="header-left"><span class="header-context">Synthetic Guidance</span></div>'
            . '<div></div><button type="button" class="header-action" id="fixtureThemeToggle" aria-label="Toggle fixture theme"><i class="bi bi-circle-half" aria-hidden="true"></i></button></header>'
            . '<aside id="sidebar" class="sidebar"><div class="sidebar-brand-row"><div class="sidebar-brand"><span class="sidebar-brand-copy"><strong>SYNTHETIC</strong><span>Guidance test fixture</span></span></div></div>'
            . '<nav class="sidebar-nav"><a class="nav-link active" href="' . esc(site_url('guidance/call-slips'), 'attr') . '"><span class="nav-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span><span class="nav-text">Call Slips</span></a></nav></aside>'
            . '<main id="main" class="main"><div class="main-content">'
            . '<div class="pagetitle d-flex justify-content-between align-items-center mb-3"><div class="page-title-copy"><h1 class="h3 mb-0">' . $title . '</h1></div>'
            . '<div class="page-actions">' . $this->renderSection('page_actions') . '</div></div>'
            . '<section class="section"><div class="alert alert-secondary py-2 small" role="note">Synthetic preview only. Attendance changes stay in memory; print buttons open labeled sample PDFs, never official documents.</div>' . $this->renderSection('content') . '</section></div></main>'
            . $confirmation[1]
            // Bounded, observation-only ledgers: these listeners cannot perform
            // submissions, fetches, state changes or simulated UI interactions.
            . '<script>window.fixtureFilterEvents=[];window.fixtureRefreshEvents=[];window.fixtureEventOverflow=0;'
            . 'document.addEventListener("synapse:filter-updated",event=>{if(fixtureFilterEvents.length<32)fixtureFilterEvents.push(event.detail.root.dataset.filterKey);else fixtureEventOverflow++;});'
            . 'for(const name of ["refresh_committed","refresh_failed","refresh_canceled","refresh_security_escalated"]){document.addEventListener(name,event=>{if(fixtureRefreshEvents.length<32)fixtureRefreshEvents.push({name,owner:event.detail.owner,regions:event.detail.regions,status:event.detail.status,cause:event.detail.cause});else fixtureEventOverflow++;});}</script>'
            . '<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . $assets
            // Real fixture-only header control for manual GUI theme checks. This
            // never changes any feature form, field, action or application state.
            . '<script>document.getElementById("fixtureThemeToggle").addEventListener("click",()=>document.documentElement.setAttribute("data-theme",document.documentElement.getAttribute("data-theme")==="dark"?"light":"dark"));</script>'
            . '<div id="page-scripts" hidden>' . $this->renderSection('scripts') . '</div>'
            . '<div class="navigation-feedback"><div id="navigation-status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>'
            . '<button id="navigation-retry" class="btn btn-sm btn-primary" type="button" hidden>Retry navigation</button>'
            . '<a id="navigation-fallback" class="btn btn-sm btn-outline-secondary" data-navigation="false" hidden>Open the full page</a></div></body></html>';
    }
}

$validStatuses = ['pending', 'acknowledged', 'attended', 'missed', 'cancelled'];
$validPurposes = ['personal_counseling', 'career_counseling', 'routine_interview', 'follow_up', 'academic_counseling', 'others'];
$status = $fixtureGet['status'] ?? '';
if (!is_string($status)) throw new InvalidArgumentException('Invalid synthetic attendance filter.', 422);
if ($status !== '' && !in_array($status, $validStatuses, true)) { $status = ''; }
$callSlips = $fixture['callSlips'] ?? [];
if (!is_array($callSlips) || !array_is_list($callSlips) || count($callSlips) > 128) {
    throw new InvalidArgumentException('Expected at most 128 synthetic Call Slips.');
}
$ids = [];
foreach ($callSlips as $slip) {
    if (!is_array($slip) || !is_int($slip['id'] ?? null) || $slip['id'] < 1 || $slip['id'] > 93999
        || isset($ids[$slip['id']]) || !in_array($slip['person_id'] ?? null, [93001, 93002], true)
        || !str_starts_with((string) ($slip['first_name'] ?? ''), 'Synthetic ')
        || !str_starts_with((string) ($slip['mis_id'] ?? ''), 'SYN-')
        || !in_array($slip['attendance_status'] ?? '', $validStatuses, true)) {
        throw new InvalidArgumentException('Only invented synthetic Call Slips are allowed.');
    }
    $ids[$slip['id']] = true;
}
if (!empty($fixtureGet['empty'])) $callSlips = [];
$total = count($callSlips);
$callSlips = array_values(array_filter($callSlips, static fn (array $slip): bool => $status === '' || $slip['attendance_status'] === $status));
usort($callSlips, static fn (array $left, array $right): int => strcmp((string) $right['call_date'], (string) $left['call_date'])
    ?: strcmp((string) $right['call_time'], (string) $left['call_time']) ?: $right['id'] <=> $left['id']);
$pagination = fixture_page($total, count($callSlips));
$callSlips = array_slice($callSlips, ($pagination['page'] - 1) * $pagination['per_page'], $pagination['per_page']);
$prefillPerson = is_array($fixture['prefillPerson'] ?? null) ? $fixture['prefillPerson'] : null;
if ($prefillPerson !== null && (!in_array($prefillPerson['id'] ?? null, [93001, 93002], true)
    || !str_starts_with((string) ($prefillPerson['first_name'] ?? ''), 'Synthetic '))) {
    throw new InvalidArgumentException('Only invented client prefill is allowed.');
}
$episodeId = (int) ($fixtureGet['episode_id'] ?? 0);
echo (new GuidanceCallSlipsFixtureView())->render([
    'title' => 'Guidance Call Slips', 'pageTitle' => 'Guidance Call Slips',
    'status' => $status, 'callSlips' => $callSlips, 'pagination' => $pagination,
    'validStatuses' => $validStatuses, 'validPurposes' => $validPurposes,
    'prefillPerson' => $prefillPerson, 'prefillEpisodeId' => $episodeId > 0 ? $episodeId : null,
    'actor' => ['id' => 93999, 'tenant_id' => 93000, 'role' => 'guidance_supervisor', 'name' => 'Synthetic Guidance actor'],
    'breadcrumbs' => [['label' => 'Guidance', 'url' => site_url('guidance')], ['label' => 'Call Slips']],
]);
