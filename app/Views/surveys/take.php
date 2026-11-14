<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$externalUrl = trim((string) ($survey['external_url'] ?? ''));
$externalHost = $externalUrl !== '' ? (string) (parse_url($externalUrl, PHP_URL_HOST) ?? '') : '';
?>
<div class="card"><div class="card-body pt-3">
  <?php if (trim((string) ($survey['description'] ?? '')) !== ''): ?>
    <p class="text-break mb-3"><?= nl2br(esc($survey['description'])) ?></p>
  <?php endif; ?>
  <?php if ($externalUrl !== ''): ?>
    <div class="alert alert-info">
      <strong>External Survey Link<?= $externalHost !== '' ? ' (' . esc($externalHost) . ')' : '' ?>:</strong>
      This Guidance survey is hosted on an external form (such as Google Forms). Open the link below to submit your responses there, then optionally mark completion here to track your participation.
    </div>
    <div class="mb-3">
      <a href="<?= esc($externalUrl, 'attr') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open External Survey
      </a>
    </div>
    <?php if ($survey['submitted']): ?>
      <div class="alert alert-success">You marked this external survey as completed.</div>
    <?php else: ?>
      <form id="survey-response-form" autocomplete="off" method="post" action="<?= esc(site_url('portal/surveys/' . $survey['id'] . '/submit')) ?>" data-success-url="<?= esc(site_url('portal/surveys')) ?>" data-survey-submit data-confirm="Mark this external survey as completed? You should have already submitted the external form before confirming." data-confirm-title="Mark external survey completed" data-confirm-label="Mark completed">
        <?= csrf_field() ?>
        <div class="alert alert-danger d-none" role="alert" data-survey-error></div>
        <button type="submit" class="btn btn-outline-primary">Mark as completed</button>
      </form>
    <?php endif; ?>
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
    <div class="alert alert-success mb-4">
      <div class="fw-semibold"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>You have submitted this survey.</div>
      <div class="small mt-1">
        Your identified completion<?= $submittedAtDisplay !== '' ? ' on ' . esc($submittedAtDisplay) : '' ?> has been recorded. Answers cannot be changed after submission.
      </div>
    </div>
    <?php if ($submittedAnswers !== null && $submittedAnswers !== []): ?>
      <h2 class="h6 fw-semibold mb-3">Your submitted answers</h2>
      <div class="vstack gap-3 mb-3">
        <?php foreach ($submittedAnswers as $answerIndex => $answer): ?>
          <?php
            $questionMeta = $survey['questions'][$answerIndex] ?? [];
            $answerValue = $answer['value'] ?? null;
            $answerType = (string) ($answer['type'] ?? ($questionMeta['type'] ?? 'text'));
          ?>
          <div class="border rounded p-3 bg-body-tertiary">
            <div class="fw-semibold mb-2"><?= $answerIndex + 1 ?>. <?= esc((string) ($answer['question'] ?? ($questionMeta['label'] ?? 'Question'))) ?></div>
            <?php if ($answerValue === null || $answerValue === '' || $answerValue === []): ?>
              <span class="text-muted small">Not answered</span>
            <?php elseif (is_array($answerValue)): ?>
              <ul class="mb-0 ps-3">
                <?php foreach ($answerValue as $item): ?>
                  <li class="text-break"><?= esc((string) $item) ?></li>
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
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><?= esc($ratingLabel) ?></span>
            <?php else: ?>
              <div class="text-break mb-0"><?= nl2br(esc((string) $answerValue)) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="text-muted small mb-3">Your recorded submission could not be displayed right now.</p>
    <?php endif; ?>
  <?php else: ?>
    <div class="alert alert-info">This is an identified survey. Your name and answers are available to authorized Guidance administrators and supervisors. You may submit once; answers cannot be changed after submission.</div>
    <form id="survey-response-form" autocomplete="off" method="post" action="<?= esc(site_url('portal/surveys/' . $survey['id'] . '/submit')) ?>" data-success-url="<?= esc(site_url('portal/surveys')) ?>" data-survey-submit data-confirm="Submit your identified response? You cannot change it afterwards." data-confirm-title="Submit survey" data-confirm-label="Submit response">
      <?= csrf_field() ?>
      <div class="alert alert-danger d-none" role="alert" data-survey-error></div>
      <?php foreach ($survey['questions'] as $index => $question): ?>
        <fieldset class="mb-4"><legend class="fs-6 fw-semibold"><?= $index + 1 ?>. <?= esc($question['label']) ?><?= $question['required'] ? ' *' : '' ?></legend>
        <?php if ($question['type'] === 'text'): ?>
          <textarea name="answers[<?= $index ?>]" class="form-control" maxlength="2000" rows="4" aria-label="<?= esc($question['label']) ?>" <?= $question['required'] ? 'required' : '' ?>></textarea>
        <?php elseif ($question['type'] === 'rating'): ?>
          <?php
            $minAnchor = trim((string) ($question['scale_min_label'] ?? ''));
            $maxAnchor = trim((string) ($question['scale_max_label'] ?? ''));
          ?>
          <?php if ($minAnchor !== '' || $maxAnchor !== ''): ?>
            <div class="small text-muted mb-2">
              Scale guide:
              <?php if ($minAnchor !== ''): ?><strong>1</strong> = <?= esc($minAnchor) ?><?php endif; ?>
              <?php if ($minAnchor !== '' && $maxAnchor !== ''): ?> · <?php endif; ?>
              <?php if ($maxAnchor !== ''): ?><strong>5</strong> = <?= esc($maxAnchor) ?><?php endif; ?>
            </div>
          <?php endif; ?>
          <select name="answers[<?= $index ?>]" class="form-select" aria-label="<?= esc($question['label']) ?>" <?= $question['required'] ? 'required' : '' ?>>
            <option value="">Select a rating</option>
            <?php for ($rating = 1; $rating <= 5; $rating++): ?>
              <?php
                $ratingText = (string) $rating;
                if ($rating === 1 && $minAnchor !== '') {
                    $ratingText .= ' — ' . $minAnchor;
                } elseif ($rating === 5 && $maxAnchor !== '') {
                    $ratingText .= ' — ' . $maxAnchor;
                }
              ?>
              <option value="<?= $rating ?>"><?= esc($ratingText) ?></option>
            <?php endfor; ?>
          </select>
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
          <div data-choice-question="<?= $index ?>">
            <?php foreach ($question['options'] as $option => $label): ?>
              <?php $isOtherOpt = $otherIndex !== null && $option === $otherIndex; ?>
              <label class="form-check mb-2">
                <input class="form-check-input" type="<?= $inputType ?>" name="<?= $inputName ?>" value="<?= $option ?>" <?= $reqAttr ?> <?= $isOtherOpt ? 'data-other-option="1"' : '' ?>>
                <span class="form-check-label"><?= esc($label) ?></span>
              </label>
            <?php endforeach; ?>
            <?php if ($useDedicatedOther): ?>
              <label class="form-check mb-2">
                <input class="form-check-input" type="<?= $inputType ?>" name="<?= $inputName ?>" value="__other__" <?= $reqAttr ?> data-other-option="1">
                <span class="form-check-label">Other (please specify)</span>
              </label>
            <?php endif; ?>
            <?php if ($hasOtherControl): ?>
              <div class="mt-2 ms-4 d-none" data-other-wrapper="<?= $index ?>">
                <label class="visually-hidden" for="answerOther-<?= $index ?>">Please specify for <?= esc($question['label']) ?></label>
                <input type="text"
                       id="answerOther-<?= $index ?>"
                       class="form-control form-control-sm"
                       name="answers_other[<?= $index ?>]"
                       maxlength="500"
                       placeholder="Please specify your answer..."
                       data-other-input="<?= $index ?>"
                       disabled>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-primary">Submit identified response</button>
    </form>
  <?php endif; ?>
  <a href="<?= esc(site_url('portal/surveys')) ?>" class="btn btn-link mt-3">Back to surveys</a>
</div></div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<?php if (!$survey['submitted']): ?>
<script>
(() => {
  'use strict';
  const lifecycle = new AbortController();
  let disposeForm = null;
  const dispose = () => {
    lifecycle.abort();
    disposeForm?.();
    disposeForm = null;
  };
  window.SynapseNavigation?.registerCleanup(dispose);
  document.addEventListener('synapse:before-swap', dispose, {signal: lifecycle.signal});
  const init = root => {
  const form = root?.querySelector('#survey-response-form');
  if (!form?.isConnected || form.dataset.bound || lifecycle.signal.aborted) return;
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
      window.location.assign(successUrl.href);
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
