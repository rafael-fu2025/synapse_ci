<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="gc-page-links ce-page-actions d-flex flex-wrap gap-2">
  <?php $referralActor = session()->get('user') ?? []; ?>
  <?php if (!empty($episode['id'])): ?>
    <a href="<?= esc(site_url('/guidance/episodes/' . (int) $episode['id'])) ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-diagram-3" aria-hidden="true"></i> Episode #<?= (int) $episode['id'] ?>
    </a>
  <?php endif; ?>
  <a href="<?= esc(site_url('/guidance/students/' . (int) $case['person_id'] . '/cumulative')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-person-lines-fill" aria-hidden="true"></i> Cumulative Record
  </a>
  <?php if (($referralActor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $referralActor['permissions'] ?? [], true)): ?>
    <a href="<?= esc(site_url('/guidance/referrals/create?' . http_build_query(['person_id' => $case['person_id']]))) ?>"
       class="btn btn-outline-primary btn-sm"
       data-app-modal-open>
      <i class="bi bi-send" aria-hidden="true"></i> Refer to Clinic
    </a>
  <?php endif; ?>
  <a href="<?= site_url('/guidance/cases') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Cases List
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$student = $package['student'];
$employee = $package['employee'] ?? null;
$patient = $package['patient'];
$sessions = $package['sessions'] ?? [];
$followups = $package['followups'] ?? [];
$actor = $actor ?? ($referralActor ?? []);
$actorId = (int) ($actor['id'] ?? 0);
$actorPerms = $actor['permissions'] ?? [];
$clinicalHistory = $clinicalHistory ?? ['controlled_forms' => [], 'interviews' => [], 'contact_forms' => [], 'contact_sheets' => []];
$controlledForms = $clinicalHistory['controlled_forms'] ?? [];
$formsPagination = $clinicalHistory['formsPagination'] ?? null;
$controlledFormsTotal = (int) ($formsPagination['total'] ?? count($controlledForms));
$caseTab = $caseTab ?? (!empty($activeSession) ? 'session' : 'timeline');
if (!in_array($caseTab, ['session', 'timeline', 'followups', 'clinical-forms'], true)
    || ($caseTab === 'session' && empty($activeSession))) {
    $caseTab = !empty($activeSession) ? 'session' : 'timeline';
}
$assignments = $assignments ?? [];
$transfers = $transfers ?? [];
$counselors = $counselors ?? [];
$userDirectory = $userDirectory ?? [];
$practitionerDirectory = $practitionerDirectory ?? [];
$ownerId = (int) ($case['counsellor_user_id'] ?? 0);
$ownerInfo = $practitionerDirectory[$ownerId] ?? null;
$isConsultationPartner = !empty($isConsultationPartner);
$isOversightReader = !empty($oversightMode) || ($ownerId > 0 && $actorId > 0 && $ownerId !== $actorId);
$canWriteCase = !$isConsultationPartner
    && !$isOversightReader
    && ($ownerId === 0 || in_array('guidance.cases.write', $actorPerms, true));
$safetyAssessments = $safetyHistory['assessments'] ?? [];
$latestSafety = $safetyAssessments[0] ?? null;
$pendingIncomingTransfers = array_values(array_filter(
    $transfers,
    static fn (array $t): bool => ($t['status'] ?? '') === 'pending' && (int) ($t['to_counsellor_user_id'] ?? 0) === $actorId
));
$canRequestTransfer = (int) ($case['counsellor_user_id'] ?? 0) === $actorId
    && ($case['status'] ?? '') !== 'closed'
    && (in_array('guidance.cases.transfer', $actorPerms, true) || in_array('guidance.cases.write', $actorPerms, true) || ($actor['role'] ?? '') === 'superadmin');
$canReassign = in_array('guidance.cases.reassign', $actorPerms, true) || ($actor['role'] ?? '') === 'superadmin';
$canAuthorClinical = in_array('guidance.clinical.author', $actorPerms, true)
    || in_array('guidance.counseling.author', $actorPerms, true)
    || in_array('guidance.psychosocial.author', $actorPerms, true);
// Outcome plaintext is optional: only the authorized, audited read supplies it.
$outcomes = $outcomes ?? ['closure_disposition' => null, 'completion_notes' => []];
$caseStatus = (string) ($case['status'] ?? 'open');
$statusDotClass = match ($caseStatus) {
    'closed'     => 'ce-status-slate',
    'monitoring' => 'ce-status-amber',
    'open'       => 'ce-status-sky',
    default      => 'ce-status-emerald',
};
?>

<section class="guidance-case-workspace ce-encounter"
         data-guidance-case="<?= (int) $case['id'] ?>"
         data-refresh-owner="guidance.case.<?= (int) $case['id'] ?>"
         data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div class="d-flex flex-column gap-3" data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <?php if ($isConsultationPartner): ?>
      <div class="alert ce-notice ce-notice-sky">
        <div class="ce-notice-body">
          <i class="bi bi-eye ce-notice-icon" aria-hidden="true"></i>
          <span><strong>Consultation Mode (Read-Only)</strong> · Shared clinical history from <?= esc($ownerInfo['name'] ?? 'primary practitioner') ?>. This access has been audited.</span>
        </div>
      </div>
    <?php elseif (!empty($oversightMode)): ?>
      <div class="alert ce-notice ce-notice-sky">
        <div class="ce-notice-body">
          <i class="bi bi-shield-check ce-notice-icon" aria-hidden="true"></i>
          <span><strong>Oversight mode</strong> · This access has been audited.</span>
        </div>
      </div>
    <?php elseif ($decryptedNote !== null && !empty($noteReadAudited)): ?>
      <div class="alert ce-notice ce-notice-sky">
        <div class="ce-notice-body">
          <i class="bi bi-shield-lock ce-notice-icon" aria-hidden="true"></i>
          <span>This access has been audited.</span>
        </div>
      </div>
    <?php endif; ?>

    <!-- Case Identity Context Card -->
    <div class="ce-patient-card">
      <div class="ce-patient-top">
        <div class="ce-patient-identity gc-patient-identity">
          <div class="gc-person-icon" aria-hidden="true">
            <img src="<?= esc(site_url('/guidance/cases/' . (int) $case['id'] . '/photo'), 'attr') ?>"
                 alt="" width="48" height="48"
                 data-avatar-fallback="<?= esc(\App\Services\ProfileAvatar::dataUri($patient), 'attr') ?>">
          </div>
          <div style="min-width: 0;">
            <div class="ce-patient-heading gc-case-heading">
              <span class="ce-tag ce-mono fw-bold"><?= esc($case['case_number']) ?></span>
              <h4 class="ce-patient-name"><?= esc($case['title']) ?></h4>
              <span class="ce-tag text-capitalize"><?= esc($case['category']) ?></span>
              <span class="ce-status-dot <?= $statusDotClass ?> text-capitalize"><?= esc($caseStatus) ?></span>
            </div>
            <div class="ce-patient-meta">
              <span>
                <?= ($patient['kind'] ?? 'student') === 'employee' ? 'Employee' : 'Student' ?>:
                <strong><?= esc($patient['last_name'] . ', ' . $patient['first_name']) ?></strong>
                (ID: <span class="ce-mono"><?= esc($patient['mis_id']) ?></span>)
              </span>
              <?php if (!empty($student)): ?>
                <span class="ce-meta-sep" aria-hidden="true">·</span>
                <span><?= esc($student['program']) ?>-<?= esc($student['year_level']) ?> (<?= esc($student['department'] ?? 'FU') ?>)</span>
              <?php elseif (!empty($employee)): ?>
                <span class="ce-meta-sep" aria-hidden="true">·</span>
                <span><?= esc($employee['department'] ?? 'FU') ?><?= !empty($employee['position']) ? ' (' . esc($employee['position']) . ')' : '' ?></span>
              <?php endif; ?>
              <span class="ce-meta-sep" aria-hidden="true">·</span>
              <span>Opened <?= date('M j, Y', strtotime((string) $case['opened_at'])) ?></span>
            </div>
            <div class="ce-patient-meta mt-1">
              <span>
                Service: <strong><?= esc($ownerInfo['service_label'] ?? 'Counseling') ?></strong>
                · Assigned: <strong><?= esc($ownerInfo['name'] ?? ($userDirectory[$ownerId] ?? 'Staff #' . $ownerId)) ?></strong>
                (<?= esc($ownerInfo['tier_label'] ?? 'Guidance Staff') ?>)
              </span>
            </div>
          </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
          <?php if ($latestSafety !== null): ?>
            <?php
              $risk = (string) ($latestSafety['risk_level'] ?? 'low');
              $riskDotClass = match ($risk) {
                  'imminent_danger', 'high' => 'ce-status-crimson',
                  'moderate' => 'ce-status-amber',
                  default => 'ce-status-sky',
              };
            ?>
            <span class="ce-tag">
              <span class="ce-status-dot <?= $riskDotClass ?>">Safety Triage: <?= esc(ucwords(str_replace('_', ' ', $risk))) ?></span>
            </span>
          <?php endif; ?>
          <span class="ce-tag">
            <i class="bi bi-shield-lock-fill" aria-hidden="true"></i> Encrypted notes · AES-256-GCM
          </span>
        </div>
      </div>
    </div>

    <?php if (!empty($pendingIncomingTransfers)): ?>
      <div class="alert ce-notice ce-notice-amber">
        <div class="ce-notice-body">
          <i class="bi bi-arrow-left-right ce-notice-icon" aria-hidden="true"></i>
          <span><strong>Pending Transfer Offer:</strong> You have <?= count($pendingIncomingTransfers) ?> incoming transfer/consultation request(s) for this case.</span>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" data-bs-toggle="collapse" data-bs-target="#case-transfers-panel">
          Review Transfer Offer
        </button>
      </div>
    <?php endif; ?>

    <!-- Case Lifecycle & Supervision Card -->
    <section class="ce-card gc-action-card" aria-labelledby="case-actions-title">
      <div class="ce-card-header">
        <h2 id="case-actions-title" class="ce-card-title">
          <i class="bi bi-sliders" aria-hidden="true"></i> Case workspace
        </h2>
        <button class="btn btn-outline-secondary btn-sm"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#case-transfers-panel"
                aria-expanded="false"
                aria-controls="case-transfers-panel">
          <i class="bi bi-people" aria-hidden="true"></i> Transfers &amp; Supervision
          <?php if (!empty($pendingIncomingTransfers)): ?>
            <span class="ce-tag ce-mono"><?= count($pendingIncomingTransfers) ?></span>
          <?php endif; ?>
        </button>
      </div>

      <div class="ce-card-body">
        <?php if ($caseStatus !== 'closed' && $canWriteCase): ?>
          <div class="gc-primary-actions">
            <form action="<?= site_url('/guidance/cases/' . (int) $case['id'] . '/session') ?>" method="POST" data-background-submit class="gc-session-form">
              <?= csrf_field() ?>
              <div class="gc-session-type">
                <label for="case-session-type" class="form-label">Session type</label>
                <select id="case-session-type" name="session_type" class="form-select" aria-label="Session type" required>
                  <?php foreach (\App\Services\Guidance\GuidanceCaseService::SESSION_TYPES as $type): ?>
                    <option value="<?= esc($type) ?>" <?= $type === 'regular' ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $type))) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-play-circle" aria-hidden="true"></i> Start New Session
              </button>
            </form>

            <?php if (empty($activeSession)): ?>
              <button class="btn btn-outline-secondary gc-status-toggle"
                      type="button"
                      data-bs-toggle="collapse"
                      data-bs-target="#case-status-actions"
                      aria-expanded="false"
                      aria-controls="case-status-actions">
                <i class="bi bi-sliders" aria-hidden="true"></i> Manage case status
              </button>
            <?php else: ?>
              <p class="gc-status-hint mb-0">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i> Close all in-progress sessions before monitoring or closing the case.
              </p>
            <?php endif; ?>
          </div>

          <?php if (empty($activeSession)): ?>
            <div class="collapse" id="case-status-actions">
              <div class="gc-status-panel">
                <p class="small text-muted mb-3">Status changes require your explicit confirmation. Closing a session does not close the case.</p>
                <?php if (in_array($caseStatus, ['open', 'active'], true)): ?>
                  <form action="<?= site_url('/guidance/cases/' . (int) $case['id'] . '/monitor') ?>"
                        method="POST"
                        data-background-submit
                        class="gc-status-row"
                        data-confirm="Move this case to monitoring? This is an explicit staff decision, not an inferred outcome."
                        data-confirm-title="Monitor case"
                        data-confirm-label="Move to monitoring"
                        data-confirm-tone="warning">
                    <?= csrf_field() ?>
                    <div>
                      <h3 class="h6 fw-semibold mb-1">Move to monitoring</h3>
                      <p class="small text-muted mb-2">Keep the case open for ongoing observation and follow-up.</p>
                      <label class="gc-confirm-label">
                        <input type="checkbox" name="confirmed" value="1" required> Confirm monitoring
                      </label>
                    </div>
                    <button type="submit" class="btn btn-outline-primary">
                      <i class="bi bi-eye" aria-hidden="true"></i> Move to Monitoring
                    </button>
                  </form>
                <?php endif; ?>

                <form action="<?= site_url('/guidance/cases/' . (int) $case['id'] . '/close') ?>"
                      method="POST"
                      data-background-submit
                      class="gc-status-row"
                      data-confirm="Close this counseling case? No new sessions can be opened after closure."
                      data-confirm-title="Close case"
                      data-confirm-label="Close case"
                      data-confirm-tone="warning">
                  <?= csrf_field() ?>
                  <div>
                    <h3 class="h6 fw-semibold mb-1">Close this case</h3>
                    <p class="small text-muted mb-2">No new sessions can be opened after closure.</p>
                    <label class="form-label" for="closure-disposition">Closure disposition</label>
                    <textarea id="closure-disposition"
                              name="closure_disposition"
                              class="form-control form-control-sm mb-2"
                              rows="2"
                              maxlength="2000"
                              required
                              placeholder="Record the case outcome and closure reason"></textarea>
                    <label class="gc-confirm-label">
                      <input type="checkbox" name="confirmed" value="1" required> Confirm case closure
                    </label>
                  </div>
                  <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-folder-x" aria-hidden="true"></i> Close Case
                  </button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-muted mb-0">
            <i class="bi bi-lock me-2" aria-hidden="true"></i>
            <?= $caseStatus === 'closed' ? 'This case is closed. Review its sessions, notes, and follow-up history below.' : 'Read-only case access. Review sessions, encrypted history, and commitments below.' ?>
          </p>
          <?php if (isset($outcomes['closure_disposition'])): ?>
            <div class="gc-outcome-note">
              <div class="ce-kv-label">Closure disposition</div>
              <p class="small mb-0"><?= esc($outcomes['closure_disposition']) ?></p>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Transfers & Supervision Collapse Panel -->
        <div class="collapse mt-3 pt-3 border-top" id="case-transfers-panel">
          <div class="row g-3">
            <div class="col-lg-6">
              <h3 class="ce-section-heading">Pending &amp; Actionable Transfers</h3>
              <?php if (!empty($pendingIncomingTransfers)): ?>
                <?php foreach ($pendingIncomingTransfers as $pt): ?>
                  <div class="ce-dispense-panel mb-3" style="border-left: 3px solid var(--ce-amber);">
                    <div class="small fw-semibold mb-1">
                      <?= esc(ucwords(str_replace('_', ' ', (string) $pt['transfer_type']))) ?> from
                      <?= esc($userDirectory[(int) $pt['from_counsellor_user_id']] ?? ('Staff #' . (int) $pt['from_counsellor_user_id'])) ?>
                    </div>
                    <p class="small mb-2"><?= esc($pt['reason']) ?></p>
                    <form action="<?= site_url('/guidance/clinical/transfers/' . (int) $pt['id'] . '/respond') ?>" method="POST" data-background-submit>
                      <?= csrf_field() ?>
                      <input type="text" name="response_notes" class="form-control form-control-sm mb-2" maxlength="500" placeholder="Optional response notes">
                      <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="decision" value="accept" class="btn btn-success btn-sm">Accept</button>
                        <button type="submit" name="decision" value="decline" class="btn btn-outline-danger btn-sm">Decline</button>
                      </div>
                    </form>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>

              <?php if ($canRequestTransfer): ?>
                <div class="ce-dispense-panel mb-3">
                  <h4 class="h6 fw-semibold mb-2">Request Case Transfer / Consultation</h4>
                  <form action="<?= site_url('/guidance/clinical/cases/' . (int) $case['id'] . '/transfer/request') ?>" method="POST" data-background-submit>
                    <?= csrf_field() ?>
                    <div class="mb-2">
                      <label class="form-label" for="transfer-to-user">Target Counselor</label>
                      <select id="transfer-to-user" name="to_counsellor_user_id" class="form-select form-select-sm" required>
                        <option value="">Select counselor...</option>
                        <?php foreach ($counselors as $cRow): ?>
                          <?php if ((int) $cRow['id'] !== (int) ($case['counsellor_user_id'] ?? 0)): ?>
                            <?php $cName = trim(($cRow['first_name'] ?? '') . ' ' . ($cRow['last_name'] ?? '')); ?>
                            <option value="<?= (int) $cRow['id'] ?>">
                              <?= esc($cName !== '' ? $cName : $cRow['username']) ?> (<?= esc(ucwords(str_replace('_', ' ', (string) $cRow['role']))) ?>)
                            </option>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="transfer-type">Transfer Type</label>
                      <select id="transfer-type" name="transfer_type" class="form-select form-select-sm" required>
                        <option value="consultation">Consultation</option>
                        <option value="full_transfer">Full Case Transfer</option>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="transfer-reason">Reason</label>
                      <textarea id="transfer-reason" name="reason" rows="2" maxlength="500" class="form-control form-control-sm" required placeholder="Clinical handover or consultation reason"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Send Transfer Request</button>
                  </form>
                </div>
              <?php endif; ?>

              <?php if ($canReassign): ?>
                <div class="ce-dispense-panel">
                  <h4 class="h6 fw-semibold mb-2">Supervisor Reassignment</h4>
                  <form action="<?= site_url('/guidance/clinical/cases/' . (int) $case['id'] . '/reassign') ?>"
                        method="POST"
                        data-background-submit
                        data-confirm="Reassign ownership of this counseling case to the selected counselor? This change is logged in the assignment ledger."
                        data-confirm-title="Reassign Case"
                        data-confirm-label="Yes, Reassign"
                        data-confirm-tone="danger">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                      <label class="form-label" for="reassign-to-user">Assign Counselor</label>
                      <select id="reassign-to-user" name="new_counsellor_user_id" class="form-select form-select-sm" required>
                        <option value="">Select counselor...</option>
                        <?php foreach ($counselors as $cRow): ?>
                          <?php $cName = trim(($cRow['first_name'] ?? '') . ' ' . ($cRow['last_name'] ?? '')); ?>
                          <option value="<?= (int) $cRow['id'] ?>" <?= (int) $cRow['id'] === (int) ($case['counsellor_user_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= esc($cName !== '' ? $cName : $cRow['username']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="reassign-reason">Audit Reason</label>
                      <textarea id="reassign-reason" name="reason" rows="2" maxlength="500" class="form-control form-control-sm" required placeholder="Supervisor reassignment justification"></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-danger btn-sm">Reassign Case</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-lg-6">
              <h3 class="ce-section-heading">Assignment &amp; Transfer History</h3>
              <?php if (empty($assignments) && empty($transfers)): ?>
                <p class="small text-muted">No assignment changes or transfers recorded yet.</p>
              <?php else: ?>
                <?php if (!empty($assignments)): ?>
                  <div class="mb-3">
                    <div class="ce-kv-label mb-2">Counselor Assignments</div>
                    <div class="ce-entry-list">
                      <?php foreach ($assignments as $a): ?>
                        <div class="ce-entry-card">
                          <div class="ce-entry-head">
                            <strong><?= esc($userDirectory[(int) $a['counsellor_user_id']] ?? ('Staff #' . (int) $a['counsellor_user_id'])) ?></strong>
                            <span class="ce-tag"><?= esc(ucwords(str_replace('_', ' ', (string) $a['assignment_type']))) ?></span>
                          </div>
                          <div class="ce-entry-notes mt-0">
                            Assigned: <?= date('M j, Y g:i A', strtotime((string) $a['assigned_at'])) ?>
                            <?php if (!empty($a['relinquished_at'])): ?>
                              · Ended: <?= date('M j, Y g:i A', strtotime((string) $a['relinquished_at'])) ?>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($a['reason'])): ?>
                            <div class="ce-entry-notes"><?= esc($a['reason']) ?></div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if (!empty($transfers)): ?>
                  <div>
                    <div class="ce-kv-label mb-2">Transfer Requests</div>
                    <div class="ce-entry-list">
                      <?php foreach ($transfers as $t): ?>
                        <?php
                          $tStatus = (string) ($t['status'] ?? 'pending');
                          $tCardMod = $tStatus === 'accepted' ? 'ce-entry-card--normal' : ($tStatus === 'declined' ? 'ce-entry-card--abnormal' : 'ce-entry-card--pending');
                          $tDotMod = $tStatus === 'accepted' ? 'ce-status-emerald' : ($tStatus === 'declined' ? 'ce-status-crimson' : 'ce-status-amber');
                        ?>
                        <div class="ce-entry-card <?= $tCardMod ?>">
                          <div class="ce-entry-head">
                            <span>
                              <?= esc($userDirectory[(int) $t['from_counsellor_user_id']] ?? ('#' . (int) $t['from_counsellor_user_id'])) ?>
                              <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i>
                              <?= esc($userDirectory[(int) $t['to_counsellor_user_id']] ?? ('#' . (int) $t['to_counsellor_user_id'])) ?>
                            </span>
                            <span class="ce-status-dot <?= $tDotMod ?>"><?= esc(ucfirst($tStatus)) ?></span>
                          </div>
                          <div class="ce-entry-notes mt-0"><?= esc(ucwords(str_replace('_', ' ', (string) $t['transfer_type']))) ?> · <?= esc($t['reason']) ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Case Workspace Tabs Card -->
    <div class="ce-card" x-data="{ currentTab: '<?= esc($caseTab, 'attr') ?>' }">
      <div class="ce-stepper-track">
        <ul class="nav nav-tabs gc-case-tabs" role="tablist">
          <?php if (!empty($activeSession)): ?>
            <li class="nav-item">
              <button id="case-active-tab"
                      type="button"
                      role="tab"
                      aria-selected="<?= $caseTab === 'session' ? 'true' : 'false' ?>"
                      aria-controls="tab-active-session"
                      class="nav-link <?= $caseTab === 'session' ? 'active' : '' ?>"
                      data-bs-toggle="tab"
                      data-bs-target="#tab-active-session">
                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                <span>Active Session #<?= esc($activeSession['session_number']) ?></span>
              </button>
            </li>
          <?php endif; ?>
          <li class="nav-item">
            <button id="case-history-tab"
                    type="button"
                    role="tab"
                    aria-selected="<?= $caseTab === 'timeline' ? 'true' : 'false' ?>"
                    aria-controls="tab-timeline"
                    class="nav-link <?= $caseTab === 'timeline' ? 'active' : '' ?>"
                    data-bs-toggle="tab"
                    data-bs-target="#tab-timeline">
              <i class="bi bi-clock-history" aria-hidden="true"></i>
              <span>Sessions &amp; Notes History (<?= count($sessions) ?>)</span>
            </button>
          </li>
          <li class="nav-item">
            <button id="case-followups-tab"
                    type="button"
                    role="tab"
                    aria-selected="<?= $caseTab === 'followups' ? 'true' : 'false' ?>"
                    aria-controls="tab-followups"
                    class="nav-link <?= $caseTab === 'followups' ? 'active' : '' ?>"
                    data-bs-toggle="tab"
                    data-bs-target="#tab-followups">
              <i class="bi bi-check2-square" aria-hidden="true"></i>
              <span>Follow-up Commitments (<?= count($followups) ?>)</span>
            </button>
          </li>
          <li class="nav-item">
            <button id="case-clinical-forms-tab"
                    type="button"
                    role="tab"
                    aria-selected="<?= $caseTab === 'clinical-forms' ? 'true' : 'false' ?>"
                    aria-controls="tab-clinical-forms"
                    class="nav-link <?= $caseTab === 'clinical-forms' ? 'active' : '' ?>"
                    data-bs-toggle="tab"
                    data-bs-target="#tab-clinical-forms">
              <i class="bi bi-file-earmark-medical" aria-hidden="true"></i>
              <span>Clinical Forms &amp; Attestations (<?= number_format($controlledFormsTotal) ?>)</span>
            </button>
          </li>
        </ul>
      </div>

      <div class="ce-stepper-body tab-content">

        <!-- TAB 1: ACTIVE SESSION EDITOR (if open) -->
        <?php if (!empty($activeSession)): ?>
          <?php
            $actOwnerId = (int) ($activeSession['counsellor_user_id'] ?? $ownerId);
            $actInfo = $practitionerDirectory[$actOwnerId] ?? $ownerInfo;
            $actServiceLabel = $actInfo['service_label'] ?? 'Counseling';
          ?>
          <div class="tab-pane fade <?= $caseTab === 'session' ? 'show active' : '' ?>" id="tab-active-session" role="tabpanel" aria-labelledby="case-active-tab">
            <div class="d-flex flex-wrap gap-3 justify-content-between align-items-start mb-3 pb-3 border-bottom gc-session-heading">
              <div>
                <h6 class="fw-bold mb-1"><?= esc($actServiceLabel) ?> Session #<?= esc($activeSession['session_number']) ?> (<?= esc(ucwords(str_replace('_', ' ', (string) $activeSession['session_type']))) ?>)</h6>
                <small class="text-muted">Started: <?= date('h:i A · M j, Y', strtotime((string) $activeSession['started_at'])) ?> · Handled by: <?= esc($actInfo['name'] ?? ($userDirectory[$actOwnerId] ?? 'Staff')) ?> (<?= esc($actInfo['tier_label'] ?? 'Guidance Staff') ?>)</small>
              </div>
              <?php if ($canWriteCase): ?>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                  <span class="ce-status-dot ce-status-amber">Session In Progress</span>
                  <?php if ($canAuthorClinical): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#close-with-ccf-panel" aria-expanded="false">
                      <i class="bi bi-file-earmark-check" aria-hidden="true"></i> Close &amp; Attach CCF-009
                    </button>
                  <?php endif; ?>
                  <form action="<?= site_url('/guidance/sessions/' . $activeSession['id'] . '/close') ?>"
                        method="POST"
                        data-background-submit
                        class="d-inline"
                        data-confirm="Complete and close this counseling session? Save any changes first. Closing the session does not automatically finalize notes."
                        data-confirm-title="Close counseling session"
                        data-confirm-label="Close session"
                        data-confirm-tone="warning">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-success btn-sm">
                      <i class="bi bi-check-circle" aria-hidden="true"></i> Close Session
                    </button>
                  </form>
                </div>
              <?php else: ?>
                <span class="ce-status-dot ce-status-amber">Session In Progress (Read-Only Consultation View)</span>
              <?php endif; ?>
            </div>

            <?php if ($canAuthorClinical): ?>
              <div class="collapse mb-3" id="close-with-ccf-panel">
                <div class="ce-dispense-panel">
                  <h6 class="fw-bold mb-1">Close Session &amp; Attach Counseling Contact Sheet (F-OGC-GCS-CCF-009-00)</h6>
                  <p class="small text-muted mb-3">
                    Attaches the controlled Counseling Contact Sheet directly to Session #<?= esc($activeSession['session_number']) ?>.
                    Leave <em>Concerns Discussed</em> blank to automatically reuse your saved session notes without duplicate entry.
                  </p>
                  <form action="<?= site_url('/guidance/sessions/' . $activeSession['id'] . '/close') ?>" method="POST" data-background-submit>
                    <?= csrf_field() ?>
                    <input type="hidden" name="attach_contact_sheet" value="1">
                    <div class="row g-2 mb-2">
                      <div class="col-md-3">
                        <label class="form-label" for="ccf-time-start-display">Time Start</label>
                        <input id="ccf-time-start-display" type="text" class="form-control form-control-sm" data-timepicker data-target="#ccf-time-start" placeholder="hh:mm A" autocomplete="off">
                        <input id="ccf-time-start" type="hidden" name="time_start" value="<?= esc(date('H:i', strtotime((string) $activeSession['started_at']))) ?>">
                      </div>
                      <div class="col-md-3">
                        <label class="form-label" for="ccf-time-end-display">Time End</label>
                        <input id="ccf-time-end-display" type="text" class="form-control form-control-sm" data-timepicker data-target="#ccf-time-end" placeholder="hh:mm A" autocomplete="off">
                        <input id="ccf-time-end" type="hidden" name="time_end" value="<?= esc(date('H:i')) ?>">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="ccf-next-schedule">Next Schedule (Optional)</label>
                        <input id="ccf-next-schedule" type="text" name="next_schedule" class="form-control form-control-sm" placeholder="YYYY-MM-DD HH:MM:SS">
                      </div>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="ccf-concerns">Concerns Discussed (Optional — reuses saved note if blank)</label>
                      <textarea id="ccf-concerns" name="concerns_discussed" rows="2" class="form-control form-control-sm" placeholder="Leave blank to import saved session note automatically"></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                      <div class="col-md-6">
                        <label class="form-label" for="ccf-goals">Counseling Goals</label>
                        <textarea id="ccf-goals" name="counseling_goals" rows="2" class="form-control form-control-sm" required placeholder="Session goals and objectives"></textarea>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="ccf-interventions">Interventions &amp; Responses</label>
                        <textarea id="ccf-interventions" name="interventions_and_responses" rows="2" class="form-control form-control-sm" required placeholder="Techniques applied and client response"></textarea>
                      </div>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="ccf-homework">Homework &amp; Commitments (Optional)</label>
                      <input id="ccf-homework" type="text" name="homework_and_commitments" class="form-control form-control-sm" placeholder="Agreed client follow-through">
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="finalize" value="1" id="ccf-finalize-check">
                        <label class="form-check-label small" for="ccf-finalize-check">
                          <?= !empty($canFinalizeDirectly) ? 'Digitally attest &amp; finalize CCF-009 immediately' : 'Submit CCF-009 for supervisor co-sign immediately' ?>
                        </label>
                      </div>
                      <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check2-all" aria-hidden="true"></i> Close Session &amp; Attach CCF-009
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            <?php endif; ?>

            <!-- Notes editor form -->
            <form action="<?= site_url('/guidance/sessions/' . $activeSession['id'] . '/notes') ?>" method="POST" data-background-submit>
              <?= csrf_field() ?>
              <div class="mb-3">
                <label for="sessionNotes" class="form-label">Confidential Session Notes</label>
                <textarea name="notes"
                          id="sessionNotes"
                          rows="8"
                          class="form-control"
                          placeholder="Type counselor observations, client reflection, discussion points..."
                          required><?= esc($decryptedNote['text'] ?? '') ?></textarea>
                <div class="form-text">
                  Notes are encrypted with AES-256-GCM before database write. Assigned counselors and authorized oversight staff can decrypt.
                </div>
              </div>

              <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center pt-3 border-top">
                <div class="text-muted small">
                  <?php if (!empty($decryptedNote)): ?>
                    Last saved version: <strong>v<?= esc($decryptedNote['version']) ?></strong> (<?= date('M j, h:i A', strtotime((string) $decryptedNote['created_at'])) ?>)
                  <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2 gc-editor-buttons">
                  <button type="submit" name="action" value="draft" formaction="<?= site_url('/guidance/sessions/' . $activeSession['id'] . '/notes') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Save Draft
                  </button>
                  <button type="submit" name="action" value="finalize" formaction="<?= site_url('/guidance/sessions/' . $activeSession['id'] . '/notes') ?>" class="btn btn-primary">
                    <i class="bi bi-lock-fill" aria-hidden="true"></i> Finalize Notes (New Version)
                  </button>
                </div>
              </div>
            </form>
          </div>
        <?php endif; ?>

        <!-- TAB 2: SESSIONS & NOTES TIMELINE -->
        <div class="tab-pane fade <?= $caseTab === 'timeline' ? 'show active' : '' ?>" id="tab-timeline" role="tabpanel" aria-labelledby="case-history-tab">
          <?php if (empty($sessions)): ?>
            <div class="ce-empty-row">
              <i class="bi bi-calendar-x" aria-hidden="true"></i>
              No counseling sessions held under this case yet. Click "Start New Session" above to begin.
            </div>
          <?php else: ?>
            <div class="ce-entry-list">
              <?php foreach ($sessions as $s): ?>
                <?php
                  $sOwnerId = (int) ($s['counsellor_user_id'] ?? 0);
                  $sInfo = $practitionerDirectory[$sOwnerId] ?? null;
                  $sServiceLabel = $sInfo['service_label'] ?? 'Counseling';
                  $sHandlerLabel = $sInfo ? ($sInfo['name'] . ' · ' . $sInfo['tier_label']) : (string) ($s['counsellor_name'] ?? 'Staff');
                  $sCardMod = ($s['status'] ?? '') === 'completed' ? 'ce-entry-card--normal' : 'ce-entry-card--pending';
                ?>
                <div class="ce-entry-card <?= $sCardMod ?>">
                  <div class="gc-history-row">
                    <div>
                      <h6 class="fw-bold mb-1">
                        Session #<?= esc($s['session_number']) ?>: <?= esc($sServiceLabel) ?> · <?= esc(ucwords(str_replace('_', ' ', (string) $s['session_type']))) ?>
                      </h6>
                      <div class="ce-entry-notes mt-0">
                        <?= date('F j, Y · h:i A', strtotime((string) $s['started_at'])) ?> · Handled by: <?= esc($sHandlerLabel) ?>
                      </div>
                    </div>
                    <div class="gc-history-actions">
                      <?php if (($s['status'] ?? '') === 'in_progress'): ?>
                        <span class="ce-status-dot ce-status-amber">In Progress</span>
                      <?php elseif (($s['status'] ?? '') === 'completed'): ?>
                        <span class="ce-status-dot ce-status-emerald">Completed</span>
                      <?php else: ?>
                        <span class="ce-status-dot ce-status-slate"><?= esc(ucwords(str_replace('_', ' ', (string) ($s['status'] ?? '')))) ?></span>
                      <?php endif; ?>
                      <a href="<?= site_url('/guidance/sessions/' . $s['id'] . '/read') ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>
                        <i class="bi bi-eye" aria-hidden="true"></i> View Decrypted Note
                      </a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- TAB 3: FOLLOW-UPS -->
        <div class="tab-pane fade <?= $caseTab === 'followups' ? 'show active' : '' ?>" id="tab-followups" role="tabpanel" aria-labelledby="case-followups-tab">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h6 class="ce-section-heading mb-0">
              <i class="bi bi-check2-square" aria-hidden="true"></i> Commitments &amp; Action Items
            </h6>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#newFollowupForm">
              <i class="bi bi-plus-circle" aria-hidden="true"></i> Add Commitment
            </button>
          </div>

          <div class="collapse mb-4" id="newFollowupForm">
            <div class="ce-dispense-panel">
              <form action="<?= site_url('/guidance/cases/' . $case['id'] . '/followups') ?>" method="POST" data-background-submit>
                <?= csrf_field() ?>
                <div class="row g-2 mb-3">
                  <div class="col-md-4">
                    <label class="form-label" for="dueDate">Target Due Date</label>
                    <input type="text"
                           class="form-control form-control-sm"
                           id="dueDate"
                           data-datepicker
                           data-target="#dueDate_iso"
                           data-min="today"
                           value=""
                           required>
                    <input type="hidden" name="due_date" id="dueDate_iso" value="">
                  </div>
                  <div class="col-md-8">
                    <label class="form-label" for="actionPlanInput">Action Plan / Commitment</label>
                    <input type="text"
                           id="actionPlanInput"
                           name="action_plan"
                           class="form-control form-control-sm"
                           placeholder="e.g. Complete study timetable, practice breathing exercise"
                           required>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Save Action Item</button>
              </form>
            </div>
          </div>

          <?php if (empty($followups)): ?>
            <div class="ce-empty-row">
              <i class="bi bi-list-check" aria-hidden="true"></i>
              No follow-up action items assigned yet.
            </div>
          <?php else: ?>
            <div class="ce-entry-list">
              <?php foreach ($followups as $f): ?>
                <?php
                  $fStatus = (string) ($f['status'] ?? 'pending');
                  $fCardMod = $fStatus === 'completed' ? 'ce-entry-card--normal' : ($fStatus === 'missed' ? 'ce-entry-card--abnormal' : 'ce-entry-card--pending');
                  $fDotMod = $fStatus === 'completed' ? 'ce-status-emerald' : ($fStatus === 'missed' ? 'ce-status-crimson' : 'ce-status-amber');
                ?>
                <div class="ce-entry-card <?= $fCardMod ?> gc-followup-row">
                  <div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                      <span class="ce-status-dot <?= $fDotMod ?>"><?= esc(ucfirst($fStatus)) ?></span>
                      <span class="<?= $fStatus === 'completed' ? 'text-decoration-line-through text-muted' : 'fw-semibold' ?>">
                        <?= esc($f['action_plan']) ?>
                      </span>
                    </div>
                    <div class="ce-entry-notes mt-0">Due: <span class="ce-mono"><?= date('M j, Y', strtotime((string) $f['due_date'])) ?></span></div>
                    <?php if (isset($outcomes['completion_notes'][(int) $f['id']])): ?>
                      <div class="gc-outcome-note">
                        <div class="ce-kv-label">Completion notes</div>
                        <p class="small mb-0"><?= esc($outcomes['completion_notes'][(int) $f['id']]) ?></p>
                      </div>
                    <?php endif; ?>
                  </div>

                  <?php if ($fStatus === 'pending'): ?>
                    <div class="gc-followup-actions">
                      <form action="<?= site_url('/guidance/followups/' . $f['id'] . '/missed') ?>"
                            method="POST"
                            data-background-submit
                            data-confirm="Explicitly mark this follow-up missed? A due date alone does not determine this outcome."
                            data-confirm-title="Missed follow-up"
                            data-confirm-label="Mark missed"
                            data-confirm-tone="warning">
                        <?= csrf_field() ?>
                        <label class="gc-confirm-label">
                          <input type="checkbox" name="confirmed" value="1" required> Confirm missed
                        </label>
                        <button type="submit" class="btn btn-outline-danger btn-sm">Mark Missed</button>
                      </form>
                      <form action="<?= site_url('/guidance/followups/' . $f['id'] . '/complete') ?>" method="POST" data-background-submit class="gc-outcome-form">
                        <?= csrf_field() ?>
                        <label class="form-label" for="completion-notes-<?= (int) $f['id'] ?>">Completion notes</label>
                        <textarea id="completion-notes-<?= (int) $f['id'] ?>"
                                  name="completion_notes"
                                  class="form-control form-control-sm mb-2"
                                  rows="2"
                                  maxlength="2000"
                                  required
                                  placeholder="Record what was completed"></textarea>
                        <button type="submit" class="btn btn-success btn-sm">
                          <i class="bi bi-check2" aria-hidden="true"></i> Mark Done
                        </button>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- TAB 4: CLINICAL FORMS & ATTESTATIONS -->
        <div class="tab-pane fade <?= $caseTab === 'clinical-forms' ? 'show active' : '' ?>" id="tab-clinical-forms" role="tabpanel" aria-labelledby="case-clinical-forms-tab">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
              <h6 class="ce-section-heading mb-1">
                <i class="bi bi-file-earmark-medical" aria-hidden="true"></i> Controlled Clinical Forms &amp; Attestations
              </h6>
              <small class="text-muted">AES-256-GCM encrypted clinical records with supervisor co-sign and digital attestation binding.</small>
            </div>
            <?php if ($canAuthorClinical): ?>
              <div class="d-flex flex-wrap gap-2">
                <?php
                  $qs = http_build_query(array_filter([
                      'case_id'    => (int) $case['id'],
                      'person_id'  => (int) $case['person_id'],
                      'episode_id' => !empty($episode['id']) ? (int) $episode['id'] : null,
                      'session_id' => !empty($activeSession['id']) ? (int) $activeSession['id'] : null,
                  ]));
                ?>
                <a href="<?= esc(site_url('/guidance/clinical/forms/new/psychosocial_support_interview?' . $qs)) ?>" class="btn btn-outline-primary btn-sm">
                  <i class="bi bi-plus-circle" aria-hidden="true"></i> New PIF-008
                </a>
                <a href="<?= esc(site_url('/guidance/clinical/forms/new/psychosocial_support_contact?' . $qs)) ?>" class="btn btn-outline-primary btn-sm">
                  <i class="bi bi-plus-circle" aria-hidden="true"></i> New PCF-008
                </a>
                <a href="<?= esc(site_url('/guidance/clinical/forms/new/counseling_contact_sheet?' . $qs)) ?>" class="btn btn-outline-primary btn-sm">
                  <i class="bi bi-plus-circle" aria-hidden="true"></i> New CCF-009
                </a>
              </div>
            <?php endif; ?>
          </div>

          <?php if (empty($controlledForms)): ?>
            <div class="ce-empty-row">
              <i class="bi bi-file-earmark-medical" aria-hidden="true"></i>
              No controlled clinical forms (PIF-008, PCF-008, or CCF-009) have been recorded for this case yet.
            </div>
          <?php else: ?>
            <div class="ce-dispense-table-wrap">
              <div class="table-responsive">
                <table class="table table-hover align-middle ce-table ce-table-mobile" data-mobile-cards="false">
                  <thead>
                    <tr>
                      <th>Form &amp; Instance</th>
                      <th>Status</th>
                      <th>Author &amp; Co-Signer Attribution</th>
                      <th>Updated</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($controlledForms as $cf): ?>
                      <?php
                        $cfStatus = (string) ($cf['status'] ?? 'draft');
                        $cfDotClass = match ($cfStatus) {
                            'finalized'      => 'ce-status-emerald',
                            'pending_cosign' => 'ce-status-sky',
                            default          => 'ce-status-amber',
                        };
                        $authorName = $userDirectory[(int) ($cf['created_by_user_id'] ?? 0)] ?? ('Staff #' . (int) ($cf['created_by_user_id'] ?? 0));
                        $supervisorName = !empty($cf['assigned_supervisor_user_id'])
                            ? ($userDirectory[(int) $cf['assigned_supervisor_user_id']] ?? ('Supervisor #' . (int) $cf['assigned_supervisor_user_id']))
                            : null;
                        $finalizerName = !empty($cf['finalized_by_user_id'])
                            ? ($userDirectory[(int) $cf['finalized_by_user_id']] ?? ('Staff #' . (int) $cf['finalized_by_user_id']))
                            : null;
                        $attestations = $cf['attestations'] ?? [];
                      ?>
                      <tr>
                        <td data-label="Form &amp; Instance">
                          <div class="fw-semibold"><?= esc($cf['canonical_title'] ?: $cf['internal_key']) ?></div>
                          <div class="ce-patient-sub">
                            <span class="ce-tag"><?= esc($cf['form_code']) ?></span>
                            <span class="ce-mono"><?= esc($cf['instance_number']) ?></span>
                          </div>
                        </td>
                        <td data-label="Status">
                          <span class="ce-status-dot <?= $cfDotClass ?>"><?= esc(ucwords(str_replace('_', ' ', $cfStatus))) ?></span>
                        </td>
                        <td data-label="Author &amp; Co-Signer Attribution" class="small">
                          <div>Author: <strong><?= esc($authorName) ?></strong></div>
                          <?php if ($cfStatus === 'pending_cosign' && $supervisorName !== null): ?>
                            <div class="text-muted">Awaiting co-sign: <strong><?= esc($supervisorName) ?></strong></div>
                          <?php elseif ($cfStatus === 'finalized'): ?>
                            <?php if (!empty($attestations)): ?>
                              <?php foreach ($attestations as $att): ?>
                                <?php $signerName = $userDirectory[(int) ($att['signer_user_id'] ?? 0)] ?? ($finalizerName ?? 'Signer'); ?>
                                <div class="text-success">
                                  <?= esc(ucwords(str_replace('_', ' ', (string) ($att['signer_role'] ?? 'signer')))) ?>:
                                  <strong><?= esc($signerName) ?></strong>
                                  <?php if (!empty($att['attested_at'])): ?>
                                    · <?= date('M j, Y g:i A', strtotime((string) $att['attested_at'])) ?>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                            <?php elseif ($finalizerName !== null): ?>
                              <div class="text-success">Attested by <strong><?= esc($finalizerName) ?></strong></div>
                            <?php endif; ?>
                          <?php endif; ?>
                        </td>
                        <td data-label="Updated">
                          <span class="ce-mono small text-muted">
                            <?= !empty($cf['updated_at']) ? date('M j, Y g:i A', strtotime((string) $cf['updated_at'])) : '—' ?>
                          </span>
                        </td>
                        <td data-label="Actions" class="text-end">
                          <div class="d-flex flex-wrap justify-content-md-end gap-2 gc-print-group">
                            <a href="<?= esc(site_url('/guidance/clinical/forms/' . (int) $cf['id'])) ?>" class="btn btn-outline-primary btn-sm">
                              <?= $cfStatus === 'finalized' ? 'View' : ($cfStatus === 'pending_cosign' ? 'Review / Co-Sign' : 'Edit') ?>
                            </a>
                            <a href="<?= esc(site_url('/guidance/clinical/forms/' . (int) $cf['id'] . '/pdf?mode=preview')) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                              <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF
                            </a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($formsPagination !== null): ?>
            <?= view('partials/table_pagination', [
                'pagination' => $formsPagination,
                'baseUrl' => site_url('/guidance/cases/' . (int) $case['id']),
                'paginationPageParam' => 'forms_page',
                'paginationPerPageParam' => 'per_page',
                'paginationExtraQuery' => ['tab' => 'clinical-forms'],
                'paginationFragment' => 'tab-clinical-forms',
                'paginationLabel' => 'Case clinical form pages',
                'paginationNavigation' => true,
            ], ['saveData' => false]) ?>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  if (window.synapseGuidanceTabs) return;
  window.synapseGuidanceTabs = true;
  // Copy only tab presentation into the detached server main, never note contents.
  document.addEventListener('synapse:before-swap', event => {
    const old = event.detail.root.querySelector('[data-guidance-case]');
    const next = event.detail.incoming?.querySelector('[data-guidance-case]');
    if (!old || !next || old.dataset.guidanceCase !== next.dataset.guidanceCase) return;
    const target = old.querySelector('[data-bs-toggle="tab"].active')?.dataset.bsTarget;
    const tabs = [...next.querySelectorAll('[data-bs-toggle="tab"]')];
    if (!tabs.some(tab => tab.dataset.bsTarget === target)) return;
    tabs.forEach(tab => {
      const active = tab.dataset.bsTarget === target;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', String(active));
      const pane = next.querySelector(tab.dataset.bsTarget);
      pane?.classList.toggle('active', active);
      pane?.classList.toggle('show', active);
    });
  });
})();
</script>
<?= $this->endSection() ?>
