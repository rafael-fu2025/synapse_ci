<?php
$evalIssuePersonId = (int) ($evalIssuePersonId ?? 0);
$evalIssuePersonLabel = trim((string) ($evalIssuePersonLabel ?? ''));
$evalIssueEpisodeId = (int) ($evalIssueEpisodeId ?? 0);
$evalIssueDefaultType = (string) ($evalIssueDefaultType ?? \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL);
$evalIssueSessions = is_array($evalIssueSessions ?? null) ? $evalIssueSessions : [];
$evalIssueProviders = is_array($evalIssueProviders ?? null) ? $evalIssueProviders : [];
$evalIssueProviderId = (int) ($evalIssueProviderId ?? 0);
$evalIssueCanSelectProvider = !empty($evalIssueCanSelectProvider) && $evalIssueProviders !== [];
?>
<div class="modal fade" id="issueEvalTokenModal" tabindex="-1" aria-labelledby="issueEvalTokenModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" data-eval-issue-modal data-issue-url="<?= esc(site_url('/guidance/evaluations/issue'), 'attr') ?>" data-lookup-url="<?= esc(site_url('/api/patients/search'), 'attr') ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="issueEvalTokenModalLabel">
          <i class="bi bi-ticket-perforated me-2" aria-hidden="true"></i>Issue Service Evaluation Token
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="POST" action="<?= esc(site_url('/guidance/evaluations/issue'), 'attr') ?>" data-eval-issue-form data-navigation-dirty="false" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="episode_id" value="<?= $evalIssueEpisodeId > 0 ? $evalIssueEpisodeId : '' ?>">

        <div class="modal-body">
          <div class="alert alert-danger py-2 px-3 small d-none" role="alert" data-eval-issue-error></div>

          <div data-eval-issue-fields>
            <?php if ($evalIssuePersonId > 0): ?>
              <input type="hidden" name="person_id" value="<?= $evalIssuePersonId ?>" data-eval-person-id>
              <div class="mb-3">
                <label for="evalIssueClientReadonly" class="form-label fw-semibold">Client</label>
                <input id="evalIssueClientReadonly" type="text" class="form-control form-control-sm" value="<?= esc($evalIssuePersonLabel !== '' ? $evalIssuePersonLabel : 'Client #' . $evalIssuePersonId, 'attr') ?>" readonly>
              </div>
            <?php else: ?>
              <div class="mb-3 position-relative">
                <label for="evalIssueClientSearch" class="form-label fw-semibold">Student / Employee <span class="text-danger">*</span></label>
                <input type="hidden" name="person_id" value="" data-eval-person-id>
                <input id="evalIssueClientSearch"
                       type="search"
                       class="form-control form-control-sm"
                       placeholder="Search by name or MIS ID (min 2 chars)…"
                       autocomplete="off"
                       data-eval-person-search>
                <div class="form-text" data-eval-person-selected>Search and select a client to tie eligibility (stored only as an HMAC, never in evaluations).</div>
                <div class="list-group mt-1 shadow-sm d-none" style="max-height: 180px; overflow-y: auto;" data-eval-person-results></div>
              </div>
            <?php endif; ?>

            <div class="mb-3">
              <label for="evalIssueType" class="form-label fw-semibold">Evaluation Form <span class="text-danger">*</span></label>
              <select id="evalIssueType" name="evaluation_type" class="form-select form-select-sm" required>
                <option value="<?= esc(\App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL, 'attr') ?>"<?= $evalIssueDefaultType === \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_PSYCHOSOCIAL ? ' selected' : '' ?>>
                  Psychosocial Support Evaluation (F-OGC-GPS-PEF-008-00)
                </option>
                <option value="<?= esc(\App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_COUNSELING, 'attr') ?>"<?= $evalIssueDefaultType === \App\Services\Guidance\GuidanceServiceEvaluationService::TYPE_COUNSELING ? ' selected' : '' ?>>
                  Counseling Evaluation (F-OGC-GCS-CEF-009-00)
                </option>
              </select>
            </div>

            <?php if ($evalIssueSessions !== []): ?>
              <div class="mb-3">
                <label for="evalIssueSessionId" class="form-label fw-semibold">Session Context <span class="text-muted fw-normal">(Optional)</span></label>
                <select id="evalIssueSessionId" name="session_id" class="form-select form-select-sm">
                  <option value="">General case / episode evaluation</option>
                  <?php foreach ($evalIssueSessions as $sess): ?>
                    <?php
                      $sessId = (int) ($sess['id'] ?? 0);
                      $sessNum = (string) ($sess['session_number'] ?? $sessId);
                      $sessStarted = !empty($sess['started_at']) ? date('M j, Y', strtotime((string) $sess['started_at'])) : '';
                    ?>
                    <option value="<?= $sessId ?>">
                      Session #<?= esc($sessNum) ?><?= $sessStarted !== '' ? ' · ' . esc($sessStarted) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($evalIssueCanSelectProvider): ?>
              <div class="mb-0">
                <label for="evalIssueProviderId" class="form-label fw-semibold">Guidance Provider</label>
                <select id="evalIssueProviderId" name="provider_user_id" class="form-select form-select-sm">
                  <?php foreach ($evalIssueProviders as $prov): ?>
                    <?php
                      $pId = (int) ($prov['id'] ?? 0);
                      $pName = trim(trim((string) ($prov['first_name'] ?? '')) . ' ' . trim((string) ($prov['last_name'] ?? '')));
                      $pLabel = $pName !== '' ? $pName : (string) ($prov['username'] ?? 'User #' . $pId);
                    ?>
                    <option value="<?= $pId ?>"<?= $pId === $evalIssueProviderId ? ' selected' : '' ?>><?= esc($pLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
          </div>

          <div class="d-none" data-eval-issue-result>
            <div class="alert alert-success py-2 px-3 small mb-3" role="status">
              <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
              One-time evaluation token issued. Share either the direct link or the token code with the student/employee.
            </div>

            <div class="mb-3">
              <label for="evalIssuedLink" class="form-label small fw-semibold mb-1">Direct Evaluation Link</label>
              <div class="input-group input-group-sm">
                <input id="evalIssuedLink" type="text" class="form-control" readonly data-eval-result-url>
                <button type="button" class="btn btn-outline-secondary" data-eval-copy="#evalIssuedLink">
                  <i class="bi bi-clipboard" aria-hidden="true"></i> Copy Link
                </button>
              </div>
            </div>

            <div class="mb-3">
              <label for="evalIssuedRawToken" class="form-label small fw-semibold mb-1">One-Time Token Code</label>
              <div class="input-group input-group-sm">
                <input id="evalIssuedRawToken" type="text" class="form-control" readonly data-eval-result-token>
                <button type="button" class="btn btn-outline-secondary" data-eval-copy="#evalIssuedRawToken">
                  <i class="bi bi-clipboard" aria-hidden="true"></i> Copy Token
                </button>
              </div>
            </div>

            <p class="small text-muted mb-0" data-eval-result-meta></p>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm d-none" data-eval-issue-another>Issue Another</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary btn-sm" data-eval-issue-submit>
            <i class="bi bi-ticket-perforated me-1" aria-hidden="true"></i>Generate One-Time Token
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';
  const modalRoot = document.querySelector('[data-eval-issue-modal]');
  if (!modalRoot || modalRoot.dataset.evalIssueBound === '1') return;
  modalRoot.dataset.evalIssueBound = '1';

  const form = modalRoot.querySelector('[data-eval-issue-form]');
  const errBox = modalRoot.querySelector('[data-eval-issue-error]');
  const fieldsWrap = modalRoot.querySelector('[data-eval-issue-fields]');
  const resultWrap = modalRoot.querySelector('[data-eval-issue-result]');
  const submitBtn = modalRoot.querySelector('[data-eval-issue-submit]');
  const anotherBtn = modalRoot.querySelector('[data-eval-issue-another]');
  const urlInput = modalRoot.querySelector('[data-eval-result-url]');
  const tokenInput = modalRoot.querySelector('[data-eval-result-token]');
  const metaEl = modalRoot.querySelector('[data-eval-result-meta]');

  const personHidden = modalRoot.querySelector('[data-eval-person-id]');
  const searchInput = modalRoot.querySelector('[data-eval-person-search]');
  const resultsList = modalRoot.querySelector('[data-eval-person-results]');
  const selectedNote = modalRoot.querySelector('[data-eval-person-selected]');
  let lookupTimer = null;

  function showError(msg) {
    if (!errBox) return;
    errBox.textContent = msg;
    errBox.classList.remove('d-none');
  }

  function clearError() {
    if (!errBox) return;
    errBox.textContent = '';
    errBox.classList.add('d-none');
  }

  if (searchInput && resultsList && personHidden) {
    searchInput.addEventListener('input', () => {
      personHidden.value = '';
      if (selectedNote) selectedNote.textContent = 'Select a matching person from the list.';
      const q = searchInput.value.trim();
      clearTimeout(lookupTimer);
      if (q.length < 2) {
        resultsList.replaceChildren();
        resultsList.classList.add('d-none');
        return;
      }
      lookupTimer = setTimeout(async () => {
        try {
          const url = `${modalRoot.dataset.lookupUrl}?q=${encodeURIComponent(q)}`;
          const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
          if (!res.ok) return;
          const data = await res.json();
          const rows = Array.isArray(data.results) ? data.results : [];
          resultsList.replaceChildren();
          if (!rows.length) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item small text-muted';
            empty.textContent = 'No matching client found.';
            resultsList.appendChild(empty);
            resultsList.classList.remove('d-none');
            return;
          }
          for (const row of rows) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 small';
            const fullName = `${row.last_name || ''}, ${row.first_name || ''}`.replace(/^,\s*|\s*,\s*$/g, '');
            btn.textContent = `${fullName}${row.mis_id ? ` (${row.mis_id})` : ''}${row.kind ? ` · ${row.kind}` : ''}`;
            btn.addEventListener('click', () => {
              personHidden.value = String(row.id);
              searchInput.value = fullName + (row.mis_id ? ` (${row.mis_id})` : '');
              if (selectedNote) selectedNote.textContent = `Selected: ${btn.textContent}`;
              resultsList.classList.add('d-none');
              clearError();
            });
            resultsList.appendChild(btn);
          }
          resultsList.classList.remove('d-none');
        } catch (_) {
          resultsList.classList.add('d-none');
        }
      }, 220);
    });
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    clearError();
    if (!personHidden || !personHidden.value || Number(personHidden.value) <= 0) {
      showError('Please select a valid student or employee first.');
      return;
    }
    if (submitBtn) submitBtn.disabled = true;

    try {
      const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(window.SynapseCsrf?.headers?.() || {}),
      };
      const res = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: new FormData(form),
      });
      const data = await res.json();
      if (data.csrf && data.csrf.name && data.csrf.hash) {
        const hidden = form.querySelector(`input[name="${data.csrf.name}"]`);
        if (hidden) hidden.value = data.csrf.hash;
        window.SynapseCsrf?.sync?.(data.csrf);
      }
      if (!res.ok || !data.success) {
        showError(data.message || 'Unable to issue evaluation token.');
        return;
      }

      if (urlInput) urlInput.value = data.redeem_url || '';
      if (tokenInput) tokenInput.value = data.raw_token || '';
      if (metaEl) {
        metaEl.textContent = `Form ${data.form_code || ''} · Valid until ${data.expires_at || '14 days'}. Shown once only — raw tokens are never stored in plain text.`;
      }
      fieldsWrap?.classList.add('d-none');
      resultWrap?.classList.remove('d-none');
      submitBtn?.classList.add('d-none');
      anotherBtn?.classList.remove('d-none');
    } catch (_) {
      showError('Network error while generating evaluation token. Please try again.');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  anotherBtn?.addEventListener('click', () => {
    clearError();
    resultWrap?.classList.add('d-none');
    fieldsWrap?.classList.remove('d-none');
    submitBtn?.classList.remove('d-none');
    anotherBtn?.classList.add('d-none');
    if (urlInput) urlInput.value = '';
    if (tokenInput) tokenInput.value = '';
  });

  modalRoot.querySelectorAll('[data-eval-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const target = modalRoot.querySelector(btn.getAttribute('data-eval-copy'));
      if (!target || !target.value) return;
      try {
        await navigator.clipboard.writeText(target.value);
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2" aria-hidden="true"></i> Copied';
        setTimeout(() => { btn.innerHTML = orig; }, 1600);
      } catch (_) {
        target.select();
      }
    });
  });
})();
</script>
