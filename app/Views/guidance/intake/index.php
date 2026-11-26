<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <button type="button"
          class="btn btn-primary btn-sm"
          data-bs-toggle="collapse"
          data-bs-target="#staffWalkinReferralCard"
          aria-expanded="false"
          aria-controls="staffWalkinReferralCard">
    <i class="bi bi-person-plus" aria-hidden="true"></i> Record Walk-In / Teacher Referral
  </button>
  <a href="<?= esc(site_url('/guidance/governance/gates')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-shield-check" aria-hidden="true"></i> Activation Gates
  </a>
  <a href="<?= esc(site_url('/guidance/cases')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-folder2-open" aria-hidden="true"></i> Counseling Cases
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$referrals       = $referrals ?? [];
$clinicReferrals = $clinicReferrals ?? [];
$statuses        = $statuses ?? [];
$priorityHints   = $priorityHints ?? ['routine', 'urgent', 'crisis'];
$statusFilter    = $statusFilter ?? '';
$priorityFilter  = $priorityFilter ?? '';
$intakePagination = $intakePagination ?? \App\Libraries\TablePage::forList([])->metadata(count($referrals), count($referrals));
$incomingPagination = $incomingPagination ?? \App\Libraries\TablePage::forList([])->metadata(count($clinicReferrals), count($clinicReferrals));
$perPage = (int) $intakePagination['per_page'];
$paginationQuery = ['status' => $statusFilter, 'priority_hint' => $priorityFilter,
    'intake_page' => (int) $intakePagination['page'], 'incoming_page' => (int) $incomingPagination['page']];

$priorityDot = static fn (string $hint): string => match ($hint) {
    'crisis' => 'ce-status-crimson',
    'urgent' => 'ce-status-amber',
    default  => 'ce-status-slate',
};

$statusDot = static fn (string $status): string => match ($status) {
    'submitted', 'received'   => 'ce-status-sky',
    'triaged'                 => 'ce-status-amber',
    'accepted', 'case_linked' => 'ce-status-emerald',
    'declined', 'withdrawn'   => 'ce-status-crimson',
    default                   => 'ce-status-slate',
};
?>

<section class="guidance-ui guidance-intake-queue ce-workspace"
         data-guidance-registry="intake"
         data-live-filters
         data-filter-key="guidance-intake"
         data-filter-url="<?= esc(site_url('guidance/intake'), 'attr') ?>"
         data-filter-page-params="intake_page incoming_page"
         data-refresh-owner="guidance.intake.queue"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <!-- Filter controls stay outside the replaceable workspace to preserve live and dirty-form state. -->
  <div class="ce-toolbar-card">
    <form method="GET" action="<?= esc(site_url('/guidance/intake'), 'attr') ?>" class="ce-intake-filter-form" data-filter-form>
      <input type="hidden" name="intake_page" value="<?= (int) $intakePagination['page'] ?>">
      <input type="hidden" name="incoming_page" value="<?= (int) $incomingPagination['page'] ?>">
      <div>
        <label for="intakeStatusFilter" class="visually-hidden">Filter by status</label>
        <select id="intakeStatusFilter" name="status" class="form-select form-select-sm w-100" aria-label="Filter by status" onchange="this.form.elements.intake_page.value='1'">
          <option value="">All Statuses</option>
          <?php foreach ($statuses as $st): ?>
            <option value="<?= esc($st, 'attr') ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
              <?= esc(ucwords(str_replace('_', ' ', $st))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="intakePriorityFilter" class="visually-hidden">Filter by referrer priority hint</label>
        <select id="intakePriorityFilter" name="priority_hint" class="form-select form-select-sm w-100" aria-label="Filter by referrer priority hint" onchange="this.form.elements.intake_page.value='1'">
          <option value="">All Priority Hints</option>
          <?php foreach ($priorityHints as $hint): ?>
            <option value="<?= esc($hint, 'attr') ?>" <?= $priorityFilter === $hint ? 'selected' : '' ?>>
              <?= esc(ucfirst($hint)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="intakePerPage" class="visually-hidden">Rows per page</label>
        <select id="intakePerPage" name="per_page" class="form-select form-select-sm w-100" aria-label="Rows per page" onchange="this.form.elements.intake_page.value='1';this.form.elements.incoming_page.value='1'">
          <?php foreach ([20, 25, 50, 100] as $size): ?>
            <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> rows per page</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ce-registry-filter-actions">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-funnel" aria-hidden="true"></i> Filter
        </button>
        <a href="<?= esc(site_url('/guidance/intake') . '?' . http_build_query(['per_page' => $perPage])) ?>" class="btn btn-outline-secondary btn-sm" data-filter-reset>Reset</a>
      </div>
    </form>
  </div>
  <p class="visually-hidden" data-filter-status role="status" aria-live="polite">Showing <?= (int) $intakePagination['filtered'] ?> intake referrals and <?= (int) $incomingPagination['total'] ?> incoming Clinic referrals.</p>
  <div class="alert ce-notice ce-notice-crimson" data-banner-dismiss="off" data-filter-error role="alert" hidden>
    <div class="ce-notice-body">
      <i class="bi bi-exclamation-octagon ce-notice-icon" aria-hidden="true"></i>
      <span data-filter-error-text></span>
    </div>
    <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" data-filter-retry>Retry</button>
  </div>
  <div class="d-flex flex-column gap-3" data-filter-region="workspace" data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <div class="ce-notice ce-notice-sky">
      <div class="ce-notice-body">
        <i class="bi bi-shield-lock ce-notice-icon" aria-hidden="true"></i>
        <span>
          <strong>Guidance Intake &amp; Safety Triage Queue</strong> — Confidential referral narratives are encrypted at rest and decrypted only inside the individual triage workspace. Authoritative practitioner safety assessments remain separate from referrer priority hints.
        </span>
      </div>
    </div>

    <!-- Walk-in / Teacher-Accompanied Referral Drawer -->
    <div class="collapse" id="staffWalkinReferralCard">
      <div class="ce-card">
        <div class="ce-card-header">
          <div>
            <h2 class="ce-card-title">
              <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Walk-In / Teacher-Accompanied General Referral (F-OGC-GGR-005)
            </h2>
            <div class="small text-muted mt-1">Record a walk-in or faculty-accompanied referral directly at Guidance Intake.</div>
          </div>
          <?php if (empty($canonicalGateEnabled)): ?>
            <span class="ce-tag">
              <span class="ce-status-dot ce-status-amber">Canonical Sign-off Pending · Draft Mode</span>
            </span>
          <?php endif; ?>
        </div>
        <div class="ce-card-body">
          <form action="<?= esc(site_url('/guidance/intake/staff-create'), 'attr') ?>" method="POST" data-background-submit>
            <?= csrf_field() ?>
            <input type="hidden" id="intakeWalkinPersonId" name="subject_person_id" value="">
            <div class="row g-3 mb-3">
              <div class="col-md-5">
                <label class="form-label" for="intakeWalkinMisId">Student / Employee Exact MIS ID</label>
                <div class="d-flex gap-2">
                  <input type="text" id="intakeWalkinMisId" name="mis_id" class="form-control form-control-sm" placeholder="e.g. 2024-0001" required>
                  <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0" id="intakeWalkinResolveBtn">Resolve ID</button>
                </div>
                <div id="intakeWalkinResolved" class="form-text mt-1">No subject resolved yet.</div>
              </div>
              <div class="col-md-3">
                <label class="form-label" for="intakeWalkinSource">Source Type</label>
                <select id="intakeWalkinSource" name="source_type" class="form-select form-select-sm" required>
                  <option value="staff_on_behalf" selected>Walk-In / Guidance Staff on Behalf</option>
                  <option value="employee_teacher">Teacher / Faculty Accompanied</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="intakeWalkinPriority">Priority Attention Hint</label>
                <select id="intakeWalkinPriority" name="priority_hint" class="form-select form-select-sm" required>
                  <option value="routine" selected>Routine</option>
                  <option value="urgent">Urgent</option>
                  <option value="crisis">Crisis / Immediate Safety</option>
                </select>
              </div>
            </div>

            <div class="mb-3">
              <div class="form-label">Reason for Referral (Select at least one)</div>
              <div class="gi-concern-grid">
                <?php foreach (($concernCatalog['checklist'] ?? []) as $code => $label): ?>
                  <label class="gi-concern-tile">
                    <input type="checkbox" class="form-check-input" name="reasons[]" value="<?= esc($code) ?>">
                    <span><?= esc($label) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="row g-3 mb-3">
              <div id="intakeWalkinOtherWrap" class="col-md-4 d-none">
                <label class="form-label" for="intakeWalkinOther">Others (Please specify)</label>
                <input type="text" id="intakeWalkinOther" name="reasons_other_specify" maxlength="500" class="form-control form-control-sm" disabled>
              </div>
              <div class="col-12 col-md">
                <label class="form-label" for="intakeWalkinPrior">Remarks (Optional)</label>
                <input type="text" id="intakeWalkinPrior" name="prior_actions_taken" maxlength="500" class="form-control form-control-sm" placeholder="Additional remarks or initial teacher/adviser actions">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="intakeWalkinNarrative">Description of Behavior / Incident Observed</label>
              <textarea id="intakeWalkinNarrative" name="reasons_narrative" rows="3" maxlength="2000" class="form-control form-control-sm" required placeholder="Specific behaviors or incidents observed..."></textarea>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-end">
              <button type="submit" name="action" value="draft" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-save" aria-hidden="true"></i> Save Walk-In Draft
              </button>
              <button type="submit" name="action" value="submit" class="btn btn-primary btn-sm" <?= empty($canonicalGateEnabled) ? 'disabled aria-disabled="true" title="Canonical forms gate pending approval"' : '' ?>>
                <i class="bi bi-send" aria-hidden="true"></i> Submit to Intake Queue
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Intake Referrals Table -->
    <div class="ce-card">
      <div class="ce-card-header">
        <h2 class="ce-card-title">
          <i class="bi bi-inbox-fill" aria-hidden="true"></i> Guidance Intake Referrals
        </h2>
        <span class="ce-tag ce-mono"><?= (int) $intakePagination['filtered'] ?> records</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle ce-table ce-table-mobile gi-intake-table" data-mobile-cards="false">
          <thead>
            <tr>
              <th>Referral #</th>
              <th>Subject</th>
              <th>Source / Referrer</th>
              <th>Attention Hint</th>
              <th>Status</th>
              <th>Assigned / Case</th>
              <th>Submitted</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($referrals === []): ?>
              <tr>
                <td colspan="8" class="ce-empty-row">
                  <i class="bi bi-inbox" aria-hidden="true"></i>
                  No intake referrals match the current filters.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($referrals as $row): ?>
                <tr>
                  <td data-label="Referral #">
                    <a href="<?= esc(site_url('/guidance/intake/' . (int) $row['id'])) ?>" class="ce-patient-link ce-mono">
                      <?= esc($row['referral_number']) ?>
                    </a>
                  </td>
                  <td data-label="Subject">
                    <div class="d-flex flex-wrap align-items-center gap-1">
                      <span class="fw-semibold"><?= esc(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? ''))) ?></span>
                      <span class="ce-tag ce-tag-upper"><?= esc((string) ($row['kind'] ?? 'student')) ?></span>
                    </div>
                    <div class="ce-patient-sub">
                      <span class="ce-mono">ID: <?= esc($row['mis_id'] ?? '—') ?></span>
                    </div>
                  </td>
                  <td data-label="Source / Referrer">
                    <div class="fw-semibold"><?= esc($row['referrer_name_snapshot']) ?></div>
                    <div class="ce-patient-sub">
                      <?= esc(ucwords(str_replace('_', ' ', (string) $row['source_type']))) ?> · <?= esc($row['referrer_department_snapshot']) ?>
                    </div>
                  </td>
                  <td data-label="Attention Hint">
                    <span class="ce-status-dot <?= $priorityDot((string) $row['priority_hint']) ?> text-capitalize">
                      <?= esc($row['priority_hint']) ?>
                    </span>
                  </td>
                  <td data-label="Status">
                    <span class="ce-status-dot <?= $statusDot((string) $row['status']) ?> text-capitalize">
                      <?= esc(str_replace('_', ' ', (string) $row['status'])) ?>
                    </span>
                  </td>
                  <td data-label="Assigned / Case">
                    <?php if (!empty($row['assigned_counsellor_username'])): ?>
                      <span class="ce-staff-cell">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <?= esc($row['assigned_counsellor_username']) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-muted small">Unassigned</span>
                    <?php endif; ?>
                    <?php if (!empty($row['linked_case_id'])): ?>
                      <div>
                        <a href="<?= esc(site_url('/guidance/cases/' . (int) $row['linked_case_id'])) ?>" class="ce-patient-link ce-mono small">
                          Case #<?= (int) $row['linked_case_id'] ?>
                        </a>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td data-label="Submitted">
                    <div class="ce-date-primary ce-mono">
                      <?= !empty($row['submitted_at']) ? esc(date('M j, Y', strtotime((string) $row['submitted_at']))) : esc(date('M j, Y', strtotime((string) $row['created_at']))) ?>
                    </div>
                    <?php if (!empty($row['submitted_at'])): ?>
                      <div class="ce-date-sub ce-mono"><?= esc(date('g:i A', strtotime((string) $row['submitted_at']))) ?></div>
                    <?php endif; ?>
                  </td>
                  <td data-label="Action" class="text-end">
                    <div class="d-flex flex-nowrap justify-content-md-end gap-2 gc-card-actions">
                      <?php if (!empty($row['subject_person_id'])): ?>
                        <a href="<?= esc(site_url('/guidance/students/' . (int) $row['subject_person_id'] . '/cumulative')) ?>"
                           class="btn btn-outline-secondary btn-sm"
                           title="Student Cumulative Guidance Record">
                          <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
                        </a>
                      <?php endif; ?>
                      <a href="<?= esc(site_url('/guidance/intake/' . (int) $row['id'])) ?>" class="btn btn-outline-primary btn-sm ce-action-btn">
                        Open Triage
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?= view('partials/table_pagination', [
          'pagination' => $intakePagination,
          'paginationPageParam' => 'intake_page',
          'paginationExtraQuery' => $paginationQuery,
          'baseUrl' => site_url('guidance/intake'),
          'paginationLabel' => 'Guidance intake referral pages',
          'paginationLiveFilters' => true,
          'paginationCardFooter' => true,
      ]) ?>
    </div>

    <!-- Incoming Bridged Clinic Referrals -->
    <div class="ce-card">
      <div class="ce-card-header">
        <div>
          <h2 class="ce-card-title">
            <i class="bi bi-hospital" aria-hidden="true"></i> Incoming University Clinic Referrals
          </h2>
          <div class="small text-muted mt-1">Bridge Clinic-to-Guidance referrals into the Guidance Intake &amp; Safety Triage workflow without altering Clinic records.</div>
        </div>
        <span class="ce-tag ce-mono"><?= (int) $incomingPagination['total'] ?> clinic referrals</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle ce-table ce-table-mobile gi-clinic-bridge-table" data-mobile-cards="false">
          <thead>
            <tr>
              <th>Clinic Ref #</th>
              <th>Patient</th>
              <th>Reason Code</th>
              <th>Priority</th>
              <th>Issued By</th>
              <th>Intake Bridge</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($clinicReferrals === []): ?>
              <tr>
                <td colspan="7" class="ce-empty-row">
                  <i class="bi bi-hospital" aria-hidden="true"></i>
                  No pending Clinic referrals targeting Guidance.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($clinicReferrals as $cref): ?>
                <tr>
                  <td data-label="Clinic Ref #">
                    <span class="ce-mono fw-bold"><?= esc($cref['referral_code']) ?></span>
                  </td>
                  <td data-label="Patient">
                    <div class="fw-semibold"><?= esc(trim(($cref['last_name'] ?? '') . ', ' . ($cref['first_name'] ?? ''))) ?></div>
                    <div class="ce-patient-sub">
                      <span class="ce-mono">ID: <?= esc($cref['mis_id'] ?? '—') ?></span>
                    </div>
                  </td>
                  <td data-label="Reason Code">
                    <span class="ce-tag"><?= esc(ucwords(str_replace('_', ' ', (string) $cref['reason_code']))) ?></span>
                  </td>
                  <td data-label="Priority">
                    <span class="ce-status-dot <?= $priorityDot((string) $cref['priority']) ?> text-capitalize">
                      <?= esc($cref['priority']) ?>
                    </span>
                  </td>
                  <td data-label="Issued By">
                    <span class="ce-staff-cell">
                      <i class="bi bi-person-check" aria-hidden="true"></i>
                      <?= esc($cref['issuer_name'] ?? 'Clinic') ?>
                    </span>
                  </td>
                  <td data-label="Intake Bridge">
                    <?php if (!empty($cref['bridged_intake_id'])): ?>
                      <span class="ce-status-dot ce-status-emerald">
                        Bridged: <?= esc($cref['bridged_referral_number']) ?>
                      </span>
                    <?php else: ?>
                      <span class="ce-status-dot ce-status-amber">Not Bridged</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Action" class="text-end">
                    <div class="d-flex flex-wrap justify-content-md-end gap-2 gc-card-actions">
                      <?php if (!empty($cref['bridged_intake_id'])): ?>
                        <a href="<?= esc(site_url('/guidance/intake/' . (int) $cref['bridged_intake_id'])) ?>" class="btn btn-outline-primary btn-sm ce-action-btn">
                          Open Intake
                        </a>
                      <?php else: ?>
                        <form action="<?= esc(site_url('/guidance/intake/bridge-clinic/' . (int) $cref['id']), 'attr') ?>" method="POST" data-background-submit>
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-primary btn-sm ce-action-btn">
                            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Bridge to Intake
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?= view('partials/table_pagination', [
          'pagination' => $incomingPagination,
          'paginationPageParam' => 'incoming_page',
          'paginationExtraQuery' => $paginationQuery,
          'baseUrl' => site_url('guidance/intake'),
          'paginationLabel' => 'Incoming Clinic referral pages',
          'paginationLiveFilters' => true,
          'paginationCardFooter' => true,
      ]) ?>
    </div>

  </div>
</section>

<script>
  (function () {
    var boundNodes = window.__synapseIntakeBound || (window.__synapseIntakeBound = new WeakSet());

    function initIntakeWalkin() {
      var otherWrap = document.getElementById('intakeWalkinOtherWrap');
      var otherInput = document.getElementById('intakeWalkinOther');
      var walkinForm = otherWrap ? otherWrap.closest('form') : null;
      var othersCheckbox = walkinForm ? walkinForm.querySelector('input[type="checkbox"][name="reasons[]"][value="others"]') : null;
      if (otherWrap && otherInput && othersCheckbox && !boundNodes.has(othersCheckbox)) {
        boundNodes.add(othersCheckbox);
        var syncOther = function (focusOnOpen) {
          var show = othersCheckbox.checked;
          otherWrap.classList.toggle('d-none', !show);
          otherInput.disabled = !show;
          if (show && focusOnOpen) {
            otherInput.focus();
          }
        };
        othersCheckbox.addEventListener('change', function () { syncOther(true); });
        syncOther(false);
      }

      var btn = document.getElementById('intakeWalkinResolveBtn');
      var input = document.getElementById('intakeWalkinMisId');
      var hidden = document.getElementById('intakeWalkinPersonId');
      var status = document.getElementById('intakeWalkinResolved');
      if (!btn || !input || !hidden || !status || boundNodes.has(btn)) return;
      boundNodes.add(btn);

      btn.addEventListener('click', function () {
        var misId = (input.value || '').trim();
        if (!misId) {
          status.textContent = 'Enter an exact MIS ID first.';
          return;
        }
        var csrfInput = btn.closest('form').querySelector('input[type="hidden"][name^="csrf_"]');
        var payload = { mis_id: misId };
        if (csrfInput) { payload[csrfInput.name] = csrfInput.value; }
        status.textContent = 'Resolving...';
        fetch(<?= json_encode(site_url('/guidance/intake/resolve-subject')) ?>, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.csrf && csrfInput) { csrfInput.value = data.csrf.hash; }
          if (!data.success || !data.person) {
            hidden.value = '';
            status.textContent = data.message || 'Subject not found.';
            return;
          }
          var p = data.person;
          hidden.value = p.person_id;
          var cumulativeUrl = <?= json_encode(site_url('/guidance/students/')) ?> + encodeURIComponent(String(p.person_id)) + '/cumulative';
          status.textContent = '';
          var strong = document.createElement('strong');
          strong.textContent = ((p.first_name || '') + ' ' + (p.last_name || '')).trim();
          var link = document.createElement('a');
          link.href = cumulativeUrl;
          link.target = '_blank';
          link.rel = 'noopener';
          link.textContent = 'Cumulative Record';
          status.appendChild(strong);
          status.appendChild(document.createTextNode(' (' + (p.kind || 'person') + ') · '));
          status.appendChild(link);
        })
        .catch(function () {
          status.textContent = 'Unable to resolve subject right now.';
        });
      });
    }

    document.addEventListener('DOMContentLoaded', initIntakeWalkin);
    document.addEventListener('synapse:page-ready', initIntakeWalkin);
    document.addEventListener('synapse:page-load', initIntakeWalkin);
    initIntakeWalkin();
  })();
</script>
<?= $this->endSection() ?>
