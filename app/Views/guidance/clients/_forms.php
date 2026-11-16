<p class="small text-muted">Guidance form metadata only. Interview answers, counseling notes, consent details, signatures, and co-sign decisions remain in the existing authorized form workflow.</p>
<?php if (!$can['forms']): ?>
  <div class="alert alert-light border small mb-0">Form histories require an appropriate Guidance form or clinical-workflow permission.</div>
<?php elseif ($forms === []): ?>
  <div class="border rounded p-4 text-muted small">No Guidance forms are available to you for this client.</div>
<?php else: ?>
  <div class="list-group list-group-flush">
    <?php foreach ($forms as $form): ?>
      <?php $clinical = in_array($form['internal_key'] ?? '', [
          \App\Services\Guidance\GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
          \App\Services\Guidance\GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT,
          \App\Services\Guidance\GuidanceClinicalFormService::FORM_COUNSELING_CONTACT,
      ], true); ?>
      <div class="list-group-item px-0 py-3 d-flex flex-column flex-lg-row justify-content-between align-items-stretch align-items-lg-start gap-3">
        <div class="min-width-0">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><strong><?= esc((string) $form['canonical_title']) ?></strong><span class="badge bg-body-secondary text-body border"><?= esc($displayStatus($form['status'])) ?></span></div>
          <div class="small text-muted"><?= esc((string) $form['form_code']) ?> · <?= esc((string) $form['instance_number']) ?></div>
          <div class="small text-muted mt-1">Created <?= esc($formatDate($form['created_at'])) ?> by <?= esc($staffName($form['created_by_user_id'])) ?><?= !empty($form['finalized_at']) ? ' · Finalized ' . esc($formatDate($form['finalized_at'])) : '' ?></div>
          <?php if (!empty($form['assigned_supervisor_user_id'])): ?><div class="small text-muted">Assigned co-signer: <?= esc($staffName($form['assigned_supervisor_user_id'])) ?></div><?php endif; ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if (!empty($form['privacy_notice_id'])): ?><span class="badge bg-light text-body border">Privacy notice linked</span><?php endif; ?>
            <?php foreach ($form['attestations'] ?? [] as $attestation): ?>
              <span class="badge bg-success-subtle text-success-emphasis border"><?= esc($displayStatus($attestation['signer_role'])) ?> attested · <?= esc($formatDate($attestation['attested_at'], false)) ?></span>
            <?php endforeach; ?>
            <?php if (($form['status'] ?? '') === 'pending_cosign'): ?><span class="badge bg-warning-subtle text-warning-emphasis">Awaiting qualified co-signature</span><?php endif; ?>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 flex-shrink-0">
          <?php if (!empty($form['case_id'])): ?>
            <a href="<?= esc(site_url('/guidance/cases/' . (int) $form['case_id'])) ?>" class="btn btn-outline-primary btn-sm">Case Workspace</a>
          <?php elseif ($clinical): ?>
            <a href="<?= esc(site_url('/guidance/clinical/forms/' . (int) $form['id'])) ?>" class="btn btn-outline-primary btn-sm">Form Workspace</a>
          <?php endif; ?>
          <?php if ($clinical && $can['documents']): ?>
            <a href="<?= esc(site_url('/guidance/clinical/forms/' . (int) $form['id'] . '/pdf')) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Preview PDF</a>
          <?php elseif (!$clinical && ($form['status'] ?? '') === 'draft' && $can['documents']): ?>
            <a href="<?= esc(site_url('/guidance/forms/' . (int) $form['id'] . '/preview')) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">Draft Preview</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($can['forms']): ?><?= $historyPager('forms') ?><?php endif; ?>
