<?php

declare(strict_types=1);

// CLI-only rendering of the actual view. No CI/Composer, dotenv, sessions,
// controllers, services or database are loaded. The caller also confines PHP
// open_basedir to this support directory and app/Views.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$input = stream_get_contents(STDIN, 262145);
if (strlen($input) > 262144) {
    throw new InvalidArgumentException('Synthetic practitioner input exceeds its bound.');
}
$fixture = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
$allowed = ['origin', 'path', 'theme', 'csrf', 'regionalEnabled', 'profiles', 'supervisions', 'guidanceUsers',
    'tiers', 'availableScopes', 'canManagePractitioners', 'canReassign'];
if (!is_array($fixture) || array_diff(array_keys($fixture), $allowed) !== []) {
    throw new InvalidArgumentException('Expected only isolated synthetic practitioner options.');
}
$root = dirname(__DIR__, 2);
$origin = (string) ($fixture['origin'] ?? 'http://127.0.0.1');
if (!preg_match('~^http://127\.0\.0\.1(?::[0-9]{1,5})?$~D', $origin)
    || (parse_url($origin, PHP_URL_PORT) ?? 80) < 1
    || (parse_url($origin, PHP_URL_PORT) ?? 80) > 65535) {
    throw new InvalidArgumentException('Only an HTTP loopback synthetic origin is allowed.');
}
$fixturePath = (string) ($fixture['path'] ?? '/guidance/governance/practitioners');
if (!preg_match('~^/guidance/governance/practitioners(?:\?[^\r\n#]*)?$~D', $fixturePath)) {
    throw new InvalidArgumentException('Only the practitioner workspace view is allowed.');
}
parse_str(parse_url($fixturePath, PHP_URL_QUERY) ?? '', $fixtureGet);
if (array_diff(array_keys($fixtureGet), ['profiles_page', 'supervision_page', 'per_page']) !== []) {
    throw new InvalidArgumentException('Unexpected synthetic practitioner query.');
}
$csrfHash = (string) ($fixture['csrf'] ?? 'practitioners-token-0');
if (!preg_match('/^practitioners-token-[0-9]{1,4}$/D', $csrfHash)) {
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
// The actual view casts current_url(true) to string; no framework URI is needed.
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
        public array $owners = ['guidance.governance.practitioners'];
        public function __construct(public bool $enabled) {}
        public function enables(string $owner): bool { return $this->enabled && in_array($owner, $this->owners, true); }
    };
}

function fixture_page(string $parameter, int $total): array
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
    $requested = $integer($parameter, 1);
    $size = $integer('per_page', 25);
    if ($requested > 1000000 || !in_array($size, [20, 25, 50, 100], true)) {
        throw new InvalidArgumentException('Invalid synthetic pagination range.', 422);
    }
    $pages = max(1, (int) ceil($total / $size));
    $page = min($requested, $pages);
    return ['page' => $page, 'per_page' => $size, 'total' => $total, 'filtered' => $total, 'pages' => $pages,
        'from' => $total === 0 ? 0 : ($page - 1) * $size + 1, 'to' => min($page * $size, $total)];
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

function synthetic_rows(string $name, array $rows): array
{
    if (count($rows) > 40) { throw new InvalidArgumentException('Synthetic row bound exceeded: ' . $name); }
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new InvalidArgumentException('Expected synthetic row: ' . $name); }
        $userKeys = $name === 'supervisions' ? ['associate_user_id', 'supervisor_user_id'] : [$name === 'guidanceUsers' ? 'id' : 'user_id'];
        foreach ($userKeys as $key) {
            if (!is_int($row[$key] ?? null) || $row[$key] < 93000 || $row[$key] > 93999) {
                throw new InvalidArgumentException('Only invented fixture staff IDs are allowed.');
            }
        }
        $usernameKeys = $name === 'supervisions' ? ['associate_username', 'supervisor_username'] : ['username'];
        foreach ($usernameKeys as $key) {
            if (!str_starts_with((string) ($row[$key] ?? ''), 'synthetic_')) {
                throw new InvalidArgumentException('Only visibly synthetic staff accounts are allowed.');
            }
        }
        if (!empty($row['license_number']) && !str_starts_with((string) $row['license_number'], 'SYN-')) {
            throw new InvalidArgumentException('Only synthetic license references are allowed.');
        }
    }
    return array_values($rows);
}

final class GuidancePractitionersFixtureView
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
    public function include(string $name): string { throw new RuntimeException('Unexpected view dependency: ' . $name); }
    public function renderSection(string $name): string { return $this->sections[$name] ?? ''; }
    public function render(array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $GLOBALS['root'] . '/app/Views/guidance/governance/practitioners.php';
        ob_end_clean();
        if (!$this->appLayout || $this->stack !== []) { throw new RuntimeException('Unbalanced practitioner layout.'); }
        // Read the existing confirmation markup, never execute the app layout.
        $layout = file_get_contents($GLOBALS['root'] . '/app/Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $layout, $confirmation)) {
            throw new RuntimeException('Actual shared confirmation markup was not found.');
        }
        $assets = '';
        // form-modal owns the real legacy SynapseMutations fallback. No page
        // script, form autofill, realtime, authentication or network owner is faked.
        foreach (['custom-dropdown', 'datepicker', 'loading', 'form-loading', 'csrf', 'toast', 'confirm', 'live-filters', 'page-styles', 'form-modal',
            'responsive-tables', 'page-lifecycle', 'navigation', 'refresh-regions', 'background-actions'] as $name) {
            $assets .= '<script src="/assets/js/' . $name . '.js"></script>';
        }
        $title = esc($data['pageTitle']);
        return '<!doctype html><html lang="en" data-theme="' . $GLOBALS['theme'] . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="icon" href="data:,"><title>' . $title . '</title>'
            . '<meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="' . esc(csrf_hash(), 'attr') . '">'
            . '<meta name="csrf-header" content="X-Fixture-CSRF">'
            . '<meta name="synapse-refresh-regions-enabled" content="' . ($GLOBALS['regionalEnabled'] ? '1' : '0') . '">'
            . '<meta name="synapse-refresh-region-owners" content="guidance.governance.practitioners">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="/assets/css/style.css"></head>'
            . '<body class="app-shell" data-navigation-scope="synthetic-guidance-practitioners">'
            . '<div id="page-styles" hidden>' . $this->renderSection('styles') . '</div>'
            . '<header id="header" class="header"><div class="header-left"><span class="header-context">Synthetic Guidance</span></div>'
            . '<div></div><button type="button" class="header-action" id="fixtureThemeToggle" aria-label="Toggle fixture theme"><i class="bi bi-circle-half" aria-hidden="true"></i></button></header>'
            . '<aside id="sidebar" class="sidebar"><div class="sidebar-brand-row"><div class="sidebar-brand"><span class="sidebar-brand-copy"><strong>SYNTHETIC</strong><span>Guidance test fixture</span></span></div></div>'
            . '<nav class="sidebar-nav"><ul class="nav-menu"><li class="nav-item"><a class="nav-link active" aria-current="page" href="' . esc(site_url('guidance/governance/practitioners'), 'attr') . '">'
            . '<span class="nav-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span><span class="nav-text">Practitioners</span></a></li></ul></nav></aside>'
            . '<main id="main" class="main"><div class="main-content"><div class="pagetitle d-flex justify-content-between align-items-center mb-3">'
            . '<div class="page-title-copy"><h1 class="h3 mb-0">' . $title . '</h1></div><div class="page-actions">' . $this->renderSection('page_actions') . '</div></div>'
            . '<section class="section"><div id="synthetic-practitioners-notice" class="alert alert-secondary py-2 small" role="note">'
            . '<strong>SYNTHETIC TEST ONLY.</strong> Invented staff, credentials and supervision assignments. Saves change memory only; no real records, sessions or database are used.</div>'
            . $this->renderSection('content') . '</section></div></main>' . $confirmation[1]
            // Bounded observation-only lifecycle evidence; never triggers an action.
            . '<script>window.fixturePractitionerEvents=[];window.fixtureEventOverflow=0;'
            . 'for(const name of ["synapse:form-saved","refresh_committed","refresh_failed","refresh_canceled","refresh_security_escalated","synapse:page-load"]){'
            . 'document.addEventListener(name,event=>{if(fixturePractitionerEvents.length<48)fixturePractitionerEvents.push({name,owner:event.detail?.owner,regions:event.detail?.regions,status:event.detail?.status});else fixtureEventOverflow++;});}</script>'
            . '<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . $assets
            . '<script>document.getElementById("fixtureThemeToggle").addEventListener("click",()=>document.documentElement.setAttribute("data-theme",document.documentElement.getAttribute("data-theme")==="dark"?"light":"dark"));</script>'
            . '<div id="page-scripts" hidden>' . $this->renderSection('scripts') . '</div>'
            . '<div class="navigation-feedback"><div id="navigation-status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>'
            . '<button id="navigation-retry" class="btn btn-sm btn-primary" type="button" hidden>Retry navigation</button>'
            . '<a id="navigation-fallback" class="btn btn-sm btn-outline-secondary" data-navigation="false" hidden>Open the full page</a></div></body></html>';
    }
}

$tiers = $fixture['tiers'] ?? ['counselor_associate', 'licensed_counselor', 'guidance_supervisor', 'qualified_assessor'];
if ($tiers !== ['counselor_associate', 'licensed_counselor', 'guidance_supervisor', 'qualified_assessor']) {
    throw new InvalidArgumentException('Synthetic forms must use the four controller tiers.');
}
$availableScopes = $fixture['availableScopes'] ?? [];
if (!is_array($availableScopes) || count($availableScopes) > 12) { throw new InvalidArgumentException('Invalid synthetic scope list.'); }
foreach ($availableScopes as $key => $label) {
    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string) $key) || !is_string($label) || strlen($label) > 2048) {
        throw new InvalidArgumentException('Invalid synthetic scope key or label.');
    }
}
$profiles = synthetic_rows('profiles', $fixture['profiles'] ?? []);
usort($profiles, static fn (array $left, array $right): int => (int) $right['is_active'] <=> (int) $left['is_active']
    ?: strcmp((string) $left['credential_tier'], (string) $right['credential_tier'])
    ?: strcmp((string) $left['username'], (string) $right['username']) ?: (int) $left['id'] <=> (int) $right['id']);
$supervisions = array_values(array_filter(synthetic_rows('supervisions', $fixture['supervisions'] ?? []),
    static fn (array $row): bool => ($row['status'] ?? '') === 'active'));
usort($supervisions, static fn (array $left, array $right): int => strcmp((string) ($right['starts_at'] ?? ''), (string) ($left['starts_at'] ?? ''))
    ?: (int) $right['id'] <=> (int) $left['id']);
$profilesPagination = fixture_page('profiles_page', count($profiles));
$supervisionPagination = fixture_page('supervision_page', count($supervisions));
$profiles = array_slice($profiles, ($profilesPagination['page'] - 1) * $profilesPagination['per_page'], $profilesPagination['per_page']);
$supervisions = array_slice($supervisions, ($supervisionPagination['page'] - 1) * $supervisionPagination['per_page'], $supervisionPagination['per_page']);
echo (new GuidancePractitionersFixtureView())->render([
    'title' => 'Practitioner Credentials & Supervision',
    'pageTitle' => 'Guidance Practitioner Credentials & Supervision',
    'profiles' => $profiles, 'profilesPagination' => $profilesPagination,
    'supervisions' => $supervisions, 'supervisionPagination' => $supervisionPagination,
    'guidanceUsers' => synthetic_rows('guidanceUsers', $fixture['guidanceUsers'] ?? []),
    'tiers' => $tiers, 'availableScopes' => $availableScopes,
    'canManagePractitioners' => ($fixture['canManagePractitioners'] ?? false) === true,
    'canReassign' => ($fixture['canReassign'] ?? false) === true,
]);
