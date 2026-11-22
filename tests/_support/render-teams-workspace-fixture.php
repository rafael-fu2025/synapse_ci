<?php

declare(strict_types=1);

// Actual Teams views in a CLI-only shell. No CI/Composer/dotenv, controllers,
// models, PHP sessions, connection factory, or database extensions are loaded.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($fixture)) { throw new InvalidArgumentException('Expected fixture options'); }
if (!extension_loaded('mbstring')) { throw new RuntimeException('The installed mbstring extension must be loaded explicitly'); }
$root = dirname(__DIR__, 2);
$origin = (string) ($fixture['origin'] ?? 'http://127.0.0.1');
if (!preg_match('~^http://127\.0\.0\.1(?::\d+)?$~D', $origin)) { throw new InvalidArgumentException('Loopback origins only'); }
$fixturePath = (string) ($fixture['path'] ?? '/guidance/teams');
$pathname = parse_url($fixturePath, PHP_URL_PATH);
if (!preg_match('~^/(clinic|guidance|facilities)/teams(?:$|/)~D', $pathname, $route)) { throw new InvalidArgumentException('Unsupported Teams route'); }
$unit = $route[1];
parse_str(parse_url($fixturePath, PHP_URL_QUERY) ?? '', $fixtureGet);
$csrfHash = (string) ($fixture['csrf'] ?? 'teams-token-0');

function esc($value, string $context = 'html'): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
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
            // Zero photo/tenant identities deliberately prevent any photo lookup.
            $values = ['tenant_id' => 0, 'user' => ['id' => 1, 'name' => 'Workspace Actor', 'role' => 'superadmin', 'tenant_id' => 0, 'person_id' => 0]];
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
function view(string $name, array $data = [], array $options = []): string { return (new TeamsWorkspaceView())->render($name, $data); }

final class TeamsWorkspaceView {
    private array $sections = [];
    private array $stack = [];
    private ?string $layout = null;
    private array $data = [];
    public function extend(string $name): string {
        if (!in_array($name, ['layouts/app', 'layouts/modal_form'], true)) { throw new RuntimeException('Unexpected layout: ' . $name); }
        $this->layout = $name; return '';
    }
    public function section(string $name): string { $this->stack[] = $name; ob_start(); return ''; }
    public function endSection(): string {
        if ($this->stack === []) { throw new RuntimeException('Unbalanced section'); }
        $this->sections[array_pop($this->stack)] = ob_get_clean(); return '';
    }
    public function renderSection(string $name): string { return $this->sections[$name] ?? ''; }
    public function include(string $name): string { return view($name, $this->data); }
    private function template(string $name): string {
        if (!in_array($name, ['teams/index', 'teams/chat', 'teams/calendar', 'teams/form', 'teams/show',
            'teams/event_form', 'teams/event_show', 'teams/_calendar_item', 'partials/live_filter_feedback',
            'partials/table_pagination', 'partials/alerts', 'layouts/modal_form'], true)) {
            throw new RuntimeException('Unexpected view dependency: ' . $name);
        }
        extract($this->data, EXTR_SKIP);
        ob_start(); require $GLOBALS['root'] . '/app/Views/' . $name . '.php'; return ob_get_clean();
    }
    public function render(string $name, array $data): string {
        $this->data = $data; $html = $this->template($name);
        while ($this->layout !== null) {
            $layout = $this->layout; $this->layout = null;
            if ($layout === 'layouts/app') { return $this->shell(); }
            $html = $this->template($layout);
        }
        if ($this->stack !== []) { throw new RuntimeException('Unclosed view section'); }
        return $html;
    }
    private function shell(): string {
        $theme = ($GLOBALS['fixtureGet']['theme'] ?? $GLOBALS['fixture']['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $title = esc($this->data['title'] ?? 'Teams workspace');
        $unit = $GLOBALS['unit'];
        $owner = 'teams.' . (str_contains($GLOBALS['pathname'], '/calendar') ? 'calendar' : 'tasks') . '.' . $unit;
        $width = ($GLOBALS['fixtureGet']['container'] ?? '') === '350' ? 'max-width:350px;' : '';
        $layout = file_get_contents($GLOBALS['root'] . '/app/Views/layouts/app.php');
        if (!preg_match('~(<div class="modal fade" id="synapseConfirmModal".*?)(?=\s*<!-- Vendor JS Files -->)~s', $layout, $confirmation)) {
            throw new RuntimeException('Actual shared confirmation markup not found');
        }
        $assets = '';
        foreach (['custom-dropdown', 'datepicker', 'datetimepicker', 'loading', 'form-loading', 'auth-session', 'csrf',
            'toast', 'confirm', 'live-filters', 'form-modal', 'responsive-tables', 'page-lifecycle', 'navigation',
            'refresh-regions', 'team-chat', 'background-actions'] as $js) { $assets .= '<script src="/assets/js/' . $js . '.js"></script>'; }
        return '<!doctype html><html lang="en" data-theme="' . $theme . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="data:,"><title>' . $title . '</title>'
            . '<meta name="csrf-name" content="fixture_csrf"><meta name="csrf-token" content="' . esc(csrf_hash()) . '">'
            . '<meta name="csrf-header" content="X-Fixture-CSRF"><meta name="synapse-refresh-regions-enabled" content="1">'
            . '<meta name="synapse-refresh-region-owners" content="teams.tasks.' . $unit . ',teams.calendar.' . $unit . '">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css"><link rel="stylesheet" href="/assets/css/style.css">'
            . '<style>.app-shell #main.main{margin:0;padding:16px;width:100%;min-height:100vh;overflow:visible}.fixture-container{width:100%;' . $width . '}'
            . '#sidebar{display:none}.fixture-page-heading{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}'
            . '@media(max-width:400px){#main.main{padding:10px}}</style><div id="page-styles" hidden>' . $this->renderSection('styles') . '</div>'
            . '<script defer src="/assets/vendor/alpine/alpine.min.js"></script></head><body class="app-shell" data-navigation-scope="synthetic-teams-' . $unit . '">'
            . '<header id="header" hidden>Database-free Teams fixture</header><aside id="sidebar"><nav class="sidebar-nav">'
            . '<a class="nav-link active" href="' . esc(site_url($unit . '/teams')) . '">Teams</a></nav></aside>'
            . '<main id="main" class="main"><div class="fixture-container"><div class="fixture-page-heading"><h1 class="h4">' . $title . '</h1>'
            . $this->renderSection('page_actions') . '</div>' . $this->renderSection('content') . '</div></main>' . $confirmation[1]
            . '<script>window.fixtureRefreshEvents=[];for(const name of ["refresh_committed","refresh_failed","refresh_canceled","refresh_security_escalated"]){document.addEventListener(name,event=>fixtureRefreshEvents.push({name,detail:event.detail}));}</script>'
            . '<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . $assets
            . '<div id="page-scripts" hidden>' . $this->renderSection('scripts') . '</div><div class="navigation-feedback">'
            . '<div id="navigation-status" class="visually-hidden" role="status"></div><button id="navigation-retry" hidden>Retry navigation</button>'
            . '<a id="navigation-fallback" hidden>Open full page</a></div></body></html>';
    }
}

$members = $fixture['members'] ?? [];
$memberNames = array_column($members, 'name', 'id');
$statuses = ['todo' => 'To do', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Done'];
$priorities = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
$teamsUrl = site_url($unit . '/teams');
$baseUrl = $teamsUrl;
$actor = ['id' => 1, 'tenant_id' => 0, 'person_id' => 0];
$allTasks = $fixture['tasks'] ?? [];
$allEvents = $fixture['events'] ?? [];
$empty = !empty($fixtureGet['empty']);
if ($empty) { $allTasks = []; $allEvents = []; }
$taskById = array_column($allTasks, null, 'id');
$eventById = array_column($allEvents, null, 'id');
$shared = compact('unit', 'baseUrl', 'teamsUrl', 'actor', 'members', 'memberNames', 'statuses', 'priorities');
$targetView = 'teams/index';
$data = $shared + ['title' => ucfirst($unit) . ' Teams', 'formModalPage' => false];

if (preg_match('~/teams/(?:create|([0-9]+)(?:/(edit))?)$~D', $pathname, $match)) {
    $task = !empty($match[1]) ? ($taskById[(int) $match[1]] ?? null) : null;
    if (!empty($match[1]) && $task === null) { throw new RuntimeException('Synthetic task not found'); }
    $editing = str_ends_with($pathname, '/create') || !empty($match[2]);
    $targetView = $editing ? 'teams/form' : 'teams/show';
    $data = $shared + ['task' => $task, 'title' => $task === null ? 'New team task' : ($editing ? 'Edit team task #' : 'Team task #') . $task['id'],
        'formAction' => $baseUrl . ($task === null ? '/store' : '/' . $task['id'] . '/update'), 'cancelUrl' => $baseUrl, 'formModalPage' => true];
} elseif (str_ends_with($pathname, '/chat')) {
    // This one pure class generates data: SVGs; no ProfilePhotoService is loaded.
    require $root . '/app/Services/ProfileAvatar.php';
    $targetView = 'teams/chat';
    $data = array_replace($shared, ['baseUrl' => $teamsUrl . '/chat', 'title' => ucfirst($unit) . ' Team Chat',
        'history' => $fixture['history'], 'afterMode' => $fixture['afterMode'] ?? false,
        'browsingHistory' => $fixture['browsingHistory'] ?? false, 'targetMessageId' => $fixture['targetMessageId'] ?? null]);
} elseif (str_contains($pathname, '/calendar')) {
    $baseUrl = $teamsUrl . '/calendar'; $shared['baseUrl'] = $baseUrl;
    $timezone = new DateTimeZone('Asia/Manila');
    $date = (string) ($fixtureGet['date'] ?? '2030-01-31');
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    if (!$anchor || $anchor->format('Y-m-d') !== $date) { throw new InvalidArgumentException('Invalid fixture date'); }
    if (preg_match('~/calendar/events/(?:create|([0-9]+)(?:/(edit))?)$~D', $pathname, $match)) {
        $event = !empty($match[1]) ? ($eventById[(int) $match[1]] ?? null) : null;
        if (!empty($match[1]) && $event === null) { throw new RuntimeException('Synthetic event not found'); }
        $editing = str_ends_with($pathname, '/create') || !empty($match[2]);
        $targetView = $editing ? 'teams/event_form' : 'teams/event_show';
        $startLocal = $event ? (new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC')))->setTimezone($timezone) : $anchor->setTime(9, 0);
        $endLocal = $event ? (new DateTimeImmutable($event['ends_at'], new DateTimeZone('UTC')))->setTimezone($timezone) : $anchor->setTime(10, 0);
        $data = $shared + ['event' => $event, 'title' => $event === null ? 'New team event' : ($editing ? 'Edit team event #' : 'Team event #') . $event['id'],
            'formAction' => $baseUrl . ($event === null ? '/events/store' : '/events/' . $event['id'] . '/update'),
            'cancelUrl' => $baseUrl, 'formModalPage' => true, 'startsValue' => $startLocal->format('Y-m-d\TH:i'),
            'endsValue' => $endLocal->format('Y-m-d\TH:i'), 'startLocal' => $startLocal, 'endLocal' => $endLocal];
    } else {
        $targetView = 'teams/calendar';
        $display = in_array($fixtureGet['view'] ?? '', ['month', 'week', 'agenda'], true) ? $fixtureGet['view'] : 'month';
        $mine = ($fixtureGet['mine'] ?? '') === '1' ? '1' : '';
        $status = in_array($fixtureGet['status'] ?? '', ['scheduled', 'cancelled', 'all'], true) ? $fixtureGet['status'] : 'scheduled';
        if ($display === 'month') {
            $first = $anchor->modify('first day of this month');
            $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
            $monthEnd = $first->modify('+1 month');
            $end = $monthEnd->modify('+' . ((8 - (int) $monthEnd->format('N')) % 7) . ' days');
            $prevDate = $first->modify('-1 month')->format('Y-m-d'); $nextDate = $first->modify('+1 month')->format('Y-m-d');
        } elseif ($display === 'week') {
            $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days'); $end = $start->modify('+7 days');
            $prevDate = $anchor->modify('-7 days')->format('Y-m-d'); $nextDate = $anchor->modify('+7 days')->format('Y-m-d');
        } else {
            $start = $anchor; $end = $anchor->modify('+30 days');
            $prevDate = $anchor->modify('-30 days')->format('Y-m-d'); $nextDate = $anchor->modify('+30 days')->format('Y-m-d');
        }
        $events = array_values(array_filter($allEvents, static fn(array $event): bool => ($status === 'all' || $event['status'] === $status)
            && ($mine === '' || $event['created_by_user_id'] === 1 || in_array(1, $event['participant_ids'], true))
            && new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC')) < $end && new DateTimeImmutable($event['ends_at'], new DateTimeZone('UTC')) > $start));
        $tasks = array_values(array_filter($allTasks, static fn(array $task): bool => $task['due_date'] && $task['due_date'] >= $start->format('Y-m-d')
            && $task['due_date'] < $end->format('Y-m-d') && ($mine === '' || $task['assignee_user_id'] === 1)));
        $days = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
            $items = [];
            foreach ($events as $event) {
                $eventStart = (new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC')))->setTimezone($timezone);
                $eventEnd = (new DateTimeImmutable($event['ends_at'], new DateTimeZone('UTC')))->setTimezone($timezone);
                if ($eventStart < $cursor->modify('+1 day') && $eventEnd > $cursor) { $items[] = ['type' => 'event', 'status' => $event['status'],
                    'id' => $event['id'], 'title' => $event['title'], 'label' => $eventStart->format('Y-m-d') === $cursor->format('Y-m-d') ? $eventStart->format('g:i A') : 'Continues', 'url' => $baseUrl . '/events/' . $event['id']]; }
            }
            foreach ($tasks as $task) { if ($task['due_date'] === $cursor->format('Y-m-d')) { $items[] = ['type' => 'task', 'status' => $task['status'],
                'id' => $task['id'], 'title' => $task['title'], 'label' => 'Due', 'url' => $teamsUrl . '/' . $task['id']]; } }
            $days[$cursor->format('Y-m-d')] = ['date' => $cursor, 'items' => $items];
        }
        $data = $shared + ['title' => ucfirst($unit) . ' Team Calendar', 'date' => $date, 'view' => $display, 'mine' => $mine, 'status' => $status,
            'startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'), 'prevDate' => $prevDate, 'nextDate' => $nextDate,
            'today' => '2030-01-31', 'days' => $days, 'truncated' => false, 'totalEvents' => count($events), 'totalTasks' => count($tasks)];
    }
} else {
    $mode = ($fixtureGet['view'] ?? '') === 'list' ? 'list' : 'board';
    $filters = ['q' => (string) ($fixtureGet['q'] ?? ''), 'status' => (string) ($fixtureGet['status'] ?? ''),
        'priority' => (string) ($fixtureGet['priority'] ?? ''), 'mine' => ($fixtureGet['mine'] ?? '') === '1' ? '1' : ''];
    $tasks = array_values(array_filter($allTasks, static fn(array $task): bool => ($filters['q'] === '' || str_contains(mb_strtolower($task['title'] . ' ' . $task['description']), mb_strtolower($filters['q'])))
        && ($filters['status'] === '' || $task['status'] === $filters['status']) && ($filters['priority'] === '' || $task['priority'] === $filters['priority'])
        && ($filters['mine'] === '' || $task['assignee_user_id'] === 1)));
    $total = count($tasks); $limit = 100; $pages = max(1, (int) ceil($total / $limit)); $page = max(1, min($pages, (int) ($fixtureGet['page'] ?? 1)));
    $tasks = array_slice($tasks, ($page - 1) * $limit, $limit);
    $data += compact('mode', 'filters', 'tasks', 'total', 'limit', 'pages', 'page');
}
echo view($targetView, $data);
