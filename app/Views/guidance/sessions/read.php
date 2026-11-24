<?= $this->extend('layouts/modal_form') ?>

<?= $this->section('content') ?>
<div class="p-1 guidance-ui gc-modal-scope">
  <!-- Session & Security Metadata Bar -->
  <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
    <div>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <span class="ce-tag ce-mono fw-bold"><?= esc($case['case_number']) ?></span>
        <strong><?= esc($case['title']) ?></strong>
        <span class="ce-tag"><?= esc(ucfirst((string) $session['session_type'])) ?> Session</span>
      </div>
      <div class="small text-muted">
        Client: <strong><?= esc(!empty($patient) ? ($patient['last_name'] . ', ' . $patient['first_name']) : 'Client') ?></strong> (ID: <span class="ce-mono"><?= esc($patient['mis_id'] ?? '—') ?></span>)
        · Counsellor: <strong><?= esc($author ?? $session['counsellor_name'] ?? 'Assigned Counsellor') ?></strong>
      </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <?php if ($note !== null && !empty($noteReadAudited)): ?>
        <span class="ce-tag">
          <i class="bi bi-shield-lock-fill" aria-hidden="true"></i> AES-256-GCM Decrypted · This access has been audited
        </span>
      <?php endif; ?>
      <?php if (!empty($oversightMode)): ?>
        <span class="ce-tag">Oversight mode · This access has been audited</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($note === null || trim((string) $note['text']) === ''): ?>
    <div class="ce-empty-row">
      <i class="bi bi-file-earmark-x" aria-hidden="true"></i>
      <p class="mb-0">No counseling notes have been recorded for this session yet.</p>
    </div>
  <?php else: ?>
    <!-- Note Details -->
    <div class="gc-note-panel mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <span class="ce-status-dot <?= !empty($note['is_draft']) ? 'ce-status-amber' : 'ce-status-emerald' ?>">
          <?= !empty($note['is_draft']) ? 'Draft Note (v' . (int) $note['version'] . ')' : 'Finalized Note (v' . (int) $note['version'] . ')' ?>
        </span>
        <small class="text-muted">
          Recorded: <?= date('F j, Y · h:i A', strtotime((string) $note['created_at'])) ?>
        </small>
      </div>
      <div class="gc-note-body"><?= esc($note['text']) ?></div>
    </div>
  <?php endif; ?>

  <div class="form-actions">
    <a href="<?= site_url('/guidance/sessions/' . $session['id'] . '/history') ?>" class="btn btn-outline-primary" data-app-modal-open>Finalized Version History</a>
    <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Close</a>
  </div>
</div>
<?= $this->endSection() ?>
