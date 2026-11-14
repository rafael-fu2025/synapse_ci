<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc(site_url('/guidance/governance/practitioners')) ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-person-badge me-1" aria-hidden="true"></i> Practitioners &amp; Supervision
  </a>
  <a href="<?= esc(site_url('/guidance/intake')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-inbox me-1" aria-hidden="true"></i> Intake Queue
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$gates          = $gates ?? [];
$authorityRoles = $authorityRoles ?? ['guidance_head', 'qa_office', 'dpo'];
$signerNames    = $signerNames ?? [];
$canSignOff     = !empty($canSignOff);

$authorityLabels = [
    'guidance_head' => 'Guidance Head',
    'qa_office'     => 'QA Office',
    'dpo'           => 'Data Protection Officer (DPO)',
];

$totalGates   = count($gates);
$enabledCount = count(array_filter($gates, static fn (array $g): bool => (int) ($g['is_enabled'] ?? 0) === 1));
$pendingCount = $totalGates - $enabledCount;
?>

<div class="guidance-governance-gates"
     data-refresh-owner="guidance.governance.gates"
     data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body py-3">
            <div class="small text-muted text-uppercase fw-semibold">Configured Gates</div>
            <div class="fs-3 fw-bold mt-1"><?= $totalGates ?></div>
            <div class="small text-muted">Multi-authority rollout controls</div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body py-3">
            <div class="small text-muted text-uppercase fw-semibold">Approved &amp; Enabled</div>
            <div class="fs-3 fw-bold text-success mt-1"><?= $enabledCount ?></div>
            <div class="small text-muted">All 3 authorities signed off</div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body py-3">
            <div class="small text-muted text-uppercase fw-semibold">Pending Sign-Off</div>
            <div class="fs-3 fw-bold text-warning mt-1"><?= $pendingCount ?></div>
            <div class="small text-muted">Live workflows remain gated</div>
          </div>
        </div>
      </div>
    </div>

    <div class="alert alert-info border-0 shadow-sm d-flex align-items-start gap-2 mb-4">
      <i class="bi bi-shield-check fs-5 mt-1" aria-hidden="true"></i>
      <div class="small">
        <strong>Multi-Authority Governance Policy:</strong> Each rollout activation gate remains disabled by default until all three required institutional authorities (<strong>Guidance Head</strong>, <strong>QA Office</strong>, and <strong>Data Protection Officer</strong>) record an explicit sign-off.
      </div>
    </div>

    <?php if ($gates === []): ?>
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
          <i class="bi bi-lock fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>
          No activation gates found for this tenant.
        </div>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($gates as $gate): ?>
          <?php
          $gateKey   = (string) $gate['gate_key'];
          $isEnabled = (int) ($gate['is_enabled'] ?? 0) === 1;
          $required  = $gate['required_signoffs'] ?? $authorityRoles;
          $recorded  = $gate['recorded_signoffs'] ?? [];
          $signedCnt = count(array_intersect($required, array_keys($recorded)));
          ?>
          <div class="col-12">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-start gap-2 py-3">
                <div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <h2 class="h6 fw-bold mb-0"><?= esc($gate['title']) ?></h2>
                    <code class="small px-2 py-1 rounded bg-body-tertiary border"><?= esc($gateKey) ?></code>
                  </div>
                  <?php if (!empty($gate['description'])): ?>
                    <p class="small text-muted mb-0 mt-1"><?= esc($gate['description']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="text-end">
                  <?php if ($isEnabled): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                      <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i> Enabled
                    </span>
                    <?php if (!empty($gate['approved_at'])): ?>
                      <div class="small text-muted mt-1">Approved <?= esc(date('M j, Y g:i A', strtotime((string) $gate['approved_at']))) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                      <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i> Gated (<?= $signedCnt ?>/<?= count($required) ?>)
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card-body">
                <div class="row g-2 mb-3">
                  <?php foreach ($required as $roleKey): ?>
                    <?php
                    $entry      = $recorded[$roleKey] ?? null;
                    $roleLabel  = $authorityLabels[$roleKey] ?? ucwords(str_replace('_', ' ', $roleKey));
                    $signerId   = (int) ($entry['user_id'] ?? 0);
                    $signerName = $signerId > 0 ? ($signerNames[$signerId] ?? ('User #' . $signerId)) : null;
                    ?>
                    <div class="col-md-4">
                      <div class="p-2 rounded border h-100 <?= $entry !== null ? 'bg-success-subtle border-success-subtle' : 'bg-body-tertiary' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                          <span class="small fw-semibold"><?= esc($roleLabel) ?></span>
                          <?php if ($entry !== null): ?>
                            <span class="badge bg-success">Signed</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">Pending</span>
                          <?php endif; ?>
                        </div>
                        <?php if ($entry !== null): ?>
                          <div class="small text-muted">
                            By <?= esc($signerName ?? 'Authorized Officer') ?>
                            <?php if (!empty($entry['signed_at'])): ?>
                              · <?= esc(date('M j, Y g:i A', strtotime((string) $entry['signed_at']))) ?>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($entry['notes'])): ?>
                            <div class="small mt-1 fst-italic">"<?= esc($entry['notes']) ?>"</div>
                          <?php endif; ?>
                        <?php else: ?>
                          <div class="small text-muted">Awaiting official sign-off.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <?php if ($canSignOff): ?>
                  <form action="<?= esc(site_url('/guidance/governance/gates/' . rawurlencode($gateKey) . '/signoff'), 'attr') ?>"
                        method="POST"
                        data-background-submit
                        data-confirm="Record official authority sign-off for '<?= esc($gate['title'], 'js') ?>'? Once all required authorities sign off, this gate becomes live."
                        data-confirm-title="Confirm Activation Gate Sign-off"
                        data-confirm-label="Record Sign-off"
                        data-confirm-tone="warning"
                        class="row g-2 align-items-end pt-2 border-top">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirmed" value="1">
                    <div class="col-md-4">
                      <label for="authority-<?= esc($gateKey, 'attr') ?>" class="form-label small fw-semibold mb-1">Authority Role</label>
                      <select id="authority-<?= esc($gateKey, 'attr') ?>" name="authority_role" class="form-select form-select-sm" required>
                        <option value="">Select authority...</option>
                        <?php foreach ($required as $roleKey): ?>
                          <option value="<?= esc($roleKey, 'attr') ?>">
                            <?= esc($authorityLabels[$roleKey] ?? $roleKey) ?><?= isset($recorded[$roleKey]) ? ' (Update Sign-off)' : '' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-5">
                      <label for="notes-<?= esc($gateKey, 'attr') ?>" class="form-label small fw-semibold mb-1">Sign-off Notes / Reference</label>
                      <input type="text"
                             id="notes-<?= esc($gateKey, 'attr') ?>"
                             name="notes"
                             class="form-control form-control-sm"
                             maxlength="1000"
                             placeholder="QA memo #, DPO review ref, or approval notes">
                    </div>
                    <div class="col-md-3 d-grid">
                      <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pen me-1" aria-hidden="true"></i> Record Sign-Off
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>
<?= $this->endSection() ?>
