<p class="cp-record-section-note mb-3">Guidance form metadata only. Interview answers, counseling notes, consent details, signatures, and co-sign decisions remain in the existing authorized form workflow.</p>
<?php if (!$can['forms']): ?>
  <div class="cp-notice" role="note">Form histories require an appropriate Guidance form or clinical-workflow permission.</div>
<?php elseif ($forms === []): ?>
  <div class="cp-empty-pane">
    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
    No Guidance forms are available to you for this client.
  </div>
<?php else: ?>
  <div class="cp-visit-list">
    <?php foreach ($forms as $form): ?>
      <?php
        $clinical = in_array($form['internal_key'] ?? '', [
            \App\Services\Guidance\GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_INTERVIEW,
            \App\Services\Guidance\GuidanceClinicalFormService::FORM_PSYCHOSOCIAL_CONTACT,
            \App\Services\Guidance\GuidanceClinicalFormService::FORM_COUNSELING_CONTACT,
        ], true);
        $formStatus = (string) ($form['status'] ?? '');
        $formTone = match ($formStatus) {
            'finalized' => 'cp-status-inline--active',
            'pending_cosign' => 'cp-status-inline--warn',
            default => 'cp-status-inline--muted',
        };
      ?>
      <article class="cp-visit-card">
        <div class="cp-visit-head">
          <div class="cp-visit-title-col">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <span class="cp-id-code"><?= esc((string) $form['instance_number']) ?></span>
              <span class="cp-status-inline <?= esc($formTone) ?>"><span class="cp-dot" aria-hidden="true"></span><?= esc($displayStatus($formStatus)) ?></span>
            </div>
            <h4 class="cp-visit-title"><?= esc((string) $form['canonical_title']) ?></h4>
            <span class="cp-visit-meta">
              <span class="cp-mono"><?= esc((string) $form['form_code']) ?></span>
              · Created <span class="cp-mono"><?= esc($formatDate($form['created_at'])) ?></span> by <?= esc($staffName($form['created_by_user_id'])) ?><?= !empty($form['finalized_at']) ? ' · Finalized <span class="cp-mono">' . esc($formatDate($form['finalized_at'])) . '</span>' : '' ?>
              <?php if (!empty($form['assigned_supervisor_user_id'])): ?>
                · Co-signer: <?= esc($staffName($form['assigned_supervisor_user_id'])) ?>
              <?php endif; ?>
            </span>
          </div>
          <div class="cp-visit-actions">
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
        <?php if (!empty($form['privacy_notice_id']) || !empty($form['attestations']) || $formStatus === 'pending_cosign'): ?>
          <div class="cp-visit-body">
            <div class="d-flex flex-wrap gap-2">
              <?php if (!empty($form['privacy_notice_id'])): ?>
                <span class="cp-tag">Privacy notice linked</span>
              <?php endif; ?>
              <?php foreach ($form['attestations'] ?? [] as $attestation): ?>
                <span class="cp-tag cp-tag--verified"><?= esc($displayStatus($attestation['signer_role'])) ?> attested · <?= esc($formatDate($attestation['attested_at'], false)) ?></span>
              <?php endforeach; ?>
              <?php if ($formStatus === 'pending_cosign'): ?>
                <span class="cp-tag cp-tag--pending">Awaiting qualified co-signature</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($can['forms']): ?><?= $historyPager('forms') ?><?php endif; ?>
