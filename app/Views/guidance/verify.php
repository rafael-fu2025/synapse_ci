<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<?php
$found = (bool) ($found ?? false);
$code = (string) ($code ?? '');
$verification = $verification ?? null;

$statusBadge = static fn (string $status): string => match (strtolower($status)) {
    'active'     => 'bg-success',
    'superseded' => 'bg-warning text-dark',
    'void'       => 'bg-danger',
    default      => 'bg-secondary',
};
?>
<main class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-6 col-md-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted fw-semibold text-uppercase">Foundation University · Office of the Guidance Center</div>
            <h4 class="fw-bold mb-0">Controlled Document Verification</h4>
          </div>
          <i class="bi bi-patch-check-fill text-primary fs-3" aria-hidden="true"></i>
        </div>
        <div class="card-body p-4">
          <?php if (!$found || !is_array($verification)): ?>
            <div class="alert alert-danger border-0 mb-3" role="alert">
              <i class="bi bi-exclamation-octagon me-2" aria-hidden="true"></i>
              <strong>Verification Code Not Found:</strong>
              No issued controlled guidance document matches code <code><?= esc($code !== '' ? $code : 'N/A') ?></code>.
            </div>
          <?php else: ?>
            <?php $docStatus = (string) ($verification['status'] ?? 'active'); ?>
            <div class="d-flex justify-content-between align-items-center p-3 rounded bg-body-tertiary mb-3">
              <div>
                <div class="small text-muted">Verification Code</div>
                <div class="h5 fw-bold mb-0 font-monospace"><?= esc($verification['verification_code'] ?? '') ?></div>
              </div>
              <span class="badge <?= $statusBadge($docStatus) ?> fs-6 text-uppercase">
                <?= esc($docStatus) ?>
              </span>
            </div>

            <dl class="row mb-3">
              <dt class="col-sm-4 text-muted">Form Code</dt>
              <dd class="col-sm-8 fw-semibold font-monospace"><?= esc($verification['form_code'] ?? 'N/A') ?></dd>

              <dt class="col-sm-4 text-muted">Revision</dt>
              <dd class="col-sm-8"><?= esc($verification['revision'] ?? '00') ?></dd>

              <dt class="col-sm-4 text-muted">Issued At</dt>
              <dd class="col-sm-8"><?= esc($verification['issued_at'] ?? 'N/A') ?></dd>

              <dt class="col-sm-4 text-muted">Status</dt>
              <dd class="col-sm-8 text-capitalize fw-semibold"><?= esc($docStatus) ?></dd>

              <?php if (strtolower($docStatus) === 'void' && !empty($verification['void_reason'])): ?>
                <dt class="col-sm-4 text-muted">Void Reason</dt>
                <dd class="col-sm-8 text-danger"><?= esc($verification['void_reason']) ?></dd>
              <?php endif; ?>

              <?php if (!empty($verification['pdf_sha256'])): ?>
                <dt class="col-sm-4 text-muted">Document SHA-256</dt>
                <dd class="col-sm-8"><code class="small text-break"><?= esc($verification['pdf_sha256']) ?></code></dd>
              <?php endif; ?>
            </dl>
          <?php endif; ?>

          <div class="alert alert-light border small text-muted mb-0">
            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
            <strong>Privacy Notice (RA 10173):</strong>
            Public verification discloses strictly non-PII document control metadata (verification code, form code, revision, issuance timestamp, and validity status). No student or client personal information is displayed.
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
<?= $this->endSection() ?>
