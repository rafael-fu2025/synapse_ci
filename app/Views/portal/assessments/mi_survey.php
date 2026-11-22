<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc($backUrl, 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>My Surveys
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$catalog = $catalog ?? [];
$items = $catalog['items'] ?? [];
$scale = $catalog['scale'] ?? [];
$draft = $draft ?? [];
$miGateEnabled = (bool) ($miGateEnabled ?? false);
$assignment = $assignment ?? [];
$chunks = array_chunk($items, 10);
$initialCount = count($draft);
?>

<div class="mi-survey">
  <div class="mi-sheet">

    <section class="mi-band mi-overview-band" id="mi-progress-card">
      <div class="mi-overview-top">
        <div class="mi-overview-copy">
          <h2 class="mi-title"><?= esc($catalog['title'] ?? 'Multiple Intelligences Survey (McKenzie 1999)') ?></h2>
          <p class="mi-subtitle">
            Rate each statement honestly from <strong>0 (Never)</strong> to <strong>4 (Always)</strong>.
            <?php if (!empty($assignment['academic_term']) || !empty($assignment['academic_year'])): ?>
              <span class="mi-subtitle-sep" aria-hidden="true">·</span>
              <span><?= esc(trim(($assignment['academic_term'] ?? '') . ' ' . ($assignment['academic_year'] ?? ''))) ?></span>
            <?php endif; ?>
          </p>
        </div>
        <div class="mi-progress-readout">
          <span class="mi-progress-label">Answered</span>
          <span class="mi-progress-value"><span id="mi-answered-count"><?= $initialCount ?></span> / 80</span>
        </div>
      </div>

      <div class="progress mi-progress-track">
        <div id="mi-progress-bar"
             class="progress-bar"
             role="progressbar"
             style="width: <?= round(($initialCount / 80) * 100, 1) ?>%;"
             aria-valuenow="<?= $initialCount ?>"
             aria-valuemin="0"
             aria-valuemax="80"></div>
      </div>

      <?php if ($chunks !== []): ?>
        <div class="mi-section-tabs" id="mi-section-pills" role="tablist" aria-label="Survey sections">
          <?php foreach ($chunks as $secIdx => $secItems): ?>
            <?php
            $startNum = ($secIdx * 10) + 1;
            $endNum = $startNum + count($secItems) - 1;
            ?>
            <button type="button"
                    role="tab"
                    aria-selected="<?= $secIdx === 0 ? 'true' : 'false' ?>"
                    class="mi-section-tab <?= $secIdx === 0 ? 'is-active' : '' ?>"
                    data-section-target="<?= $secIdx ?>">
              <span class="mi-tab-name">Section <?= $secIdx + 1 ?></span>
              <span class="mi-tab-range"><?= $startNum ?>–<?= $endNum ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$miGateEnabled): ?>
        <div class="mi-gate-notice" role="status">
          <i class="bi bi-shield-lock" aria-hidden="true"></i>
          <div>
            <strong>Activation Gate Active (<code>mi_reproduction_signoff</code>):</strong>
            Live final submission is currently disabled pending multi-authority sign-off (Guidance Head, QA Office, DPO). You can complete items and save your encrypted draft below.
          </div>
        </div>
      <?php endif; ?>
    </section>

    <form id="mi-survey-form"
          class="mi-form"
          method="POST"
          action="<?= esc($finalizeUrl, 'attr') ?>"
          data-draft-url="<?= esc($draftUrl, 'attr') ?>"
          data-gate-enabled="<?= $miGateEnabled ? '1' : '0' ?>"
          autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="signer_capacity" value="student">

      <div id="mi-feedback-banner" class="alert mi-feedback-banner d-none" role="status"></div>

      <?php foreach ($chunks as $secIdx => $secItems): ?>
        <section class="mi-band mi-section-pane <?= $secIdx === 0 ? '' : 'd-none' ?>" data-section-index="<?= $secIdx ?>">
          <div class="mi-section-head">
            <h3 class="mi-section-title">
              Section <?= $secIdx + 1 ?> of <?= count($chunks) ?>
              <span class="mi-section-range">· Items <?= ($secIdx * 10) + 1 ?>–<?= ($secIdx * 10) + count($secItems) ?></span>
            </h3>
            <span class="mi-section-progress" data-section-progress="<?= $secIdx ?>">0 / <?= count($secItems) ?></span>
          </div>

          <div class="mi-question-list">
            <?php foreach ($secItems as $item): ?>
              <?php
              $num = (int) $item['item_number'];
              $savedVal = array_key_exists($num, $draft) ? (int) $draft[$num] : null;
              ?>
              <fieldset class="mi-question">
                <legend class="mi-question-legend">
                  <span class="mi-question-num"><?= $num ?>.</span>
                  <?= esc($item['text']) ?>
                </legend>
                <div class="mi-likert-group">
                  <?php foreach ($scale as $opt): ?>
                    <?php
                    $val = (int) $opt['value'];
                    $inputId = 'mi-q-' . $num . '-v-' . $val;
                    $checked = $savedVal === $val;
                    ?>
                    <label class="mi-likert-option" for="<?= esc($inputId, 'attr') ?>">
                      <input type="radio"
                             id="<?= esc($inputId, 'attr') ?>"
                             name="responses[<?= $num ?>]"
                             value="<?= $val ?>"
                             data-mi-item="<?= $num ?>"
                             <?= $checked ? 'checked' : '' ?>>
                      <span class="mi-likert-pill">
                        <span class="mi-likert-val"><?= $val ?></span>
                        <span class="mi-likert-label"><?= esc($opt['label']) ?></span>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            <?php endforeach; ?>
          </div>

          <div class="mi-section-nav <?= $secIdx === count($chunks) - 1 ? 'mi-section-nav--last' : '' ?>">
            <button type="button"
                    class="btn btn-outline-secondary btn-sm mi-btn mi-prev-btn"
                    <?= $secIdx === 0 ? 'disabled' : '' ?>
                    data-prev-section="<?= $secIdx - 1 ?>">
              <i class="bi bi-chevron-left" aria-hidden="true"></i>
              <span>Previous Section</span>
            </button>
            <div class="mi-section-nav-actions">
              <button type="button" class="btn btn-outline-secondary btn-sm mi-btn mi-save-draft-btn">
                <span>Save Encrypted Draft</span>
              </button>
              <?php if ($secIdx < count($chunks) - 1): ?>
                <button type="button"
                        class="btn btn-primary btn-sm mi-btn mi-next-btn"
                        data-next-section="<?= $secIdx + 1 ?>">
                  <span>Next Section</span>
                  <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php endforeach; ?>

      <section class="mi-band mi-attestation-band">
        <div class="mi-attestation-head">
          <h3 class="mi-attestation-title">Student Digital Attestation &amp; Final Submission</h3>
          <p class="mi-attestation-desc">
            All 80 items must be answered before finalization. Your finalized submission is attested exclusively under your authenticated student account (never by simulated guardian signatures).
          </p>
        </div>

        <div class="mi-field">
          <label for="mi-attestation-statement" class="mi-field-label">Digital Attestation Statement</label>
          <textarea id="mi-attestation-statement"
                    name="attestation_statement"
                    class="form-control mi-attestation-textarea"
                    rows="2"
                    maxlength="500"
                    readonly><?= esc($attestationDefault) ?></textarea>
        </div>

        <label class="mi-confirm-check" for="mi-attestation-confirmed">
          <input class="form-check-input"
                 type="checkbox"
                 value="1"
                 id="mi-attestation-confirmed"
                 name="attestation_confirmed"
                 <?= $miGateEnabled ? '' : 'disabled' ?>>
          <span class="mi-confirm-label">
            I confirm this student digital attestation and verify that all 80 responses are my own.
          </span>
        </label>

        <div class="mi-attestation-actions">
          <button type="button" class="btn btn-outline-secondary mi-btn mi-save-draft-btn" id="mi-save-draft-footer">
            <span>Save Encrypted Draft (<span id="mi-footer-count"><?= $initialCount ?></span>/80)</span>
          </button>
          <button type="submit"
                  id="mi-finalize-btn"
                  class="btn btn-primary mi-btn"
                  <?= $miGateEnabled ? '' : 'disabled aria-disabled="true"' ?>>
            <span><?= $miGateEnabled ? 'Finalize &amp; View 8-Domain Profile' : 'Finalization Gated (Pending Sign-off)' ?></span>
          </button>
        </div>
      </section>
    </form>

  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(() => {
  'use strict';
  const form = document.getElementById('mi-survey-form');
  if (!form) return;

  const totalItems = 80;
  const panes = Array.from(form.querySelectorAll('.mi-section-pane'));
  const tabs = Array.from(document.querySelectorAll('.mi-section-tab'));
  const countEl = document.getElementById('mi-answered-count');
  const footerCountEl = document.getElementById('mi-footer-count');
  const progressBar = document.getElementById('mi-progress-bar');
  const feedback = document.getElementById('mi-feedback-banner');
  const finalizeBtn = document.getElementById('mi-finalize-btn');
  const gateEnabled = form.dataset.gateEnabled === '1';

  const showSection = index => {
    panes.forEach((pane, idx) => {
      pane.classList.toggle('d-none', idx !== index);
    });
    tabs.forEach((tab, idx) => {
      const active = idx === index;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const scrollTarget = document.getElementById('mi-progress-card') || document.getElementById('portal-workspace-pane');
    if (scrollTarget) {
      const top = Math.max(0, window.scrollY + scrollTarget.getBoundingClientRect().top - 76);
      window.scrollTo({ top, behavior: 'smooth' });
    } else {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const collectResponses = () => {
    const out = {};
    form.querySelectorAll('input[data-mi-item]:checked').forEach(radio => {
      out[radio.dataset.miItem] = Number(radio.value);
    });
    return out;
  };

  const updateProgress = () => {
    const responses = collectResponses();
    const answered = Object.keys(responses).length;
    if (countEl) countEl.textContent = String(answered);
    if (footerCountEl) footerCountEl.textContent = String(answered);
    if (progressBar) {
      const pct = ((answered / totalItems) * 100).toFixed(1);
      progressBar.style.width = pct + '%';
      progressBar.setAttribute('aria-valuenow', String(answered));
    }

    panes.forEach((pane, idx) => {
      const paneTotal = pane.querySelectorAll('fieldset').length;
      const paneDone = pane.querySelectorAll('input[data-mi-item]:checked').length;
      const badge = pane.querySelector(`[data-section-progress="${idx}"]`);
      if (badge) {
        badge.textContent = `${paneDone} / ${paneTotal}`;
        badge.classList.toggle('is-complete', paneDone === paneTotal && paneTotal > 0);
      }
      if (tabs[idx]) {
        tabs[idx].classList.toggle('is-complete', paneDone === paneTotal && paneTotal > 0);
      }
    });
  };

  const setFeedback = (type, message) => {
    if (!feedback) return;
    feedback.className = `alert alert-${type} mi-feedback-banner`;
    feedback.textContent = message;
  };

  const syncCsrf = csrf => {
    if (!csrf || !csrf.name || !csrf.hash) return;
    const hidden = form.querySelector(`input[name="${csrf.name}"]`);
    if (hidden) hidden.value = csrf.hash;
    window.SynapseCsrf?.sync(csrf);
  };

  const getCsrfHeaders = () => {
    const metaName = document.querySelector('meta[name="csrf-name"]')?.content;
    const metaHeader = document.querySelector('meta[name="csrf-header"]')?.content || 'X-CSRF-TOKEN';
    const hidden = metaName ? form.querySelector(`input[name="${metaName}"]`) : null;
    const tokenVal = hidden?.value || document.querySelector('meta[name="csrf-token"]')?.content || '';
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      [metaHeader]: tokenVal,
    };
  };

  const saveDraft = async () => {
    const responses = collectResponses();
    try {
      const res = await fetch(form.dataset.draftUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: getCsrfHeaders(),
        body: JSON.stringify({ responses }),
      });
      const data = await res.json();
      syncCsrf(data.csrf);
      if (!res.ok || !data.success) {
        setFeedback('danger', data.message || 'Unable to save draft.');
        return;
      }
      setFeedback('success', `Encrypted draft saved (${data.saved_count} / 80 items).`);
    } catch (_) {
      setFeedback('danger', 'Network error while saving draft. Your selections remain on screen.');
    }
  };

  tabs.forEach(tab => {
    tab.addEventListener('click', () => showSection(Number(tab.dataset.sectionTarget)));
  });

  form.querySelectorAll('.mi-prev-btn').forEach(btn => {
    btn.addEventListener('click', () => showSection(Number(btn.dataset.prevSection)));
  });

  form.querySelectorAll('.mi-next-btn').forEach(btn => {
    btn.addEventListener('click', () => showSection(Number(btn.dataset.nextSection)));
  });

  form.querySelectorAll('.mi-save-draft-btn').forEach(btn => {
    btn.addEventListener('click', saveDraft);
  });

  form.addEventListener('change', event => {
    if (event.target.matches('input[data-mi-item]')) {
      updateProgress();
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!gateEnabled) {
      setFeedback('warning', 'Final submission is blocked until mi_reproduction_signoff activation gate is approved.');
      return;
    }
    const responses = collectResponses();
    if (Object.keys(responses).length < totalItems) {
      setFeedback('danger', `Please answer all 80 items before finalizing (${Object.keys(responses).length} / 80 answered).`);
      return;
    }
    const checkbox = document.getElementById('mi-attestation-confirmed');
    if (!checkbox?.checked) {
      setFeedback('danger', 'Please check the student digital attestation confirmation box.');
      return;
    }

    if (finalizeBtn) finalizeBtn.disabled = true;
    try {
      const res = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: getCsrfHeaders(),
        body: JSON.stringify({
          responses,
          signer_capacity: 'student',
          attestation_confirmed: true,
          attestation_statement: document.getElementById('mi-attestation-statement')?.value || '',
        }),
      });
      const data = await res.json();
      syncCsrf(data.csrf);
      if (!res.ok || !data.success) {
        setFeedback('danger', data.message || 'Unable to finalize assessment.');
        if (finalizeBtn) finalizeBtn.disabled = false;
        return;
      }
      if (window.SynapsePortalWorkspace?.isActive?.()) {
        void window.SynapsePortalWorkspace.navigate(data.redirect, { bypassDirtyCheck: true });
      } else {
        window.location.assign(data.redirect);
      }
    } catch (_) {
      setFeedback('danger', 'Unable to confirm finalization. Please check your connection and retry.');
      if (finalizeBtn) finalizeBtn.disabled = false;
    }
  });

  updateProgress();
})();
</script>
<?= $this->endSection() ?>
