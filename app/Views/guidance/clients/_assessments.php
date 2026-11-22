<?php
$domainKeys = [
    'linguistic' => 'Linguistic', 'logical_mathematical' => 'Logical', 'spatial' => 'Spatial', 'bodily_kinesthetic' => 'Bodily',
    'musical' => 'Musical', 'interpersonal' => 'Interpersonal', 'intrapersonal' => 'Intrapersonal', 'naturalist' => 'Naturalistic',
];
?>
<section class="cp-record-section" aria-labelledby="guidance-client-mi-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-mi-title">
      Multiple Intelligence history
      <span class="cp-card-count"><?= number_format((int) $totals['miAttempts']) ?></span>
    </h4>
  </div>
  <?php if (!$can['assessments']): ?>
    <div class="cp-notice" role="note">Your account does not have permission to view assessment results.</div>
  <?php elseif ($miAttempts === []): ?>
    <div class="cp-empty-pane">
      <i class="bi bi-stars" aria-hidden="true"></i>
      No Multiple Intelligence attempts have been recorded.
    </div>
  <?php else: ?>
    <p class="cp-record-section-note">All eight domain scores (0–40) are preserved across terms. These educational assessments are not a medical diagnosis.</p>
    <div class="d-none d-md-block cp-table-card">
      <div class="cp-table-wrap">
        <table class="table align-middle mb-0 cp-table" data-mobile-cards="false">
          <caption class="visually-hidden">Multiple Intelligence attempts and all eight domain scores</caption>
          <thead>
            <tr>
              <th scope="col">Term / School year</th>
              <th scope="col">Status / Date</th>
              <?php foreach ($domainKeys as $label): ?><th scope="col" class="text-center"><?= esc($label) ?></th><?php endforeach; ?>
              <th scope="col" class="text-end">Results</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($miAttempts as $attempt): ?>
              <tr>
                <td><span class="cp-person-name"><?= esc(trim((string) ($attempt['academic_term'] ?? '') . ' · ' . (string) ($attempt['academic_year'] ?? ''))) ?></span></td>
                <td>
                  <span class="cp-tag"><?= esc($displayStatus($attempt['status'])) ?></span>
                  <span class="cp-person-sub cp-mono"><?= esc($formatDate($attempt['attested_at'] ?? $attempt['created_at'])) ?></span>
                </td>
                <?php foreach ($domainKeys as $key => $label): ?>
                  <td class="text-center fw-semibold cp-mono"><?= isset($attempt['domain_scores'][$key]) ? (int) $attempt['domain_scores'][$key] : '—' ?></td>
                <?php endforeach; ?>
                <td class="text-end">
                  <?php if (($attempt['status'] ?? '') === 'finalized'): ?>
                    <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $attempt['id'])) ?>" class="btn btn-outline-primary btn-sm">Results</a>
                  <?php else: ?>
                    <span class="small text-muted">In progress</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="d-md-none cp-visit-list">
      <?php foreach ($miAttempts as $attempt): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <h5 class="cp-visit-title"><?= esc(trim((string) ($attempt['academic_term'] ?? '') . ' · ' . (string) ($attempt['academic_year'] ?? ''))) ?></h5>
              <span class="cp-visit-meta cp-mono"><?= esc($formatDate($attempt['attested_at'] ?? $attempt['created_at'])) ?></span>
            </div>
            <span class="cp-tag"><?= esc($displayStatus($attempt['status'])) ?></span>
          </div>
          <div class="cp-visit-body">
            <div class="cp-mi-grid gc-mi-mobile-grid">
              <?php foreach ($domainKeys as $key => $label): ?>
                <div class="cp-mi-cell">
                  <span class="text-muted"><?= esc($label) ?></span>
                  <strong class="cp-mono"><?= isset($attempt['domain_scores'][$key]) ? (int) $attempt['domain_scores'][$key] : '—' ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (($attempt['status'] ?? '') === 'finalized'): ?>
              <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $attempt['id'])) ?>" class="btn btn-outline-primary btn-sm w-100">Results</a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($can['assessments']): ?><?= $historyPager('miAttempts') ?><?php endif; ?>
</section>

<?php if ($can['assessments']): ?>
<section class="cp-record-section" aria-labelledby="guidance-client-pending-mi-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-pending-mi-title">
      Pending assessments
      <span class="cp-card-count"><?= number_format((int) $totals['pendingAssessments']) ?></span>
    </h4>
  </div>
  <?php if ($pendingAssessments === []): ?>
    <div class="cp-empty-pane">No pending MI assignments.</div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($pendingAssessments as $assignment): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <h5 class="cp-visit-title"><?= esc(trim((string) ($assignment['academic_term'] ?? '') . ' · ' . (string) ($assignment['academic_year'] ?? ''))) ?></h5>
              <span class="cp-visit-meta cp-mono">Assigned <?= esc($formatDate($assignment['assigned_at'])) ?> · Due <?= esc($formatDate($assignment['due_at'])) ?></span>
            </div>
            <span class="cp-status-inline cp-status-inline--warn"><span class="cp-dot" aria-hidden="true"></span><?= esc($displayStatus($assignment['status'])) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $historyPager('pendingAssessments') ?>
</section>
<?php endif; ?>

<section class="cp-record-section" aria-labelledby="guidance-client-surveys-title">
  <div class="cp-record-section-head">
    <h4 class="cp-record-section-title" id="guidance-client-surveys-title">
      Surveys &amp; routine interviews
      <span class="cp-card-count"><?= number_format((int) $totals['surveys']) ?></span>
    </h4>
  </div>
  <?php if (!$can['surveys']): ?>
    <div class="cp-notice" role="note">Survey-response and interview histories require survey-response read permission.</div>
  <?php elseif ($surveys === []): ?>
    <div class="cp-empty-pane">
      <i class="bi bi-clipboard-check" aria-hidden="true"></i>
      No identified survey responses have been recorded.
    </div>
  <?php else: ?>
    <div class="cp-visit-list">
      <?php foreach ($surveys as $survey): ?>
        <article class="cp-visit-card">
          <div class="cp-visit-head">
            <div class="cp-visit-title-col">
              <h5 class="cp-visit-title"><?= esc((string) $survey['title']) ?></h5>
              <span class="cp-visit-meta">
                Submitted <span class="cp-mono"><?= esc($formatDate($survey['submitted_at'])) ?></span>
                <?php if (!empty($survey['interviewed_at'])): ?>
                  · <span class="cp-status-inline cp-status-inline--active"><span class="cp-dot" aria-hidden="true"></span>Interview verified by <?= esc((string) ($survey['interviewer_name'] ?? 'Guidance staff')) ?> on <?= esc($formatDate($survey['interviewed_at'])) ?></span>
                <?php else: ?>
                  · <span class="cp-status-inline cp-status-inline--muted"><span class="cp-dot" aria-hidden="true"></span>Staff interview verification pending</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="cp-visit-actions">
              <a href="<?= esc(site_url('/guidance/surveys/' . (int) $survey['survey_id'] . '/responses/' . (int) $survey['response_id'])) ?>" class="btn btn-outline-primary btn-sm">Open Response</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($can['surveys']): ?><?= $historyPager('surveys') ?><?php endif; ?>
</section>
