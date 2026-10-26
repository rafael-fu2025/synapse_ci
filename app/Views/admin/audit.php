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
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body p-3">
    <?php if (! empty($filterErrors)): ?>
      <div class="alert alert-danger" role="alert">
        <p class="fw-bold mb-1">Please correct the audit filters:</p>
        <ul class="mb-0">
          <?php foreach ($filterErrors as $error): ?>
            <li><?= esc($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <form action="<?= esc(site_url('/admin/audit'), 'attr') ?>" method="GET" class="row g-2" aria-label="Filter audit ledger">
      <?php foreach ([
          'event_type' => 'Event type',
          'action' => 'Action',
          'entity_type' => 'Entity type',
          'entity_id' => 'Entity ID',
          'actor_user_id' => 'Actor user ID',
      ] as $key => $label): ?>
        <div class="col-12 col-sm-6 col-lg-3">
          <label for="audit-<?= esc($key, 'attr') ?>" class="form-label small mb-1"><?= esc($label) ?></label>
          <input type="text" class="form-control form-control-sm" aria-describedby="audit-filter-note" id="audit-<?= esc($key, 'attr') ?>" name="<?= esc($key, 'attr') ?>" value="<?= esc($filters[$key] ?? '', 'attr') ?>">
        </div>
      <?php endforeach; ?>
      <div class="col-12 col-sm-6 col-lg-3">
        <label for="audit-per-page" class="form-label small mb-1">Page size</label>
        <select class="form-select form-select-sm" id="audit-per-page" name="per_page">
          <?php foreach (['25', '50', '100'] as $size): ?>
            <option value="<?= esc($size, 'attr') ?>"<?= ($filters['per_page'] ?? '50') === $size ? ' selected' : '' ?>><?= esc($size) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php foreach (['from' => 'From', 'to' => 'To'] as $key => $label): ?>
        <div class="col-12 col-sm-6 col-lg-3">
          <label for="audit-<?= $key ?>-display" class="form-label small mb-1"><?= $label ?></label>
          <input type="text" class="form-control form-control-sm" id="audit-<?= $key ?>-display" data-datepicker data-target="#audit-<?= $key ?>" placeholder="DD/MM/YYYY" autocomplete="off" aria-describedby="audit-date-note">
          <input type="hidden" id="audit-<?= $key ?>" name="<?= $key ?>" value="<?= esc($filters[$key] ?? '', 'attr') ?>">
        </div>
      <?php endforeach; ?>
      <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
        <div class="text-muted small">
          <span id="audit-filter-note">Text and ID filters use exact matches.</span>
          <span id="audit-date-note">Dates use the server ledger timezone.</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
          <a href="<?= esc(site_url('/admin/audit'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">Clear filters</a>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center py-3 gap-2">
    <div>
      <h5 class="fw-bold mb-0 text-dark">Tamper-evident Cryptographic Audit Ledger</h5>
      <p class="text-muted small mb-1">Append-only SHA-256 hash-chained log for clinical, governance, and facilities mutations.</p>
      <p class="text-muted small mb-0">Verification checks the whole tenant chain, not the current filtered page. No external checkpoint is used.</p>
    </div>
  </div>

  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 font-monospace" style="font-size: 0.8rem;">
        <thead class="table-light text-uppercase">
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
              <td colspan="6" class="text-center py-5 text-muted font-sans-serif">
                <i class="bi bi-shield-slash fs-1 d-block mb-2 opacity-50"></i>
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
                <td class="ps-3 fw-bold text-dark">
                  #<?= esc($ev['id']) ?>
                </td>
                <td>
                  <span class="badge bg-primary-subtle text-primary font-sans-serif">
                    <?= esc($ev['event_type']) ?>
                  </span>
                  <div class="text-muted mt-1 small">Action: <?= esc($ev['action']) ?></div>
                  <?php if ($ev['event_type'] === 'access.role_changed'):
                      $change = json_decode($ev['payload_json'] ?? '', true);
                      $text = static fn (string $key): string => is_array($change) && is_string($change[$key] ?? null) ? $change[$key] : 'Unavailable';
                  ?>
                    <details class="mt-2 font-sans-serif" data-role-change-details>
                      <summary>Role change details</summary>
                      <dl class="small mt-2 mb-0">
                        <dt>Previous role</dt><dd><?= esc($text('previous_role')) ?></dd>
                        <dt>New role</dt><dd><?= esc($text('new_role')) ?></dd>
                        <dt>Reason</dt><dd class="text-break"><?= esc($text('reason')) ?></dd>
                      </dl>
                    </details>
                  <?php endif; ?>
                </td>
                <td>
                  <strong><?= esc($ev['entity_type']) ?></strong> #<?= esc($ev['entity_id']) ?>
                </td>
                <td>
                  <?= esc($ev['actor_name'] ?? 'System / Machine') ?><br>
                  <span class="text-muted small">IP: <?= esc($ev['actor_ip']) ?></span>
                </td>
                <td>
                  <div class="text-truncate text-muted" style="max-width: 250px;" title="Prev: <?= esc($ev['prev_hash']) ?>">
                    <span class="text-secondary">P:</span> <?= esc(substr($ev['prev_hash'], 0, 16)) ?>...
                  </div>
                  <div class="text-truncate fw-bold text-dark" style="max-width: 250px;" title="Curr: <?= esc($ev['curr_hash']) ?>">
                    <span class="text-primary">C:</span> <?= esc(substr($ev['curr_hash'], 0, 16)) ?>...
                  </div>
                </td>
                <td class="pe-3 font-sans-serif text-muted small">
                  <?= date('M j, Y', strtotime($ev['occurred_at'])) ?><br>
                  <?= date('h:i:s A', strtotime($ev['occurred_at'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
    <span class="text-muted small"><?= esc((string) count($events)) ?> events shown</span>
    <nav aria-label="Audit ledger pagination" class="d-flex flex-wrap gap-2">
      <a href="<?= esc($pagination['first_url'], 'attr') ?>" class="btn btn-outline-secondary btn-sm">Latest matching events</a>
      <?php if (! empty($pagination['newer_url'])): ?>
        <a href="<?= esc($pagination['newer_url'], 'attr') ?>" class="btn btn-outline-primary btn-sm">Newer</a>
      <?php else: ?>
        <span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">Newer</span>
      <?php endif; ?>
      <?php if (! empty($pagination['older_url'])): ?>
        <a href="<?= esc($pagination['older_url'], 'attr') ?>" class="btn btn-outline-primary btn-sm">Older</a>
      <?php else: ?>
        <span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">Older</span>
      <?php endif; ?>
    </nav>
  </div>
</div>
<?= $this->endSection() ?>
