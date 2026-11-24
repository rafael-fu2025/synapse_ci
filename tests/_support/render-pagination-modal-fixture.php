<?php

declare(strict_types=1);

// Actual pagination partial and disposable modal markup; no application/database bootstrap.
if (PHP_SAPI !== 'cli') exit(1);
$input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
$origin = $input['origin'] ?? '';
if (!is_string($origin) || !preg_match('~\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z~D', $origin)) exit(2);
$comments = max(1, min(4, (int) ($input['comments_page'] ?? 1)));
$activity = max(1, min(3, (int) ($input['activity_page'] ?? 1)));
$fragment = ($input['fragment'] ?? false) === true;
function esc($value, $context = 'html'): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pagination(array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__, 2) . '/app/Views/partials/table_pagination.php';
    return ob_get_clean();
}
$meta = static fn (int $page, int $total): array => [
    'page' => $page, 'per_page' => 20, 'filtered' => $total, 'total' => $total,
    'pages' => max(1, (int) ceil($total / 20)), 'from' => ($page - 1) * 20 + 1,
    'to' => min($page * 20, $total),
];
?>
<?php if (!$fragment): ?>
<!doctype html><html lang="en" data-theme="<?= ($input['theme'] ?? '') === 'dark' ? 'dark' : 'light' ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= esc($origin) ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= esc($origin) ?>/assets/css/style.css">
<title>Synthetic history pagination</title></head><body>
<main id="main"><h1>SYNTHETIC TEST ONLY</h1><a href="<?= esc($origin) ?>/tasks/7" data-app-modal-open>Open task history</a></main>
<?php endif; ?>
<?php if ($fragment): ?>
<div class="modal" id="sourceModal" tabindex="-1" data-app-form-modal data-return-url="<?= esc($origin) ?>/">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="appFormModalTitle">Synthetic task history</h2><button class="btn-close" type="button" data-app-modal-close aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="alert alert-danger d-none" data-modal-error role="alert"></div>
      <h3 class="h6">Discussion</h3><p data-comments-page>Comments page <?= $comments ?></p>
      <?= pagination(['baseUrl' => $origin . '/tasks/7', 'pagination' => $meta($comments, 65),
          'paginationPageParam' => 'comments_page', 'paginationPerPageParam' => 'comments_per_page',
          'paginationExtraQuery' => ['activity_page' => $activity, 'activity_per_page' => 20],
          'paginationLabel' => 'Discussion pages', 'paginationModalPages' => true]) ?>
      <h3 class="h6 mt-4">Activity</h3><p data-activity-page>Activity page <?= $activity ?></p>
      <?= pagination(['baseUrl' => $origin . '/tasks/7', 'pagination' => $meta($activity, 45),
          'paginationPageParam' => 'activity_page', 'paginationPerPageParam' => 'activity_per_page',
          'paginationExtraQuery' => ['comments_page' => $comments, 'comments_per_page' => 20],
          'paginationLabel' => 'Activity pages', 'paginationModalPages' => true]) ?>
      <form method="post" action="<?= esc($origin) ?>/never-write"><label for="comment">Comment draft</label><input id="comment" name="comment" class="form-control"><button class="btn btn-primary mt-2">Save comment</button></form>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-app-modal-close>Close task</button></div>
  </div></div>
</div>
<?php endif; ?>
<?php if (!$fragment): ?>
<div class="modal" id="synapseConfirmModal" tabindex="-1" aria-labelledby="confirmTitle"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 id="confirmTitle" class="modal-title h5" data-confirm-title-text>Confirm</h2><button type="button" class="btn-close" aria-label="Close confirmation"></button></div><div class="modal-body"><p data-confirm-message></p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-confirm-cancel>Keep edits</button><button type="button" class="btn btn-warning" data-confirm-accept>Discard and change page</button></div></div></div></div>
<script src="<?= esc($origin) ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= esc($origin) ?>/assets/js/confirm.js"></script>
<script src="<?= esc($origin) ?>/assets/js/form-modal.js"></script>
</body></html>
<?php endif; ?>
