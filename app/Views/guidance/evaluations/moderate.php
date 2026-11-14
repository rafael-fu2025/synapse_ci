<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/guidance/evaluations/dashboard'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
  <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Evaluation Aggregates
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $pendingComments = $pendingComments ?? []; ?>

<div class="alert alert-info border-0 shadow-sm mb-4">
  <i class="bi bi-shield-check me-2" aria-hidden="true"></i>
  <strong>Unlinked Qualitative Comment Moderation:</strong>
  Comments below are decrypted strictly for moderation (removing PII or abusive language before cohort-safe release). No student, episode, or session identifiers exist in this dataset.
</div>

<?php if ($pendingComments === []): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
      <i class="bi bi-check2-all fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>
      <h5 class="fw-semibold">Moderation Queue Clear</h5>
      <p class="mb-0">There are no pending qualitative comments waiting for review.</p>
    </div>
  </div>
<?php else: ?>
  <div class="vstack gap-3">
    <?php foreach ($pendingComments as $item): ?>
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <span class="badge bg-warning text-dark">Pending Review</span>
              <code class="small"><?= esc($item['form_code']) ?></code>
              <span class="small text-muted">Submitted Date: <?= esc($item['submitted_date']) ?></span>
              <?php if (!empty($item['academic_term']) || !empty($item['academic_year'])): ?>
                <span class="small text-muted">· <?= esc(trim(($item['academic_term'] ?? '') . ' ' . ($item['academic_year'] ?? ''))) ?></span>
              <?php endif; ?>
            </div>
            <span class="small text-muted">Evaluation #<?= (int) $item['id'] ?></span>
          </div>

          <blockquote class="p-3 rounded bg-body-tertiary border-start border-4 border-primary mb-3">
            <p class="mb-0"><?= nl2br(esc($item['comment_text'])) ?></p>
          </blockquote>

          <div class="d-flex flex-wrap justify-content-end gap-2">
            <form method="POST" action="<?= esc(site_url('/guidance/evaluations/moderate/' . (int) $item['id']), 'attr') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="status" value="redacted">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Redact
              </button>
            </form>
            <form method="POST" action="<?= esc(site_url('/guidance/evaluations/moderate/' . (int) $item['id']), 'attr') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="status" value="approved">
              <button type="submit" class="btn btn-success btn-sm">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Approve for Aggregate
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?= $this->endSection() ?>
