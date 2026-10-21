<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="card"><div class="card-body pt-3">
  <h5 class="card-title"><?= esc($survey['title']) ?></h5>
  <p class="text-break"><?= nl2br(esc($survey['description'])) ?></p>
  <div class="alert alert-info">This is an identified survey. Your name and answers are available to authorized Guidance administrators and supervisors. You may submit once; answers cannot be changed after submission.</div>
  <?php if ($survey['submitted']): ?>
    <div class="alert alert-success">You have submitted this survey. Your completion has been recorded.</div>
  <?php else: ?>
  <form id="survey-response-form" autocomplete="off" method="post" action="<?= esc(site_url('portal/surveys/' . $survey['id'] . '/submit')) ?>" data-success-url="<?= esc(site_url('portal/surveys')) ?>" data-survey-submit data-confirm="Submit your identified response? You cannot change it afterwards." data-confirm-title="Submit survey" data-confirm-label="Submit response">
    <?= csrf_field() ?>
    <div class="alert alert-danger d-none" role="alert" data-survey-error></div>
    <?php foreach ($survey['questions'] as $index => $question): ?>
      <fieldset class="mb-4"><legend class="fs-6 fw-semibold"><?= $index + 1 ?>. <?= esc($question['label']) ?><?= $question['required'] ? ' *' : '' ?></legend>
      <?php if ($question['type'] === 'text'): ?>
        <textarea name="answers[<?= $index ?>]" class="form-control" maxlength="2000" rows="4" aria-label="<?= esc($question['label']) ?>" <?= $question['required'] ? 'required' : '' ?>></textarea>
      <?php elseif ($question['type'] === 'rating'): ?>
        <select name="answers[<?= $index ?>]" class="form-select" aria-label="<?= esc($question['label']) ?>" <?= $question['required'] ? 'required' : '' ?>><option value="">Select a rating</option><?php for ($rating = 1; $rating <= 5; $rating++): ?><option value="<?= $rating ?>"><?= $rating ?></option><?php endfor; ?></select>
      <?php else: ?>
        <?php foreach ($question['options'] as $option => $label): ?><label class="form-check mb-2"><input class="form-check-input" type="<?= $question['type'] === 'single' ? 'radio' : 'checkbox' ?>" name="answers[<?= $index ?>]<?= $question['type'] === 'multiple' ? '[]' : '' ?>" value="<?= $option ?>" <?= $question['required'] && $question['type'] === 'single' ? 'required' : '' ?>><span class="form-check-label"><?= esc($label) ?></span></label><?php endforeach; ?>
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
  disposeForm = () => {
    disposed = true;
    request?.abort();
    events.abort();
    form.reset();
    delete form.dataset.bound;
  };
  window.addEventListener('pagehide', () => { request?.abort(); form.reset(); }, {signal: events.signal});
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
