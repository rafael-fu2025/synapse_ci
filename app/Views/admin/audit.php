<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<form action="<?= site_url('/admin/audit/verify') ?>" method="POST" class="d-inline" data-background-submit>
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-primary btn-sm">
    <i class="bi bi-shield-check me-1"></i> Verify Cryptographic Chain
  </button>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-audit-page adm-workspace adm-audit-workspace" data-refresh-owner="audit.ledger" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
<?php
$filters = $filters ?? [];
$filterErrors = $filterErrors ?? [];
$pagination = $pagination ?? [
    'newer_url' => null,
    'older_url' => null,
    'first_url' => site_url('/admin/audit'),
];
$hasFilters = false;
foreach (['event_type', 'action', 'entity_type', 'entity_id', 'actor_user_id', 'from', 'to'] as $key) {
    if (($filters[$key] ?? '') !== '') {
        $hasFilters = true;
        break;
    }
}
?>

<div class="adm-sheet">
  <!-- Intro & Cryptographic Chain Status Band -->
  <div class="adm-band adm-audit-intro-band">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <p class="adm-intro-desc mb-0">
        Append-only SHA-256 hash-chained log for clinical, governance, and facilities mutations.
        Verification checks the whole tenant chain, not the current filtered page. No external checkpoint is used.
      </p>
      <div class="adm-audit-badge-wrap flex-shrink-0">
        <span class="adm-status is-active">
          <span>SHA-256 Chain Secured</span>
        </span>
      </div>
    </div>
  </div>

  <!-- Filter Band -->
  <div class="adm-band adm-audit-filters-band">
    <div data-refresh-region="filter-feedback">
    <?php if (! empty($filterErrors)): ?>
      <div class="alert alert-danger adm-audit-alert mb-3" role="alert">
        <p class="fw-bold mb-1">Please correct the audit filters:</p>
        <ul class="mb-0 ps-3">
          <?php foreach ($filterErrors as $error): ?>
            <li><?= esc($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    </div>

    <form action="<?= esc(site_url('/admin/audit'), 'attr') ?>" method="GET" class="adm-audit-filter-grid row g-2" aria-label="Filter audit ledger">
      <?php foreach ([
          'event_type' => 'Event type',
          'action' => 'Action',
          'entity_type' => 'Entity type',
          'entity_id' => 'Entity ID',
          'actor_user_id' => 'Actor user ID',
      ] as $key => $label): ?>
        <div class="col-12 col-sm-6 col-lg-3">
          <label for="audit-<?= esc($key, 'attr') ?>" class="form-label small mb-1 adm-filter-label"><?= esc($label) ?></label>
          <input type="text" class="form-control form-control-sm adm-input" aria-describedby="audit-filter-note" id="audit-<?= esc($key, 'attr') ?>" name="<?= esc($key, 'attr') ?>" value="<?= esc($filters[$key] ?? '', 'attr') ?>">
        </div>
      <?php endforeach; ?>

      <div class="col-12 col-sm-6 col-lg-3">
        <label for="audit-per-page" class="form-label small mb-1 adm-filter-label">Page size</label>
        <select class="form-select form-select-sm adm-input" id="audit-per-page" name="per_page">
          <?php foreach (['25', '50', '100'] as $size): ?>
            <option value="<?= esc($size, 'attr') ?>"<?= ($filters['per_page'] ?? '50') === $size ? ' selected' : '' ?>><?= esc($size) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php foreach (['from' => 'From', 'to' => 'To'] as $key => $label): ?>
        <div class="col-6 col-sm-6 col-lg-3">
          <label for="audit-<?= $key ?>-display" class="form-label small mb-1 adm-filter-label"><?= $label ?></label>
          <input type="text" class="form-control form-control-sm adm-input" id="audit-<?= $key ?>-display" data-datepicker data-target="#audit-<?= $key ?>" placeholder="DD/MM/YYYY" autocomplete="off" aria-describedby="audit-date-note">
          <input type="hidden" id="audit-<?= $key ?>" name="<?= $key ?>" value="<?= esc($filters[$key] ?? '', 'attr') ?>">
        </div>
      <?php endforeach; ?>

      <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 pt-2 adm-filter-bar">
        <div class="text-muted small adm-filter-notes">
          <span id="audit-filter-note">Text and ID filters use exact matches.</span>
          <span id="audit-date-note" class="ms-sm-2 d-inline-block">Dates use the server ledger timezone.</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary btn-sm adm-btn adm-btn-primary">Apply filters</button>
          <a href="<?= esc(site_url('/admin/audit'), 'attr') ?>" class="btn btn-outline-secondary btn-sm adm-btn adm-btn-secondary">Clear filters</a>
        </div>
      </div>
    </form>
  </div>

  <!-- Results Region -->
  <div data-refresh-region="results">
    <div class="adm-results-meta d-flex align-items-center justify-content-between">
      <span><?= esc((string) count($events)) ?> events shown</span>
      <span class="text-muted small">Cryptographic order: newest blocks first</span>
    </div>

    <div class="adm-table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th class="ps-3">Block #</th>
            <th>Event / Action</th>
            <th>Entity</th>
            <th>Actor</th>
            <th>Previous Hash / Current Block Hash</th>
            <th class="pe-3">Timestamp</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($events)): ?>
            <tr>
              <td colspan="6" class="adm-empty-cell text-center py-5 text-muted">
                <i class="bi bi-shield-check fs-1 d-block mb-2 opacity-40"></i>
                <?php if (! empty($filterErrors)): ?>
                  Audit events were not loaded because the filters or pagination are invalid.
                <?php elseif ($hasFilters || ! empty($hasCursor) || ! empty($pagination['newer_url']) || ! empty($pagination['older_url'])): ?>
                  No audit events match the current filters or page.
                <?php else: ?>
                  No audit events recorded yet.
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($events as $ev): ?>
              <tr>
                <td class="ps-3">
                  <span class="adm-audit-block-id">#<?= esc($ev['id']) ?></span>
                </td>
                <td>
                  <span class="adm-audit-event-tag">
                    <?= esc($ev['event_type']) ?>
                  </span>
                  <div class="adm-audit-action">
                    Action: <strong><?= esc($ev['action']) ?></strong>
                  </div>
                  <?php if ($ev['event_type'] === 'access.role_changed'):
                      $change = json_decode($ev['payload_json'] ?? '', true);
                      $text = static fn (string $key): string => is_array($change) && is_string($change[$key] ?? null) ? $change[$key] : 'Unavailable';
                  ?>
                    <details class="adm-audit-details mt-2" data-role-change-details>
                      <summary>Role change details</summary>
                      <dl class="adm-audit-dl small mt-2 mb-0">
                        <dt>Previous role</dt><dd><?= esc($text('previous_role')) ?></dd>
                        <dt>New role</dt><dd><?= esc($text('new_role')) ?></dd>
                        <dt>Reason</dt><dd class="text-break"><?= esc($text('reason')) ?></dd>
                      </dl>
                    </details>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="adm-audit-entity">
                    <strong class="adm-audit-entity-type"><?= esc($ev['entity_type']) ?></strong>
                    <span class="adm-mono adm-audit-entity-id">#<?= esc($ev['entity_id']) ?></span>
                  </div>
                </td>
                <td>
                  <div class="adm-audit-actor">
                    <span class="adm-audit-actor-name"><?= esc($ev['actor_name'] ?? 'System / Machine') ?></span>
                    <span class="adm-audit-actor-ip text-muted small">IP: <span class="adm-mono"><?= esc($ev['actor_ip']) ?></span></span>
                  </div>
                </td>
                <td>
                  <div class="adm-audit-hashes">
                    <div class="adm-hash-row" title="Prev: <?= esc($ev['prev_hash']) ?>">
                      <span class="adm-hash-tag">P</span>
                      <code class="adm-mono adm-hash-val"><?= esc(substr($ev['prev_hash'], 0, 16)) ?>...</code>
                    </div>
                    <div class="adm-hash-row" title="Curr: <?= esc($ev['curr_hash']) ?>">
                      <span class="adm-hash-tag is-curr">C</span>
                      <code class="adm-mono adm-hash-val is-curr"><?= esc(substr($ev['curr_hash'], 0, 16)) ?>...</code>
                    </div>
                  </div>
                </td>
                <td class="pe-3">
                  <div class="adm-audit-time">
                    <span class="adm-audit-date"><?= date('M j, Y', strtotime($ev['occurred_at'])) ?></span>
                    <span class="adm-audit-clock adm-mono text-muted small"><?= date('h:i:s A', strtotime($ev['occurred_at'])) ?></span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination & Summary Band -->
    <div class="adm-pagination-band d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
      <p class="small text-muted mb-0"><?= esc((string) count($events)) ?> events shown</p>
      <nav aria-label="Audit ledger pagination">
        <ul class="pagination pagination-sm flex-wrap mb-0">
          <li class="page-item">
            <a class="page-link" href="<?= esc($pagination['first_url'], 'attr') ?>"><span class="d-none d-sm-inline">Latest matching events</span><span class="d-sm-none">First</span></a>
          </li>
          <?php if (! empty($pagination['newer_url'])): ?>
            <li class="page-item">
              <a class="page-link" href="<?= esc($pagination['newer_url'], 'attr') ?>" rel="prev">Newer</a>
            </li>
          <?php else: ?>
            <li class="page-item disabled">
              <span class="page-link" aria-disabled="true">Newer</span>
            </li>
          <?php endif; ?>
          <li class="page-item active">
            <span class="page-link" aria-current="page"><?= !empty($hasCursor) ? 'Cursor' : '1' ?></span>
          </li>
          <?php if (! empty($pagination['older_url'])): ?>
            <li class="page-item">
              <a class="page-link" href="<?= esc($pagination['older_url'], 'attr') ?>" rel="next">Older</a>
            </li>
          <?php else: ?>
            <li class="page-item disabled">
              <span class="page-link" aria-disabled="true">Older</span>
            </li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>
</section>
<?= $this->endSection() ?>
