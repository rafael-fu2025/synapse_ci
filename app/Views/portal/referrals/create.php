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

    <div class="row g-3 g-lg-4">
      <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-transparent py-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
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

              <!-- Step 1: Source Type & Student Resolution -->
              <div class="mb-4">
                <label class="form-label fw-semibold" for="referralSourceType">1. Referral Type &amp; Student Lookup</label>
                <select name="source_type" id="referralSourceType" class="form-select mb-3" required>
                  <option value="employee_teacher">Faculty / Teacher Referral</option>
                  <option value="staff_on_behalf">Staff Referral on Behalf of Students</option>
                </select>

                <div id="misLookupPanel" class="p-3 rounded border bg-body-tertiary">
                  <label for="exactMisIdInput" class="form-label small fw-semibold">Exact Student MIS ID</label>
                  <div class="d-flex flex-column flex-sm-row gap-2 mb-2">
                    <input type="text"
                           id="exactMisIdInput"
                           class="form-control form-control-sm"
                           maxlength="64"
                           placeholder="Enter exact student MIS ID (e.g. 2024-00123)"
                           autocomplete="off">
                    <button type="button"
                            id="resolveSubjectBtn"
                            class="btn btn-outline-primary btn-sm flex-shrink-0"
                            data-resolve-url="<?= esc(site_url('/portal/referrals/resolve-student'), 'attr') ?>">
                      <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Add Student
                    </button>
                  </div>
                  <div class="form-text small text-muted">
                    Add up to <?= \App\Services\Guidance\GuidanceIntakeService::MAX_PORTAL_STUDENTS ?> students by exact MIS ID. Directory browsing and wildcard queries (<code>%</code>, <code>*</code>, <code>?</code>) remain disabled for privacy.
                  </div>
                  <div id="subjectResolveStatus" class="small mt-2" role="status" aria-live="polite"></div>

                  <div class="mt-3" id="selectedStudentsPanel">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                      <span class="small fw-semibold">Selected Students</span>
                      <span class="badge bg-primary-subtle text-primary" id="selectedStudentsCount">0 selected</span>
                    </div>
                    <div id="selectedStudentsEmpty" class="small text-muted border rounded p-3">No students selected yet.</div>
                    <div id="selectedStudentsList" class="vstack gap-2" aria-live="polite"></div>
                  </div>
                </div>
              </div>

              <!-- Step 2: Concern Catalog -->
              <div class="alert alert-info small border-0" role="note">
                The concerns, narrative, priority, and prior actions below will be copied into a separate confidential referral for every selected student.
              </div>
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
                <div id="reasonsOtherWrap" class="mt-2 d-none">
                  <label for="reasonsOtherSpecify" class="form-label small fw-semibold">If "Others" selected, please specify</label>
                  <input type="text" id="reasonsOtherSpecify" name="reasons_other_specify" class="form-control form-control-sm" maxlength="500" placeholder="Optional clarification" disabled>
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

              <div class="d-grid d-sm-flex flex-sm-wrap gap-2 justify-content-sm-end pt-3 border-top">
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
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
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
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
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
                          <form action="<?= esc(site_url('/portal/referrals/' . (int) $ref['id'] . '/withdraw'), 'attr') ?>"
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

  const MAX_SELECTED_STUDENTS = <?= \App\Services\Guidance\GuidanceIntakeService::MAX_PORTAL_STUDENTS ?>;
  const boundNodes = window.__synapseReferralBound || (window.__synapseReferralBound = new WeakSet());

  function bindReferralLookup() {
    const form = document.getElementById('portalReferralForm');
    const misInput = document.getElementById('exactMisIdInput');
    const resolveBtn = document.getElementById('resolveSubjectBtn');
    const statusBox = document.getElementById('subjectResolveStatus');
    const list = document.getElementById('selectedStudentsList');
    const empty = document.getElementById('selectedStudentsEmpty');
    const count = document.getElementById('selectedStudentsCount');

    if (!form || !misInput || !resolveBtn || !list || boundNodes.has(resolveBtn)) return;
    boundNodes.add(resolveBtn);

    const selected = new Map();

    function syncSelectedStudents() {
      list.replaceChildren();
      selected.forEach(function (person) {
        const row = document.createElement('div');
        row.className = 'd-flex flex-wrap align-items-center justify-content-between gap-2 rounded border bg-white p-2';

        const copy = document.createElement('div');
        const name = document.createElement('strong');
        name.className = 'small d-block';
        name.textContent = `${person.last_name}, ${person.first_name}`;
        const meta = document.createElement('span');
        meta.className = 'small text-muted';
        meta.textContent = [`MIS ID: ${person.mis_id}`, person.program || null, person.year_level ? `Year ${person.year_level}` : null].filter(Boolean).join(' · ');
        copy.append(name, meta);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'subject_person_ids[]';
        hidden.value = String(person.person_id || person.id);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-outline-danger btn-sm';
        remove.setAttribute('aria-label', `Remove ${person.first_name} ${person.last_name}`);
        remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i> Remove';
        remove.addEventListener('click', function () {
          selected.delete(String(person.person_id || person.id));
          syncSelectedStudents();
        });

        row.append(copy, hidden, remove);
        list.append(row);
      });
      const size = selected.size;
      if (empty) empty.classList.toggle('d-none', size > 0);
      if (count) count.textContent = `${size} selected`;
      resolveBtn.disabled = size >= MAX_SELECTED_STUDENTS;
    }

    resolveBtn.addEventListener('click', async function () {
      const misId = (misInput.value || '').trim();
      if (!misId) {
        if (statusBox) {
          statusBox.className = 'small mt-2 text-danger';
          statusBox.textContent = 'Enter an exact student MIS ID before adding.';
        }
        return;
      }
      if (selected.size >= MAX_SELECTED_STUDENTS) {
        if (statusBox) {
          statusBox.className = 'small mt-2 text-danger';
          statusBox.textContent = `You may select up to ${MAX_SELECTED_STUDENTS} students per form.`;
        }
        return;
      }

      resolveBtn.disabled = true;
      if (statusBox) {
        statusBox.className = 'small mt-2 text-muted';
        statusBox.textContent = 'Resolving exact student MIS ID...';
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
          throw new Error(payload.message || 'Student not found for that exact MIS ID.');
        }

        const person = payload.person;
        const personId = String(person.person_id || person.id || '');
        if (!personId || person.kind !== 'student') {
          throw new Error('Only active students may be selected.');
        }
        if (selected.has(personId)) {
          throw new Error('That student is already selected.');
        }
        selected.set(personId, person);
        syncSelectedStudents();
        misInput.value = '';
        if (statusBox) {
          statusBox.className = 'small mt-2 text-success';
          statusBox.textContent = `${person.first_name} ${person.last_name} added.`;
        }
        misInput.focus();
      } catch (err) {
        if (statusBox) {
          statusBox.className = 'small mt-2 text-danger';
          statusBox.textContent = err.message || 'Unable to resolve student.';
        }
      } finally {
        resolveBtn.disabled = selected.size >= MAX_SELECTED_STUDENTS;
      }
    });

    misInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        resolveBtn.click();
      }
    });

    form.addEventListener('submit', function (event) {
      if (selected.size > 0) return;
      event.preventDefault();
      if (statusBox) {
        statusBox.className = 'small mt-2 text-danger';
        statusBox.textContent = 'Add at least one student before saving or submitting.';
      }
      misInput.focus();
    });

    syncSelectedStudents();
  }

  function bindOthersToggle() {
    const form = document.getElementById('portalReferralForm');
    const otherWrap = document.getElementById('reasonsOtherWrap');
    const otherInput = document.getElementById('reasonsOtherSpecify');
    const othersCheckbox = form?.querySelector('input[type="checkbox"][name="reasons[]"][value="others"]');
    if (!form || !otherWrap || !otherInput || !othersCheckbox || boundNodes.has(othersCheckbox)) return;
    boundNodes.add(othersCheckbox);

    function syncOthersField(focusOnOpen = false) {
      const show = othersCheckbox.checked;
      otherWrap.classList.toggle('d-none', !show);
      otherInput.disabled = !show;
      if (show && focusOnOpen) {
        otherInput.focus();
      }
    }

    othersCheckbox.addEventListener('change', function () {
      syncOthersField(true);
    });
    syncOthersField(false);
  }

  function initReferralForm() {
    bindReferralLookup();
    bindOthersToggle();
  }

  document.addEventListener('DOMContentLoaded', initReferralForm);
  document.addEventListener('synapse:page-ready', initReferralForm);
  document.addEventListener('synapse:page-load', initReferralForm);
  initReferralForm();
})();
</script>
<?= $this->endSection() ?>
