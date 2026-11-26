<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/portal'), 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Health Portal
</a>
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

$statusTone = static fn (string $status): string => match ($status) {
    'submitted'               => 'is-submitted',
    'received'                => 'is-received',
    'triaged'                 => 'is-triaged',
    'accepted', 'case_linked' => 'is-accepted',
    'declined', 'withdrawn'   => 'is-declined',
    default                   => '',
};
?>

<div class="portal-referral-workspace"
     data-refresh-owner="portal.referrals"
     data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <div class="rf-layout">
      <!-- Primary Form Sheet -->
      <section class="rf-sheet" aria-labelledby="referralFormHeading">
        <div class="rf-band">
          <div class="rf-header-row">
            <div>
              <h2 class="rf-title" id="referralFormHeading">Guidance General Referral Form</h2>
              <p class="rf-subtitle">Form Code: <?= esc($catalog['form_code'] ?? 'F-OGC-GRE-GGR-005-00') ?> · AES-256-GCM Encrypted</p>
            </div>
            <span class="rf-confidential">
              <i class="bi bi-lock-fill" aria-hidden="true"></i>Confidential
            </span>
          </div>

          <?php if (!$canonicalFormsOpen): ?>
            <div class="rf-gate-notice" role="status">
              <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
              <div>
                <strong>Activation Gate Pending (<code>canonical_forms_signoff</code>):</strong>
                Live General Referral submission is currently disabled until Guidance Head, QA Office, and DPO complete canonical form sign-off. You may still resolve a subject and save a referral <strong>as a draft</strong>.
              </div>
            </div>
          <?php endif; ?>
        </div>

        <form id="portalReferralForm"
              action="<?= esc(site_url('/portal/referrals/store'), 'attr') ?>"
              method="POST"
              data-background-submit>
          <?= csrf_field() ?>

          <!-- Step 1: Referral Type & Exact Student MIS ID Lookup -->
          <div class="rf-band">
            <label class="rf-step-label" for="referralSourceType">
              <span class="rf-step-num">1.</span>Referral Type &amp; Student Lookup
            </label>
            <select name="source_type" id="referralSourceType" class="form-select rf-select" required>
              <option value="employee_teacher">Faculty / Teacher Referral</option>
              <option value="staff_on_behalf">Staff Referral on Behalf of Students</option>
            </select>

            <div id="misLookupPanel" class="rf-lookup-panel">
              <label for="exactMisIdInput" class="rf-sub-label">Exact Student MIS ID</label>
              <div class="rf-lookup-row">
                <input type="text"
                       id="exactMisIdInput"
                       class="form-control rf-input"
                       maxlength="64"
                       placeholder="Enter exact student MIS ID (e.g. 2024-00123)"
                       autocomplete="off">
                <button type="button"
                        id="resolveSubjectBtn"
                        class="btn btn-outline-primary rf-btn"
                        data-resolve-url="<?= esc(site_url('/portal/referrals/resolve-student'), 'attr') ?>">
                  <i class="bi bi-person-plus" aria-hidden="true"></i>Add Student
                </button>
              </div>
              <p class="rf-field-note">
                Add up to <?= \App\Services\Guidance\GuidanceIntakeService::MAX_PORTAL_STUDENTS ?> students by exact MIS ID. Directory browsing and wildcard queries (<code>%</code>, <code>*</code>, <code>?</code>) remain disabled for privacy.
              </p>
              <div id="subjectResolveStatus" class="rf-lookup-status" role="status" aria-live="polite"></div>

              <div class="rf-roster" id="selectedStudentsPanel">
                <div class="rf-roster-head">
                  <span class="rf-roster-title">Selected Students</span>
                  <span class="rf-roster-count" id="selectedStudentsCount">0 selected</span>
                </div>
                <div id="selectedStudentsEmpty" class="rf-roster-empty">No students selected yet.</div>
                <div id="selectedStudentsList" class="rf-roster-list" aria-live="polite"></div>
              </div>
            </div>
          </div>

          <!-- Step 2: Reason for Referral -->
          <div class="rf-band">
            <div class="rf-step-label">
              <span class="rf-step-num">2.</span>Reason for Referral <span class="rf-step-opt">Select all that apply</span>
            </div>
            <p class="rf-step-help">
              The selected reasons, incident description, priority, and remarks below will be copied into a separate confidential referral for every selected student.
            </p>

            <div class="rf-concern-grid">
              <?php foreach ($catalogItems as $item): ?>
                <?php $code = (string) ($item['code'] ?? ''); ?>
                <label class="rf-concern-item">
                  <input class="form-check-input" type="checkbox" name="reasons[]" value="<?= esc($code, 'attr') ?>">
                  <span class="rf-concern-copy">
                    <span class="rf-concern-label"><?= esc($item['label'] ?? $code) ?></span>
                    <?php if (!empty($item['description'])): ?>
                      <span class="rf-concern-desc"><?= esc($item['description']) ?></span>
                    <?php endif; ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>

            <div id="reasonsOtherWrap" class="rf-other-wrap d-none">
              <label for="reasonsOtherSpecify" class="rf-sub-label">Others (Please specify)</label>
              <input type="text" id="reasonsOtherSpecify" name="reasons_other_specify" class="form-control rf-input" maxlength="500" placeholder="Please specify other reason for referral" disabled>
            </div>
          </div>

          <!-- Step 3: Priority Attention Hint -->
          <div class="rf-band">
            <label for="priorityHint" class="rf-step-label">
              <span class="rf-step-num">3.</span>Referrer Priority Attention Hint
            </label>
            <select name="priority_hint" id="priorityHint" class="form-select rf-select" required>
              <option value="routine">Routine — Standard intake scheduling</option>
              <option value="urgent">Urgent — Prompt counselor review requested</option>
              <option value="crisis">Crisis — Immediate safety / acute distress attention</option>
            </select>
            <p class="rf-field-note">
              This is an attention hint for Guidance intake triage; a qualified Guidance practitioner determines the authoritative clinical risk level. For immediate danger to life, contact Campus Security / Clinic Emergency directly.
            </p>
          </div>

          <!-- Steps 4 & 5: Description of Behavior / Incident Observed & Remarks -->
          <div class="rf-band">
            <div class="rf-field-group">
              <label for="reasonsNarrative" class="rf-step-label">
                <span class="rf-step-num">4.</span>Description of Behavior / Incident Observed<span class="rf-step-req" aria-hidden="true">*</span>
              </label>
              <textarea name="reasons_narrative"
                        id="reasonsNarrative"
                        rows="5"
                        class="form-control rf-textarea"
                        maxlength="4096"
                        required
                        placeholder="Describe specific behaviors or incidents observed. You may submit directly to the Guidance Center; encrypted prior to storage."></textarea>
            </div>

            <div class="rf-field-group">
              <label for="priorActionsTaken" class="rf-step-label">
                <span class="rf-step-num">5.</span>Remarks <span class="rf-step-opt">Optional (prior context / initial actions)</span>
              </label>
              <textarea name="prior_actions_taken"
                        id="priorActionsTaken"
                        rows="3"
                        class="form-control rf-textarea"
                        maxlength="4096"
                        placeholder="Additional remarks or initial teacher/adviser actions taken"></textarea>
            </div>
          </div>

          <!-- Form Action Footer -->
          <div class="rf-form-footer">
            <span class="rf-footer-note">
              <i class="bi bi-shield-lock" aria-hidden="true"></i>Encrypted prior to storage
            </span>
            <div class="rf-footer-actions">
              <button type="submit" name="action" value="draft" class="btn btn-outline-secondary rf-btn">
                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Save as Draft
              </button>
              <button type="submit"
                      name="action"
                      value="submit"
                      class="btn btn-primary rf-btn"
                      <?= !$canonicalFormsOpen ? 'disabled title="Disabled until canonical_forms_signoff gate is enabled"' : '' ?>>
                <i class="bi bi-send-check" aria-hidden="true"></i>Submit Live Referral
              </button>
            </div>
          </div>
        </form>
      </section>

      <!-- My Submitted & Draft Referrals Sheet -->
      <aside class="rf-sheet" aria-labelledby="myReferralsHeading">
        <div class="rf-history-head">
          <h2 class="rf-title" id="myReferralsHeading">My Submitted &amp; Draft Referrals</h2>
          <?php if ($myReferrals !== []): ?>
            <span class="rf-history-count"><?= count($myReferrals) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($myReferrals === []): ?>
          <div class="rf-history-empty">
            You have not created any Guidance referrals yet.
          </div>
        <?php else: ?>
          <div class="rf-history-list">
            <?php foreach ($myReferrals as $ref): ?>
              <div class="rf-history-row">
                <div class="rf-history-top">
                  <span class="rf-history-num"><?= esc($ref['referral_number']) ?></span>
                  <span class="rf-history-status <?= $statusTone((string) $ref['status']) ?>">
                    <?= esc(str_replace('_', ' ', (string) $ref['status'])) ?>
                  </span>
                </div>
                <div class="rf-history-subject">
                  <?= esc(trim(($ref['last_name'] ?? '') . ', ' . ($ref['first_name'] ?? ''))) ?>
                  <span>(<?= esc($ref['mis_id'] ?? '—') ?>)</span>
                </div>
                <div class="rf-history-bottom">
                  <span class="rf-history-hint">Hint: <?= esc($ref['priority_hint']) ?></span>
                  <div class="rf-history-actions">
                    <?php if ($ref['status'] === 'draft' && $canonicalFormsOpen): ?>
                      <form action="<?= esc(site_url('/portal/referrals/store'), 'attr') ?>" method="POST" data-background-submit class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="referral_id" value="<?= (int) $ref['id'] ?>">
                        <input type="hidden" name="action" value="submit">
                        <button type="submit" class="btn btn-outline-primary rf-btn-xs">Submit</button>
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
                            class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="confirmed" value="1">
                        <button type="submit" class="btn btn-outline-danger rf-btn-xs">Withdraw</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>
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
        row.className = 'rf-student-row';

        const copy = document.createElement('div');
        copy.className = 'rf-student-copy';
        const name = document.createElement('strong');
        name.className = 'rf-student-name';
        name.textContent = `${person.last_name}, ${person.first_name}`;
        const meta = document.createElement('span');
        meta.className = 'rf-student-meta';
        meta.textContent = [`MIS ID: ${person.mis_id}`, person.program || null, person.year_level ? `Year ${person.year_level}` : null].filter(Boolean).join(' · ');
        copy.append(name, meta);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'subject_person_ids[]';
        hidden.value = String(person.person_id || person.id);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-outline-danger rf-btn-xs';
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
          statusBox.className = 'rf-lookup-status text-danger';
          statusBox.textContent = 'Enter an exact student MIS ID before adding.';
        }
        return;
      }
      if (selected.size >= MAX_SELECTED_STUDENTS) {
        if (statusBox) {
          statusBox.className = 'rf-lookup-status text-danger';
          statusBox.textContent = `You may select up to ${MAX_SELECTED_STUDENTS} students per form.`;
        }
        return;
      }

      resolveBtn.disabled = true;
      if (statusBox) {
        statusBox.className = 'rf-lookup-status text-muted';
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
          statusBox.className = 'rf-lookup-status text-success';
          statusBox.textContent = `${person.first_name} ${person.last_name} added.`;
        }
        misInput.focus();
      } catch (err) {
        if (statusBox) {
          statusBox.className = 'rf-lookup-status text-danger';
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
        statusBox.className = 'rf-lookup-status text-danger';
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
