<?php
$tableMeta = $pagination ?? [];
$tablePerPage = (int) ($tableMeta['per_page'] ?? $filters['per_page'] ?? 25);
$tablePerPage = in_array($tablePerPage, [25, 50, 100], true) ? $tablePerPage : 25;
$tableFiltered = max(0, (int) ($tableMeta['filtered'] ?? $rowCount ?? 0));
$tableTotal = max(0, (int) ($tableMeta['total'] ?? $tableFiltered));
$tablePages = max(1, (int) ($tableMeta['pages'] ?? ceil($tableFiltered / $tablePerPage)));
$tablePage = max(1, min($tablePages, (int) ($tableMeta['page'] ?? 1)));
$tableFrom = (int) ($tableMeta['from'] ?? ($tableFiltered === 0 ? 0 : ($tablePage - 1) * $tablePerPage + 1));
$tableTo = (int) ($tableMeta['to'] ?? min($tablePage * $tablePerPage, $tableFiltered));
$tableFilters = array_intersect_key($filters ?? [], array_flip(['q', 'kind', 'status']));
$tablePageUrl = static fn (int $target): string => $baseUrl . '?' . http_build_query(
    array_merge($paginationExtraQuery ?? [], $tableFilters, ['page' => $target, 'per_page' => $tablePerPage]),
    '', '&', PHP_QUERY_RFC3986,
);
$tableWindowStart = max(1, $tablePage - 2);
$tableWindowEnd = min($tablePages, $tablePage + 2);
?>
<div data-table-pagination data-page="<?= $tablePage ?>" data-per-page="<?= $tablePerPage ?>" data-filtered="<?= $tableFiltered ?>" data-total="<?= $tableTotal ?>" class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mt-3">
  <p class="small text-muted mb-0">
    Showing <?= number_format($tableFrom) ?>–<?= number_format($tableTo) ?> of <?= number_format($tableFiltered) ?>
    <?php if ($tableFiltered !== $tableTotal): ?>
      (<?= number_format($tableTotal) ?> <?= esc($totalLabel ?? 'total records') ?>)
    <?php endif; ?>
  </p>
  <nav aria-label="<?= esc($paginationLabel ?? 'Table pages') ?>">
    <ul class="pagination pagination-sm flex-wrap mb-0">
      <?php foreach (['First' => 1, 'Previous' => max(1, $tablePage - 1)] as $tableLinkLabel => $tableTarget): ?>
        <?php if ($tablePage === 1): ?>
          <li class="page-item disabled"><span class="page-link" aria-disabled="true"><?= esc($tableLinkLabel) ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= esc($tablePageUrl($tableTarget)) ?>" data-table-page="<?= $tableTarget ?>"><?= esc($tableLinkLabel) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php for ($tableTarget = $tableWindowStart; $tableTarget <= $tableWindowEnd; $tableTarget++): ?>
        <?php if ($tableTarget === $tablePage): ?>
          <li class="page-item active"><span class="page-link" aria-current="page"><?= number_format($tableTarget) ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= esc($tablePageUrl($tableTarget)) ?>" data-table-page="<?= $tableTarget ?>" aria-label="Page <?= $tableTarget ?>"><?= number_format($tableTarget) ?></a></li>
        <?php endif; ?>
      <?php endfor; ?>
      <?php foreach (['Next' => min($tablePages, $tablePage + 1), 'Last' => $tablePages] as $tableLinkLabel => $tableTarget): ?>
        <?php if ($tablePage === $tablePages): ?>
          <li class="page-item disabled"><span class="page-link" aria-disabled="true"><?= esc($tableLinkLabel) ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= esc($tablePageUrl($tableTarget)) ?>" data-table-page="<?= $tableTarget ?>"><?= esc($tableLinkLabel) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </nav>
</div>
