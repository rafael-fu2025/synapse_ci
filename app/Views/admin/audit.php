<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<form action="<?= site_url('/admin/audit/verify') ?>" method="POST" class="d-inline">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-primary btn-sm">
    <i class="bi bi-shield-check me-1"></i> Verify Cryptographic Chain
  </button>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center py-3 gap-2">
    <div>
      <h5 class="fw-bold mb-0 text-dark">Immutable Cryptographic Audit Ledger</h5>
      <span class="text-muted small">Append-only SHA-256 hash-chained log for clinical, governance, and facilities mutations</span>
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
                No audit events recorded yet.
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
</div>
<?= $this->endSection() ?>
