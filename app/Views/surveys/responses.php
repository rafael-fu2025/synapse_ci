<?= $this->extend('layouts/app') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-surveys.css'), 'attr') ?>">
<?= $this->endSection() ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('guidance/surveys')) ?>">
    <i class="bi bi-arrow-left me-1"></i> Back to Surveys
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php 
$total = (int) ($pagination['total'] ?? count($items));
$questions = $survey['questions'] ?? [];
$analytics = $analytics ?? [
    'total_submissions' => $total,
    'overall_avg_rating' => null,
    'questions' => [],
    'daily_trend' => [],
];
$overallRating = $analytics['overall_avg_rating'] ?? null;
$refreshOwner = 'survey-responses.' . (int) $survey['id'];
?>
<section class="guidance-ui gs-workspace gs-analytics" data-refresh-owner="<?= esc($refreshOwner, 'attr') ?>" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
<div class="gs-sheet">
  <header class="gs-band gs-overview-head">
    <div class="gs-overview-meta">
      <span class="gs-status is-<?= esc($survey['status'] ?? 'published', 'attr') ?>"><?= esc(ucfirst($survey['status'] ?? 'published')) ?></span>
      <span class="gs-meta">Audience: <?= esc($survey['audience'] ?? 'all') ?></span>
      <span class="gs-count"><?= number_format($total) ?> Responses</span>
    </div>
    <h2 class="gs-intro-title"><?= esc($survey['title']) ?></h2>
    <?php if (!empty($survey['description'])): ?><p class="gs-intro-desc"><?= esc($survey['description']) ?></p><?php endif; ?>
    <?php if (!empty($survey['external_url'])): ?>
      <a href="<?= esc((string) $survey['external_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm gs-external-action"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open External Survey Link</a>
    <?php endif; ?>
    <p class="gs-overview-note">Identified responses, not anonymous feedback. Response access and downloads are audited.</p>
  </header>

<?php if ($canExportResponses ?? false): ?>
<section class="gs-band gs-export-section" aria-labelledby="surveyExportHeading">
  <h2 class="gs-results-title" id="surveyExportHeading">Export identified survey responses</h2>
  <p class="gs-export-note">This is not anonymous analytics: the CSV contains respondent names and plaintext answers. Store it securely and share only with authorized recipients. Downloads are audited; the server does not retain the CSV. Maximum 500 responses and 31 calendar days, inclusive; larger exports are rejected, not truncated.</p>
  <form method="post" action="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses/export'), 'attr') ?>" class="gs-export-form" data-report-download>
    <?= csrf_field() ?>
    <div class="gs-field gs-export-range">
      <label class="form-label" for="response-export-range">Date range</label>
      <input id="response-export-range" class="form-control" type="text" data-daterangepicker data-target="#response-export-from" data-range-end="#response-export-to" data-max-days="31" autocomplete="off" placeholder="DD/MM/YYYY – DD/MM/YYYY" aria-describedby="response-export-date-help" required>
      <input id="response-export-from" type="hidden" name="from" value="">
      <input id="response-export-to" type="hidden" name="to" value="">
      <p id="response-export-date-help" class="gs-field-note">Dates use Philippine time (Asia/Manila). Both selected dates are included.</p>
    </div>
    <label class="gs-export-consent"><input class="form-check-input" type="checkbox" name="confirm_identified" value="1" required><span>I understand this download contains identified survey answers.</span></label>
    <div><button type="submit" class="btn btn-outline-primary btn-sm">Download identified response CSV</button></div>
  </form>
</section>
<?php else: ?>
<p class="gs-band gs-export-note gs-export-section">Identified CSV downloads additionally require reports.export and Guidance administrator or supervisor access. Viewing responses does not grant export access.</p>
<?php endif; ?>

<section class="gs-metrics" aria-label="Survey response summary">
  <article class="gs-metric nd-kpi-card">
    <span class="gs-metric-label">Submissions</span>
    <strong class="gs-metric-value nd-kpi-value"><?= number_format($total) ?></strong>
    <span class="gs-metric-note">Identified responses</span>
  </article>
  <article class="gs-metric">
    <span class="gs-metric-label">Average Satisfaction</span>
    <strong class="gs-metric-value"><?= $overallRating !== null ? esc(number_format((float) $overallRating, 1)) . ' <small>/ 5.0</small>' : '—' ?></strong>
    <span class="gs-metric-note"><?= $overallRating !== null ? esc(str_repeat('★', (int) round((float) $overallRating)) . str_repeat('☆', 5 - (int) round((float) $overallRating))) . ' rating' : 'No rating questions' ?></span>
  </article>
  <article class="gs-metric">
    <span class="gs-metric-label">Survey Instrument</span>
    <strong class="gs-metric-value"><?= !empty($survey['external_url']) ? 'External Link' : count($questions) . ' Questions' ?></strong>
    <span class="gs-metric-note"><?= !empty($survey['external_url']) ? 'Hosted form URL' : 'AES-256-GCM encrypted' ?></span>
  </article>
  <article class="gs-metric">
    <span class="gs-metric-label">Availability &amp; Window</span>
    <strong class="gs-metric-value"><?= esc(ucfirst($survey['status'] ?? 'published')) ?></strong>
    <span class="gs-metric-note">Audience: <?= esc(ucfirst($survey['audience'] ?? 'All')) ?></span>
  </article>
</section>

<div class="gs-tabs-section">
  <ul class="nav nav-tabs gs-tabs" role="tablist">
    <li class="nav-item"><button id="survey-visual-tab" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-visualizations" type="button" role="tab" aria-controls="tab-visualizations" aria-selected="true"><i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Visual Analytics &amp; Charts</button></li>
    <li class="nav-item"><button id="survey-submissions-tab" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-submissions" type="button" role="tab" aria-controls="tab-submissions" aria-selected="false"><i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>Individual Submissions (<?= number_format($total) ?>)</button></li>
  </ul>
  <div class="tab-content gs-tab-content">
    <!-- TAB 1: VISUAL ANALYTICS -->
    <div class="tab-pane fade show active" id="tab-visualizations" role="tabpanel" aria-labelledby="survey-visual-tab">
      <?php if ($total === 0): ?>
        <div class="text-center py-5 text-muted">
          <i class="bi bi-pie-chart fs-1 d-block mb-2 opacity-50"></i>
          <h6>No responses recorded yet</h6>
          <p class="small text-muted mb-0">Visual charts and question distributions will appear here as respondents complete this survey.</p>
        </div>
      <?php else: ?>

        <!-- Daily Submission Activity -->
        <?php if (!empty($analytics['daily_trend'])): ?>
          <div class="gs-trend-section">
            <h3 class="gs-results-title">Submission Activity Velocity</h3>
            <div class="gs-trend-list">
              <?php foreach ($analytics['daily_trend'] as $date => $cnt): ?>
                <div class="gs-trend-item">
                  <span class="gs-trend-date"><?= esc($date) ?></span>
                  <strong><?= number_format($cnt) ?></strong>
                  <small>submission<?= $cnt === 1 ? '' : 's' ?></small>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($survey['external_url']) && $analytics['questions'] === []): ?>
          <div class="alert alert-info small mb-0">
            <strong>External Link Survey:</strong> Respondent submissions are collected on the external form provider. Only completion confirmations and staff interview attribution notes are recorded in Synapse.
          </div>
        <?php else: ?>
        <!-- Per-Question Analytics Visualizations -->
        <h6 class="fw-bold text-dark mb-3">Question Breakdowns & Visualizations</h6>

        <?php foreach ($analytics['questions'] as $qIdx => $qStat): ?>
          <div class="survey-q-card">
            <div class="survey-q-header">
              <div>
                <span class="gs-question-number">Q<?= $qIdx + 1 ?></span>
                <strong class="gs-question-label"><?= esc($qStat['label']) ?></strong>
              </div>
              <div>
                <span class="badge bg-light text-dark border text-capitalize">
                  <?= esc($qStat['type']) ?> choice
                </span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1">
                  <?= number_format($qStat['total_answers']) ?> answered
                </span>
              </div>
            </div>

            <div class="survey-q-body">
              <?php if ($qStat['total_answers'] === 0): ?>
                <div class="text-muted small py-2">No answers recorded for this question yet.</div>

              <?php elseif ($qStat['type'] === 'rating'): ?>
                <!-- Rating Question Visual Distribution (1 to 5 Stars) -->
                <?php
                  $minAnchor = trim((string) ($qStat['scale_min_label'] ?? ''));
                  $maxAnchor = trim((string) ($qStat['scale_max_label'] ?? ''));
                ?>
                <?php if ($minAnchor !== '' || $maxAnchor !== ''): ?>
                  <div class="small text-muted mb-2">
                    Scale:
                    <?php if ($minAnchor !== ''): ?><strong>1</strong> = <?= esc($minAnchor) ?><?php endif; ?>
                    <?php if ($minAnchor !== '' && $maxAnchor !== ''): ?> · <?php endif; ?>
                    <?php if ($maxAnchor !== ''): ?><strong>5</strong> = <?= esc($maxAnchor) ?><?php endif; ?>
                  </div>
                <?php endif; ?>
                <div class="row align-items-center mb-3">
                  <div class="col-sm-4 text-center border-end gc-rating-summary-col">
                    <div class="display-5 fw-bold text-warning"><?= esc(number_format((float) ($qStat['avg_rating'] ?? 0), 1)) ?></div>
                    <div class="text-warning fs-5">
                      <?= str_repeat('★', (int) round((float) ($qStat['avg_rating'] ?? 0))) . str_repeat('☆', 5 - (int) round((float) ($qStat['avg_rating'] ?? 0))) ?>
                    </div>
                    <small class="text-muted d-block mt-1">Average Score (<?= number_format($qStat['total_answers']) ?> votes)</small>
                  </div>
                  <div class="col-sm-8 ps-sm-4">
                    <?php for ($star = 5; $star >= 1; $star--): ?>
                      <?php 
                        $cnt = (int) ($qStat['counts'][$star] ?? 0);
                        $pct = $qStat['total_answers'] > 0 ? round(($cnt / $qStat['total_answers']) * 100, 1) : 0;
                      ?>
                      <div class="survey-bar-row">
                        <div class="survey-bar-label">
                          <?php if ($star === 5 && $maxAnchor !== ''): ?><small class="text-muted d-block"><?= esc($maxAnchor) ?></small><?php endif; ?>
                          <span class="gs-rating-stars d-block"><?= $star ?> Star<?= $star === 1 ? '' : 's' ?> <span class="text-warning">★</span></span>
                          <?php if ($star === 1 && $minAnchor !== ''): ?><small class="text-muted d-block"><?= esc($minAnchor) ?></small><?php endif; ?>
                        </div>
                        <div class="survey-bar-track">
                          <div class="survey-bar-fill bar-rating-<?= $star ?>" style="width: <?= max(5, $pct) ?>%;">
                            <?= $pct ?>%
                          </div>
                        </div>
                        <div class="survey-bar-meta">
                          <?= number_format($cnt) ?> <small class="text-muted">vote<?= $cnt === 1 ? '' : 's' ?></small>
                        </div>
                      </div>
                    <?php endfor; ?>
                  </div>
                </div>

              <?php elseif (in_array($qStat['type'], ['single', 'multiple'], true)): ?>
                <!-- Single / Multiple Choice Option Frequency Visual Bars -->
                <div>
                  <?php foreach ($qStat['options'] as $opt): ?>
                    <?php 
                      $cnt = (int) ($qStat['counts'][$opt] ?? 0);
                      $pct = $qStat['total_answers'] > 0 ? round(($cnt / $qStat['total_answers']) * 100, 1) : 0;
                    ?>
                    <div class="survey-bar-row">
                      <div class="survey-bar-label" title="<?= esc($opt) ?>">
                        <?= esc($opt) ?>
                      </div>
                      <div class="survey-bar-track">
                        <div class="survey-bar-fill" style="width: <?= max(6, $pct) ?>%;">
                          <?= $pct ?>%
                        </div>
                      </div>
                      <div class="survey-bar-meta">
                        <?= number_format($cnt) ?> <small class="text-muted">pick<?= $cnt === 1 ? '' : 's' ?></small>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (!empty($qStat['other_samples'])): ?>
                    <div class="mt-3 pt-2 border-top">
                      <div class="small fw-bold text-muted text-uppercase mb-2">
                        &ldquo;Other&rdquo; Specified Responses (<?= count($qStat['other_samples']) ?>)
                      </div>
                      <div style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($qStat['other_samples'] as $sample): ?>
                          <div class="survey-quote-card">
                            <p class="mb-1"><?= esc($sample['text']) ?></p>
                            <small class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?= esc($sample['date']) ?></small>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>

              <?php elseif ($qStat['type'] === 'text'): ?>
                <!-- Qualitative Text Feedback Samples -->
                <div>
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small fw-bold text-muted text-uppercase">Qualitative Feedback Responses (<?= count($qStat['text_samples']) ?>)</span>
                  </div>
                  <?php if (empty($qStat['text_samples'])): ?>
                    <p class="text-muted small">No text entries submitted.</p>
                  <?php else: ?>
                    <div style="max-height: 260px; overflow-y: auto;">
                      <?php foreach ($qStat['text_samples'] as $sample): ?>
                        <div class="survey-quote-card">
                          <p class="mb-1 text-dark"><?= esc($sample['text']) ?></p>
                          <small class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?= esc($sample['date']) ?></small>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php endif; ?>
    </div>

    <!-- TAB 2: INDIVIDUAL SUBMISSIONS -->
    <div class="tab-pane fade" id="tab-submissions" role="tabpanel" aria-labelledby="survey-submissions-tab">
      <div data-refresh-region="submissions">
        <div class="gs-submissions-notice" role="note">
          <strong><?= number_format($pagination['total']) ?> identified submissions.</strong> This is a completion count, not anonymous participation data. Response access is audited.
        </div>
        <div class="gs-table-wrap">
          <table class="gs-table gs-submission-table" role="table">
            <caption class="visually-hidden">Identified survey submission list</caption>
            <thead role="rowgroup"><tr role="row"><th scope="col">Respondent</th><th scope="col">Submitted (UTC)</th><th scope="col">Interview Status</th><th scope="col">Action</th></tr></thead>
            <tbody role="rowgroup">
              <?php if ($items === []): ?>
                <tr role="row"><td colspan="4" class="gs-empty-cell" role="cell"><i class="bi bi-inbox" aria-hidden="true"></i>No responses recorded yet.</td></tr>
              <?php else: ?>
                <?php foreach ($items as $response): ?>
                  <tr role="row" data-submission-row>
                    <td class="gs-respondent-cell" role="cell"><span class="gs-cell-label" aria-hidden="true">Respondent</span><strong><?= esc(trim($response['first_name'] . ' ' . $response['last_name'])) ?></strong></td>
                    <td class="gs-submitted-cell" role="cell"><span class="gs-cell-label" aria-hidden="true">Submitted (UTC)</span><span class="gs-timestamp"><?= esc($response['submitted_at']) ?></span></td>
                    <td class="gs-interview-cell" role="cell">
                      <span class="gs-cell-label" aria-hidden="true">Interview Status</span>
                      <?php if (!empty($response['interviewed_by_user_id'])): ?>
                        <span class="gs-status is-completed">Interviewed by <?= esc((string) ($response['interviewer_name'] ?? 'Staff')) ?> · <?= esc(date('M j, Y', strtotime((string) $response['interviewed_at']))) ?></span>
                      <?php else: ?>
                        <span class="gs-status is-pending">Pending Interview</span>
                      <?php endif; ?>
                    </td>
                    <td class="gs-submission-action" role="cell"><a href="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses/' . $response['id']), 'attr') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Read response</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="gs-pagination-band"><?= $this->include('partials/table_pagination') ?></div>
      </div>
    </div>
  </div>
</div>
</div>
</section>
<?= $this->endSection() ?>
