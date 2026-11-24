<?php

declare(strict_types=1);

// CLI-only synthetic display using the production modal layout; no app, session, or DB bootstrap.
if (PHP_SAPI !== 'cli') exit(1);
$fixture = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
$origin = $fixture['origin'] ?? '';
if (!is_string($origin) || !preg_match('~\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z~D', $origin)) exit(2);
$mode = $fixture['mode'] ?? 'delayed';
if (!in_array($mode, ['delayed', 'failure', 'close', 'paging'], true)) exit(3);
$page = ($fixture['page'] ?? 1) === 2 ? 2 : 1;
function esc($value, $context = 'html'): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function service(string $name): object {
    if ($name !== 'request') throw new RuntimeException('Unexpected fixture service');
    return new class {
        public function getGet(string $name): ?string { return $name === 'fragment' ? '1' : null; }
    };
}
final class ModalStyleReadinessFixture {
    public function __construct(private readonly array $sections) {}
    public function renderSection(string $name): string { return $this->sections[$name] ?? ''; }
    public function include(string $name): string {
        if ($name !== 'partials/alerts') throw new RuntimeException('Unexpected fixture include');
        return '';
    }
    public function render(string $title, string $cancelUrl): string {
        $modalSize = 'modal-lg';
        ob_start();
        require dirname(__DIR__, 2) . '/app/Views/layouts/modal_form.php';
        return ob_get_clean();
    }
}
if (($fixture['host'] ?? false) === true): ?>
<!doctype html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modal style readiness — synthetic test only</title>
<link rel="stylesheet" href="<?= esc($origin) ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
<template id="page-styles" data-page-style-owner="head"></template>
<style>.host-state { background: rgb(235, 243, 255); padding: 24px; border-radius: 12px; } .modal-ready-panel { padding: 8px; }</style>
<template data-page-styles-end></template>
</head><body>
<main id="main" class="container py-4">
<h1 class="h3">SYNTHETIC TEST ONLY</h1>
<section class="host-state mb-4" aria-label="Current screen"><h2 class="h5">Current screen stays mounted</h2>
<form method="post" action="<?= esc($origin) ?>/never-write"><label for="host-note" class="form-label">Current screen draft</label><input id="host-note" name="note" class="form-control"></form></section>
<nav class="d-flex flex-wrap gap-2" aria-label="Read-only modal fixtures">
<?php foreach (['delayed' => 'Open styled form', 'failure' => 'Open failing form', 'close' => 'Open slow form', 'paging' => 'Open styled history'] as $name => $label): ?>
<a class="btn btn-primary" href="<?= esc($origin . '/modal/readiness?mode=' . $name) ?>" data-app-modal-open><?= esc($label) ?></a>
<?php endforeach; ?>
</nav>
<p class="mt-3 text-muted">All writes and off-site requests are refused by the isolated fixture server.</p>
</main>
<script src="<?= esc($origin) ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= esc($origin) ?>/assets/js/page-styles.js"></script>
<script src="<?= esc($origin) ?>/assets/js/form-modal.js"></script>
</body></html>
<?php else:
    $styleUrl = $origin . '/assets/modal-style-readiness/' . $mode . '-' . $page . '.css';
    $styles = '<link rel="stylesheet" href="' . esc($styleUrl) . '">';
    // Global host CSS and scripts are deliberately outside the modal metadata.
    echo '<link rel="stylesheet" href="' . esc($origin) . '/assets/modal-style-readiness/never-global.css">';
    echo '<script src="' . esc($origin) . '/assets/modal-style-readiness/never-script.js"></script>';
    $nextUrl = $origin . '/modal/readiness?mode=' . $mode . '&page=2';
    $content = '<section class="modal-ready-panel" data-modal-ready-panel><h2 class="h5">Styled history page ' . $page . '</h2>'
        . '<p>Final form content appears only when all of its CSS is ready.</p>'
        . '<form method="post" action="' . esc($origin) . '/never-write"><label for="modal-note" class="form-label">Modal draft</label>'
        . '<input id="modal-note" name="note" class="form-control"><div class="form-actions mt-3">'
        . '<button type="button" class="btn btn-secondary" data-app-modal-close>Cancel</button></div></form>';
    if ($page === 1) $content .= '<nav class="mt-3" aria-label="History pages" data-table-pagination data-page-param="page" data-per-page-param="per_page">'
        . '<a class="btn btn-outline-primary" href="' . esc($nextUrl) . '" data-app-modal-page>Next history page</a></nav>';
    $content .= '</section>';
    echo (new ModalStyleReadinessFixture(['styles' => $styles, 'content' => $content]))->render('Synthetic styled form', $origin . '/');
endif;
