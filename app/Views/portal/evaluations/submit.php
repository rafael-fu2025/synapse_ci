<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/portal'), 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Health Portal
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$tokenState = $tokenState ?? 'empty';
$rawToken = $rawToken ?? '';
$submittedToken = $submittedToken ?? '';
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

<div class="pe-eval">
  <div class="pe-eval-sheet">

    <section class="pe-eval-band pe-eval-head">
      <div class="pe-eval-top">
        <div class="pe-eval-title-group">
          <h2 class="pe-eval-title">
            <?= $isPsychosocial ? 'Psychosocial Support Evaluation Form' : 'Counseling Evaluation Form' ?>
          </h2>
          <p class="pe-eval-meta">
            <span>Controlled Form Code: <code><?= esc($formCode) ?></code></span>
            <span class="pe-eval-sep" aria-hidden="true">·</span>
            <span>One-Time Unlinked Submission</span>
          </p>
        </div>
      </div>

      <div class="pe-eval-privacy" role="note">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <div>
          <strong class="pe-eval-privacy-title">RA 10173 Privacy &amp; Cryptographic Unlinkability Notice</strong>
          <span>
            Your service evaluation is strictly <strong>pseudonymous and cryptographically unlinked</strong> from your student identity.
            Once you submit this form, your one-time token is marked redeemed and the evaluation record stores
            <strong>zero personal identifiers</strong> (no <code>person_id</code>, <code>student_id</code>, <code>session_id</code>, <code>episode_id</code>, or <code>token_id</code>).
            Individual evaluations are never disclosed to guidance providers; quantitative averages and moderated comments are only released when at least <strong>5</strong> responses have been collected.
            <a href="<?= esc(site_url('privacy-policy'), 'attr') ?>" target="_blank" rel="noopener noreferrer" data-navigation="false">Read Foundation University Data Privacy Policy</a>.
          </span>
        </div>
      </div>
    </section>

    <?php if ($tokenState !== 'valid'): ?>
      <section class="pe-eval-band pe-eval-token-band">
        <div class="pe-eval-token-state">
          <?php if ($tokenState === 'redeemed'): ?>
            <span class="pe-eval-state-indicator is-redeemed">Token Redeemed</span>
            <h3 class="pe-eval-token-title">Evaluation Token Already Redeemed</h3>
            <p class="pe-eval-token-desc">This one-time evaluation link has already been used and cannot be submitted again.</p>
          <?php elseif ($tokenState === 'expired'): ?>
            <span class="pe-eval-state-indicator is-expired">Token Expired</span>
            <h3 class="pe-eval-token-title">Evaluation Token Expired</h3>
            <p class="pe-eval-token-desc">This one-time evaluation token has passed its expiration date.</p>
          <?php elseif ($tokenState === 'not_found'): ?>
            <span class="pe-eval-state-indicator is-invalid">Invalid Token</span>
            <h3 class="pe-eval-token-title">Evaluation Token Not Recognized</h3>
            <p class="pe-eval-token-desc">The token you entered does not match an active evaluation issued by Guidance. Check for typos or paste the full evaluation link from your counselor.</p>
          <?php else: ?>
            <span class="pe-eval-state-indicator is-ready">Ready to Redeem</span>
            <h3 class="pe-eval-token-title">Enter Your Evaluation Token</h3>
            <p class="pe-eval-token-desc">Paste the one-time evaluation token or link given to you after your Guidance psychosocial support or counseling session to open the anonymous feedback form.</p>
          <?php endif; ?>

          <form method="GET" action="<?= esc(site_url('/portal/evaluations/redeem'), 'attr') ?>" class="pe-eval-redeem-form" id="eval-redeem-form">
            <label for="eval-redeem-token-input" class="pe-eval-label">One-Time Evaluation Token</label>
            <div class="pe-eval-redeem-row">
              <input id="eval-redeem-token-input"
                     type="text"
                     name="token"
                     class="form-control pe-eval-input"
                     maxlength="512"
                     value="<?= esc($submittedToken, 'attr') ?>"
                     placeholder="Paste your one-time evaluation token or link…"
                     autocomplete="off">
              <button type="submit" class="btn btn-primary pe-eval-btn">Redeem Token</button>
              <a href="<?= esc(site_url('/portal'), 'attr') ?>" class="btn btn-outline-secondary pe-eval-btn">Return to Personal Portal</a>
            </div>
            <div id="eval-redeem-error" class="alert alert-danger py-2 px-3 small mt-2 d-none" role="alert"></div>
          </form>
        </div>
      </section>
    <?php else: ?>
      <form id="guidance-evaluation-form" class="pe-eval-form" method="POST" action="<?= esc($submitUrl, 'attr') ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="raw_token" value="<?= esc($rawToken, 'attr') ?>">

        <div id="eval-error-banner" class="alert alert-danger pe-eval-error d-none" role="alert"></div>

        <section class="pe-eval-band">
          <fieldset class="pe-eval-fieldset mb-4">
            <legend class="pe-eval-section-title mb-2">
              1. Visit Type <span class="pe-eval-req">*</span>
            </legend>
            <p class="pe-eval-section-desc mb-3">Select how this guidance session was initiated.</p>
            <div class="pe-eval-visit-grid">
              <?php foreach ($visitTypes as $vtKey => $vtLabel): ?>
                <label class="pe-eval-choice">
                  <input class="form-check-input" type="radio" name="visit_type" value="<?= esc($vtKey, 'attr') ?>" required>
                  <span class="pe-eval-choice-label"><?= esc($vtLabel) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <div class="pe-eval-term-grid is-divided">
            <div class="pe-eval-field">
              <label for="eval-academic-term" class="pe-eval-label">
                Academic Term <span class="pe-eval-optional">(Optional)</span>
              </label>
              <input id="eval-academic-term"
                     type="text"
                     name="academic_term"
                     class="form-control pe-eval-input"
                     maxlength="64"
                     placeholder="e.g. 1st Trimester">
            </div>
            <div class="pe-eval-field">
              <label for="eval-academic-year" class="pe-eval-label">
                Academic Year <span class="pe-eval-optional">(Optional)</span>
              </label>
              <input id="eval-academic-year"
                     type="text"
                     name="academic_year"
                     class="form-control pe-eval-input"
                     maxlength="32"
                     placeholder="e.g. 2026-2027">
            </div>
          </div>
        </section>

        <section class="pe-eval-band">
          <div class="pe-eval-section-head">
            <h3 class="pe-eval-section-title">
              Service Quality Ratings (1 to 5) <span class="pe-eval-req">*</span>
            </h3>
            <p class="pe-eval-section-desc">
              1 = Not at all · 2 = To a lesser extent · 3 = To a moderate extent · 4 = To a large extent · 5 = To the full extent
            </p>
          </div>

          <div class="pe-eval-question-list">
            <?php $dimNum = 1; ?>
            <?php foreach ($dimensions as $field => $question): ?>
              <fieldset class="pe-eval-question">
                <legend class="pe-eval-question-legend">
                  <span class="pe-eval-qnum"><?= $dimNum++ ?>.</span>
                  <?= esc($question) ?>
                </legend>
                <div class="pe-eval-likert-group">
                  <?php foreach ($ratingScale as $score => $label): ?>
                    <label class="pe-eval-likert-option">
                      <input type="radio"
                             name="<?= esc($field, 'attr') ?>"
                             value="<?= (int) $score ?>"
                             required>
                      <span class="pe-eval-likert-pill">
                        <span class="pe-eval-likert-val"><?= (int) $score ?></span>
                        <span class="pe-eval-likert-label"><?= esc($label) ?></span>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="pe-eval-band">
          <fieldset class="pe-eval-fieldset pe-eval-bool-fieldset">
            <legend class="pe-eval-question-legend">
              <?= esc($booleanQuestion) ?> <span class="pe-eval-req">*</span>
            </legend>
            <div class="pe-eval-bool-grid">
              <label class="pe-eval-choice">
                <input class="form-check-input" type="radio" name="<?= esc($booleanField, 'attr') ?>" value="1" required>
                <span class="pe-eval-choice-label">Yes</span>
              </label>
              <label class="pe-eval-choice">
                <input class="form-check-input" type="radio" name="<?= esc($booleanField, 'attr') ?>" value="0" required>
                <span class="pe-eval-choice-label">No</span>
              </label>
            </div>
          </fieldset>

          <div class="pe-eval-field mt-3">
            <label for="eval-future-visit-reason" class="pe-eval-label">
              Reason/s why: <span class="pe-eval-optional">(Optional)</span>
            </label>
            <input id="eval-future-visit-reason"
                   type="text"
                   name="future_visit_reason"
                   class="form-control pe-eval-input"
                   maxlength="512"
                   placeholder="Optional reason for your response above (do not include identifying details)">
          </div>

          <div class="pe-eval-comment-block">
            <label for="eval-qualitative-comment" class="pe-eval-label">
              Comments and Suggestions
              <span class="pe-eval-optional">(Optional · Encrypted &amp; moderated before release)</span>
            </label>
            <textarea id="eval-qualitative-comment"
                      name="<?= esc($commentField, 'attr') ?>"
                      class="form-control pe-eval-textarea"
                      rows="4"
                      maxlength="2048"
                      placeholder="Do not include your name or identifying details. Comments are encrypted at rest and reviewed by a moderator before appearing in cohort aggregates."></textarea>
          </div>
        </section>

        <footer class="pe-eval-footer">
          <span class="pe-eval-footer-note">
            <i class="bi bi-lock" aria-hidden="true"></i>
            <span>Zero personal identifiers stored upon submission</span>
          </span>
          <div class="pe-eval-footer-actions">
            <a href="<?= esc(site_url('/portal'), 'attr') ?>" class="btn btn-outline-secondary pe-eval-btn">Cancel</a>
            <button type="submit" class="btn btn-primary pe-eval-btn" id="eval-submit-btn">
              <span>Submit Pseudonymous Evaluation</span>
            </button>
          </div>
        </footer>
      </form>
    <?php endif; ?>

  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($tokenState !== 'valid'): ?>
<script>
(() => {
  'use strict';
  const redeemForm = document.getElementById('eval-redeem-form');
  if (!redeemForm) return;
  const input = document.getElementById('eval-redeem-token-input');
  const errEl = document.getElementById('eval-redeem-error');
  const baseRedeemUrl = <?= json_encode(site_url('/portal/evaluations/redeem'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function extractToken(raw) {
    const trimmed = String(raw || '').trim();
    if (!trimmed) return '';
    if (/^https?:\/\//i.test(trimmed) || trimmed.includes('portal/evaluations/redeem')) {
      try {
        const parsed = new URL(trimmed, window.location.origin);
        const qTok = parsed.searchParams.get('token');
        if (qTok && qTok.trim()) return qTok.trim();
        const match = parsed.pathname.match(/portal\/evaluations\/redeem\/([^/]+)/);
        if (match && match[1]) return decodeURIComponent(match[1]).trim();
      } catch (_) {
        // Fall back to raw value
      }
    }
    return trimmed;
  }

  redeemForm.addEventListener('submit', event => {
    event.preventDefault();
    if (errEl) {
      errEl.classList.add('d-none');
      errEl.textContent = '';
    }
    const token = extractToken(input ? input.value : '');
    if (!token) {
      if (errEl) {
        errEl.textContent = 'Please paste your one-time evaluation token or link before redeeming.';
        errEl.classList.remove('d-none');
      }
      input?.focus();
      return;
    }
    const targetUrl = `${baseRedeemUrl.replace(/\/+$/, '')}/${encodeURIComponent(token)}`;
    if (window.SynapsePortalWorkspace?.isActive?.()) {
      void window.SynapsePortalWorkspace.navigate(targetUrl, { bypassDirtyCheck: true });
    } else {
      window.location.assign(targetUrl);
    }
  });
})();
</script>
<?php endif; ?>
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
      const targetHref = data.redirect || '<?= esc(site_url('/portal'), 'js') ?>';
      if (window.SynapsePortalWorkspace?.isActive?.()) {
        void window.SynapsePortalWorkspace.navigate(targetHref, { bypassDirtyCheck: true });
      } else {
        window.location.assign(targetHref);
      }
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
