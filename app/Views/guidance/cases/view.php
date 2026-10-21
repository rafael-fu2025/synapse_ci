<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="gc-page-links d-flex flex-wrap gap-2">
  <?php $referralActor = session()->get('user') ?? []; ?>
  <?php if (($referralActor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $referralActor['permissions'] ?? [], true)): ?>
  <a href="<?= esc(site_url('/guidance/referrals/create?' . http_build_query(['person_id' => $case['person_id']]))) ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>Refer to Clinic</a>
  <?php endif; ?>
  <a href="<?= site_url('/guidance/cases') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i> Cases List
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
?>

<div class="guidance-case-workspace">
<!-- Case identity stays separate from session and lifecycle actions. -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body p-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="d-flex align-items-start gap-3 gc-patient-identity">
        <div class="gc-person-icon" aria-hidden="true">
          <i class="bi bi-person-heart"></i>
        </div>
        <div>
          <div class="d-flex flex-wrap align-items-center gap-2 gc-case-heading">
            <span class="badge bg-dark-subtle text-dark fw-bold"><?= esc($case['case_number']) ?></span>
            <h4 class="h5 fw-bold mb-0 text-dark"><?= esc($case['title']) ?></h4>
            <span class="badge bg-light text-dark border text-capitalize"><?= esc($case['category']) ?></span>
            <span class="badge bg-<?= ($case['status'] === 'closed') ? 'secondary' : 'success' ?> text-capitalize"><?= esc($case['status']) ?></span>
          </div>
          <div class="text-muted small mt-1">
            <?= ($patient['kind'] ?? 'student') === 'employee' ? 'Employee' : 'Student' ?>: <strong><?= esc($patient['last_name'] . ', ' . $patient['first_name']) ?></strong> (ID: <?= esc($patient['mis_id']) ?>)
            <?php if (!empty($student)): ?>
              <span class="ms-2">· <?= esc($student['program']) ?>-<?= esc($student['year_level']) ?> (<?= esc($student['department'] ?? 'FU') ?>)</span>
            <?php elseif (!empty($employee)): ?>
              <span class="ms-2">· <?= esc($employee['department'] ?? 'FU') ?><?= !empty($employee['position']) ? ' (' . esc($employee['position']) . ')' : '' ?></span>
            <?php endif; ?>
            <span class="ms-2 text-muted">· Opened <?= date('M j, Y', strtotime($case['opened_at'])) ?></span>
          </div>
        </div>
      </div>

      <!-- Security Notice Badge -->
      <div>
        <span class="badge bg-success-subtle text-success border border-success-subtle p-2">
          <i class="bi bi-shield-lock-fill me-1"></i> Encrypted notes · AES-256-GCM
        </span>
      </div>
    </div>
  </div>
</div>

<section class="card border-0 shadow-sm mb-4 gc-action-card" aria-labelledby="case-actions-title">
  <div class="card-body p-3 p-lg-4">
    <h2 id="case-actions-title" class="h6 fw-semibold mb-3">Case workspace</h2>
    <?php if ($case['status'] !== 'closed'): ?>
    <div class="gc-primary-actions">
      <form action="<?= site_url('/guidance/cases/' . (int) $case['id'] . '/session') ?>" method="POST" class="gc-session-form">
        <?= csrf_field() ?>
        <div class="gc-session-type"><label for="case-session-type" class="form-label small fw-semibold">Session type</label>
          <select id="case-session-type" name="session_type" class="form-select" aria-label="Session type" required>
            <?php foreach (\App\Services\Guidance\GuidanceCaseService::SESSION_TYPES as $type): ?><option value="<?= esc($type) ?>" <?= $type === 'regular' ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-play-circle me-2" aria-hidden="true"></i>Start New Session</button>
      </form>
      <?php if (empty($activeSession)): ?>
      <button class="btn btn-outline-secondary gc-status-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#case-status-actions" aria-expanded="false" aria-controls="case-status-actions"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Manage case status</button>
      <?php else: ?><p class="small text-muted gc-status-hint mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Close all in-progress sessions before monitoring or closing the case.</p><?php endif; ?>
    </div>
    <?php if (empty($activeSession)): ?>
    <div class="collapse" id="case-status-actions">
      <div class="gc-status-panel">
        <p class="small text-muted mb-3">Status changes require your explicit confirmation. Closing a session does not close the case.</p>
        <?php if (in_array($case['status'], ['open', 'active'], true)): ?>
        <form action="<?= site_url('/guidance/cases/' . (int) $case['id'] . '/monitor') ?>" method="POST" class="gc-status-row"
              data-confirm="Move this case to monitoring? This is an explicit staff decision, not an inferred outcome."
              data-confirm-title="Monitor case" data-confirm-label="Move to monitoring" data-confirm-tone="warning">
          <?= csrf_field() ?>
          <div><h3 class="h6 mb-1">Move to monitoring</h3><p class="small text-muted mb-2">Keep the case open for ongoing observation and follow-up.</p><label class="gc-confirm-label small"><input type="checkbox" name="confirmed" value="1" required> Confirm monitoring</label></div>
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-eye me-2" aria-hidden="true"></i>Move to Monitoring</button>
        </form>
        <?php endif; ?>
        <form action="<?= site_url('/guidance/cases/' . (int) $case['id'] . '/close') ?>" method="POST" class="gc-status-row"
              data-confirm="Close this counseling case? No new sessions can be opened after closure."
              data-confirm-title="Close case" data-confirm-label="Close case" data-confirm-tone="warning">
          <?= csrf_field() ?>
          <div><h3 class="h6 mb-1">Close this case</h3><p class="small text-muted mb-2">No new sessions can be opened after closure.</p><label class="gc-confirm-label small"><input type="checkbox" name="confirmed" value="1" required> Confirm case closure</label></div>
          <button type="submit" class="btn btn-outline-danger"><i class="bi bi-folder-x me-2" aria-hidden="true"></i>Close Case</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?><p class="text-muted mb-0"><i class="bi bi-lock me-2" aria-hidden="true"></i>This case is closed. Review its sessions, notes, and follow-up history below.</p><?php endif; ?>
  </div>
</section>

<!-- Case workspace tabs -->
<div class="card border-0 shadow-sm" x-data="{ currentTab: '<?= !empty($activeSession) ? 'session' : 'timeline' ?>' }">
  <div class="card-header bg-transparent border-bottom p-0">
    <ul class="nav nav-tabs nav-tabs-bordered" role="tablist">
      <?php if (!empty($activeSession)): ?>
        <li class="nav-item">
          <button id="case-active-tab" type="button" role="tab" aria-selected="true" aria-controls="tab-active-session" class="nav-link active py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-active-session">
            <i class="bi bi-pencil-square text-primary me-1"></i> Active Session #<?= esc($activeSession['session_number']) ?>
          </button>
        </li>
      <?php endif; ?>
      <li class="nav-item">
        <button id="case-history-tab" type="button" role="tab" aria-selected="<?= empty($activeSession) ? 'true' : 'false' ?>" aria-controls="tab-timeline" class="nav-link <?= empty($activeSession) ? 'active' : '' ?> py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-timeline">
          <i class="bi bi-clock-history me-1"></i> Sessions & Notes History (<?= count($sessions) ?>)
        </button>
      </li>
      <li class="nav-item">
        <button id="case-followups-tab" type="button" role="tab" aria-selected="false" aria-controls="tab-followups" class="nav-link py-3 px-4 fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-followups">
          <i class="bi bi-check2-square me-1"></i> Follow-up Commitments (<?= count($followups) ?>)
        </button>
      </li>
    </ul>
  </div>

  <div class="card-body p-4 tab-content">

    <!-- TAB 1: ACTIVE SESSION EDITOR (if open) -->
    <?php if (!empty($activeSession)): ?>
      <div class="tab-pane fade show active" id="tab-active-session" role="tabpanel" aria-labelledby="case-active-tab">
        <div class="d-flex flex-wrap gap-3 justify-content-between align-items-start mb-3 gc-session-heading">
          <div>
            <h6 class="fw-bold mb-0">Consultation Session #<?= esc($activeSession['session_number']) ?> (<?= esc(ucfirst($activeSession['session_type'])) ?>)</h6>
            <small class="text-muted">Started: <?= date('h:i A · M j, Y', strtotime($activeSession['started_at'])) ?></small>
          </div>
          <div>
            <span class="badge bg-warning text-dark me-2">Session In Progress</span>
            <form action="<?= site_url('/guidance/sessions/' . $activeSession['id'] . '/close') ?>" method="POST" class="d-inline"
                                      data-confirm="Complete and close this counseling session? Save any changes first. Closing the session does not automatically finalize notes."
                                      data-confirm-title="Close counseling session"
                                      data-confirm-label="Close session"
                                      data-confirm-tone="warning">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-outline-success btn-sm">
                <i class="bi bi-check-circle me-1"></i> Close Session
              </button>
            </form>
          </div>
        </div>

        <!-- Notes editor form -->
        <form action="<?= site_url('/guidance/sessions/' . $activeSession['id'] . '/notes') ?>" method="POST">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label for="sessionNotes" class="form-label fw-semibold">Confidential Session Notes</label>
            <textarea name="notes"
                      id="sessionNotes"
                      rows="8"
                      class="form-control"
                      placeholder="Type counselor observations, client reflection, discussion points..."
                      required><?= esc($decryptedNote['text'] ?? '') ?></textarea>
            <div class="form-text small text-muted">
              Notes are encrypted with AES-256-GCM before database write. Only authenticated counsellors with oversight permissions can decrypt.
            </div>
          </div>

          <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center pt-3 border-top">
            <div class="text-muted small">
              <?php if (!empty($decryptedNote)): ?>
                Last saved version: <strong>v<?= esc($decryptedNote['version']) ?></strong> (<?= date('M j, h:i A', strtotime($decryptedNote['created_at'])) ?>)
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 gc-editor-buttons">
              <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">
                <i class="bi bi-cloud-arrow-up me-1"></i> Save Draft
              </button>
              <button type="submit" name="action" value="finalize" class="btn btn-primary">
                <i class="bi bi-lock-fill me-1"></i> Finalize Notes (New Version)
              </button>
            </div>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <!-- TAB 2: SESSIONS & NOTES TIMELINE -->
    <div class="tab-pane fade <?= empty($activeSession) ? 'show active' : '' ?>" id="tab-timeline" role="tabpanel" aria-labelledby="case-history-tab">
      <?php if (empty($sessions)): ?>
        <div class="text-center py-5 text-muted small">
          <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-50"></i>
          No counseling sessions held under this case yet. Click "Start New Session" above to begin.
        </div>
      <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($sessions as $s): ?>
            <div class="list-group-item bg-transparent p-3 border-bottom">
              <div class="gc-history-row mb-2">
                <div>
                  <h6 class="fw-bold mb-0 text-dark">
                    Session #<?= esc($s['session_number']) ?>: <?= esc(ucfirst($s['session_type'])) ?> Consultation
                  </h6>
                  <small class="text-muted">
                    <?= date('F j, Y · h:i A', strtotime($s['started_at'])) ?> · Counsellor: <?= esc($s['counsellor_name']) ?>
                  </small>
                </div>
                <div class="gc-history-actions">
                  <?php if ($s['status'] === 'in_progress'): ?>
                    <span class="badge bg-warning text-dark">In Progress</span>
                  <?php elseif ($s['status'] === 'completed'): ?>
                    <span class="badge bg-success">Completed</span>
                  <?php endif; ?>
                  <a href="<?= site_url('/guidance/sessions/' . $s['id'] . '/read') ?>" class="btn btn-outline-primary btn-sm" data-app-modal-open>
                    <i class="bi bi-eye me-1"></i> View Decrypted Note
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- TAB 3: FOLLOW-UPS -->
    <div class="tab-pane fade" id="tab-followups" role="tabpanel" aria-labelledby="case-followups-tab">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">Commitments &amp; Action Items</h6>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#newFollowupForm">
          <i class="bi bi-plus-circle me-1"></i> Add Commitment
        </button>
      </div>

      <!-- New commitment collapse form -->
      <div class="collapse mb-4" id="newFollowupForm">
        <div class="card bg-light border-0 p-3">
          <form action="<?= site_url('/guidance/cases/' . $case['id'] . '/followups') ?>" method="POST">
            <?= csrf_field() ?>
            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Target Due Date</label>
                <!-- Custom date picker: visible trigger + ISO hidden value -->
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
                <label class="form-label small fw-semibold">Action Plan / Commitment</label>
                <input type="text" name="action_plan" class="form-control form-control-sm" placeholder="e.g. Complete study timetable, practice breathing exercise" required>
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save Action Item</button>
          </form>
        </div>
      </div>

      <?php if (empty($followups)): ?>
        <div class="text-center py-5 text-muted small">
          No follow-up action items assigned yet.
        </div>
      <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($followups as $f): ?>
            <div class="list-group-item bg-transparent p-3 border-bottom gc-followup-row">
              <div>
                <span class="badge bg-<?= ($f['status'] === 'completed') ? 'success' : 'warning text-dark' ?> me-2">
                  <?= esc(ucfirst($f['status'])) ?>
                </span>
                <span class="<?= ($f['status'] === 'completed') ? 'text-decoration-line-through text-muted' : 'fw-semibold' ?>">
                  <?= esc($f['action_plan']) ?>
                </span>
                <div class="text-muted small mt-1">Due: <?= date('M j, Y', strtotime($f['due_date'])) ?></div>
              </div>

              <?php if ($f['status'] === 'pending'): ?>
                <div class="gc-followup-actions">
                <form action="<?= site_url('/guidance/followups/' . $f['id'] . '/missed') ?>" method="POST"
                      data-confirm="Explicitly mark this follow-up missed? A due date alone does not determine this outcome."
                      data-confirm-title="Missed follow-up" data-confirm-label="Mark missed" data-confirm-tone="warning">
                  <?= csrf_field() ?>
                  <label class="small gc-confirm-label"><input type="checkbox" name="confirmed" value="1" required> Confirm missed</label>
                  <button type="submit" class="btn btn-outline-warning btn-sm">Mark Missed</button>
                </form>
                <form action="<?= site_url('/guidance/followups/' . $f['id'] . '/complete') ?>" method="POST">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-check2 me-1"></i> Mark Done
                  </button>
                </form>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</div>
<?= $this->endSection() ?>
