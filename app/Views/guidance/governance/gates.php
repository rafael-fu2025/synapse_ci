<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-gates.css'), 'attr') ?>">
<?= $this->endSection() ?>

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
<div class="guidance-ui guidance-governance-gates gg-workspace"
     data-refresh-owner="guidance.governance.gates"
     data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div class="gg-sheet">
    <div data-refresh-region="workspace" data-refresh-heading tabindex="-1">
      <header class="gg-band gg-intro">
        <h2 class="gg-intro-title">Rollout governance</h2>
        <p class="gg-intro-desc">Review activation gates and the current institutional authority sign-offs for Guidance workflows.</p>
        <div class="gg-notice" role="note">
          <svg class="gg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m12 3-8 3v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/></svg>
          <p>Each gate requires the authority sign-offs listed below before activation. Recording the final required sign-off enables that gate. Updating an authority replaces its current recorded entry.</p>
        </div>
      </header>
      <div class="gg-summary" aria-label="Activation gate summary">
        <div class="gg-summary-item"><span class="gg-summary-label">Configured gates</span><strong class="gg-summary-value"><?= $totalGates ?></strong><span class="gg-summary-note">Tenant rollout controls</span></div>
        <div class="gg-summary-item"><span class="gg-summary-label">Enabled gates</span><strong class="gg-summary-value"><?= $enabledCount ?></strong><span class="gg-summary-note">Recorded activation flags</span></div>
        <div class="gg-summary-item"><span class="gg-summary-label">Pending gates</span><strong class="gg-summary-value"><?= $pendingCount ?></strong><span class="gg-summary-note">Not yet enabled</span></div>
      </div>
      <?php if ($gates === []): ?>
        <div class="gg-empty" role="status">
          <svg class="gg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
          <h3>No activation gates found for this tenant.</h3>
          <p>Configured gates and their recorded sign-offs will appear here.</p>
        </div>
      <?php else: ?>
        <div class="gg-gate-list">
          <?php $gateNumber = 0; foreach ($gates as $gate): ?>
            <?php
            $gateNumber++;
            $gateKey   = (string) $gate['gate_key'];
            $isEnabled = (int) ($gate['is_enabled'] ?? 0) === 1;
            $required  = $gate['required_signoffs'] ?? $authorityRoles;
            $recorded  = $gate['recorded_signoffs'] ?? [];
            $signedCnt = count(array_intersect($required, array_keys($recorded)));
            ?>
            <article class="gg-gate" aria-labelledby="gg-gate-title-<?= $gateNumber ?>">
              <header class="gg-band gg-gate-head">
                <div class="gg-gate-heading">
                  <h3 id="gg-gate-title-<?= $gateNumber ?>" class="gg-gate-title"><?= esc($gate['title']) ?></h3>
                  <code class="gg-gate-key"><?= esc($gateKey) ?></code>
                  <?php if (!empty($gate['description'])): ?><p class="gg-gate-description"><?= esc($gate['description']) ?></p><?php endif; ?>
                </div>
                <div class="gg-gate-state">
                  <span class="gg-status <?= $isEnabled ? 'is-enabled' : 'is-gated' ?>"><?= $isEnabled ? 'Enabled' : 'Gated' ?></span>
                  <span class="gg-progress"><?= $signedCnt ?>/<?= count($required) ?> required sign-offs</span>
                  <?php if ($isEnabled && !empty($gate['approved_at'])): ?><span class="gg-approved">Approved <span class="gg-timestamp"><?= esc(date('M j, Y g:i A', strtotime((string) $gate['approved_at']))) ?></span></span><?php endif; ?>
                </div>
              </header>
              <div class="gg-table-wrap" data-mobile-cards="false">
                <table class="gg-table gg-signoff-table" role="table">
                  <caption class="visually-hidden">Current authority sign-offs for <?= esc($gate['title']) ?></caption>
                  <thead role="rowgroup"><tr role="row"><th scope="col">Authority</th><th scope="col">Status</th><th scope="col">Recorded by</th><th scope="col">Notes / reference</th></tr></thead>
                  <tbody role="rowgroup">
                    <?php foreach ($required as $roleKey): ?>
                      <?php
                      $entry      = $recorded[$roleKey] ?? null;
                      $roleLabel  = $authorityLabels[$roleKey] ?? ucwords(str_replace('_', ' ', $roleKey));
                      $signerId   = (int) ($entry['user_id'] ?? 0);
                      $signerName = $signerId > 0 ? ($signerNames[$signerId] ?? ('User #' . $signerId)) : null;
                      ?>
                      <tr role="row">
                        <td class="gg-authority-cell" role="cell"><span class="gg-authority-name"><?= esc($roleLabel) ?></span></td>
                        <td class="gg-status-cell" role="cell"><span class="gg-cell-label" aria-hidden="true">Status</span><span class="gg-status <?= $entry !== null ? 'is-signed' : 'is-pending' ?>"><?= $entry !== null ? 'Signed' : 'Pending' ?></span></td>
                        <td class="gg-signer-cell" role="cell">
                          <span class="gg-cell-label" aria-hidden="true">Recorded by</span>
                          <?php if ($entry !== null): ?>
                            <span class="gg-signer-name"><?= esc($signerName ?? 'Authorized Officer') ?></span>
                            <?php if (!empty($entry['signed_at'])): ?><span class="gg-timestamp d-block"><?= esc(date('M j, Y g:i A', strtotime((string) $entry['signed_at']))) ?></span><?php endif; ?>
                          <?php else: ?><span class="gg-muted">Awaiting official sign-off.</span><?php endif; ?>
                        </td>
                        <td class="gg-notes-cell" role="cell"><span class="gg-cell-label" aria-hidden="true">Notes / reference</span><?php if ($entry !== null && !empty($entry['notes'])): ?><p class="gg-notes-text"><?= esc($entry['notes']) ?></p><?php else: ?><span class="gg-muted">—</span><?php endif; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($canSignOff): ?>
                <div class="gg-signoff-band">
                  <h4 class="gg-form-title">Record authority sign-off</h4>
                  <form action="<?= esc(site_url('/guidance/governance/gates/' . rawurlencode($gateKey) . '/signoff'), 'attr') ?>"
                        method="POST"
                        data-background-submit
                        data-confirm="<?= esc("Record official authority sign-off for '" . $gate['title'] . "'? Once all required authorities sign off, this gate becomes enabled.", 'attr') ?>"
                        data-confirm-title="Confirm Activation Gate Sign-off"
                        data-confirm-label="Record Sign-off"
                        data-confirm-tone="warning"
                        class="gg-signoff-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirmed" value="1">
                    <div>
                      <label for="authority-<?= esc($gateKey, 'attr') ?>" class="form-label">Authority role</label>
                      <select id="authority-<?= esc($gateKey, 'attr') ?>" name="authority_role" class="form-select form-select-sm" required>
                        <option value="">Select authority...</option>
                        <?php foreach ($required as $roleKey): ?><option value="<?= esc($roleKey, 'attr') ?>"><?= esc($authorityLabels[$roleKey] ?? $roleKey) ?><?= isset($recorded[$roleKey]) ? ' (Update Sign-off)' : '' ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label for="notes-<?= esc($gateKey, 'attr') ?>" class="form-label">Sign-off notes / reference</label>
                      <input type="text" id="notes-<?= esc($gateKey, 'attr') ?>" name="notes" class="form-control form-control-sm" maxlength="1000" placeholder="QA memo #, DPO review ref, or approval notes">
                    </div>
                    <div class="gg-form-action"><button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-pen me-1" aria-hidden="true"></i> Record Sign-Off</button></div>
                  </form>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
