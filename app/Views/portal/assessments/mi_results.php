<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc($backUrl, 'attr') ?>" class="portal-back-btn">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Back
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
        return ['Highly Developed', 'is-high'];
    }
    if ($score >= 24) {
        return ['Moderately Developed', 'is-moderate'];
    }
    if ($score >= 16) {
        return ['Developing', 'is-developing'];
    }
    return ['Emerging', 'is-emerging'];
};
?>

<div class="mi-survey mi-results">
  <div class="mi-sheet">

    <section class="mi-band mi-results-head">
      <div class="mi-results-top">
        <div class="mi-results-identity">
          <div class="mi-results-eyebrow">
            <span>Walter McKenzie (1999) · 80-Item Profile</span>
            <span class="mi-subtitle-sep" aria-hidden="true">·</span>
            <span><?= $mode === 'counselor' ? 'Counselor Review' : 'Student Copy' ?></span>
          </div>
          <h2 class="mi-results-student"><?= esc($studentMeta['full_name']) ?></h2>
          <p class="mi-results-meta">
            MIS ID: <strong><?= esc($studentMeta['mis_id']) ?></strong>
            <span class="mi-subtitle-sep" aria-hidden="true">·</span>
            Course/Year: <strong><?= esc($studentMeta['course_year']) ?></strong>
            <?php if (!empty($assignment['academic_term']) || !empty($assignment['academic_year'])): ?>
              <span class="mi-subtitle-sep" aria-hidden="true">·</span>
              Term: <strong><?= esc(trim(($assignment['academic_term'] ?? '') . ' ' . ($assignment['academic_year'] ?? ''))) ?></strong>
            <?php endif; ?>
          </p>
        </div>

        <div class="mi-results-attestation">
          <span class="mi-progress-label">Student Digital Attestation</span>
          <span class="mi-attestation-time"><?= esc($attestation['attested_at'] ?? 'Finalized') ?></span>
          <?php if (!empty($attestation['snapshot_hash'])): ?>
            <code class="mi-attestation-hash">SHA-256: <?= esc(substr((string) $attestation['snapshot_hash'], 0, 16)) ?>…</code>
          <?php endif; ?>
        </div>
      </div>

      <div class="mi-results-disclosure">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
          <strong>Developmental Educational Profile (All 8 Domains):</strong>
          Scores are presented on a <strong>0–40</strong> scale in canonical source order across all eight intelligence domains without truncation or clinical/diagnostic labeling.
        </div>
      </div>
    </section>

    <section class="mi-band">
      <div class="mi-section-head">
        <h3 class="mi-section-title">8-Domain Multiple Intelligences Score Profile (0–40)</h3>
        <span class="mi-section-progress">Canonical Order</span>
      </div>

      <div class="mi-score-ledger">
        <?php foreach ($domains as $domain): ?>
          <?php
          $score = (int) ($domain['score'] ?? 0);
          $max = (int) ($domain['max_score'] ?? 40);
          $pct = (float) ($domain['percentage'] ?? round(($score / max(1, $max)) * 100, 1));
          [$bandLabel, $bandTone] = $strengthBand($score);
          ?>
          <div class="mi-score-row">
            <div class="mi-score-top">
              <span class="mi-score-domain">
                <?= (int) ($domain['order'] ?? 0) ?>. <?= esc($domain['title'] ?? '') ?>
                <span class="mi-score-abbr">(<?= esc($domain['abbreviation'] ?? '') ?>)</span>
              </span>
              <span class="mi-score-meta">
                <span class="mi-strength-label <?= $bandTone ?>"><?= esc($bandLabel) ?></span>
                <strong class="mi-score-num"><?= $score ?> / <?= $max ?></strong>
                <span class="mi-score-pct">(<?= number_format($pct, 1) ?>%)</span>
              </span>
            </div>
            <div class="progress mi-domain-progress">
              <div class="progress-bar"
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
    </section>

    <section class="mi-band">
      <div class="mi-section-head">
        <h3 class="mi-section-title">Domain Descriptions &amp; Educational Guidance</h3>
        <span class="mi-section-progress">8 Domains</span>
      </div>

      <div class="mi-domain-grid">
        <?php foreach ($domains as $domain): ?>
          <?php
          $score = (int) ($domain['score'] ?? 0);
          $max = (int) ($domain['max_score'] ?? 40);
          [$bandLabel, $bandTone] = $strengthBand($score);
          ?>
          <article class="mi-domain-item">
            <div class="mi-domain-item-head">
              <h4 class="mi-domain-item-title">
                <?= (int) ($domain['order'] ?? 0) ?>. <?= esc($domain['title'] ?? '') ?>
              </h4>
              <span class="mi-domain-item-score">
                <strong class="mi-score-num"><?= $score ?> / <?= $max ?></strong>
                <span class="mi-subtitle-sep" aria-hidden="true">·</span>
                <span class="mi-strength-label <?= $bandTone ?>"><?= esc($bandLabel) ?></span>
              </span>
            </div>
            <p class="mi-domain-copy">
              <strong>Domain Description:</strong> <?= esc($domain['description'] ?? '') ?>
            </p>
            <p class="mi-domain-copy is-muted">
              <strong>Self-Inquiry Characteristics:</strong> <?= esc($domain['characteristics'] ?? '') ?>
            </p>
            <p class="mi-domain-suggestion">
              <strong>Educational Suggestions:</strong> <?= esc($domain['educational_suggestions'] ?? '') ?>
            </p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

  </div>
</div>
<?= $this->endSection() ?>
