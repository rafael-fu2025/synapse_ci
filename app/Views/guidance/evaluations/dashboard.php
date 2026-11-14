<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <?php if (!empty($canModerate)): ?>
    <a href="<?= esc(site_url('/guidance/evaluations/moderate'), 'attr') ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-chat-square-text me-1" aria-hidden="true"></i>Comment Moderation Queue
    </a>
  <?php endif; ?>
  <a href="<?= esc(site_url('/guidance'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Guidance Dashboard
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$psychosocial = $psychosocial ?? [];
$counseling = $counseling ?? [];
$psychosocialLabels = $psychosocialLabels ?? [];
$counselingLabels = $counselingLabels ?? [];
$visitTypeLabels = $visitTypeLabels ?? [];
$providerUserId = (int) ($providerUserId ?? 0);
$providerName = $providerName ?? 'Guidance Provider';
$providers = $providers ?? [];
$canViewAnyProvider = (bool) ($canViewAnyProvider ?? false);
$filters = $filters ?? [];
$cohortGateEnabled = (bool) ($cohortGateEnabled ?? false);

$renderAggregateCard = static function (
    string $title,
    string $formCode,
    string $evaluationType,
    array $agg,
    array $dimensionLabels,
    string $booleanLabel,
    array $visitTypeLabels,
    int $providerUserId,
    array $filters
): string {
    $cohortMet = (bool) ($agg['cohort_met'] ?? false);
    $total = (int) ($agg['total_responses'] ?? 0);
    $minReq = (int) ($agg['minimum_required'] ?? 5);
    $pdfQuery = http_build_query(array_filter([
        'evaluation_type'  => $evaluationType,
        'provider_user_id' => $providerUserId,
        'academic_term'    => $filters['academic_term'] ?? '',
        'academic_year'    => $filters['academic_year'] ?? '',
    ], static fn ($v) => $v !== '' && $v !== null));
    $pdfHref = site_url('/guidance/evaluations/aggregate-pdf?' . $pdfQuery);

    ob_start();
    ?>
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div>
          <h5 class="card-title fw-bold mb-0"><?= esc($title) ?></h5>
          <small class="text-muted">Form Code: <code><?= esc($formCode) ?></code></small>
        </div>
        <a href="<?= esc($pdfHref, 'attr') ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
          <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Aggregate PDF
        </a>
      </div>
      <div class="card-body">
        <?php if (!$cohortMet): ?>
          <div class="alert alert-warning border-0 mb-0" role="status">
            <div class="d-flex align-items-start gap-2">
              <i class="bi bi-shield-lock fs-4 mt-1" aria-hidden="true"></i>
              <div>
                <h6 class="fw-bold mb-1">Cohort Threshold Suppression Active</h6>
                <p class="mb-2">
                  Minimum <?= $minReq ?> responses required; currently <?= $total ?>/<?= $minReq ?>.
                </p>
                <p class="small text-muted mb-0">
                  To protect student pseudonymity under RA 10173, dimension averages, willingness/recommendation rates, and qualitative comments are suppressed until at least <?= $minReq ?> responses are recorded.
                </p>
              </div>
            </div>
          </div>
        <?php else: ?>
          <?php
          $averages = $agg['averages'] ?? [];
          $overallMean = (float) ($averages['overall_mean'] ?? 0);
          $booleanPct = (float) ($agg['boolean_percentage'] ?? round(((float) ($agg['boolean_rate'] ?? 0)) * 100, 1));
          $comments = $agg['comments'] ?? [];
          $visitDist = $agg['visit_type_distribution'] ?? null;
          ?>
          <div class="row g-3 mb-4">
            <div class="col-sm-4">
              <div class="p-3 rounded bg-body-tertiary text-center h-100">
                <div class="small text-muted">Cohort Size</div>
                <div class="h4 fw-bold mb-0"><?= $total ?></div>
              </div>
            </div>
            <div class="col-sm-4">
              <div class="p-3 rounded bg-body-tertiary text-center h-100">
                <div class="small text-muted">Overall Mean (1–5)</div>
                <div class="h4 fw-bold text-primary mb-0"><?= number_format($overallMean, 2) ?></div>
              </div>
            </div>
            <div class="col-sm-4">
              <div class="p-3 rounded bg-body-tertiary text-center h-100">
                <div class="small text-muted"><?= esc($booleanLabel) ?></div>
                <div class="h4 fw-bold text-success mb-0"><?= number_format($booleanPct, 1) ?>%</div>
              </div>
            </div>
          </div>

          <h6 class="fw-bold mb-2">Dimension Means (1.00 – 5.00)</h6>
          <div class="vstack gap-2 mb-4">
            <?php foreach ($dimensionLabels as $dimKey => $dimText): ?>
              <?php
              $mean = (float) ($averages[$dimKey] ?? 0);
              $pct = min(100, max(0, ($mean / 5.0) * 100));
              ?>
              <div>
                <div class="d-flex justify-content-between small mb-1 gap-2">
                  <span><?= esc($dimText) ?></span>
                  <strong><?= number_format($mean, 2) ?> / 5.00</strong>
                </div>
                <div class="progress" style="height: 8px;">
                  <div class="progress-bar bg-primary" style="width: <?= number_format($pct, 1) ?>%;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (is_array($visitDist)): ?>
            <h6 class="fw-bold mb-2">Visit Type Distribution</h6>
            <div class="d-flex flex-wrap gap-2 mb-4">
              <?php foreach ($visitTypeLabels as $vtKey => $vtLabel): ?>
                <span class="badge bg-light text-dark border">
                  <?= esc($vtLabel) ?>: <strong><?= (int) ($visitDist[$vtKey] ?? 0) ?></strong>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <h6 class="fw-bold mb-2">Approved Qualitative Comments</h6>
          <?php if ($comments === []): ?>
            <p class="small text-muted mb-0">No moderator-approved qualitative comments for this cohort yet.</p>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($comments as $comment): ?>
                <li class="list-group-item px-0 small">
                  <i class="bi bi-chat-quote text-primary me-2" aria-hidden="true"></i><?= esc($comment) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>

<div class="alert alert-light border shadow-sm mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <strong>Provider:</strong> <?= esc($providerName) ?>
    <span class="text-muted small ms-2">· Strictly pseudonymous, threshold-safe reporting (MIN_COHORT_SIZE = 5)</span>
  </div>
  <span class="badge <?= $cohortGateEnabled ? 'bg-success' : 'bg-warning text-dark' ?>">
    <?= $cohortGateEnabled ? 'Policy Sign-off Active' : 'Cohort Policy Sign-off Pending' ?>
  </span>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <form method="GET" action="<?= esc(site_url('/guidance/evaluations/dashboard'), 'attr') ?>" class="row g-2 align-items-end">
      <?php if ($canViewAnyProvider && $providers !== []): ?>
        <div class="col-md-4">
          <label for="eval-provider-select" class="form-label small fw-semibold">Guidance Provider</label>
          <select id="eval-provider-select" name="provider_user_id" class="form-select form-select-sm">
            <?php foreach ($providers as $prov): ?>
              <?php
              $pName = trim(trim((string) ($prov['first_name'] ?? '')) . ' ' . trim((string) ($prov['last_name'] ?? '')));
              $pDisplay = $pName !== '' ? $pName : (string) $prov['username'];
              ?>
              <option value="<?= (int) $prov['id'] ?>" <?= (int) $prov['id'] === $providerUserId ? 'selected' : '' ?>>
                <?= esc($pDisplay) ?> (<?= esc(ucwords(str_replace('_', ' ', (string) $prov['role']))) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-3">
        <label for="eval-filter-term" class="form-label small fw-semibold">Academic Term</label>
        <input id="eval-filter-term" type="text" name="academic_term" class="form-control form-control-sm" value="<?= esc($filters['academic_term'] ?? '', 'attr') ?>" placeholder="All terms">
      </div>
      <div class="col-md-3">
        <label for="eval-filter-year" class="form-label small fw-semibold">Academic Year</label>
        <input id="eval-filter-year" type="text" name="academic_year" class="form-control form-control-sm" value="<?= esc($filters['academic_year'] ?? '', 'attr') ?>" placeholder="All years">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Apply</button>
        <a href="<?= esc(site_url('/guidance/evaluations/dashboard'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-6">
    <?= $renderAggregateCard(
        'Psychosocial Support Evaluation',
        'F-OGC-GPS-PEF-008-00',
        \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL,
        $psychosocial,
        $psychosocialLabels,
        'Willingness to Visit Again',
        $visitTypeLabels,
        $providerUserId,
        $filters
    ) ?>
  </div>
  <div class="col-xl-6">
    <?= $renderAggregateCard(
        'Counseling Service Evaluation',
        'F-OGC-GCS-CEF-009-00',
        \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_COUNSELING,
        $counseling,
        $counselingLabels,
        'Service Recommendation Rate',
        $visitTypeLabels,
        $providerUserId,
        $filters
    ) ?>
  </div>
</div>
<?= $this->endSection() ?>
