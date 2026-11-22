<?php
$contentPages = max(1, (int) ($pagination['pages'] ?? 1));
$contentPage = max(1, min($contentPages, (int) ($pagination['page'] ?? 1)));
$contentCount = max(0, (int) ($pagination['filtered'] ?? 0));
$contentSize = max(1, (int) ($pagination['per_page'] ?? 12));
$contentFrom = $contentCount === 0 ? 0 : ($contentPage - 1) * $contentSize + 1;
$contentTo = min($contentPage * $contentSize, $contentCount);
$contentQuery = array_intersect_key($filters ?? [], array_flip(['q', 'status', 'unit']));
$contentLiveFilters = !empty($liveFilters);
$contentUrl = static fn (int $page): string => $baseUrl . '?' . http_build_query($contentQuery + ['page' => $page, 'per_page' => $contentSize], '', '&', PHP_QUERY_RFC3986);
$contentWindowStart = max(1, $contentPage - 2);
$contentWindowEnd = min($contentPages, $contentPage + 2);
?>
<div class="pe-pagination" data-page="<?= $contentPage ?>" data-per-page="<?= $contentSize ?>" data-filtered="<?= $contentCount ?>">
  <p class="small text-muted mb-0">Showing <?= number_format($contentFrom) ?>–<?= number_format($contentTo) ?> of <?= number_format($contentCount) ?></p>
  <nav aria-label="<?= esc($paginationLabel ?? 'Content pages') ?>">
    <ul class="pagination pagination-sm flex-wrap mb-0">
      <?php foreach (['First' => 1, 'Previous' => max(1, $contentPage - 1)] as $linkLabel => $target): ?>
        <?php if ($contentPage === 1): ?>
          <li class="page-item disabled"><span class="page-link" aria-disabled="true"><?= esc($linkLabel) ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= esc($contentUrl($target)) ?>"<?= $linkLabel === 'Previous' ? ' rel="prev"' : '' ?><?= $contentLiveFilters ? ' data-filter-link' : '' ?>><?= esc($linkLabel) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php for ($target = $contentWindowStart; $target <= $contentWindowEnd; $target++): ?>
        <?php if ($target === $contentPage): ?>
          <li class="page-item active"><span class="page-link" aria-current="page"><?= number_format($target) ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= esc($contentUrl($target)) ?>"<?= $contentLiveFilters ? ' data-filter-link' : '' ?> aria-label="Page <?= $target ?>"><?= number_format($target) ?></a></li>
        <?php endif; ?>
      <?php endfor; ?>
      <?php foreach (['Next' => min($contentPages, $contentPage + 1), 'Last' => $contentPages] as $linkLabel => $target): ?>
        <?php if ($contentPage === $contentPages): ?>
          <li class="page-item disabled"><span class="page-link" aria-disabled="true"><?= esc($linkLabel) ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= esc($contentUrl($target)) ?>"<?= $linkLabel === 'Next' ? ' rel="next"' : '' ?><?= $contentLiveFilters ? ' data-filter-link' : '' ?>><?= esc($linkLabel) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </nav>
</div>
