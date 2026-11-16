<section class="mb-4" aria-labelledby="guidance-client-intakes-title">
  <h4 class="h6 fw-bold" id="guidance-client-intakes-title">Intake referrals <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['intakes']) ?></span></h4>
  <?php if (!$can['intake']): ?>
    <div class="alert alert-light border small">Intake histories require intake-processing permission.</div>
  <?php elseif ($intakes === []): ?>
    <div class="border rounded p-4 text-muted small">No intake referrals are available to you.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($intakes as $intake): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3">
          <div><div class="fw-semibold"><?= esc((string) $intake['referral_number']) ?></div>
            <div class="small text-muted"><?= esc($displayStatus($intake['source_type'])) ?> · <?= esc($displayStatus($intake['status'])) ?> · <?= esc($formatDate($intake['submitted_at'] ?? $intake['created_at'])) ?></div>
          </div>
          <a href="<?= esc(site_url('/guidance/intake/' . (int) $intake['id'])) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0">Open Intake</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($can['intake']): ?><?= $historyPager('intakes') ?><?php endif; ?>
</section>
<?php if ($can['referrals']): ?>
<section class="mb-4" aria-labelledby="guidance-client-referrals-title">
  <h4 class="h6 fw-bold" id="guidance-client-referrals-title">Shared referral coordination <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['bridgeReferrals']) ?></span></h4>
  <p class="small text-muted">Referral routing and status only; Clinic encounter and medical details are not loaded.</p>
  <?php if ($bridgeReferrals === []): ?>
    <div class="border rounded p-3 text-muted small">No shared referrals involve Guidance for this client.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($bridgeReferrals as $referral): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3">
          <div><strong class="small"><?= esc((string) $referral['referral_code']) ?></strong><div class="small text-muted"><?= esc(ucfirst((string) $referral['source_module'])) ?> → <?= esc(ucfirst((string) $referral['target_module'])) ?> · <?= esc($displayStatus($referral['status'])) ?> · <?= esc($formatDate($referral['created_at'])) ?></div></div>
          <a href="<?= esc(site_url('/guidance/referrals')) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0">Referral Registry</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('bridgeReferrals') ?>
</section>
<?php endif; ?>
<section class="mb-4" aria-labelledby="guidance-client-transfers-title">
  <h4 class="h6 fw-bold" id="guidance-client-transfers-title">Case transfers &amp; consultations <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['transfers']) ?></span></h4>
  <?php if ($transfers === []): ?>
    <div class="border rounded p-3 text-muted small">No transfer history is available in your visible cases.</div>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle"><caption class="visually-hidden">Authorized case transfer and consultation history</caption>
      <thead><tr><th scope="col">Case</th><th scope="col">Type</th><th scope="col">Practitioners</th><th scope="col">Status</th><th scope="col">Requested</th><th scope="col">Workspace</th></tr></thead>
      <tbody><?php foreach ($transfers as $transfer): ?>
        <tr><td><?= esc((string) $transfer['case_number']) ?></td><td><?= esc($displayStatus($transfer['transfer_type'])) ?></td>
          <td><?= esc($staffName($transfer['from_counsellor_user_id'])) ?> → <?= esc($staffName($transfer['to_counsellor_user_id'])) ?></td>
          <td><?= esc($displayStatus($transfer['status'])) ?></td><td><?= esc($formatDate($transfer['requested_at'])) ?></td>
          <td><div class="table-card-actions"><a href="<?= esc(site_url('/guidance/cases/' . (int) $transfer['case_id'])) ?>" class="btn btn-outline-primary btn-sm">Case Workspace</a></div></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  <?= $historyPager('transfers') ?>
</section>
<section class="mb-4" aria-labelledby="guidance-client-assignments-title">
  <h4 class="h6 fw-bold" id="guidance-client-assignments-title">Practitioner assignment history <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['assignments']) ?></span></h4>
  <?php if ($assignments === []): ?>
    <div class="border rounded p-3 text-muted small">No assignment history is available in your visible cases.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($assignments as $assignment): ?>
        <div class="list-group-item px-0 py-3"><div class="small fw-semibold"><?= esc((string) $assignment['case_number']) ?> · <?= esc($staffName($assignment['counsellor_user_id'])) ?></div>
          <div class="small text-muted"><?= esc($displayStatus($assignment['assignment_type'])) ?> · Assigned <?= esc($formatDate($assignment['assigned_at'])) ?><?= !empty($assignment['relinquished_at']) ? ' · Relinquished ' . esc($formatDate($assignment['relinquished_at'])) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('assignments') ?>
</section>
<?php if ($can['safety']): ?>
<section aria-labelledby="guidance-client-safety-title">
  <h4 class="h6 fw-bold" id="guidance-client-safety-title">Safety review records <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['safetyReviews']) ?></span></h4>
  <p class="small text-muted">Only reviews linked to your visible cases appear. Risk details, escalation narratives, and safety plans remain in the authorized case or intake workspace.</p>
  <?php if ($safetyReviews === []): ?>
    <div class="border rounded p-3 text-muted small">No safety-review records are available in your visible cases.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($safetyReviews as $review): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3">
          <div><strong class="small"><?= esc((string) $review['case_number']) ?></strong><div class="small text-muted">Reviewed <?= esc($formatDate($review['assessed_at'])) ?> by <?= esc($staffName($review['assessor_user_id'])) ?></div></div>
          <a href="<?= esc(site_url('/guidance/cases/' . (int) $review['case_id'])) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0">Authorized Workspace</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('safetyReviews') ?>
</section>
<?php endif; ?>
