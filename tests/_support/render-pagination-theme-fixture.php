<?php

declare(strict_types=1);

// Synthetic, CLI-only renderer of the actual shared partials. No app bootstrap or DB.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$input = json_decode(stream_get_contents(STDIN, 16384), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($input) || array_diff(array_keys($input), ['origin', 'theme', 'table_page', 'content_page'])) {
    exit(2);
}
$origin = $input['origin'] ?? '';
if (!is_string($origin) || !preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D', $origin, $port) || (int) $port[1] > 65535) {
    exit(2);
}
$theme = $input['theme'] ?? 'light';
if (!in_array($theme, ['light', 'dark', 'legacy-dark'], true)) {
    exit(2);
}
$tablePage = (int) ($input['table_page'] ?? 1);
$contentPage = (int) ($input['content_page'] ?? 1);
if ($tablePage < 1 || $tablePage > 5 || $contentPage < 1 || $contentPage > 5) {
    exit(2);
}
function esc($value, $context = 'html'): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function renderPagination(string $kind, array $data): string
{
    // Deliberately allow only these two local production partials.
    if (!in_array($kind, ['table', 'content'], true)) {
        throw new InvalidArgumentException('Unknown synthetic partial');
    }
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__, 2) . '/app/Views/partials/' . $kind . '_pagination.php';
    return (string) ob_get_clean();
}
$filters = ['q' => 'synthetic only', 'kind' => 'sample', 'status' => 'open', 'unit' => 'test unit'];
$common = [
    'baseUrl' => $origin . '/pagination-theme', 'filters' => $filters,
    'paginationFragment' => 'theme-records',
];
$tableData = array_merge($common, [
    'pagination' => ['page' => $tablePage, 'pages' => 5, 'per_page' => 25, 'filtered' => 105, 'total' => 125],
    'paginationPageParam' => 'table_page', 'paginationPerPageParam' => 'table_per_page',
    'paginationExtraQuery' => ['theme' => $theme, 'content_page' => $contentPage, 'content_per_page' => 12],
    'paginationLiveFilters' => true, 'paginationNavigation' => true, 'totalLabel' => 'synthetic records',
]);
$contentData = array_merge($common, [
    'pagination' => ['page' => $contentPage, 'pages' => 5, 'per_page' => 12, 'filtered' => 58],
    'paginationPageParam' => 'content_page', 'paginationPerPageParam' => 'content_per_page',
    'paginationExtraQuery' => ['theme' => $theme, 'table_page' => $tablePage, 'table_per_page' => 25],
    'liveFilters' => true,
]);
?>
<!doctype html>
<html lang="en"<?= $theme === 'legacy-dark' ? '' : ' data-theme="' . esc($theme) . '"' ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Synthetic pagination theme fixture</title>
  <link rel="stylesheet" href="<?= esc($origin) ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= esc($origin) ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?= esc($origin) ?>/assets/css/pages/guidance-mi-registry.css">
</head>
<body<?= $theme === 'legacy-dark' ? ' class="dark-mode"' : '' ?>>
<main class="container-fluid p-3" id="theme-records">
  <header class="mb-3">
    <h1 class="h4">SYNTHETIC TEST ONLY — pagination colors</h1>
    <p class="small text-muted mb-2">Actual shared partials; no application, database, or real records.</p>
    <nav aria-label="Synthetic themes" class="d-flex flex-wrap gap-2 mb-2">
      <?php foreach (['light' => 'Light theme', 'dark' => 'Modern dark theme', 'legacy-dark' => 'Legacy dark theme'] as $mode => $label): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= esc($origin) ?>/pagination-theme?theme=<?= esc($mode) ?>"><?= esc($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <button class="btn btn-sm btn-secondary" type="button" data-bs-toggle="modal" data-bs-target="#themePagerModal">Open modal pager</button>
  </header>

  <section class="card mb-3" data-theme-card="generic" aria-labelledby="genericTitle">
    <div class="card-body p-3">
      <h2 class="h6" id="genericTitle">Generic table — unchanged card surface</h2>
      <button class="btn btn-sm btn-light" type="button">Focus generic pager</button>
      <?= renderPagination('table', array_merge($tableData, ['paginationLabel' => 'Generic table pages'])) ?>
    </div>
  </section>

  <section class="adm-workspace mb-3" aria-labelledby="adminTitle">
    <div class="adm-sheet" data-theme-card="admin">
      <div class="adm-band py-2"><h2 class="h6 mb-0" id="adminTitle">Admin table — unchanged sheet and footer</h2></div>
      <div class="adm-pagination-band">
        <button class="btn btn-sm btn-light mb-2" type="button">Focus admin pager</button>
        <?= renderPagination('table', array_merge($tableData, ['paginationLabel' => 'Admin table pages'])) ?>
      </div>
    </div>
  </section>

  <section class="ce-workspace gmi-workspace mb-3" aria-labelledby="miTitle">
    <div class="ce-card gmi-ledger" data-theme-card="mi">
      <div class="ce-card-body py-2"><h2 class="h6 mb-0" id="miTitle">MI registry — unchanged ledger and footer</h2></div>
      <div class="gmi-pagination">
        <button class="btn btn-sm btn-light mt-2" type="button">Focus mi pager</button>
        <?= renderPagination('table', array_merge($tableData, ['paginationLabel' => 'MI registry pages'])) ?>
      </div>
    </div>
  </section>

  <section class="portal-engagement mb-3" aria-labelledby="portalTitle">
    <article class="card pe-content-card" data-theme-card="portal"><div class="card-body p-3">
      <h2 class="h6" id="portalTitle">Portal content — unchanged white card</h2>
      <button class="btn btn-sm btn-light" type="button">Focus portal pager</button>
      <?= renderPagination('content', array_merge($contentData, ['paginationLabel' => 'Portal content pages'])) ?>
    </div></article>
  </section>

  <details>
    <summary>Legacy pagers without shared hook</summary>
    <div class="adm-workspace mt-2"><div class="adm-sheet p-3" data-theme-card="legacy-admin">
      <?= str_replace(' synapse-pagination', '', renderPagination('table', array_merge($tableData, ['paginationLabel' => 'Legacy admin pages']))) ?>
    </div></div>
    <div class="ce-workspace mt-2"><div class="ce-card p-3" data-theme-card="legacy-mi"><div class="gmi-pagination">
      <?= str_replace(' synapse-pagination', '', renderPagination('table', array_merge($tableData, ['paginationLabel' => 'Legacy MI pages']))) ?>
    </div></div></div>
  </details>
</main>

<div class="modal fade" id="themePagerModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content" data-theme-card="modal">
    <div class="modal-header"><h2 class="modal-title h5" id="modalTitle">Synthetic modal history</h2>
      <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close modal pager"></button>
    </div>
    <div class="modal-body">
      <p class="small text-muted">The dialog surface and independent history cursor are unchanged.</p>
      <button class="btn btn-sm btn-light" type="button">Focus modal pager</button>
      <?= renderPagination('table', array_merge($tableData, ['paginationLabel' => 'Modal history pages', 'paginationModalPages' => true])) ?>
    </div>
  </div></div>
</div>
<script src="<?= esc($origin) ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
