<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#issueEvalTokenModal">
    <i class="bi bi-ticket-perforated" aria-hidden="true"></i>Issue Evaluation Token
  </button>
  <?php if (!empty($canModerate)): ?>
    <a href="<?= esc(site_url('/guidance/evaluations/moderate'), 'attr') ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-chat-square-text" aria-hidden="true"></i>Comment Moderation Queue
    </a>
  <?php endif; ?>
  <a href="<?= esc(site_url('/guidance'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Guidance Dashboard
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
    <article class="ce-card gse-aggregate" data-evaluation-type="<?= esc($evaluationType, 'attr') ?>">
      <header class="ce-card-header gse-aggregate-header">
        <div class="gse-heading">
          <h2 class="ce-card-title"><?= esc($title) ?></h2>
          <p class="gse-caption">Form <span class="ce-mono gse-form-code"><?= esc($formCode) ?></span></p>
        </div>
        <a href="<?= esc($pdfHref, 'attr') ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" data-navigation="false" aria-label="<?= esc($title . ' Aggregate PDF', 'attr') ?>">
          <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>Aggregate PDF
        </a>
      </header>
      <div class="gse-cohort-status">
        <span class="ce-status-dot <?= $cohortMet ? 'ce-status-emerald' : 'ce-status-amber' ?>">
          <?= $cohortMet ? 'Cohort threshold met' : 'Reporting suppressed' ?>
        </span>
        <span class="ce-tag"><span class="gse-number"><?= $total ?></span> responses</span>
      </div>
      <div class="ce-card-body gse-aggregate-body">
        <?php if (!$cohortMet): ?>
          <div class="gse-threshold">
            <div class="gse-threshold-count">
              <span class="gse-section-label">Responses toward reporting threshold</span>
              <strong class="gse-number"><?= $total ?>/<?= $minReq ?></strong>
            </div>
            <div class="gse-meter" aria-hidden="true"><span style="width: <?= number_format(min(100, max(0, $total / max(1, $minReq) * 100)), 1) ?>%;"></span></div>
            <?php if ($total === 0): ?>
              <p class="gse-caption">No evaluation responses for this provider and the selected filters.</p>
            <?php endif; ?>
          </div>
          <div class="ce-notice ce-notice-amber" role="status">
            <div class="ce-notice-body">
              <i class="bi bi-shield-lock ce-notice-icon" aria-hidden="true"></i>
              <div>
                <h3 class="gse-notice-title">Cohort Threshold Suppression Active</h3>
                <p>At least <?= $minReq ?> response records are required for this provider, service, and filtered cohort.</p>
                <p class="gse-caption">To protect student pseudonymity under RA 10173, dimension averages, willingness/recommendation rates, visit-type distribution, and qualitative comments remain suppressed.</p>
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
          <dl class="gse-summary">
            <div class="gse-stat">
              <dt>Cohort Size</dt>
              <dd class="gse-number"><?= $total ?><span>responses</span></dd>
            </div>
            <div class="gse-stat">
              <dt>Overall Mean (1–5)</dt>
              <dd class="gse-number"><?= number_format($overallMean, 2) ?></dd>
            </div>
            <div class="gse-stat">
              <dt><?= esc($booleanLabel) ?></dt>
              <dd class="gse-number"><?= number_format($booleanPct, 1) ?>%</dd>
            </div>
          </dl>

          <section class="gse-detail-section" aria-label="<?= esc($title . ' dimension means', 'attr') ?>">
            <div class="gse-section-heading">
              <h3>Dimension Means</h3>
              <span class="gse-caption">Scale 1.00–5.00</span>
            </div>
            <ul class="gse-dimensions">
              <?php foreach ($dimensionLabels as $dimKey => $dimText): ?>
                <?php
                $mean = (float) ($averages[$dimKey] ?? 0);
                $pct = min(100, max(0, ($mean / 5.0) * 100));
                ?>
                <li>
                  <div class="gse-dimension-label">
                    <span><?= esc($dimText) ?></span>
                    <strong class="gse-number"><?= number_format($mean, 2) ?> <span>/ 5.00</span></strong>
                  </div>
                  <div class="gse-meter" aria-hidden="true"><span style="width: <?= number_format($pct, 1) ?>%;"></span></div>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>

          <?php if (is_array($visitDist)): ?>
            <section class="gse-detail-section" aria-label="<?= esc($title . ' visit type distribution', 'attr') ?>">
              <div class="gse-section-heading"><h3>Visit Type Distribution</h3></div>
              <dl class="gse-visits">
                <?php foreach ($visitTypeLabels as $vtKey => $vtLabel): ?>
                  <div>
                    <dt><?= esc($vtLabel) ?></dt>
                    <dd class="gse-number"><?= (int) ($visitDist[$vtKey] ?? 0) ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </section>
          <?php endif; ?>

          <section class="gse-detail-section" aria-label="<?= esc($title . ' approved qualitative comments', 'attr') ?>">
            <div class="gse-section-heading"><h3>Approved Qualitative Comments</h3></div>
            <?php if ($comments === []): ?>
              <p class="gse-comments-empty"><i class="bi bi-chat-square-text" aria-hidden="true"></i>No moderator-approved qualitative comments for this cohort yet.</p>
            <?php else: ?>
              <p class="gse-caption">Only moderator-approved comments are included.</p>
              <ul class="gse-comments">
                <?php foreach ($comments as $comment): ?>
                  <li><i class="bi bi-chat-quote" aria-hidden="true"></i><p><?= esc($comment) ?></p></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
};
?>
<section class="guidance-ui ce-workspace gse-workspace" aria-label="Service evaluation aggregates workspace">
  <section class="ce-card gse-context" aria-label="Provider and reporting policy">
    <div class="ce-card-body gse-context-body">
      <div class="gse-provider">
        <span class="gse-section-label">Guidance Provider</span>
        <h2><?= esc($providerName) ?></h2>
        <p class="gse-caption">Pseudonymous aggregate reporting · Minimum cohort size: 5 response records per service.</p>
      </div>
      <div class="gse-policy">
        <span class="ce-tag"><span class="ce-status-dot <?= $cohortGateEnabled ? 'ce-status-emerald' : 'ce-status-amber' ?>"><?= $cohortGateEnabled ? 'Policy Sign-off Active' : 'Cohort Policy Sign-off Pending' ?></span></span>
        <p class="gse-caption">Sign-off status is informational here. The response threshold always applies.</p>
      </div>
    </div>
  </section>

  <section class="ce-card gse-filters" aria-label="Cohort filters">
    <header class="ce-card-header">
      <div class="gse-heading">
        <h2 class="ce-card-title"><i class="bi bi-funnel" aria-hidden="true"></i>Cohort Filters</h2>
        <p class="gse-caption">Apply the same provider and academic period to both services.</p>
      </div>
    </header>
    <div class="ce-card-body">
      <form method="GET" action="<?= esc(site_url('/guidance/evaluations/dashboard'), 'attr') ?>" class="gse-filter-form <?= $canViewAnyProvider && $providers !== [] ? 'gse-filter-form-provider' : '' ?>">
        <?php if ($canViewAnyProvider && $providers !== []): ?>
          <div class="gse-filter-field gse-provider-field">
            <label for="eval-provider-select" class="form-label">Guidance Provider</label>
            <select id="eval-provider-select" name="provider_user_id" class="form-select" data-searchable="true">
              <?php foreach ($providers as $prov): ?>
                <?php
                $pName = trim(trim((string) ($prov['first_name'] ?? '')) . ' ' . trim((string) ($prov['last_name'] ?? '')));
                $pDisplay = $pName !== '' ? $pName : (string) $prov['username'];
                ?>
                <option value="<?= (int) $prov['id'] ?>" <?= (int) $prov['id'] === $providerUserId ? 'selected' : '' ?>><?= esc($pDisplay) ?> (<?= esc(ucwords(str_replace('_', ' ', (string) $prov['role']))) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="gse-filter-field">
          <label for="eval-filter-term" class="form-label">Academic Term</label>
          <input id="eval-filter-term" type="text" name="academic_term" class="form-control" maxlength="64" value="<?= esc($filters['academic_term'] ?? '', 'attr') ?>" placeholder="All terms">
        </div>
        <div class="gse-filter-field">
          <label for="eval-filter-year" class="form-label">Academic Year</label>
          <input id="eval-filter-year" type="text" name="academic_year" class="form-control" maxlength="32" value="<?= esc($filters['academic_year'] ?? '', 'attr') ?>" placeholder="All years">
        </div>
        <div class="gse-filter-actions">
          <button type="submit" class="btn btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>Apply</button>
          <a href="<?= esc(site_url('/guidance/evaluations/dashboard'), 'attr') ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
      </form>
    </div>
  </section>

  <div class="gse-aggregate-grid">
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
</section>
<?= view('guidance/evaluations/_issue_modal', [
    'evalIssuePersonId'          => 0,
    'evalIssueProviders'         => $providers ?? [],
    'evalIssueProviderId'        => $providerUserId ?? 0,
    'evalIssueCanSelectProvider' => !empty($canViewAnyProvider),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-service-evaluations.css'), 'attr') ?>">
<?= $this->endSection() ?>
