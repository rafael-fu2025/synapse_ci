<?php
$domainKeys = [
    'linguistic' => 'Linguistic', 'logical_mathematical' => 'Logical', 'spatial' => 'Spatial', 'bodily_kinesthetic' => 'Bodily',
    'musical' => 'Musical', 'interpersonal' => 'Interpersonal', 'intrapersonal' => 'Intrapersonal', 'naturalist' => 'Naturalistic',
];
?>
<section class="mb-4" aria-labelledby="guidance-client-mi-title">
  <h4 class="h6 fw-bold" id="guidance-client-mi-title">Multiple Intelligence history <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['miAttempts']) ?></span></h4>
  <?php if (!$can['assessments']): ?>
    <div class="alert alert-light border small">Your account does not have permission to view assessment results.</div>
  <?php elseif ($miAttempts === []): ?>
    <div class="border rounded p-4 text-muted small">No Multiple Intelligence attempts have been recorded.</div>
  <?php else: ?>
    <p class="small text-muted">All eight domain scores (0–40) are preserved across terms. These educational assessments are not a medical diagnosis.</p>
    <div class="d-none d-md-block table-responsive"><table class="table table-sm table-hover align-middle">
      <caption class="visually-hidden">Multiple Intelligence attempts and all eight domain scores</caption>
      <thead><tr><th scope="col">Term / school year</th><th scope="col">Status / date</th>
        <?php foreach ($domainKeys as $label): ?><th scope="col" class="text-center"><?= esc($label) ?></th><?php endforeach; ?>
        <th scope="col">Results</th></tr></thead>
      <tbody>
        <?php foreach ($miAttempts as $attempt): ?>
          <tr><td><?= esc(trim((string) ($attempt['academic_term'] ?? '') . ' · ' . (string) ($attempt['academic_year'] ?? ''))) ?></td>
            <td><span class="badge bg-body-secondary text-body border"><?= esc($displayStatus($attempt['status'])) ?></span><div class="small text-muted"><?= esc($formatDate($attempt['attested_at'] ?? $attempt['created_at'])) ?></div></td>
            <?php foreach ($domainKeys as $key => $label): ?><td class="text-center fw-semibold"><?= isset($attempt['domain_scores'][$key]) ? (int) $attempt['domain_scores'][$key] : '—' ?></td><?php endforeach; ?>
            <td><?php if (($attempt['status'] ?? '') === 'finalized'): ?><a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $attempt['id'])) ?>" class="btn btn-outline-primary btn-sm">Results</a><?php else: ?><span class="small text-muted">In progress</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="d-md-none vstack gap-3">
      <?php foreach ($miAttempts as $attempt): ?>
        <div class="border rounded p-3">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div class="small fw-semibold"><?= esc(trim((string) ($attempt['academic_term'] ?? '') . ' · ' . (string) ($attempt['academic_year'] ?? ''))) ?><div class="small text-muted"><?= esc($formatDate($attempt['attested_at'] ?? $attempt['created_at'])) ?></div></div>
            <span class="badge bg-body-secondary text-body border"><?= esc($displayStatus($attempt['status'])) ?></span>
          </div>
          <div class="gc-mi-mobile-grid mb-3">
            <?php foreach ($domainKeys as $key => $label): ?>
              <div class="border rounded p-2 d-flex justify-content-between gap-2"><span class="small text-muted"><?= esc($label) ?></span><strong class="small"><?= isset($attempt['domain_scores'][$key]) ? (int) $attempt['domain_scores'][$key] : '—' ?></strong></div>
            <?php endforeach; ?>
          </div>
          <?php if (($attempt['status'] ?? '') === 'finalized'): ?><a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $attempt['id'])) ?>" class="btn btn-outline-primary btn-sm w-100">Results</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($can['assessments']): ?><?= $historyPager('miAttempts') ?><?php endif; ?>
</section>
<?php if ($can['assessments']): ?>
<section class="mb-4" aria-labelledby="guidance-client-pending-mi-title">
  <h4 class="h6 fw-bold" id="guidance-client-pending-mi-title">Pending assessments <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['pendingAssessments']) ?></span></h4>
  <?php if ($pendingAssessments === []): ?>
    <div class="border rounded p-3 text-muted small">No pending MI assignments.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($pendingAssessments as $assignment): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-wrap justify-content-between gap-2">
          <div><strong class="small"><?= esc(trim((string) ($assignment['academic_term'] ?? '') . ' · ' . (string) ($assignment['academic_year'] ?? ''))) ?></strong><div class="small text-muted">Assigned <?= esc($formatDate($assignment['assigned_at'])) ?> · Due <?= esc($formatDate($assignment['due_at'])) ?></div></div>
          <span class="badge bg-warning-subtle text-warning-emphasis align-self-start"><?= esc($displayStatus($assignment['status'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('pendingAssessments') ?>
</section>
<?php endif; ?>
<section aria-labelledby="guidance-client-surveys-title">
  <h4 class="h6 fw-bold" id="guidance-client-surveys-title">Surveys &amp; routine interviews <span class="badge bg-body-secondary text-body border"><?= number_format((int) $totals['surveys']) ?></span></h4>
  <?php if (!$can['surveys']): ?>
    <div class="alert alert-light border small mb-0">Survey-response and interview histories require survey-response read permission.</div>
  <?php elseif ($surveys === []): ?>
    <div class="border rounded p-4 text-muted small">No identified survey responses have been recorded.</div>
  <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($surveys as $survey): ?>
        <div class="list-group-item px-0 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-start gap-3">
          <div><div class="fw-semibold"><?= esc((string) $survey['title']) ?></div><div class="small text-muted">Submitted <?= esc($formatDate($survey['submitted_at'])) ?></div>
            <?php if (!empty($survey['interviewed_at'])): ?>
              <div class="small text-success mt-1">Interview verified by <?= esc((string) ($survey['interviewer_name'] ?? 'Guidance staff')) ?> on <?= esc($formatDate($survey['interviewed_at'])) ?></div>
            <?php else: ?><div class="small text-muted mt-1">Staff interview verification pending</div><?php endif; ?>
          </div>
          <a href="<?= esc(site_url('/guidance/surveys/' . (int) $survey['survey_id'] . '/responses/' . (int) $survey['response_id'])) ?>" class="btn btn-outline-primary btn-sm flex-shrink-0">Open Response</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($can['surveys']): ?><?= $historyPager('surveys') ?><?php endif; ?>
</section>
