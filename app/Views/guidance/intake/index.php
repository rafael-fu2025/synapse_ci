<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#staffWalkinReferralCard" aria-expanded="false" aria-controls="staffWalkinReferralCard">
    <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Record Walk-In / Teacher Referral
  </button>
  <a href="<?= esc(site_url('/guidance/governance/gates')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-shield-check me-1" aria-hidden="true"></i> Activation Gates
  </a>
  <a href="<?= esc(site_url('/guidance/cases')) ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-folder2-open me-1" aria-hidden="true"></i> Counseling Cases
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

$priorityBadge = static fn (string $hint): string => match ($hint) {
    'crisis' => 'bg-danger text-white',
    'urgent' => 'bg-warning text-dark',
    default  => 'bg-secondary-subtle text-secondary border',
};

$statusBadge = static fn (string $status): string => match ($status) {
    'submitted'                => 'bg-primary-subtle text-primary border-primary-subtle',
    'received'                 => 'bg-info-subtle text-info border-info-subtle',
    'triaged'                  => 'bg-warning-subtle text-warning border-warning-subtle',
    'accepted', 'case_linked'  => 'bg-success-subtle text-success border-success-subtle',
    'declined', 'withdrawn'    => 'bg-danger-subtle text-danger border-danger-subtle',
    'external_handoff'         => 'bg-dark-subtle text-body border',
    default                    => 'bg-secondary-subtle text-secondary border',
};
?>

<div class="guidance-intake-queue"
     data-refresh-owner="guidance.intake.queue"
     data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <!-- Walk-in / Teacher-Accompanied Referral Drawer -->
    <div class="collapse mb-3" id="staffWalkinReferralCard">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
          <div>
            <h2 class="h6 fw-bold mb-0"><i class="bi bi-file-earmark-plus text-primary me-1" aria-hidden="true"></i>Walk-In / Teacher-Accompanied General Referral (F-OGC-GGR-005)</h2>
            <small class="text-muted">Record a walk-in or faculty-accompanied referral directly at Guidance Intake.</small>
          </div>
          <?php if (empty($canonicalGateEnabled)): ?>
            <span class="badge bg-warning text-dark">Canonical Sign-off Pending · Draft Mode</span>
          <?php endif; ?>
        </div>
        <div class="card-body p-3 p-lg-4">
          <form action="<?= esc(site_url('/guidance/intake/staff-create'), 'attr') ?>" method="POST" data-background-submit>
            <?= csrf_field() ?>
            <input type="hidden" id="intakeWalkinPersonId" name="subject_person_id" value="">
            <div class="row g-3 mb-3">
              <div class="col-md-5">
                <label class="form-label small fw-semibold" for="intakeWalkinMisId">Student / Employee Exact MIS ID</label>
                <div class="input-group input-group-sm">
                  <input type="text" id="intakeWalkinMisId" name="mis_id" class="form-control" placeholder="e.g. 2024-0001" required>
                  <button type="button" class="btn btn-outline-primary" id="intakeWalkinResolveBtn">Resolve ID</button>
                </div>
                <div id="intakeWalkinResolved" class="small text-muted mt-1">No subject resolved yet.</div>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-semibold" for="intakeWalkinSource">Source Type</label>
                <select id="intakeWalkinSource" name="source_type" class="form-select form-select-sm" required>
                  <option value="staff_on_behalf" selected>Walk-In / Guidance Staff on Behalf</option>
                  <option value="employee_teacher">Teacher / Faculty Accompanied</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold" for="intakeWalkinPriority">Priority Attention Hint</label>
                <select id="intakeWalkinPriority" name="priority_hint" class="form-select form-select-sm" required>
                  <option value="routine" selected>Routine</option>
                  <option value="urgent">Urgent</option>
                  <option value="crisis">Crisis / Immediate Safety</option>
                </select>
              </div>
            </div>

            <div class="mb-3">
              <div class="form-label small fw-semibold">Presenting Concerns (Select at least one)</div>
              <div class="row g-2">
                <?php foreach (($concernCatalog['checklist'] ?? []) as $code => $label): ?>
                  <div class="col-md-4 col-lg-3">
                    <label class="form-check small">
                      <input type="checkbox" class="form-check-input" name="reasons[]" value="<?= esc($code) ?>">
                      <span class="form-check-label"><?= esc($label) ?></span>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <label class="form-label small fw-semibold" for="intakeWalkinOther">If Others, Specify (Optional)</label>
                <input type="text" id="intakeWalkinOther" name="reasons_other_specify" maxlength="500" class="form-control form-control-sm">
              </div>
              <div class="col-md-8">
                <label class="form-label small fw-semibold" for="intakeWalkinPrior">Prior Teacher / Adviser Actions (Optional)</label>
                <input type="text" id="intakeWalkinPrior" name="prior_actions_taken" maxlength="500" class="form-control form-control-sm" placeholder="Initial talks, parent contact, or academic intervention taken">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label small fw-semibold" for="intakeWalkinNarrative">Observed / Reported Reason for Referral</label>
              <textarea id="intakeWalkinNarrative" name="reasons_narrative" rows="3" maxlength="2000" class="form-control form-control-sm" required placeholder="Specific behaviors, concerns, or walk-in reason..."></textarea>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-end">
              <button type="submit" name="action" value="draft" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-save me-1"></i>Save Walk-In Draft
              </button>
              <button type="submit" name="action" value="submit" class="btn btn-primary btn-sm" <?= empty($canonicalGateEnabled) ? 'disabled aria-disabled="true" title="Canonical forms gate pending approval"' : '' ?>>
                <i class="bi bi-send me-1"></i>Submit to Intake Queue
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body py-3">
        <form method="GET" action="<?= esc(site_url('/guidance/intake'), 'attr') ?>" class="row g-2 align-items-end">
          <div class="col-md-4">
            <label for="intakeStatusFilter" class="form-label small fw-semibold mb-1">Status</label>
            <select id="intakeStatusFilter" name="status" class="form-select form-select-sm">
              <option value="">All Statuses</option>
              <?php foreach ($statuses as $st): ?>
                <option value="<?= esc($st, 'attr') ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
                  <?= esc(ucwords(str_replace('_', ' ', $st))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label for="intakePriorityFilter" class="form-label small fw-semibold mb-1">Referrer Priority Hint</label>
            <select id="intakePriorityFilter" name="priority_hint" class="form-select form-select-sm">
              <option value="">All Hints</option>
              <?php foreach ($priorityHints as $hint): ?>
                <option value="<?= esc($hint, 'attr') ?>" <?= $priorityFilter === $hint ? 'selected' : '' ?>>
                  <?= esc(ucfirst($hint)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="<?= esc(site_url('/guidance/intake')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
          </div>
        </form>
      </div>
    </div>

    <!-- Intake Referrals Table -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
        <div>
          <h2 class="h6 fw-bold mb-0">Guidance Intake Referrals</h2>
          <small class="text-muted">Confidential narratives are encrypted at rest and decrypted only inside the individual referral workspace.</small>
        </div>
        <span class="badge bg-primary-subtle text-primary border"><?= count($referrals) ?> Records</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase text-muted">
              <tr>
                <th class="ps-3">Referral #</th>
                <th>Subject</th>
                <th>Source / Referrer</th>
                <th>Attention Hint</th>
                <th>Status</th>
                <th>Assigned / Case</th>
                <th>Submitted</th>
                <th class="text-end pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($referrals === []): ?>
                <tr>
                  <td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50" aria-hidden="true"></i>
                    No intake referrals match the current filters.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($referrals as $row): ?>
                  <tr>
                    <td class="ps-3 fw-bold">
                      <a href="<?= esc(site_url('/guidance/intake/' . (int) $row['id'])) ?>" class="text-decoration-none">
                        <?= esc($row['referral_number']) ?>
                      </a>
                    </td>
                    <td>
                      <div class="fw-semibold">
                        <?= esc(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? ''))) ?>
                      </div>
                      <small class="text-muted">ID: <?= esc($row['mis_id'] ?? '—') ?> · <?= esc(strtoupper((string) ($row['kind'] ?? 'student'))) ?></small>
                    </td>
                    <td class="small">
                      <div class="fw-semibold"><?= esc($row['referrer_name_snapshot']) ?></div>
                      <div class="text-muted"><?= esc(ucwords(str_replace('_', ' ', (string) $row['source_type']))) ?> · <?= esc($row['referrer_department_snapshot']) ?></div>
                    </td>
                    <td>
                      <span class="badge text-capitalize <?= $priorityBadge((string) $row['priority_hint']) ?>">
                        <?= esc($row['priority_hint']) ?>
                      </span>
                    </td>
                    <td>
                      <span class="badge border text-capitalize <?= $statusBadge((string) $row['status']) ?>">
                        <?= esc(str_replace('_', ' ', (string) $row['status'])) ?>
                      </span>
                    </td>
                    <td class="small">
                      <?php if (!empty($row['assigned_counsellor_username'])): ?>
                        <div><i class="bi bi-person me-1" aria-hidden="true"></i><?= esc($row['assigned_counsellor_username']) ?></div>
                      <?php else: ?>
                        <span class="text-muted">Unassigned</span>
                      <?php endif; ?>
                      <?php if (!empty($row['linked_case_id'])): ?>
                        <a href="<?= esc(site_url('/guidance/cases/' . (int) $row['linked_case_id'])) ?>" class="small text-decoration-none">
                          Case #<?= (int) $row['linked_case_id'] ?>
                        </a>
                      <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                      <?= !empty($row['submitted_at']) ? esc(date('M j, Y g:i A', strtotime((string) $row['submitted_at']))) : esc(date('M j, Y', strtotime((string) $row['created_at']))) ?>
                    </td>
                    <td class="text-end pe-3">
                      <div class="d-inline-flex gap-1">
                        <?php if (!empty($row['subject_person_id'])): ?>
                          <a href="<?= esc(site_url('/guidance/students/' . (int) $row['subject_person_id'] . '/cumulative')) ?>" class="btn btn-outline-secondary btn-sm" title="Student Cumulative Guidance Record">
                            <i class="bi bi-person-lines-fill"></i>
                          </a>
                        <?php endif; ?>
                        <a href="<?= esc(site_url('/guidance/intake/' . (int) $row['id'])) ?>" class="btn btn-outline-primary btn-sm">
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
      </div>
    </div>

    <!-- Incoming Bridged Clinic Referrals -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
        <div>
          <h2 class="h6 fw-bold mb-0"><i class="bi bi-hospital me-1" aria-hidden="true"></i> Incoming University Clinic Referrals</h2>
          <small class="text-muted">Bridge Clinic-to-Guidance referrals into the Guidance Intake &amp; Safety Triage workflow without altering Clinic records.</small>
        </div>
        <span class="badge bg-info-subtle text-info border"><?= count($clinicReferrals) ?> Clinic Referrals</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase text-muted">
              <tr>
                <th class="ps-3">Clinic Ref #</th>
                <th>Patient</th>
                <th>Reason Code</th>
                <th>Priority</th>
                <th>Issued By</th>
                <th>Intake Bridge</th>
                <th class="text-end pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($clinicReferrals === []): ?>
                <tr>
                  <td colspan="7" class="text-center py-4 text-muted">
                    No pending Clinic referrals targeting Guidance.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($clinicReferrals as $cref): ?>
                  <tr>
                    <td class="ps-3 fw-semibold"><?= esc($cref['referral_code']) ?></td>
                    <td>
                      <div class="fw-semibold"><?= esc(trim(($cref['last_name'] ?? '') . ', ' . ($cref['first_name'] ?? ''))) ?></div>
                      <small class="text-muted">ID: <?= esc($cref['mis_id'] ?? '—') ?></small>
                    </td>
                    <td class="small"><?= esc(ucwords(str_replace('_', ' ', (string) $cref['reason_code']))) ?></td>
                    <td>
                      <span class="badge text-capitalize <?= $priorityBadge((string) $cref['priority']) ?>">
                        <?= esc($cref['priority']) ?>
                      </span>
                    </td>
                    <td class="small text-muted"><?= esc($cref['issuer_name'] ?? 'Clinic') ?></td>
                    <td class="small">
                      <?php if (!empty($cref['bridged_intake_id'])): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                          Bridged: <?= esc($cref['bridged_referral_number']) ?>
                        </span>
                      <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Not Bridged</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                      <?php if (!empty($cref['bridged_intake_id'])): ?>
                        <a href="<?= esc(site_url('/guidance/intake/' . (int) $cref['bridged_intake_id'])) ?>" class="btn btn-outline-primary btn-sm">
                          Open Intake
                        </a>
                      <?php else: ?>
                        <form action="<?= esc(site_url('/guidance/intake/bridge-clinic/' . (int) $cref['id']), 'attr') ?>" method="POST" data-background-submit class="d-inline">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i> Bridge to Intake
                          </button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('intakeWalkinResolveBtn');
    var input = document.getElementById('intakeWalkinMisId');
    var hidden = document.getElementById('intakeWalkinPersonId');
    var status = document.getElementById('intakeWalkinResolved');
    if (!btn || !input || !hidden || !status) return;

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
      fetch(<?= json_encode(site_url('/portal/referrals/resolve-subject')) ?>, {
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
        var cumulativeUrl = <?= json_encode(site_url('/guidance/students/')) ?> + p.person_id + '/cumulative';
        status.innerHTML = '<strong>' + (p.first_name || '') + ' ' + (p.last_name || '') + '</strong> (' + (p.kind || 'person') + ') · <a href="' + cumulativeUrl + '" target="_blank" rel="noopener">Cumulative Record</a>';
      })
      .catch(function () {
        status.textContent = 'Unable to resolve subject right now.';
      });
    });
  });
</script>
<?= $this->endSection() ?>
