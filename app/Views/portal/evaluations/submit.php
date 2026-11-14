<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/portal'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
  <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Portal
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$tokenState = $tokenState ?? 'not_found';
$rawToken = $rawToken ?? '';
$formCode = $formCode ?? 'F-OGC-GPS-PEF-008-00';
$isPsychosocial = (bool) ($isPsychosocial ?? true);
$dimensions = $dimensions ?? [];
$visitTypes = $visitTypes ?? [];
$ratingScale = $ratingScale ?? [];
$booleanField = $booleanField ?? 'future_visit_willingness';
$booleanQuestion = $booleanQuestion ?? '';
$commentField = $commentField ?? 'qualitative_comments';
$submitUrl = $submitUrl ?? site_url('/portal/evaluations/submit');
?>

<div class="alert alert-info border-0 shadow-sm mb-4" role="note">
  <div class="d-flex align-items-start gap-2">
    <i class="bi bi-shield-lock-fill fs-4 mt-1 text-primary" aria-hidden="true"></i>
    <div>
      <h6 class="fw-bold mb-1">RA 10173 Privacy &amp; Cryptographic Unlinkability Notice</h6>
      <p class="small mb-0">
        Your service evaluation is strictly <strong>pseudonymous and cryptographically unlinked</strong> from your student identity.
        Once you submit this form, your one-time token is marked redeemed and the evaluation record stores
        <strong>zero personal identifiers</strong> (no <code>person_id</code>, <code>student_id</code>, <code>session_id</code>, <code>episode_id</code>, or <code>token_id</code>).
        Individual evaluations are never disclosed to guidance providers; quantitative averages and moderated comments are only released when at least <strong>5</strong> responses have been collected.
      </p>
    </div>
  </div>
</div>

<?php if ($tokenState !== 'valid'): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
      <?php if ($tokenState === 'redeemed'): ?>
        <i class="bi bi-check-circle text-success fs-1 d-block mb-2" aria-hidden="true"></i>
        <h5 class="fw-bold">Evaluation Token Already Redeemed</h5>
        <p class="text-muted mb-3">This one-time evaluation link has already been used and cannot be submitted again.</p>
      <?php elseif ($tokenState === 'expired'): ?>
        <i class="bi bi-hourglass-bottom text-warning fs-1 d-block mb-2" aria-hidden="true"></i>
        <h5 class="fw-bold">Evaluation Token Expired</h5>
        <p class="text-muted mb-3">This one-time evaluation token has passed its expiration date.</p>
      <?php else: ?>
        <i class="bi bi-exclamation-octagon text-danger fs-1 d-block mb-2" aria-hidden="true"></i>
        <h5 class="fw-bold">Invalid Evaluation Token</h5>
        <p class="text-muted mb-3">No active evaluation token matched the provided redemption code.</p>
      <?php endif; ?>
      <a href="<?= esc(site_url('/portal'), 'attr') ?>" class="btn btn-primary btn-sm">Return to Personal Portal</a>
    </div>
  </div>
<?php else: ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
      <div>
        <h5 class="card-title fw-bold mb-0">
          <?= $isPsychosocial ? 'Psychosocial Support Evaluation Form' : 'Counseling Evaluation Form' ?>
        </h5>
        <small class="text-muted">Controlled Form Code: <code><?= esc($formCode) ?></code></small>
      </div>
      <span class="badge bg-primary-subtle text-primary">One-Time Unlinked Submission</span>
    </div>

    <div class="card-body">
      <form id="guidance-evaluation-form" method="POST" action="<?= esc($submitUrl, 'attr') ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="raw_token" value="<?= esc($rawToken, 'attr') ?>">

        <div id="eval-error-banner" class="alert alert-danger d-none mb-3" role="alert"></div>

        <?php if ($isPsychosocial): ?>
          <fieldset class="mb-4 pb-3 border-bottom">
            <legend class="fs-6 fw-bold mb-2">1. Visit Type <span class="text-danger">*</span></legend>
            <div class="row g-2">
              <?php foreach ($visitTypes as $vtKey => $vtLabel): ?>
                <div class="col-sm-6 col-md-3">
                  <label class="form-check border rounded p-2 d-flex align-items-center gap-2 mb-0 h-100">
                    <input class="form-check-input mt-0" type="radio" name="visit_type" value="<?= esc($vtKey, 'attr') ?>" required>
                    <span class="form-check-label small fw-semibold"><?= esc($vtLabel) ?></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endif; ?>

        <div class="row g-3 mb-4 pb-3 border-bottom">
          <div class="col-md-6">
            <label for="eval-academic-term" class="form-label fw-semibold">Academic Term <span class="text-muted fw-normal">(Optional)</span></label>
            <input id="eval-academic-term" type="text" name="academic_term" class="form-control" maxlength="64" placeholder="e.g. 1st Trimester">
          </div>
          <div class="col-md-6">
            <label for="eval-academic-year" class="form-label fw-semibold">Academic Year <span class="text-muted fw-normal">(Optional)</span></label>
            <input id="eval-academic-year" type="text" name="academic_year" class="form-control" maxlength="32" placeholder="e.g. 2026-2027">
          </div>
        </div>

        <div class="mb-4">
          <h6 class="fw-bold mb-1">Service Quality Ratings (1 to 5) <span class="text-danger">*</span></h6>
          <p class="small text-muted mb-3">
            1 = Not at all · 2 = To a lesser extent · 3 = To a moderate extent · 4 = To a large extent · 5 = To the full extent
          </p>

          <?php $dimNum = 1; ?>
          <?php foreach ($dimensions as $field => $question): ?>
            <fieldset class="mb-3 pb-3 border-bottom">
              <legend class="fs-6 fw-semibold mb-2"><?= $dimNum++ ?>. <?= esc($question) ?></legend>
              <div class="row g-2">
                <?php foreach ($ratingScale as $score => $label): ?>
                  <div class="col-6 col-md">
                    <label class="form-check border rounded p-2 d-flex align-items-center gap-2 mb-0 h-100">
                      <input class="form-check-input mt-0" type="radio" name="<?= esc($field, 'attr') ?>" value="<?= (int) $score ?>" required>
                      <span class="form-check-label small">
                        <strong><?= (int) $score ?></strong> · <?= esc($label) ?>
                      </span>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </fieldset>
          <?php endforeach; ?>
        </div>

        <fieldset class="mb-4 pb-3 border-bottom">
          <legend class="fs-6 fw-bold mb-2"><?= esc($booleanQuestion) ?> <span class="text-danger">*</span></legend>
          <div class="d-flex gap-3">
            <label class="form-check border rounded px-3 py-2 mb-0">
              <input class="form-check-input" type="radio" name="<?= esc($booleanField, 'attr') ?>" value="1" required>
              <span class="form-check-label fw-semibold">Yes</span>
            </label>
            <label class="form-check border rounded px-3 py-2 mb-0">
              <input class="form-check-input" type="radio" name="<?= esc($booleanField, 'attr') ?>" value="0" required>
              <span class="form-check-label fw-semibold">No</span>
            </label>
          </div>
        </fieldset>

        <div class="mb-4">
          <label for="eval-qualitative-comment" class="form-label fw-bold">
            Qualitative Comments / Suggestions <span class="text-muted fw-normal">(Optional, encrypted &amp; moderated before release)</span>
          </label>
          <textarea id="eval-qualitative-comment"
                    name="<?= esc($commentField, 'attr') ?>"
                    class="form-control"
                    rows="4"
                    maxlength="2048"
                    placeholder="Do not include your name or identifying details. Comments are encrypted at rest and reviewed by a moderator before appearing in cohort aggregates."></textarea>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <a href="<?= esc(site_url('/portal'), 'attr') ?>" class="btn btn-outline-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary" id="eval-submit-btn">
            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Submit Pseudonymous Evaluation
          </button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($tokenState === 'valid'): ?>
<script>
(() => {
  'use strict';
  const form = document.getElementById('guidance-evaluation-form');
  if (!form) return;
  const errBox = document.getElementById('eval-error-banner');
  const submitBtn = document.getElementById('eval-submit-btn');

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (errBox) errBox.classList.add('d-none');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: new FormData(form),
      });
      const data = await res.json();
      if (data.csrf && data.csrf.name && data.csrf.hash) {
        const hidden = form.querySelector(`input[name="${data.csrf.name}"]`);
        if (hidden) hidden.value = data.csrf.hash;
        window.SynapseCsrf?.sync(data.csrf);
      }
      if (!res.ok || !data.success) {
        if (errBox) {
          errBox.textContent = data.message || 'Please complete all required evaluation fields.';
          errBox.classList.remove('d-none');
        }
        if (submitBtn) submitBtn.disabled = false;
        return;
      }
      form.reset();
      window.location.assign(data.redirect || '<?= esc(site_url('/portal'), 'js') ?>');
    } catch (_) {
      if (errBox) {
        errBox.textContent = 'Network error submitting evaluation. Please try again.';
        errBox.classList.remove('d-none');
      }
      if (submitBtn) submitBtn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
