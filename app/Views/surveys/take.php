<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('portal/surveys'), 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Surveys
</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$externalUrl = trim((string) ($survey['external_url'] ?? ''));
$externalHost = $externalUrl !== '' ? (string) (parse_url($externalUrl, PHP_URL_HOST) ?? '') : '';
$questionCount = is_array($survey['questions'] ?? null) ? count($survey['questions']) : 0;
$endsAtDisplay = '';
if (!empty($survey['ends_at'])) {
    try {
        $endsAtDisplay = (new \DateTimeImmutable((string) $survey['ends_at'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Asia/Manila'))
            ->format('d M Y, g:i A') . ' (Manila)';
    } catch (\Throwable) {
        $endsAtDisplay = '';
    }
}
?>
<div class="sv-take">
  <div class="sv-take-sheet">

    <!-- Band 1: Survey Context & Disclosure -->
    <div class="sv-take-band">
      <div class="sv-take-meta">
        <span class="sv-take-meta-unit">
          <?= view('partials/unit_vector_icon', ['unit' => 'guidance']) ?>Guidance Office
        </span>
        <?php if ($externalUrl !== ''): ?>
          <span class="sv-take-meta-sep">External questionnaire<?= $externalHost !== '' ? ' · ' . esc($externalHost) : '' ?></span>
        <?php elseif ($questionCount > 0): ?>
          <span class="sv-take-meta-sep"><?= $questionCount ?> <?= $questionCount === 1 ? 'question' : 'questions' ?></span>
        <?php endif; ?>
        <?php if ($endsAtDisplay !== '' && empty($survey['submitted'])): ?>
          <span class="sv-take-meta-sep">Closes <?= esc($endsAtDisplay) ?></span>
        <?php endif; ?>
      </div>

      <?php if (trim((string) ($survey['description'] ?? '')) !== ''): ?>
        <p class="sv-take-desc"><?= nl2br(esc($survey['description'])) ?></p>
      <?php endif; ?>

      <?php if ($externalUrl !== ''): ?>
        <div class="sv-take-notice">
          <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
          <div>
            <span class="sv-take-notice-title">External Survey Link<?= $externalHost !== '' ? ' (' . esc($externalHost) . ')' : '' ?></span>
            This Guidance survey is hosted on an external form (such as Google Forms). Open the link below to submit your responses there, then optionally mark completion here to track your participation.
          </div>
        </div>
      <?php elseif ($survey['submitted']): ?>
        <?php
          $submittedAtDisplay = '';
          if (!empty($survey['submitted_at'])) {
              try {
                  $submittedAtDisplay = (new \DateTimeImmutable((string) $survey['submitted_at'], new \DateTimeZone('UTC')))
                      ->setTimezone(new \DateTimeZone('Asia/Manila'))
                      ->format('d M Y, g:i A') . ' (Manila)';
              } catch (\Throwable) {
                  $submittedAtDisplay = (string) $survey['submitted_at'];
              }
          }
          $submittedAnswers = is_array($survey['submitted_answers'] ?? null) ? $survey['submitted_answers'] : null;
        ?>
        <div class="sv-take-notice is-success">
          <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
          <div>
            <span class="sv-take-notice-title">You have submitted this survey.</span>
            Your identified completion<?= $submittedAtDisplay !== '' ? ' on ' . esc($submittedAtDisplay) : '' ?> has been recorded. Answers cannot be changed after submission.
          </div>
        </div>
      <?php else: ?>
        <div class="sv-take-notice">
          <i class="bi bi-shield-lock" aria-hidden="true"></i>
          <div>This is an identified survey. Your name and answers are available to authorized Guidance administrators and supervisors. You may submit once; answers cannot be changed after submission.</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Band 2: External Actions, Submitted Receipt, or Active Response Form -->
    <div class="sv-take-band">
      <?php if ($externalUrl !== ''): ?>
        <?php if ($survey['submitted']): ?>
          <div class="sv-take-notice is-success mt-0 pt-0 border-0 mb-3">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <div><span class="sv-take-notice-title mb-0">You marked this external survey as completed.</span></div>
          </div>
        <?php endif; ?>

        <div class="sv-external-actions">
          <a href="<?= esc($externalUrl, 'attr') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary sv-take-btn">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Open External Survey
          </a>

          <?php if (!$survey['submitted']): ?>
            <form id="survey-response-form" autocomplete="off" method="post" action="<?= esc(site_url('portal/surveys/' . $survey['id'] . '/submit')) ?>" data-success-url="<?= esc(site_url('portal/surveys')) ?>" data-survey-submit data-confirm="Mark this external survey as completed? You should have already submitted the external form before confirming." data-confirm-title="Mark external survey completed" data-confirm-label="Mark completed">
              <?= csrf_field() ?>
              <div class="alert alert-danger d-none sv-take-error" role="alert" data-survey-error></div>
              <button type="submit" class="btn btn-outline-secondary sv-take-btn">Mark as completed</button>
            </form>
          <?php endif; ?>

          <a href="<?= esc(site_url('portal/surveys')) ?>" class="btn btn-outline-secondary sv-take-btn">Back to surveys</a>
        </div>

      <?php elseif ($survey['submitted']): ?>
        <?php if ($submittedAnswers !== null && $submittedAnswers !== []): ?>
          <h2 class="sv-submitted-heading">Your submitted answers</h2>
          <div class="sv-answer-list">
            <?php foreach ($submittedAnswers as $answerIndex => $answer): ?>
              <?php
                $questionMeta = $survey['questions'][$answerIndex] ?? [];
                $answerValue = $answer['value'] ?? null;
                $answerType = (string) ($answer['type'] ?? ($questionMeta['type'] ?? 'text'));
              ?>
              <div class="sv-answer-row">
                <p class="sv-answer-q">
                  <span class="sv-question-num"><?= $answerIndex + 1 ?>.</span><?= esc((string) ($answer['question'] ?? ($questionMeta['label'] ?? 'Question'))) ?>
                </p>
                <div class="sv-answer-val">
                  <?php if ($answerValue === null || $answerValue === '' || $answerValue === []): ?>
                    <span class="sv-answer-empty">Not answered</span>
                  <?php elseif (is_array($answerValue)): ?>
                    <ul>
                      <?php foreach ($answerValue as $item): ?>
                        <li><?= esc((string) $item) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php elseif ($answerType === 'rating'): ?>
                    <?php
                      $ratingVal = (int) $answerValue;
                      $minAnchor = trim((string) ($questionMeta['scale_min_label'] ?? ''));
                      $maxAnchor = trim((string) ($questionMeta['scale_max_label'] ?? ''));
                      $ratingLabel = $ratingVal . ' / 5';
                      if ($ratingVal === 1 && $minAnchor !== '') {
                          $ratingLabel .= ' — ' . $minAnchor;
                      } elseif ($ratingVal === 5 && $maxAnchor !== '') {
                          $ratingLabel .= ' — ' . $maxAnchor;
                      }
                    ?>
                    <div class="sv-rating-readout">
                      <span class="sv-rating-dots" aria-hidden="true">
                        <?php for ($dot = 1; $dot <= 5; $dot++): ?>
                          <span class="sv-rating-dot<?= $dot <= $ratingVal ? ' is-filled' : '' ?>"></span>
                        <?php endfor; ?>
                      </span>
                      <span class="sv-rating-text"><?= esc($ratingLabel) ?></span>
                    </div>
                  <?php else: ?>
                    <div><?= nl2br(esc((string) $answerValue)) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-muted small mb-0">Your recorded submission could not be displayed right now.</p>
        <?php endif; ?>

        <div class="sv-take-actions">
          <a href="<?= esc(site_url('portal/surveys')) ?>" class="btn btn-outline-secondary sv-take-btn">Back to surveys</a>
        </div>

      <?php else: ?>
        <form id="survey-response-form" class="sv-take-form" autocomplete="off" method="post" action="<?= esc(site_url('portal/surveys/' . $survey['id'] . '/submit')) ?>" data-success-url="<?= esc(site_url('portal/surveys')) ?>" data-survey-submit data-confirm="Submit your identified response? You cannot change it afterwards." data-confirm-title="Submit survey" data-confirm-label="Submit response">
          <?= csrf_field() ?>
          <div class="alert alert-danger d-none sv-take-error" role="alert" data-survey-error></div>

          <?php foreach ($survey['questions'] as $index => $question): ?>
            <fieldset class="sv-question">
              <legend class="sv-question-legend">
                <span class="sv-question-num"><?= $index + 1 ?>.</span><?= esc($question['label']) ?><?php if ($question['required']): ?><span class="sv-question-req" aria-hidden="true">*</span><?php endif; ?>
              </legend>

              <?php if ($question['type'] === 'text'): ?>
                <textarea name="answers[<?= $index ?>]" class="form-control sv-textarea" maxlength="2000" rows="4" placeholder="Write your response…" aria-label="<?= esc($question['label']) ?>" <?= $question['required'] ? 'required' : '' ?>></textarea>

              <?php elseif ($question['type'] === 'rating'): ?>
                <?php
                  $minAnchor = trim((string) ($question['scale_min_label'] ?? ''));
                  $maxAnchor = trim((string) ($question['scale_max_label'] ?? ''));
                  $minAnchorId = 'survey-scale-min-' . $index;
                  $maxAnchorId = 'survey-scale-max-' . $index;
                  $hasAnchors = $minAnchor !== '' || $maxAnchor !== '';
                ?>
                <div class="pe-linear-scale<?= $hasAnchors ? ' has-anchors' : '' ?>" data-rating-scale>
                  <?php if ($minAnchor !== ''): ?>
                    <span class="pe-linear-scale-anchor pe-linear-scale-anchor--min" id="<?= $minAnchorId ?>">
                      <span class="pe-linear-scale-anchor-num" aria-hidden="true">1 — </span><?= esc($minAnchor) ?>
                    </span>
                  <?php endif; ?>
                  <div class="pe-linear-scale-options">
                    <?php for ($rating = 1; $rating <= 5; $rating++): ?>
                      <?php
                        $describedBy = '';
                        if ($rating === 1 && $minAnchor !== '') {
                            $describedBy = $minAnchorId;
                        } elseif ($rating === 5 && $maxAnchor !== '') {
                            $describedBy = $maxAnchorId;
                        }
                      ?>
                      <label class="pe-linear-scale-option">
                        <span class="pe-linear-scale-number"><?= $rating ?></span>
                        <span class="pe-linear-scale-control">
                          <input type="radio"
                                 class="pe-linear-scale-radio"
                                 name="answers[<?= $index ?>]"
                                 value="<?= $rating ?>"
                                 <?= $describedBy !== '' ? 'aria-describedby="' . $describedBy . '"' : '' ?>
                                 <?= $question['required'] ? 'required' : '' ?>>
                        </span>
                      </label>
                    <?php endfor; ?>
                  </div>
                  <?php if ($maxAnchor !== ''): ?>
                    <span class="pe-linear-scale-anchor pe-linear-scale-anchor--max" id="<?= $maxAnchorId ?>">
                      <span class="pe-linear-scale-anchor-num" aria-hidden="true">5 — </span><?= esc($maxAnchor) ?>
                    </span>
                  <?php endif; ?>
                </div>

              <?php else: ?>
                <?php
                  $otherIndex = \App\Services\Guidance\SurveyQuestion::otherOptionIndex($question);
                  $hasExplicitOther = !empty($question['allow_other']);
                  $useDedicatedOther = $hasExplicitOther && $otherIndex === null;
                  $hasOtherControl = $useDedicatedOther || $otherIndex !== null;
                  $inputType = $question['type'] === 'single' ? 'radio' : 'checkbox';
                  $inputName = 'answers[' . $index . ']' . ($question['type'] === 'multiple' ? '[]' : '');
                  $reqAttr = $question['required'] && $question['type'] === 'single' ? 'required' : '';
                ?>
                <?php if ($question['type'] === 'multiple'): ?>
                  <p class="sv-question-hint">Select all that apply.</p>
                <?php endif; ?>
                <div class="sv-choice-list" data-choice-question="<?= $index ?>">
                  <?php foreach ($question['options'] as $option => $label): ?>
                    <?php $isOtherOpt = $otherIndex !== null && $option === $otherIndex; ?>
                    <label class="sv-choice">
                      <input class="form-check-input" type="<?= $inputType ?>" name="<?= $inputName ?>" value="<?= $option ?>" <?= $reqAttr ?> <?= $isOtherOpt ? 'data-other-option="1"' : '' ?>>
                      <span class="form-check-label"><?= esc($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                  <?php if ($useDedicatedOther): ?>
                    <label class="sv-choice">
                      <input class="form-check-input" type="<?= $inputType ?>" name="<?= $inputName ?>" value="__other__" <?= $reqAttr ?> data-other-option="1">
                      <span class="form-check-label">Other (please specify)</span>
                    </label>
                  <?php endif; ?>
                  <?php if ($hasOtherControl): ?>
                    <div class="sv-other-wrap d-none" data-other-wrapper="<?= $index ?>">
                      <label class="visually-hidden" for="answerOther-<?= $index ?>">Please specify for <?= esc($question['label']) ?></label>
                      <input type="text"
                             id="answerOther-<?= $index ?>"
                             class="form-control sv-other-input"
                             name="answers_other[<?= $index ?>]"
                             maxlength="500"
                             placeholder="Please specify your answer…"
                             data-other-input="<?= $index ?>"
                             disabled>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </fieldset>
          <?php endforeach; ?>

          <div class="sv-take-actions">
            <button type="submit" class="btn btn-primary sv-take-btn">Submit identified response</button>
            <a href="<?= esc(site_url('portal/surveys')) ?>" class="btn btn-outline-secondary sv-take-btn">Back to surveys</a>
            <p class="sv-take-footnote">You’ll confirm once before your response is recorded.</p>
          </div>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<?php if (!$survey['submitted']): ?>
<script>
(() => {
  'use strict';
  const lifecycle = new AbortController();
  const boundForms = window.__synapseSurveyBoundForms || (window.__synapseSurveyBoundForms = new WeakSet());
  let boundForm = null, disposeForm = null, unregister = null;
  const dispose = () => {
    lifecycle.abort();
    disposeForm?.();
    disposeForm = null;
    unregister?.();
    unregister = null;
  };
  unregister = window.SynapseNavigation?.registerCleanup(dispose);
  document.addEventListener('synapse:before-swap', event => {
    const root = event.detail?.root;
    if (boundForm && root && (root === boundForm || root.contains(boundForm))) dispose();
  }, {signal: lifecycle.signal});
  const init = root => {
  const form = root?.querySelector('#survey-response-form');
  if (!form?.isConnected || boundForms.has(form) || lifecycle.signal.aborted) return;
  boundForm = form;
  boundForms.add(form);
  form.dataset.bound = '1';
  const button = form.querySelector('button[type="submit"]');
  const error = form.querySelector('[data-survey-error]');
  const events = new AbortController();
  let request = null, inFlight = false, disposed = false, completed = false;
  const fail = message => { error.textContent = message; error.classList.remove('d-none'); };
  const syncChoiceGroup = (group, shouldFocus = false) => {
    const wrapper = group.querySelector('[data-other-wrapper]');
    const input = group.querySelector('[data-other-input]');
    const otherControl = group.querySelector('[data-other-option]');
    if (!wrapper || !input || !otherControl) return;
    const active = Boolean(otherControl.checked);
    wrapper.classList.toggle('d-none', !active);
    input.disabled = !active;
    input.required = active;
    if (!active) {
      input.value = '';
    } else if (shouldFocus) {
      input.focus();
    }
  };
  const syncAllOtherInputs = () => {
    form.querySelectorAll('[data-choice-question]').forEach(group => syncChoiceGroup(group, false));
  };
  syncAllOtherInputs();
  form.addEventListener('change', event => {
    const group = event.target?.closest?.('[data-choice-question]');
    if (group) {
      syncChoiceGroup(group, Boolean(event.target.matches('[data-other-option]') && event.target.checked));
    }
  }, {signal: events.signal});
  disposeForm = () => {
    disposed = true;
    request?.abort();
    events.abort();
    form.reset();
    syncAllOtherInputs();
    boundForms.delete(form);
    delete form.dataset.bound;
  };
  window.addEventListener('pagehide', () => { request?.abort(); form.reset(); syncAllOtherInputs(); }, {signal: events.signal});
  window.addEventListener('pageshow', event => {
    if (event.persisted) window.location.reload();
  }, {signal: events.signal});
  form.addEventListener('submit', async event => {
    // confirm.js captures the first submit and requestSubmits only after approval.
    if (event.defaultPrevented) return;
    event.preventDefault();
    if (disposed || inFlight || !form.isConnected) return;
    if (!window.SynapseConfirm?.confirm) { fail('Confirmation is unavailable. Reload this page before submitting.'); return; }
    const target = new URL(form.action, window.location.href);
    const successUrl = new URL(form.dataset.successUrl, window.location.href);
    if (target.origin !== window.location.origin || successUrl.origin !== window.location.origin) {
      fail('Invalid survey destination. Reload this page.'); return;
    }
    inFlight = true;
    button.disabled = true;
    error.classList.add('d-none');
    request = new AbortController();
    const timeout = window.setTimeout(() => request?.abort(), 30000);
    try {
      const response = await fetch(target.href, {method:'POST', body:new FormData(form),
        credentials:'same-origin', redirect:'error', cache:'no-store', signal:request.signal,
        headers:{'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json'}});
      if (disposed || !form.isConnected) return;
      window.SynapseCsrf?.fromResponse(response);
      const result = await response.json();
      if (disposed || !form.isConnected) return;
      window.SynapseCsrf?.sync(result.csrf);
      if (!response.ok || result.success !== true) {
        fail(typeof result.message === 'string' ? result.message : 'Unable to submit. Please try again.');
        return;
      }
      form.reset();
      completed = true;
      if (window.SynapsePortalWorkspace?.isActive?.()) {
        void window.SynapsePortalWorkspace.navigate(successUrl.href, { bypassDirtyCheck: true });
      } else {
        window.location.assign(successUrl.href);
      }
    } catch (_) {
      if (!disposed && form.isConnected) fail('Unable to confirm submission. Your answers remain on this page. Check your survey status before retrying.');
    } finally {
      window.clearTimeout(timeout);
      request = null;
      inFlight = completed;
      if (!disposed && !completed && form.isConnected) button.disabled = false;
    }
  }, {signal: events.signal});
  };
  // Soft-navigation scripts run before incoming main is inserted. Bind only after its page-load event.
  document.addEventListener('synapse:page-load', event => init(event.detail?.root), {signal: lifecycle.signal});
  if (document.readyState === 'loading' || !window.SynapseNavigation) init(document.getElementById('main'));
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
