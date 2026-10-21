<?= $this->extend('layouts/modal_form') ?>

<?= $this->section('content') ?>
<div class="p-1">
  <!-- Session & Security Metadata Bar -->
  <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <span class="badge bg-dark-subtle text-dark fw-bold"><?= esc($case['case_number']) ?></span>
        <strong class="text-dark"><?= esc($case['title']) ?></strong>
        <span class="badge bg-light text-dark border"><?= esc(ucfirst($session['session_type'])) ?> Session</span>
      </div>
      <div class="small text-muted">
        Client: <strong><?= esc(!empty($patient) ? ($patient['last_name'] . ', ' . $patient['first_name']) : 'Client') ?></strong> (ID: <?= esc($patient['mis_id'] ?? '—') ?>)
        · Counsellor: <strong><?= esc($author ?? $session['counsellor_name'] ?? 'Assigned Counsellor') ?></strong>
      </div>
    </div>
    <div>
      <span class="badge bg-success-subtle text-success border border-success-subtle p-2">
        <i class="bi bi-shield-lock-fill me-1"></i> AES-256-GCM Decrypted
      </span>
    </div>
  </div>

  <?php if ($note === null || trim($note['text']) === ''): ?>
    <div class="text-center py-4 text-muted">
      <i class="bi bi-file-earmark-x fs-1 d-block mb-2 opacity-50"></i>
      <p class="mb-0">No counseling notes have been recorded for this session yet.</p>
    </div>
  <?php else: ?>
    <!-- Note Details -->
    <div class="card bg-light border-0 p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="badge <?= $note['is_draft'] ? 'bg-warning text-dark' : 'bg-primary text-white' ?>">
          <?= $note['is_draft'] ? 'Draft Note (v' . (int) $note['version'] . ')' : 'Finalized Note (v' . (int) $note['version'] . ')' ?>
        </span>
        <small class="text-muted">
          Recorded: <?= date('F j, Y · h:i A', strtotime($note['created_at'])) ?>
        </small>
      </div>
      <div class="p-3 bg-white border rounded" style="white-space: pre-wrap; font-family: inherit; line-height: 1.6; min-height: 120px;">
        <?= esc($note['text']) ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="form-actions">
    <a href="<?= site_url('/guidance/sessions/' . $session['id'] . '/history') ?>" class="btn btn-outline-primary" data-app-modal-open>Finalized Version History</a>
    <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Close</a>
  </div>
</div>
<?= $this->endSection() ?>
