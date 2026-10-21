<?php
$query = $query ?? [];
$pageKey = $pageKey ?? 'page';
?>
<nav aria-label="<?= esc($paginationLabel ?? 'Announcement pages') ?>" class="d-flex align-items-center justify-content-between gap-3 my-3">
  <span class="small text-muted"><?= (int) $pagination['total'] ?> records · Page <?= (int) $pagination['page'] ?> of <?= (int) $pagination['pages'] ?> · 25 per page</span>
  <div class="btn-group">
    <?php if ($pagination['page'] > 1): ?><a class="btn btn-outline-secondary btn-sm" rel="prev" href="<?= esc($baseUrl . '?' . http_build_query(array_merge($query, [$pageKey => $pagination['page'] - 1]))) ?>">Previous</a><?php endif; ?>
    <?php if ($pagination['page'] < $pagination['pages']): ?><a class="btn btn-outline-secondary btn-sm" rel="next" href="<?= esc($baseUrl . '?' . http_build_query(array_merge($query, [$pageKey => $pagination['page'] + 1]))) ?>">Next</a><?php endif; ?>
  </div>
</nav>
