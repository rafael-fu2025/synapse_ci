<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc($backUrl, 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
  </a>
  <a href="<?= esc($pdfUrl, 'attr') ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener">
    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download Report PDF
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$results = $results ?? [];
$domains = $results['domains'] ?? [];
$studentMeta = $studentMeta ?? ['full_name' => 'Student', 'mis_id' => 'N/A', 'course_year' => 'N/A'];
$assignment = $results['assignment'] ?? [];
$attestation = $results['attestation'] ?? [];
$mode = $mode ?? 'portal';

$strengthBand = static function (int $score): array {
    if ($score >= 32) {
        return ['Highly Developed', 'bg-success'];
    }
    if ($score >= 24) {
        return ['Moderately Developed', 'bg-primary'];
    }
    if ($score >= 16) {
        return ['Developing', 'bg-info text-dark'];
    }
    return ['Emerging', 'bg-secondary'];
};
?>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <div class="row g-3 align-items-center">
      <div class="col-md-8">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-primary-subtle text-primary">Walter McKenzie (1999) · 80-Item Profile</span>
          <span class="badge bg-light text-dark border"><?= $mode === 'counselor' ? 'Counselor Review' : 'Student Copy' ?></span>
        </div>
        <h4 class="fw-bold mb-1"><?= esc($studentMeta['full_name']) ?></h4>
        <p class="text-muted small mb-0">
          MIS ID: <strong><?= esc($studentMeta['mis_id']) ?></strong>
          · Course/Year: <strong><?= esc($studentMeta['course_year']) ?></strong>
          <?php if (!empty($assignment['academic_term']) || !empty($assignment['academic_year'])): ?>
            · Term: <strong><?= esc(trim(($assignment['academic_term'] ?? '') . ' ' . ($assignment['academic_year'] ?? ''))) ?></strong>
          <?php endif; ?>
        </p>
      </div>
      <div class="col-md-4 text-md-end">
        <div class="small text-muted">Student Digital Attestation</div>
        <div class="fw-semibold small"><?= esc($attestation['attested_at'] ?? 'Finalized') ?></div>
        <?php if (!empty($attestation['snapshot_hash'])): ?>
          <code class="small text-muted">SHA-256: <?= esc(substr((string) $attestation['snapshot_hash'], 0, 16)) ?>…</code>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-light border shadow-sm mb-4">
  <i class="bi bi-info-circle text-primary me-2" aria-hidden="true"></i>
  <strong>Developmental Educational Profile (All 8 Domains):</strong>
  Scores are presented on a <strong>0–40</strong> scale in canonical source order across all eight intelligence domains without truncation or clinical/diagnostic labeling.
</div>

<!-- Horizontal Bar Chart across ALL 8 canonical domains -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent py-3">
    <h5 class="card-title mb-0 fw-bold">
      <i class="bi bi-bar-chart-horizontal me-2 text-primary" aria-hidden="true"></i>8-Domain Multiple Intelligences Score Profile (0–40)
    </h5>
  </div>
  <div class="card-body">
    <div class="vstack gap-3">
      <?php foreach ($domains as $domain): ?>
        <?php
        $score = (int) ($domain['score'] ?? 0);
        $max = (int) ($domain['max_score'] ?? 40);
        $pct = (float) ($domain['percentage'] ?? round(($score / max(1, $max)) * 100, 1));
        [$bandLabel, $bandClass] = $strengthBand($score);
        ?>
        <div>
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-1 gap-2">
            <span class="fw-semibold">
              <?= (int) ($domain['order'] ?? 0) ?>. <?= esc($domain['title'] ?? '') ?>
              <small class="text-muted">(<?= esc($domain['abbreviation'] ?? '') ?>)</small>
            </span>
            <span class="d-flex align-items-center gap-2">
              <span class="badge <?= $bandClass ?>"><?= esc($bandLabel) ?></span>
              <strong><?= $score ?> / <?= $max ?></strong>
              <small class="text-muted">(<?= number_format($pct, 1) ?>%)</small>
            </span>
          </div>
          <div class="progress" style="height: 14px;">
            <div class="progress-bar bg-primary"
                 role="progressbar"
                 style="width: <?= $pct ?>%;"
                 aria-valuenow="<?= $score ?>"
                 aria-valuemin="0"
                 aria-valuemax="<?= $max ?>">
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Full Descriptions & Educational Suggestions for ALL 8 domains in canonical order -->
<div class="row g-3">
  <?php foreach ($domains as $domain): ?>
    <?php
    $score = (int) ($domain['score'] ?? 0);
    $max = (int) ($domain['max_score'] ?? 40);
    [$bandLabel, $bandClass] = $strengthBand($score);
    ?>
    <div class="col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
          <h6 class="fw-bold mb-0">
            <?= (int) ($domain['order'] ?? 0) ?>. <?= esc($domain['title'] ?? '') ?>
          </h6>
          <span class="badge <?= $bandClass ?>"><?= $score ?> / <?= $max ?> · <?= esc($bandLabel) ?></span>
        </div>
        <div class="card-body">
          <p class="small mb-2"><strong>Domain Description:</strong> <?= esc($domain['description'] ?? '') ?></p>
          <p class="small text-muted mb-2"><strong>Self-Inquiry Characteristics:</strong> <?= esc($domain['characteristics'] ?? '') ?></p>
          <div class="p-2 rounded bg-body-tertiary small">
            <strong>Educational Suggestions:</strong> <?= esc($domain['educational_suggestions'] ?? '') ?>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
