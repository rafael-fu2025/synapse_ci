<?php
$unitLabel = ucfirst($unit);
$filters = array_replace(
    ['q' => '', 'status' => 'all', 'audience' => '', 'pinned' => ''],
    is_array($filters ?? null) ? $filters : []
);
$defaultTotal = (int) ($pagination['total'] ?? count($announcements));
$counts = array_replace(
    ['all' => $defaultTotal, 'draft' => 0, 'published' => 0, 'archived' => 0],
    is_array($counts ?? null) ? $counts : []
);
$hasActiveFilters = $filters['q'] !== ''
    || $filters['status'] !== 'all'
    || $filters['audience'] !== ''
    || $filters['pinned'] !== '';
$activeFilterQuery = array_filter([
    'q'        => $filters['q'] !== '' ? $filters['q'] : null,
    'status'   => $filters['status'] !== 'all' ? $filters['status'] : null,
    'audience' => $filters['audience'] !== '' ? $filters['audience'] : null,
    'pinned'   => $filters['pinned'] === '1' ? '1' : null,
], static fn ($v): bool => $v !== null);
$statusTabUrl = static function (string $state) use ($baseUrl, $filters): string {
    $params = ['status' => $state];
    if ($filters['q'] !== '') { $params['q'] = $filters['q']; }
    if ($filters['audience'] !== '') { $params['audience'] = $filters['audience']; }
    if ($filters['pinned'] === '1') { $params['pinned'] = '1'; }
    return $baseUrl . '?' . http_build_query($params);
};
$statusTabs = [
    'all'       => 'All',
    'draft'     => 'Draft',
    'published' => 'Published',
    'archived'  => 'Archived',
];
$filteredCount = (int) ($pagination['filtered'] ?? $pagination['total'] ?? count($announcements));
$totalCount = (int) ($pagination['total'] ?? $filteredCount);
?>
<section class="guidance-ui ga-workspace"
         data-refresh-owner="<?= esc($refreshOwner, 'attr') ?>"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
         data-live-filters
         data-filter-url="<?= esc($baseUrl, 'attr') ?>"
         data-filter-key="announcements-<?= esc($unit, 'attr') ?>">
  <div class="ga-sheet">
    <header class="ga-band ga-intro">
      <h2 class="ga-intro-title">Announcement management</h2>
      <p class="ga-intro-desc">Prepare <?= esc($unitLabel) ?> notices, choose who can see them, and publish updates to the portal.</p>
      <div class="ga-notice" role="note">
        <svg class="ga-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><rect x="9" y="10" width="6" height="5" rx="1"/><path d="M10 10V8a2 2 0 0 1 4 0v2"/></svg>
        <p>Saving creates a draft only. Published text, audience, and visibility dates cannot be edited; archive and create a new announcement for corrections.</p>
      </div>
    </header>

    <!-- Search & Filter Toolbar -->
    <div class="ga-filter-band">
      <form method="get"
            action="<?= esc($baseUrl, 'attr') ?>"
            class="ga-filter-form"
            role="search"
            aria-label="Search and filter <?= esc($unitLabel) ?> announcements"
            data-filter-form>
        <input type="hidden" name="status" value="<?= esc($filters['status'], 'attr') ?>">

        <div class="ga-filter-field ga-filter-field--search">
          <label for="ga-announcement-search" class="ga-filter-label">Search announcements</label>
          <div class="ga-search-wrap">
            <i class="bi bi-search ga-search-icon" aria-hidden="true"></i>
            <input id="ga-announcement-search"
                   type="search"
                   name="q"
                   class="form-control form-control-sm ga-search-input"
                   maxlength="120"
                   placeholder="Search title or message..."
                   value="<?= esc($filters['q'], 'attr') ?>">
          </div>
        </div>

        <div class="ga-filter-field ga-filter-field--select">
          <label for="ga-announcement-audience" class="ga-filter-label">Audience</label>
          <select id="ga-announcement-audience" name="audience" class="form-select form-select-sm ga-filter-select">
            <option value="" <?= $filters['audience'] === '' ? 'selected' : '' ?>>All audiences</option>
            <option value="all" <?= $filters['audience'] === 'all' ? 'selected' : '' ?>>All students &amp; employees</option>
            <option value="students" <?= $filters['audience'] === 'students' ? 'selected' : '' ?>>Students</option>
            <option value="employees" <?= $filters['audience'] === 'employees' ? 'selected' : '' ?>>Employees</option>
          </select>
        </div>

        <div class="ga-filter-field ga-filter-field--select">
          <label for="ga-announcement-pinned" class="ga-filter-label">Advisory type</label>
          <select id="ga-announcement-pinned" name="pinned" class="form-select form-select-sm ga-filter-select">
            <option value="" <?= $filters['pinned'] === '' ? 'selected' : '' ?>>All notices</option>
            <option value="1" <?= $filters['pinned'] === '1' ? 'selected' : '' ?>>Pinned advisories only</option>
          </select>
        </div>

        <div class="ga-filter-actions">
          <button class="btn btn-primary btn-sm ga-btn-search" type="submit">
            <i class="bi bi-search" aria-hidden="true"></i> Search
          </button>
          <a href="<?= esc($baseUrl, 'attr') ?>" class="btn btn-outline-secondary btn-sm ga-btn-reset" data-filter-reset>Reset</a>
        </div>
      </form>

      <div class="ga-filter-feedback">
        <p class="ga-filter-status" data-filter-status role="status" aria-live="polite"></p>
        <div class="ga-filter-error" data-filter-error role="alert" hidden>
          <span data-filter-error-text></span>
          <button class="btn btn-outline-danger btn-sm" type="button" data-filter-retry>Retry</button>
        </div>
      </div>
    </div>

    <div class="ga-results-region" data-refresh-region="announcements.list" data-filter-region="list">
      <div class="ga-results-head">
        <div class="ga-results-head-left">
          <h3 class="ga-results-title">Announcement library</h3>
          <nav class="ga-status-tabs" aria-label="Filter announcements by status">
            <?php foreach ($statusTabs as $sKey => $sLabel):
                $isActiveTab = $filters['status'] === $sKey;
            ?>
              <a href="<?= esc($statusTabUrl($sKey), 'attr') ?>"
                 class="ga-status-tab<?= $isActiveTab ? ' is-active' : '' ?>"
                 data-filter-link
                 <?= $isActiveTab ? 'aria-current="page"' : '' ?>>
                <span><?= esc($sLabel) ?></span>
                <span class="ga-status-tab-count"><?= number_format((int) ($counts[$sKey] ?? 0)) ?></span>
              </a>
            <?php endforeach; ?>
          </nav>
        </div>
        <span class="ga-count">
          <?php if ($hasActiveFilters && $filteredCount !== $totalCount): ?>
            <?= number_format($filteredCount) ?> of <?= number_format($totalCount) ?> announcements
          <?php else: ?>
            <?= number_format($filteredCount) ?> announcements
          <?php endif; ?>
        </span>
      </div>

      <?php if ($announcements === []): ?>
        <div class="ga-empty" role="status">
          <svg class="ga-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m4 9 15-5v14L4 13H2V9h2ZM7 14l1 6H5l-1-7M19 8l3-1M19 14l3 1"/></svg>
          <?php if ($hasActiveFilters): ?>
            <h3>No matching announcements.</h3>
            <p>No <?= esc($unitLabel) ?> notices matched your current search or filter criteria.</p>
            <div class="ga-empty-action">
              <a href="<?= esc($baseUrl, 'attr') ?>" class="btn btn-outline-secondary btn-sm" data-filter-reset>Reset filters</a>
            </div>
          <?php else: ?>
            <h3>No announcements yet.</h3>
            <p>Create a draft to prepare your first <?= esc($unitLabel) ?> notice.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="ga-table-wrap">
          <table class="ga-table ga-announcement-table" role="table">
            <caption class="visually-hidden"><?= esc($unitLabel) ?> announcements, newest first</caption>
            <thead role="rowgroup"><tr role="row"><th scope="col">Announcement</th><th scope="col">Audience</th><th scope="col">Status &amp; visibility</th><th scope="col">Actions</th></tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($announcements as $notice): $url = $baseUrl . '/' . (int) $notice['id']; ?>
                <tr role="row">
                  <td class="ga-announcement-cell" role="cell">
                    <strong class="ga-announcement-title"><?= esc($notice['title']) ?></strong>
                    <?php if (!empty($notice['is_pinned_advisory'])): ?>
                      <span class="ga-advisory"><svg class="ga-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m16 3 5 5-5 2-2 4-4-4 4-2 2-5ZM10 10l-4 4M5 15l-3 7 7-3"/></svg>Pinned advisory</span>
                    <?php endif; ?>
                    <details class="ga-preview">
                      <summary>Preview text<span class="visually-hidden">: <?= esc($notice['title']) ?></span></summary>
                      <div class="ga-preview-text"><?= esc($notice['body']) ?></div>
                    </details>
                  </td>
                  <td class="ga-audience-cell" role="cell">
                    <span class="ga-cell-label" aria-hidden="true">Audience</span>
                    <span><?= esc(ucfirst($notice['audience'])) ?></span>
                    <?= view('announcements/_targeting_summary', ['targetRecord' => $notice], ['saveData' => false]) ?>
                  </td>
                  <td class="ga-visibility-cell" role="cell">
                    <span class="ga-cell-label" aria-hidden="true">Status &amp; visibility</span>
                    <span class="ga-status is-<?= esc($notice['status'], 'attr') ?>"><?= esc(ucfirst($notice['status'])) ?></span>
                    <dl class="ga-visibility">
                      <div><dt>From</dt><dd><?= esc($notice['starts_at'] === null ? 'On publication' : $announcementDate($notice['starts_at'])) ?></dd></div>
                      <div><dt>Until</dt><dd><?= esc($notice['ends_at'] === null ? 'No expiry' : $announcementDate($notice['ends_at'])) ?></dd></div>
                    </dl>
                    <span class="ga-timezone">Asia/Manila</span>
                  </td>
                  <td class="ga-actions-cell" role="cell">
                    <span class="ga-cell-label" aria-hidden="true">Actions</span>
                    <div class="ga-row-actions">
                      <?php if ($notice['status'] === 'draft'): ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?= esc($url . '/edit') ?>" data-app-modal-open>Edit draft</a>
                        <form action="<?= esc($url . '/publish') ?>" method="POST" data-background-submit data-confirm="Publish this announcement to the selected audience? Its text, audience and visibility dates cannot be edited afterward." data-confirm-title="Publish announcement" data-confirm-label="Publish announcement" data-confirm-tone="warning"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $notice['version'] ?>"><button class="btn btn-primary btn-sm" type="submit">Publish</button></form>
                      <?php endif; ?>
                      <?php if ($notice['status'] !== 'archived'): ?>
                        <form action="<?= esc($url . '/archive') ?>" method="POST" data-background-submit data-confirm="Archive this announcement? It will no longer be visible in the portal and cannot be republished." data-confirm-title="Archive announcement" data-confirm-label="Archive announcement" data-confirm-tone="warning"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $notice['version'] ?>"><button class="btn btn-outline-secondary btn-sm" type="submit">Archive</button></form>
                      <?php endif; ?>
                      <?php if ($notice['published_at'] !== null): ?><a class="btn btn-outline-secondary btn-sm" href="<?= esc($baseUrl . '?' . http_build_query($activeFilterQuery + ['reads' => $notice['id'], 'page' => $pagination['page']])) ?>">Read history</a><?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="ga-pagination-band">
        <?= view('partials/table_pagination', [
            'pagination' => $pagination,
            'baseUrl' => $baseUrl,
            'filters' => [],
            'paginationExtraQuery' => $activeFilterQuery + (isset($readHistory) ? ['reads' => $readHistory['announcement']['id'], 'read_page' => $readHistory['pagination']['page']] : []),
            'paginationLabel' => 'Announcement pages',
            'totalLabel' => 'total announcements',
        ], ['saveData' => false]) ?>
      </div>
    </div>
    <div data-refresh-region="announcements.read-history" data-filter-region="read-history">
      <?php if (isset($readHistory)): ?>
        <section class="ga-read-history" aria-labelledby="ga-read-title">
          <header class="ga-band">
            <h3 id="ga-read-title" class="ga-results-title">Read history</h3>
            <p class="ga-read-announcement"><?= esc($readHistory['announcement']['title']) ?></p>
            <p class="ga-intro-desc">A read marker is not acknowledgement or proof of comprehension.</p>
          </header>
          <div class="ga-table-wrap">
            <table class="ga-table ga-read-table" role="table">
              <caption class="visually-hidden">People who marked this announcement as read</caption>
              <thead role="rowgroup"><tr role="row"><th scope="col">Person</th><th scope="col">Marked read at (Asia/Manila)</th></tr></thead>
              <tbody role="rowgroup">
                <?php foreach ($readHistory['reads'] as $read): ?>
                  <tr role="row"><td role="cell"><?= esc($read['first_name'] . ' ' . $read['last_name']) ?></td><td role="cell"><span class="ga-cell-label" aria-hidden="true">Marked read · Asia/Manila</span><span class="ga-timestamp"><?= esc($announcementDate($read['read_at'])) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if ($readHistory['reads'] === []): ?><tr role="row"><td colspan="2" class="ga-empty-cell" role="cell">No read markers yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="ga-pagination-band">
            <?= view('partials/table_pagination', [
                'pagination' => $readHistory['pagination'],
                'baseUrl' => $baseUrl,
                'filters' => [],
                'paginationPageParam' => 'read_page',
                'paginationLabel' => 'Read history pages',
                'paginationExtraQuery' => $activeFilterQuery + ['reads' => $readHistory['announcement']['id'], 'page' => $pagination['page']],
            ], ['saveData' => false]) ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</section>
