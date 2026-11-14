<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc($backUrl, 'attr') ?>" class="btn btn-outline-secondary btn-sm">
  <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>My MI Assessments
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

<style>
  .mi-likert-group {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.4rem;
  }
  @media (max-width: 767.98px) {
    .mi-likert-group {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  .mi-likert-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }
  .mi-likert-pill {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 0.5rem 0.35rem;
    border-radius: 0.5rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    background-color: var(--bs-body-bg, #fff);
    cursor: pointer;
    transition: all 0.15s ease;
    font-size: 0.8rem;
    min-height: 54px;
  }
  .mi-likert-option input[type="radio"]:checked + .mi-likert-pill {
    background-color: var(--bs-primary, #0d6efd);
    border-color: var(--bs-primary, #0d6efd);
    color: #fff;
    font-weight: 600;
    box-shadow: 0 2px 6px rgba(13, 110, 253, 0.25);
  }
  .mi-likert-option input[type="radio"]:focus-visible + .mi-likert-pill {
    outline: 2px solid var(--bs-primary, #0d6efd);
    outline-offset: 2px;
  }
</style>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">
      <div>
        <h5 class="fw-bold mb-1"><?= esc($catalog['title'] ?? 'Multiple Intelligences Survey (McKenzie 1999)') ?></h5>
        <p class="text-muted small mb-0">
          Rate each statement honestly from <strong>0 (Never)</strong> to <strong>4 (Always)</strong>.
          <?php if (!empty($assignment['academic_term']) || !empty($assignment['academic_year'])): ?>
            · <?= esc(trim(($assignment['academic_term'] ?? '') . ' ' . ($assignment['academic_year'] ?? ''))) ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="text-end">
        <div class="small text-muted">Answered Progress</div>
        <div class="h5 fw-bold mb-0"><span id="mi-answered-count"><?= $initialCount ?></span> / 80</div>
      </div>
    </div>
    <div class="progress mb-2" style="height: 10px;">
      <div id="mi-progress-bar" class="progress-bar" role="progressbar" style="width: <?= round(($initialCount / 80) * 100, 1) ?>%;" aria-valuenow="<?= $initialCount ?>" aria-valuemin="0" aria-valuemax="80"></div>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-3" id="mi-section-pills" role="tablist" aria-label="Survey sections">
      <?php foreach ($chunks as $secIdx => $secItems): ?>
        <?php
        $startNum = ($secIdx * 10) + 1;
        $endNum = $startNum + count($secItems) - 1;
        ?>
        <button type="button"
                class="btn btn-sm <?= $secIdx === 0 ? 'btn-primary' : 'btn-outline-secondary' ?> mi-section-tab"
                data-section-target="<?= $secIdx ?>">
          Section <?= $secIdx + 1 ?> (<?= $startNum ?>–<?= $endNum ?>)
        </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (!$miGateEnabled): ?>
  <div class="alert alert-warning border-0 shadow-sm mb-3" role="status">
    <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>
    <strong>Activation Gate Active (<code>mi_reproduction_signoff</code>):</strong>
    Live final submission is currently disabled pending multi-authority sign-off (Guidance Head, QA Office, DPO). You can complete items and save your encrypted draft below.
  </div>
<?php endif; ?>

<form id="mi-survey-form"
      method="POST"
      action="<?= esc($finalizeUrl, 'attr') ?>"
      data-draft-url="<?= esc($draftUrl, 'attr') ?>"
      data-gate-enabled="<?= $miGateEnabled ? '1' : '0' ?>"
      autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="signer_capacity" value="student">

  <div id="mi-feedback-banner" class="alert d-none mb-3" role="status"></div>

  <?php foreach ($chunks as $secIdx => $secItems): ?>
    <div class="card border-0 shadow-sm mb-3 mi-section-pane <?= $secIdx === 0 ? '' : 'd-none' ?>" data-section-index="<?= $secIdx ?>">
      <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
        <h6 class="fw-bold mb-0">
          Section <?= $secIdx + 1 ?> of <?= count($chunks) ?>
          <span class="text-muted fw-normal">(Items <?= ($secIdx * 10) + 1 ?>–<?= ($secIdx * 10) + count($secItems) ?>)</span>
        </h6>
        <span class="badge bg-light text-dark border mi-section-progress" data-section-progress="<?= $secIdx ?>">0 / <?= count($secItems) ?></span>
      </div>
      <div class="card-body">
        <?php foreach ($secItems as $item): ?>
          <?php
          $num = (int) $item['item_number'];
          $savedVal = array_key_exists($num, $draft) ? (int) $draft[$num] : null;
          ?>
          <fieldset class="pb-3 mb-3 border-bottom">
            <legend class="fs-6 fw-semibold mb-2">
              <span class="badge bg-secondary-subtle text-body me-2"><?= $num ?></span>
              <?= esc($item['text']) ?>
            </legend>
            <div class="mi-likert-group">
              <?php foreach ($scale as $opt): ?>
                <?php
                $val = (int) $opt['value'];
                $inputId = 'mi-q-' . $num . '-v-' . $val;
                $checked = $savedVal === $val;
                ?>
                <label class="mi-likert-option position-relative mb-0" for="<?= esc($inputId, 'attr') ?>">
                  <input type="radio"
                         id="<?= esc($inputId, 'attr') ?>"
                         name="responses[<?= $num ?>]"
                         value="<?= $val ?>"
                         data-mi-item="<?= $num ?>"
                         <?= $checked ? 'checked' : '' ?>>
                  <span class="mi-likert-pill">
                    <span class="fw-bold"><?= $val ?></span>
                    <span><?= esc($opt['label']) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>

        <div class="d-flex justify-content-between align-items-center pt-2">
          <button type="button" class="btn btn-outline-secondary btn-sm mi-prev-btn" <?= $secIdx === 0 ? 'disabled' : '' ?> data-prev-section="<?= $secIdx - 1 ?>">
            <i class="bi bi-chevron-left me-1" aria-hidden="true"></i>Previous Section
          </button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm mi-save-draft-btn">
              <i class="bi bi-cloud-check me-1" aria-hidden="true"></i>Save Encrypted Draft
            </button>
            <?php if ($secIdx < count($chunks) - 1): ?>
              <button type="button" class="btn btn-primary btn-sm mi-next-btn" data-next-section="<?= $secIdx + 1 ?>">
                Next Section<i class="bi bi-chevron-right ms-1" aria-hidden="true"></i>
              </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3">
      <h6 class="fw-bold mb-0">
        <i class="bi bi-shield-check me-2 text-primary" aria-hidden="true"></i>Student Digital Attestation &amp; Final Submission
      </h6>
    </div>
    <div class="card-body">
      <p class="small text-muted mb-2">
        All 80 items must be answered before finalization. Your finalized submission is attested exclusively under your authenticated student account (never by simulated guardian signatures).
      </p>
      <div class="mb-3">
        <label for="mi-attestation-statement" class="form-label fw-semibold">Digital Attestation Statement</label>
        <textarea id="mi-attestation-statement" name="attestation_statement" class="form-control" rows="2" maxlength="500" readonly><?= esc($attestationDefault) ?></textarea>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" value="1" id="mi-attestation-confirmed" name="attestation_confirmed" <?= $miGateEnabled ? '' : 'disabled' ?>>
        <label class="form-check-label fw-semibold" for="mi-attestation-confirmed">
          I confirm this student digital attestation and verify that all 80 responses are my own.
        </label>
      </div>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <button type="button" class="btn btn-outline-primary mi-save-draft-btn" id="mi-save-draft-footer">
          <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Save Encrypted Draft (<span id="mi-footer-count"><?= $initialCount ?></span>/80)
        </button>
        <button type="submit"
                id="mi-finalize-btn"
                class="btn btn-success"
                <?= $miGateEnabled ? '' : 'disabled aria-disabled="true"' ?>>
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
          <?= $miGateEnabled ? 'Finalize &amp; View 8-Domain Profile' : 'Finalization Gated (Pending Sign-off)' ?>
        </button>
      </div>
    </div>
  </div>
</form>
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
      tab.classList.toggle('btn-primary', idx === index);
      tab.classList.toggle('btn-outline-secondary', idx !== index);
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
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
        badge.classList.toggle('bg-success', paneDone === paneTotal);
        badge.classList.toggle('text-white', paneDone === paneTotal);
        badge.classList.toggle('bg-light', paneDone !== paneTotal);
        badge.classList.toggle('text-dark', paneDone !== paneTotal);
      }
    });
  };

  const setFeedback = (type, message) => {
    if (!feedback) return;
    feedback.className = `alert alert-${type} mb-3`;
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
      window.location.assign(data.redirect);
    } catch (_) {
      setFeedback('danger', 'Unable to confirm finalization. Please check your connection and retry.');
      if (finalizeBtn) finalizeBtn.disabled = false;
    }
  });

  updateProgress();
})();
</script>
<?= $this->endSection() ?>
