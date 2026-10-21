<?= $this->extend('layouts/app') ?>

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
?>

<!-- Survey Title & Status Header -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-secondary text-uppercase"><?= esc($survey['status'] ?? 'published') ?></span>
          <span class="badge bg-light text-dark border text-uppercase">Audience: <?= esc($survey['audience'] ?? 'all') ?></span>
        </div>
        <h4 class="fw-bold mb-1 text-dark"><?= esc($survey['title']) ?></h4>
        <?php if (!empty($survey['description'])): ?>
          <p class="text-muted small mb-0"><?= esc($survey['description']) ?></p>
        <?php endif; ?>
      </div>
      <div class="text-end">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6">
          <i class="bi bi-people-fill me-1"></i> <?= number_format($total) ?> Responses
        </span>
      </div>
    </div>
  </div>
</div>

<?php if ($canExportResponses ?? false): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
  <h2 class="h6">Export identified survey responses</h2>
  <p class="small">This is not anonymous analytics: the CSV contains respondent names and plaintext answers. Store it securely and share only with authorized recipients. Downloads are audited; the server does not retain the CSV. Maximum 500 responses and 31 inclusive Manila calendar days; larger exports are rejected, not truncated.</p>
  <form method="post" action="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses/export')) ?>" class="row g-3" data-report-download>
    <?= csrf_field() ?>
    <div class="col-sm-4"><label class="form-label" for="response-export-from">From (Manila)</label><input id="response-export-from" class="form-control" type="date" name="from" required></div>
    <div class="col-sm-4"><label class="form-label" for="response-export-to">To (Manila, inclusive)</label><input id="response-export-to" class="form-control" type="date" name="to" required></div>
    <div class="col-12"><label><input type="checkbox" name="confirm_identified" value="1" required> I understand this download contains identified survey answers.</label></div>
    <div class="col-12"><button type="submit" class="btn btn-outline-primary btn-sm">Download identified response CSV</button></div>
  </form>
</div></div>
<?php else: ?>
<p class="small text-muted">Identified CSV downloads additionally require reports.export and Guidance administrator or supervisor access. Viewing responses does not grant export access.</p>
<?php endif; ?>

<!-- NiceAdmin v3 KPI Cards Rail -->
<section class="row g-3 mb-4 nd-kpi-rail">
  <div class="col-xxl-3 col-md-6">
    <article class="nd-kpi-card nd-kpi-leads">
      <span class="nd-kpi-icon"><i class="bi bi-people"></i></span>
      <span class="nd-kpi-label">Submissions</span>
      <strong class="nd-kpi-value"><?= number_format($total) ?></strong>
      <span class="nd-kpi-trend up">
        <i class="bi bi-check2-circle"></i> Identified responses
      </span>
    </article>
  </div>

  <div class="col-xxl-3 col-md-6">
    <article class="nd-kpi-card nd-kpi-cycle">
      <span class="nd-kpi-icon"><i class="bi bi-star"></i></span>
      <span class="nd-kpi-label">Average Satisfaction</span>
      <strong class="nd-kpi-value">
        <?= $overallRating !== null ? esc(number_format((float) $overallRating, 1)) . ' <small class="text-muted fw-normal fs-6">/ 5.0</small>' : '—' ?>
      </strong>
      <span class="nd-kpi-trend <?= $overallRating !== null && $overallRating >= 3.5 ? 'up' : '' ?>">
        <?php if ($overallRating !== null): ?>
          <i class="bi bi-arrow-up-right"></i> <?= str_repeat('★', (int) round((float) $overallRating)) . str_repeat('☆', 5 - (int) round((float) $overallRating)) ?> rating
        <?php else: ?>
          No rating questions
        <?php endif; ?>
      </span>
    </article>
  </div>

  <div class="col-xxl-3 col-md-6">
    <article class="nd-kpi-card nd-kpi-retention">
      <span class="nd-kpi-icon"><i class="bi bi-shield-check"></i></span>
      <span class="nd-kpi-label">Survey Instrument</span>
      <strong class="nd-kpi-value"><?= count($questions) ?> Questions</strong>
      <span class="nd-kpi-trend up">
        <i class="bi bi-lock-fill"></i> AES-256-GCM encrypted
      </span>
    </article>
  </div>

  <div class="col-xxl-3 col-md-6">
    <article class="nd-kpi-card nd-kpi-revenue">
      <span class="nd-kpi-icon"><i class="bi bi-broadcast"></i></span>
      <span class="nd-kpi-label">Availability & Window</span>
      <strong class="nd-kpi-value"><?= esc(ucfirst($survey['status'] ?? 'published')) ?></strong>
      <span class="nd-kpi-trend <?= ($survey['status'] ?? '') === 'published' ? 'up' : 'down' ?>">
        <i class="bi bi-person-check"></i> Audience: <?= esc(ucfirst($survey['audience'] ?? 'All')) ?>
      </span>
    </article>
  </div>
</section>

<!-- Tabs: Visual Analytics vs Individual Submissions -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent border-bottom p-0">
    <ul class="nav nav-tabs nav-tabs-bordered" role="tablist">
      <li class="nav-item">
        <button class="nav-link active py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-visualizations" type="button" role="tab">
          <i class="bi bi-bar-chart-line-fill me-1"></i> Visual Analytics & Charts
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-submissions" type="button" role="tab">
          <i class="bi bi-person-lines-fill me-1"></i> Individual Submissions (<?= number_format($total) ?>)
        </button>
      </li>
    </ul>
  </div>

  <div class="card-body p-4 tab-content">
    <!-- TAB 1: VISUAL ANALYTICS -->
    <div class="tab-pane fade show active" id="tab-visualizations" role="tabpanel">
      <?php if ($total === 0): ?>
        <div class="text-center py-5 text-muted">
          <i class="bi bi-pie-chart fs-1 d-block mb-2 opacity-50"></i>
          <h6>No responses recorded yet</h6>
          <p class="small text-muted mb-0">Visual charts and question distributions will appear here as respondents complete this survey.</p>
        </div>
      <?php else: ?>

        <!-- Daily Submission Activity -->
        <?php if (!empty($analytics['daily_trend'])): ?>
          <div class="card bg-light border-0 p-3 mb-4">
            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-graph-up text-primary me-1"></i> Submission Activity Velocity</h6>
            <div class="d-flex flex-wrap gap-2 pt-1">
              <?php foreach ($analytics['daily_trend'] as $date => $cnt): ?>
                <div class="p-2 bg-white border rounded text-center" style="min-width: 85px;">
                  <div class="small fw-semibold text-muted" style="font-size: 0.72rem;"><?= esc($date) ?></div>
                  <div class="fw-bold fs-6 text-primary"><?= number_format($cnt) ?></div>
                  <small class="text-muted" style="font-size: 0.65rem;">submission<?= $cnt === 1 ? '' : 's' ?></small>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Per-Question Analytics Visualizations -->
        <h6 class="fw-bold text-dark mb-3">Question Breakdowns & Visualizations</h6>

        <?php foreach ($analytics['questions'] as $qIdx => $qStat): ?>
          <div class="survey-q-card">
            <div class="survey-q-header">
              <div>
                <span class="badge bg-secondary me-2">Q<?= $qIdx + 1 ?></span>
                <strong class="text-dark"><?= esc($qStat['label']) ?></strong>
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
                <div class="row align-items-center mb-3">
                  <div class="col-sm-4 text-center border-end">
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
                          <?= $star ?> Star<?= $star === 1 ? '' : 's' ?>
                          <span class="text-warning">★</span>
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
    </div>

    <!-- TAB 2: INDIVIDUAL SUBMISSIONS -->
    <div class="tab-pane fade" id="tab-submissions" role="tabpanel">
      <!-- Preserves required identified submissions notice for automated audit & feature tests -->
      <div class="alert alert-info small mb-3">
        <strong><?= number_format($pagination['total']) ?> identified submissions.</strong> This is a completion count, not anonymous participation data. Response access is audited.
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <caption class="visually-hidden">Identified survey submission list</caption>
          <thead class="table-light small text-muted text-uppercase">
            <tr>
              <th scope="col" class="ps-3">Respondent</th>
              <th scope="col">Submitted (UTC)</th>
              <th scope="col" class="text-end pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($items === []): ?>
              <tr>
                <td colspan="3" class="text-center text-muted py-5">
                  <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                  No responses recorded yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($items as $response): ?>
                <tr>
                  <td class="ps-3 fw-semibold text-dark">
                    <?= esc(trim($response['first_name'] . ' ' . $response['last_name'])) ?>
                  </td>
                  <td class="text-muted small">
                    <?= esc($response['submitted_at']) ?>
                  </td>
                  <td class="text-end pe-3">
                    <a href="<?= esc(site_url('guidance/surveys/' . $survey['id'] . '/responses/' . $response['id'])) ?>" class="btn btn-outline-primary btn-sm">
                      <i class="bi bi-eye me-1"></i> Read response
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="mt-3">
        <?= $this->include('partials/table_pagination') ?>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
