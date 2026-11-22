<section class="cp-record-section" aria-labelledby="guidance-client-intakes-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-intakes-title">
      Intake referrals
      <span class="cp-card-count"><?= number_format((int) $totals['intakes']) ?></span>
    </h4>
  </div>
  <?php if (!$can['intake']): ?>
    <div class="cp-notice" role="note">Intake histories require intake-processing permission.</div>
  <?php elseif ($intakes === []): ?>
    <div class="cp-empty-pane">
      <i class="bi bi-inbox" aria-hidden="true"></i>
      No intake referrals are available to you.
    </div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($intakes as $intake): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="cp-id-code"><?= esc((string) $intake['referral_number']) ?></span>
                <span class="cp-tag"><?= esc($displayStatus($intake['status'])) ?></span>
              </div>
              <span class="cp-visit-meta">
                <?= esc($displayStatus($intake['source_type'])) ?> · <span class="cp-mono"><?= esc($formatDate($intake['submitted_at'] ?? $intake['created_at'])) ?></span>
              </span>
            </div>
            <div class="cp-visit-actions">
              <a href="<?= esc(site_url('/guidance/intake/' . (int) $intake['id'])) ?>" class="btn btn-outline-primary btn-sm">Open Intake</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($can['intake']): ?><?= $historyPager('intakes') ?><?php endif; ?>
</section>

<?php if ($can['referrals']): ?>
<section class="cp-record-section" aria-labelledby="guidance-client-referrals-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-referrals-title">
      Shared referral coordination
      <span class="cp-card-count"><?= number_format((int) $totals['bridgeReferrals']) ?></span>
    </h4>
  </div>
  <p class="cp-record-section-note">Referral routing and status only; Clinic encounter and medical details are not loaded.</p>
  <?php if ($bridgeReferrals === []): ?>
    <div class="cp-empty-pane">No shared referrals involve Guidance for this client.</div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($bridgeReferrals as $referral): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="cp-id-code"><?= esc((string) $referral['referral_code']) ?></span>
                <span class="cp-tag"><?= esc($displayStatus($referral['status'])) ?></span>
              </div>
              <span class="cp-visit-meta">
                <?= esc(ucfirst((string) $referral['source_module'])) ?> → <?= esc(ucfirst((string) $referral['target_module'])) ?> · <span class="cp-mono"><?= esc($formatDate($referral['created_at'])) ?></span>
              </span>
            </div>
            <div class="cp-visit-actions">
              <a href="<?= esc(site_url('/guidance/referrals')) ?>" class="btn btn-outline-primary btn-sm">Referral Registry</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('bridgeReferrals') ?>
</section>
<?php endif; ?>

<section class="cp-record-section" aria-labelledby="guidance-client-transfers-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-transfers-title">
      Case transfers &amp; consultations
      <span class="cp-card-count"><?= number_format((int) $totals['transfers']) ?></span>
    </h4>
  </div>
  <?php if ($transfers === []): ?>
    <div class="cp-empty-pane">No transfer history is available in your visible cases.</div>
  <?php else: ?>
    <div class="cp-table-card">
      <div class="cp-table-wrap">
        <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
          <caption class="visually-hidden">Authorized case transfer and consultation history</caption>
          <thead>
            <tr>
              <th scope="col">Case</th>
              <th scope="col">Type</th>
              <th scope="col">Practitioners</th>
              <th scope="col">Status</th>
              <th scope="col">Requested</th>
              <th scope="col" class="text-end">Workspace</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transfers as $transfer): ?>
              <tr class="cp-row">
                <td class="cp-cell-header" data-label="Case">
                  <span class="cp-id-code"><?= esc((string) $transfer['case_number']) ?></span>
                </td>
                <td data-label="Type"><?= esc($displayStatus($transfer['transfer_type'])) ?></td>
                <td data-label="Practitioners"><?= esc($staffName($transfer['from_counsellor_user_id'])) ?> → <?= esc($staffName($transfer['to_counsellor_user_id'])) ?></td>
                <td data-label="Status"><span class="cp-tag"><?= esc($displayStatus($transfer['status'])) ?></span></td>
                <td data-label="Requested" class="cp-mono"><?= esc($formatDate($transfer['requested_at'])) ?></td>
                <td class="text-end cp-cell-actions">
                  <div class="table-card-actions cp-row-actions">
                    <a href="<?= esc(site_url('/guidance/cases/' . (int) $transfer['case_id'])) ?>" class="btn btn-outline-primary btn-sm">Case Workspace</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
  <?= $historyPager('transfers') ?>
</section>

<section class="cp-record-section" aria-labelledby="guidance-client-assignments-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-assignments-title">
      Practitioner assignment history
      <span class="cp-card-count"><?= number_format((int) $totals['assignments']) ?></span>
    </h4>
  </div>
  <?php if ($assignments === []): ?>
    <div class="cp-empty-pane">No assignment history is available in your visible cases.</div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($assignments as $assignment): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="cp-id-code"><?= esc((string) $assignment['case_number']) ?></span>
                <strong class="small"><?= esc($staffName($assignment['counsellor_user_id'])) ?></strong>
              </div>
              <span class="cp-visit-meta">
                <?= esc($displayStatus($assignment['assignment_type'])) ?> · Assigned <span class="cp-mono"><?= esc($formatDate($assignment['assigned_at'])) ?></span><?= !empty($assignment['relinquished_at']) ? ' · Relinquished <span class="cp-mono">' . esc($formatDate($assignment['relinquished_at'])) . '</span>' : '' ?>
              </span>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('assignments') ?>
</section>

<?php if ($can['safety']): ?>
<section class="cp-record-section" aria-labelledby="guidance-client-safety-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-safety-title">
      Safety review records
      <span class="cp-card-count"><?= number_format((int) $totals['safetyReviews']) ?></span>
    </h4>
  </div>
  <p class="cp-record-section-note">Only reviews linked to your visible cases appear. Risk details, escalation narratives, and safety plans remain in the authorized case or intake workspace.</p>
  <?php if ($safetyReviews === []): ?>
    <div class="cp-empty-pane">No safety-review records are available in your visible cases.</div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($safetyReviews as $review): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <span class="cp-id-code"><?= esc((string) $review['case_number']) ?></span>
              <span class="cp-visit-meta">
                Reviewed <span class="cp-mono"><?= esc($formatDate($review['assessed_at'])) ?></span> by <?= esc($staffName($review['assessor_user_id'])) ?>
              </span>
            </div>
            <div class="cp-visit-actions">
              <a href="<?= esc(site_url('/guidance/cases/' . (int) $review['case_id'])) ?>" class="btn btn-outline-primary btn-sm">Authorized Workspace</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('safetyReviews') ?>
</section>
<?php endif; ?>
