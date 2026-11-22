<?php

declare(strict_types=1);

// CLI-only actual-view renderer. No Composer/CI bootstrap, dotenv, controllers,
// models, PHP sessions, connection factory, database, or real gate sign-offs.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$fixture = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
if (!is_array($fixture)) { throw new InvalidArgumentException('Expected fixture options'); }
$root = dirname(__DIR__, 2);
$origin = (string) ($fixture['origin'] ?? 'http://127.0.0.1');
if (!preg_match('~^http://127\.0\.0\.1(?::[0-9]{1,5})?$~D', $origin)) {
    throw new InvalidArgumentException('Only loopback fixture origins are permitted');
}
$fixturePath = (string) ($fixture['path'] ?? '/guidance/governance/gates');
if (!preg_match('~^/guidance/governance/gates(?:\?[^\r\n#]*)?$~D', $fixturePath)) {
    throw new InvalidArgumentException('Only the synthetic gates view is permitted');
}
parse_str(parse_url($fixturePath, PHP_URL_QUERY) ?? '', $fixtureGet);
$csrfHash = (string) ($fixture['csrf'] ?? 'gates-token-0');

// Deliberately small helper allowlist. Unknown view/helper dependencies fail
// rather than importing an application helper that might bootstrap services.
function esc($value, string $context = 'html'): string {
    if (!in_array($context, ['html', 'attr', 'js'], true)) {
        throw new RuntimeException('Unexpected escaping context: ' . $context);
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function site_url(string $path = ''): string { return $GLOBALS['origin'] . '/' . ltrim($path, '/'); }
function base_url(string $path = ''): string { return site_url($path); }
function current_url(bool $returnObject = false): string { return $GLOBALS['origin'] . $GLOBALS['fixturePath']; }
function csrf_token(): string { return 'fixture_csrf'; }
function csrf_hash(): string { return $GLOBALS['csrfHash']; }
function csrf_field(): string { return '<input type="hidden" name="fixture_csrf" value="' . esc(csrf_hash(), 'attr') . '">'; }

final class GuidanceRolloutGatesView {
    private array $sections = [];
    private array $stack = [];
    private bool $appLayout = false;

    public function extend(string $name): string {
        if ($name !== 'layouts/app') { throw new RuntimeException('Unexpected layout dependency: ' . $name); }
        $this->appLayout = true;
        return '';
    }
    public function section(string $name): string {
        if (!in_array($name, ['page_actions', 'styles', 'content', 'scripts'], true)) {
            throw new RuntimeException('Unexpected section dependency: ' . $name);
        }
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
    public function include(string $name): string { throw new RuntimeException('Unexpected view dependency: ' . $name); }
    public function render(array $data): string {
        extract($data, EXTR_SKIP);
        ob_start();
        require $GLOBALS['root'] . '/app/Views/guidance/governance/gates.php';
        ob_end_clean();
        if ($this->stack !== [] || !$this->appLayout) { throw new RuntimeException('Unbalanced or unexpected view layout'); }
        return $this->shell($data);
    }
    private function shell(array $data): string {
        $theme = ($GLOBALS['fixture']['theme'] ?? $GLOBALS['fixtureGet']['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $title = esc($data['title']);
        $gatesUrl = site_url('/guidance/governance/gates');
        $width = !empty($GLOBALS['fixtureGet']['container']) ? 'max-width:350px;' : '';
        $appLayout = file_get_contents($GLOBALS['root'] . '/app/Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $appLayout, $confirmation)) {
            throw new RuntimeException('Actual shared confirmation markup not found; do not substitute a fake modal');
        }
        $js = ['custom-dropdown', 'datepicker', 'datetimepicker', 'loading', 'form-loading', 'csrf',
            'toast', 'confirm', 'form-modal', 'responsive-tables', 'page-lifecycle', 'navigation',
            'refresh-regions', 'background-actions'];
        $assets = '';
        foreach ($js as $name) { $assets .= '<script src="/assets/js/' . $name . '.js"></script>'; }
        // Only the unrelated app header/sidebar services are absent. The page
        // styles/scripts come from the actual view sections, never a test copy.
        return '<!doctype html><html lang="en" data-theme="' . $theme . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="icon" href="data:,"><title>' . $title . '</title>'
            . '<meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="' . esc(csrf_hash(), 'attr') . '">'
            . '<meta name="csrf-header" content="X-Fixture-CSRF"><meta name="synapse-refresh-regions-enabled" content="1">'
            . '<meta name="synapse-refresh-region-owners" content="guidance.governance.gates">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">'
            . '<link rel="stylesheet" href="/assets/css/style.css">'
            . '<style>#main.main{margin:0;padding:16px;width:100%;min-height:100vh}.fixture-container{width:100%;' . $width . '}'
            . '#sidebar{display:none}.fixture-page-heading{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}'
            . '@media(max-width:400px){#main.main{padding:10px}}</style>'
            . '<div id="page-styles" hidden>' . $this->renderSection('styles') . '</div>'
            . '<script defer src="/assets/vendor/alpine/alpine.min.js"></script></head>'
            . '<body data-navigation-scope="synthetic-guidance-rollout-gates">'
            . '<header id="header" hidden>Database-free Rollout Gates fixture</header>'
            . '<aside id="sidebar"><nav class="sidebar-nav"><a class="nav-link active" href="' . esc($gatesUrl, 'attr') . '">Rollout Gates</a></nav></aside>'
            . '<main id="main" class="main"><div class="fixture-container"><div class="fixture-page-heading"><h1 class="h4">' . $title . '</h1>'
            . $this->renderSection('page_actions') . '</div>' . $this->renderSection('content') . '</div></main>' . $confirmation[1]
            // Observation only: no simulated Alpine, state injection, fake
            // lifecycle APIs, or requests/actions from fixture script code.
            . '<script>window.fixtureRefreshEvents=[];window.fixtureRefreshEventOverflow=0;for(const name of ["refresh_committed","refresh_failed","refresh_canceled","refresh_security_escalated"]){document.addEventListener(name,event=>{const detail={owner:event.detail.owner,regions:event.detail.regions,url:event.detail.url,status:event.detail.status,reason:event.detail.reason};if(fixtureRefreshEvents.length<20)fixtureRefreshEvents.push({name,detail});else fixtureRefreshEventOverflow++;});}</script>'
            . '<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . $assets
            . '<div id="page-scripts" hidden>' . $this->renderSection('scripts') . '</div>'
            . '<div class="navigation-feedback"><div id="navigation-status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>'
            . '<button id="navigation-retry" class="btn btn-sm btn-primary" type="button" hidden>Retry navigation</button>'
            . '<a id="navigation-fallback" class="btn btn-sm btn-outline-secondary" data-navigation="false" hidden>Open the full page</a></div></body></html>';
    }
}

$data = [
    'title' => 'Rollout Activation Gates',
    'pageTitle' => 'Guidance Rollout Activation Gates',
    'gates' => $fixture['gates'] ?? [],
    'authorityRoles' => $fixture['authorityRoles'] ?? ['guidance_head', 'qa_office', 'dpo'],
    'signerNames' => $fixture['signerNames'] ?? [],
    'canSignOff' => !empty($fixture['canSignOff']),
];
// Preserve production date()/strtotime() semantics. No Manila conversion or
// application timezone bootstrap is introduced by this isolated renderer.
echo (new GuidanceRolloutGatesView())->render($data);
