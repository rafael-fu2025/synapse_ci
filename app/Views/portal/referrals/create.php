<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= esc(site_url('/portal')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Back to Portal
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$catalog            = $catalog ?? [];
$catalogItems       = $catalog['items'] ?? [];
$priorityHints      = $priorityHints ?? ['routine', 'urgent', 'crisis'];
$canonicalFormsGate = $canonicalFormsGate ?? null;
$canonicalFormsOpen = !empty($canonicalFormsOpen);
$selfPerson         = $selfPerson ?? null;
$myReferrals        = $myReferrals ?? [];
$user               = $user ?? [];

$statusBadge = static fn (string $status): string => match ($status) {
    'draft'            => 'bg-secondary-subtle text-secondary border-secondary-subtle',
    'submitted'        => 'bg-primary-subtle text-primary border-primary-subtle',
    'received'         => 'bg-info-subtle text-info border-info-subtle',
    'triaged'          => 'bg-warning-subtle text-warning border-warning-subtle',
    'accepted', 'case_linked' => 'bg-success-subtle text-success border-success-subtle',
    'declined', 'withdrawn'   => 'bg-danger-subtle text-danger border-danger-subtle',
    default            => 'bg-body-tertiary text-body',
};
?>

<div class="portal-referral-workspace"
     data-refresh-owner="portal.referrals"
     data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <?php if (!$canonicalFormsOpen): ?>
      <div class="alert alert-warning border-0 shadow-sm d-flex align-items-start gap-2 mb-4" role="status">
        <i class="bi bi-shield-exclamation fs-5 mt-1" aria-hidden="true"></i>
        <div class="small">
          <strong>Activation Gate Pending (<code>canonical_forms_signoff</code>):</strong>
          Live General Referral submission is currently disabled until Guidance Head, QA Office, and DPO complete canonical form sign-off. You may still resolve a subject and save a referral <strong>as a draft</strong>.
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
            <div>
              <h2 class="h6 fw-bold mb-0">Guidance General Referral Form</h2>
              <small class="text-muted">Form Code: <?= esc($catalog['form_code'] ?? 'F-OGC-GRE-GGR-005-00') ?> · AES-256-GCM Encrypted</small>
            </div>
            <span class="badge bg-success-subtle text-success border border-success-subtle">
              <i class="bi bi-lock-fill me-1" aria-hidden="true"></i> Confidential
            </span>
          </div>
          <div class="card-body">

            <form id="portalReferralForm"
                  action="<?= esc(site_url('/portal/referrals/store'), 'attr') ?>"
                  method="POST"
                  data-background-submit>
              <?= csrf_field() ?>
              <input type="hidden" name="subject_person_id" id="resolvedSubjectPersonId" value="">

              <!-- Step 1: Source Type & Subject Resolution -->
              <div class="mb-4">
                <label class="form-label fw-semibold" for="referralSourceType">1. Referral Type &amp; Subject Lookup</label>
                <select name="source_type" id="referralSourceType" class="form-select mb-3" required>
                  <option value="employee_teacher">Faculty / Teacher Referral (For a Student)</option>
                  <option value="staff_on_behalf">Staff Referral on Behalf of Subject</option>
                  <option value="self" <?= $selfPerson === null ? 'disabled' : '' ?>>Self-Referral (For Myself)</option>
                </select>

                <div id="misLookupPanel" class="p-3 rounded border bg-body-tertiary">
                  <label for="exactMisIdInput" class="form-label small fw-semibold">Exact Student / Employee MIS ID</label>
                  <div class="input-group input-group-sm mb-2">
                    <input type="text"
                           id="exactMisIdInput"
                           name="mis_id"
                           class="form-control"
                           maxlength="64"
                           placeholder="Enter exact MIS ID (e.g. 2024-00123)"
                           autocomplete="off">
                    <button type="button"
                            id="resolveSubjectBtn"
                            class="btn btn-outline-primary"
                            data-resolve-url="<?= esc(site_url('/portal/referrals/resolve-subject'), 'attr') ?>">
                      <i class="bi bi-person-check me-1" aria-hidden="true"></i> Resolve Subject
                    </button>
                  </div>
                  <div class="form-text small text-muted">
                    Directory browsing and wildcard queries (<code>%</code>, <code>*</code>, <code>?</code>) are strictly disabled for privacy. Provide the exact MIS ID.
                  </div>
                  <div id="subjectResolveStatus" class="small mt-2" role="status" aria-live="polite"></div>
                  <div id="resolvedSubjectCard" class="mt-2 p-2 rounded border bg-success-subtle text-success d-none">
                    <div class="d-flex justify-content-between align-items-center">
                      <div>
                        <strong id="resolvedSubjectName"></strong>
                        <div class="small" id="resolvedSubjectMeta"></div>
                      </div>
                      <span class="badge bg-success">Verified</span>
                    </div>
                  </div>
                </div>

                <?php if ($selfPerson !== null): ?>
                  <div id="selfSubjectPanel" class="p-3 rounded border bg-body-tertiary d-none">
                    <div class="small text-muted">Referring self as:</div>
                    <div class="fw-semibold">
                      <?= esc(trim(($selfPerson['last_name'] ?? '') . ', ' . ($selfPerson['first_name'] ?? ''))) ?>
                      (MIS ID: <?= esc($selfPerson['mis_id'] ?? '—') ?>)
                    </div>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Step 2: Concern Catalog -->
              <div class="mb-4">
                <div class="form-label fw-semibold">2. Presenting Concerns (Select all that apply)</div>
                <div class="row g-2">
                  <?php foreach ($catalogItems as $item): ?>
                    <?php $code = (string) ($item['code'] ?? ''); ?>
                    <div class="col-md-6">
                      <label class="d-flex align-items-start gap-2 p-2 rounded border bg-body-tertiary h-100" style="cursor: pointer;">
                        <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" name="reasons[]" value="<?= esc($code, 'attr') ?>">
                        <span class="small">
                          <strong class="d-block"><?= esc($item['label'] ?? $code) ?></strong>
                          <?php if (!empty($item['description'])): ?>
                            <span class="text-muted"><?= esc($item['description']) ?></span>
                          <?php endif; ?>
                        </span>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="mt-2">
                  <label for="reasonsOtherSpecify" class="form-label small fw-semibold">If "Others" selected, please specify</label>
                  <input type="text" id="reasonsOtherSpecify" name="reasons_other_specify" class="form-control form-control-sm" maxlength="500" placeholder="Optional clarification">
                </div>
              </div>

              <!-- Step 3: Priority Attention Hint -->
              <div class="mb-4">
                <label for="priorityHint" class="form-label fw-semibold">3. Referrer Priority Attention Hint</label>
                <select name="priority_hint" id="priorityHint" class="form-select" required>
                  <option value="routine">Routine — Standard intake scheduling</option>
                  <option value="urgent">Urgent — Prompt counselor review requested</option>
                  <option value="crisis">Crisis — Immediate safety / acute distress attention</option>
                </select>
                <div class="form-text small text-muted">
                  This is an attention hint for Guidance intake triage; a qualified Guidance practitioner determines the authoritative clinical risk level. For immediate danger to life, contact Campus Security / Clinic Emergency directly.
                </div>
              </div>

              <!-- Step 4: Encrypted Narrative & Prior Actions -->
              <div class="mb-3">
                <label for="reasonsNarrative" class="form-label fw-semibold">4. Observable Concerns &amp; Narrative <span class="text-danger">*</span></label>
                <textarea name="reasons_narrative"
                          id="reasonsNarrative"
                          rows="5"
                          class="form-control"
                          maxlength="4096"
                          required
                          placeholder="Describe specific behaviors, attendance patterns, or concerns observed. Encrypted prior to storage."></textarea>
              </div>

              <div class="mb-4">
                <label for="priorActionsTaken" class="form-label fw-semibold">5. Prior Actions / Interventions Taken (Optional)</label>
                <textarea name="prior_actions_taken"
                          id="priorActionsTaken"
                          rows="3"
                          class="form-control"
                          maxlength="4096"
                          placeholder="E.g., initial student conference, parent/adviser notice, academic support offered"></textarea>
              </div>

              <div class="d-flex flex-wrap gap-2 justify-content-end pt-3 border-top">
                <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">
                  <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i> Save as Draft
                </button>
                <button type="submit"
                        name="action"
                        value="submit"
                        class="btn btn-primary"
                        <?= !$canonicalFormsOpen ? 'disabled title="Disabled until canonical_forms_signoff gate is enabled"' : '' ?>>
                  <i class="bi bi-send-check me-1" aria-hidden="true"></i> Submit Live Referral
                </button>
              </div>
            </form>

          </div>
        </div>
      </div>

      <!-- My Recent Referrals Sidebar -->
      <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-transparent py-3">
            <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history me-1" aria-hidden="true"></i> My Submitted &amp; Draft Referrals</h2>
          </div>
          <div class="card-body p-0">
            <?php if ($myReferrals === []): ?>
              <div class="p-4 text-center text-muted small">
                You have not created any Guidance referrals yet.
              </div>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach ($myReferrals as $ref): ?>
                  <div class="list-group-item bg-transparent py-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                      <div>
                        <div class="fw-bold small"><?= esc($ref['referral_number']) ?></div>
                        <div class="small text-muted">
                          Subject: <?= esc(trim(($ref['last_name'] ?? '') . ', ' . ($ref['first_name'] ?? ''))) ?>
                          (<?= esc($ref['mis_id'] ?? '—') ?>)
                        </div>
                      </div>
                      <span class="badge border text-capitalize <?= $statusBadge((string) $ref['status']) ?>">
                        <?= esc(str_replace('_', ' ', (string) $ref['status'])) ?>
                      </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                      <span class="small text-muted text-capitalize">Hint: <?= esc($ref['priority_hint']) ?></span>
                      <div class="d-flex gap-1">
                        <?php if ($ref['status'] === 'draft' && $canonicalFormsOpen): ?>
                          <form action="<?= esc(site_url('/portal/referrals/store'), 'attr') ?>" method="POST" data-background-submit class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="referral_id" value="<?= (int) $ref['id'] ?>">
                            <input type="hidden" name="action" value="submit">
                            <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-2">Submit</button>
                          </form>
                        <?php endif; ?>
                        <?php if (in_array($ref['status'], ['draft', 'submitted', 'received'], true)): ?>
                          <form action="<?= esc(site_url('/guidance/intake/' . (int) $ref['id'] . '/withdraw'), 'attr') ?>"
                                method="POST"
                                data-background-submit
                                data-confirm="Withdraw referral <?= esc($ref['referral_number'], 'js') ?>? Referrals cannot be withdrawn once triaged."
                                data-confirm-title="Withdraw Referral"
                                data-confirm-label="Withdraw"
                                data-confirm-tone="danger"
                                class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="confirmed" value="1">
                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2">Withdraw</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  'use strict';

  function bindReferralLookup() {
    const sourceSelect = document.getElementById('referralSourceType');
    const misPanel = document.getElementById('misLookupPanel');
    const selfPanel = document.getElementById('selfSubjectPanel');
    const misInput = document.getElementById('exactMisIdInput');
    const resolveBtn = document.getElementById('resolveSubjectBtn');
    const hiddenPersonId = document.getElementById('resolvedSubjectPersonId');
    const statusBox = document.getElementById('subjectResolveStatus');
    const card = document.getElementById('resolvedSubjectCard');
    const nameEl = document.getElementById('resolvedSubjectName');
    const metaEl = document.getElementById('resolvedSubjectMeta');

    if (!sourceSelect || !resolveBtn || resolveBtn.dataset.bound === '1') return;
    resolveBtn.dataset.bound = '1';

    function toggleSourceMode() {
      const isSelf = sourceSelect.value === 'self';
      if (misPanel) misPanel.classList.toggle('d-none', isSelf);
      if (selfPanel) selfPanel.classList.toggle('d-none', !isSelf);
      if (isSelf && hiddenPersonId) {
        hiddenPersonId.value = '';
      }
    }

    sourceSelect.addEventListener('change', toggleSourceMode);
    toggleSourceMode();

    misInput?.addEventListener('input', function () {
      if (hiddenPersonId) hiddenPersonId.value = '';
      if (card) card.classList.add('d-none');
    });

    resolveBtn.addEventListener('click', async function () {
      const misId = (misInput?.value || '').trim();
      if (!misId) {
        if (statusBox) {
          statusBox.className = 'small mt-2 text-danger';
          statusBox.textContent = 'Enter an exact MIS ID before looking up.';
        }
        return;
      }

      resolveBtn.disabled = true;
      if (statusBox) {
        statusBox.className = 'small mt-2 text-muted';
        statusBox.textContent = 'Resolving exact MIS ID...';
      }

      try {
        const body = new FormData();
        body.append('mis_id', misId);
        const csrfName = document.querySelector('meta[name="csrf-name"]')?.content;
        const csrfHash = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrfName && csrfHash) body.append(csrfName, csrfHash);

        const response = await fetch(resolveBtn.dataset.resolveUrl, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(window.SynapseCsrf?.headers() || {})
          }
        });
        window.SynapseCsrf?.fromResponse(response);
        const payload = await response.json();
        window.SynapseCsrf?.sync(payload.csrf);

        if (!response.ok || payload.success !== true || !payload.person) {
          throw new Error(payload.message || 'Subject not found for that exact MIS ID.');
        }

        const p = payload.person;
        if (hiddenPersonId) hiddenPersonId.value = String(p.person_id || p.id || '');
        if (nameEl) nameEl.textContent = `${p.last_name}, ${p.first_name}`;
        const details = [
          `MIS ID: ${p.mis_id}`,
          (p.kind || 'student').toUpperCase(),
          p.program ? `${p.program}${p.year_level ? ' · Yr ' + p.year_level : ''}` : null,
          p.department || null
        ].filter(Boolean).join(' · ');
        if (metaEl) metaEl.textContent = details;
        if (card) card.classList.remove('d-none');
        if (statusBox) {
          statusBox.className = 'small mt-2 text-success';
          statusBox.textContent = 'Exact subject resolved.';
        }
      } catch (err) {
        if (hiddenPersonId) hiddenPersonId.value = '';
        if (card) card.classList.add('d-none');
        if (statusBox) {
          statusBox.className = 'small mt-2 text-danger';
          statusBox.textContent = err.message || 'Unable to resolve subject.';
        }
      } finally {
        resolveBtn.disabled = false;
      }
    });
  }

  document.addEventListener('DOMContentLoaded', bindReferralLookup);
  document.addEventListener('synapse:page-ready', bindReferralLookup);
  bindReferralLookup();
})();
</script>
<?= $this->endSection() ?>
