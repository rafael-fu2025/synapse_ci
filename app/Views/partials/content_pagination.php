<?php
$contentPage = (int) $pagination['page'];
$contentPages = (int) $pagination['pages'];
$contentCount = (int) $pagination['filtered'];
$contentSize = (int) $pagination['per_page'];
$contentFrom = $contentCount === 0 ? 0 : ($contentPage - 1) * $contentSize + 1;
$contentTo = min($contentPage * $contentSize, $contentCount);
$contentQuery = array_intersect_key($filters ?? [], array_flip(['q', 'status', 'unit']));
$contentUrl = static fn (int $page): string => $baseUrl . '?' . http_build_query($contentQuery + ['page' => $page, 'per_page' => $contentSize], '', '&', PHP_QUERY_RFC3986);
?>
<div class="pe-pagination">
  <p class="small text-muted mb-0">Showing <?= number_format($contentFrom) ?>–<?= number_format($contentTo) ?> of <?= number_format($contentCount) ?></p>
  <nav aria-label="<?= esc($paginationLabel ?? 'Content pages') ?>" class="d-flex align-items-center flex-wrap gap-2">
    <?php if ($contentPage > 1): ?><a class="btn btn-outline-secondary btn-sm" rel="prev"<?= !empty($liveFilters) ? ' data-filter-link' : '' ?> href="<?= esc($contentUrl($contentPage - 1)) ?>">Previous</a><?php endif; ?>
    <span class="small text-muted">Page <?= $contentPage ?> of <?= $contentPages ?></span>
    <?php if ($contentPage < $contentPages): ?><a class="btn btn-outline-secondary btn-sm" rel="next"<?= !empty($liveFilters) ? ' data-filter-link' : '' ?> href="<?= esc($contentUrl($contentPage + 1)) ?>">Next</a><?php endif; ?>
  </nav>
</div>
