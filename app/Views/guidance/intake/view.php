<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <?php if (!empty($referral['linked_case_id'])): ?>
    <a href="<?= esc(site_url('/guidance/cases/' . (int) $referral['linked_case_id'])) ?>" class="btn btn-success btn-sm">
      <i class="bi bi-folder2-open me-1" aria-hidden="true"></i> Open Linked Case #<?= (int) $referral['linked_case_id'] ?>
    </a>
  <?php endif; ?>
  <a href="<?= esc(site_url('/guidance/intake')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Intake Queue
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$referral           = $referral ?? [];
$subjectPerson      = $subjectPerson ?? [];
$studentProfile     = $studentProfile ?? null;
$counselors         = $counselors ?? [];
$existingCases      = $existingCases ?? [];
$catalogChecklist   = $catalogChecklist ?? [];
$priorityHints      = $priorityHints ?? ['routine', 'urgent', 'crisis'];
$riskLevels         = $riskLevels ?? ['low', 'moderate', 'high', 'imminent_danger'];
$escalationChannels = $escalationChannels ?? [];
$safetyHistory      = $safetyHistory ?? null;
$safetyDenied       = !empty($safetyDenied);
$isQualifiedSafety  = !empty($isQualifiedSafety);
$safetyGateOpen     = !empty($safetyGateOpen);
$canLinkCase        = !empty($canLinkCase);
$actor              = $actor ?? [];

$refId  = (int) ($referral['id'] ?? 0);
$status = (string) ($referral['status'] ?? 'submitted');

$rawSelections = $referral['selections'] ?? [];
$concernKeys   = [];
$otherSpecify  = '';
if (isset($rawSelections['items']) && is_array($rawSelections['items'])) {
    $concernKeys  = $rawSelections['items'];
    $otherSpecify = (string) ($rawSelections['other_specify'] ?? '');
} elseif (is_array($rawSelections)) {
    $concernKeys = $rawSelections;
}

$riskBadge = static fn (string $level): string => match ($level) {
    'imminent_danger' => 'bg-danger text-white',
    'high'            => 'bg-danger-subtle text-danger border-danger-subtle',
    'moderate'        => 'bg-warning-subtle text-warning border-warning-subtle',
    default           => 'bg-success-subtle text-success border-success-subtle',
};

$priorityBadge = static fn (string $hint): string => match ($hint) {
    'crisis' => 'bg-danger text-white',
    'urgent' => 'bg-warning text-dark',
    default  => 'bg-secondary-subtle text-secondary border',
};

$isTerminal = in_array($status, ['declined', 'closed', 'withdrawn', 'external_handoff'], true);
$canWithdraw = in_array($status, ['draft', 'submitted', 'received'], true)
    && (int) ($referral['referrer_user_id'] ?? 0) === (int) ($actor['id'] ?? 0);

$assessments = $safetyHistory['assessments'] ?? [];
$events      = $safetyHistory['events'] ?? [];
?>

<div class="guidance-intake-workspace"
     data-refresh-owner="guidance.intake.<?= $refId ?>"
     data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div data-refresh-region="workspace" data-refresh-heading tabindex="-1">

    <!-- Identity & Status Header Card -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <span class="badge bg-dark-subtle text-body border fw-bold"><?= esc($referral['referral_number']) ?></span>
              <span class="badge text-capitalize <?= $priorityBadge((string) $referral['priority_hint']) ?>">
                Attention Hint: <?= esc($referral['priority_hint']) ?>
              </span>
              <span class="badge bg-primary-subtle text-primary border text-capitalize">
                Status: <?= esc(str_replace('_', ' ', $status)) ?>
              </span>
            </div>
            <h2 class="h5 fw-bold mb-1">
              <?= esc(trim(($subjectPerson['last_name'] ?? 'Subject') . ', ' . ($subjectPerson['first_name'] ?? ''))) ?>
              <small class="text-muted fw-normal">(MIS ID: <?= esc($subjectPerson['mis_id'] ?? '—') ?>)</small>
            </h2>
            <div class="small text-muted">
              <?= esc(ucfirst((string) ($subjectPerson['kind'] ?? 'student'))) ?>
              <?php if ($studentProfile !== null): ?>
                · <?= esc($studentProfile['program'] ?? '') ?> Year <?= esc((string) ($studentProfile['year_level'] ?? '')) ?>
                <?= !empty($studentProfile['department']) ? ' (' . esc($studentProfile['department']) . ')' : '' ?>
              <?php endif; ?>
              · Referrer: <strong><?= esc($referral['referrer_name_snapshot']) ?></strong>
              (<?= esc($referral['referrer_designation_snapshot']) ?>, <?= esc($referral['referrer_department_snapshot']) ?>)
            </div>
          </div>
          <div>
            <span class="badge bg-success-subtle text-success border border-success-subtle p-2">
              <i class="bi bi-shield-lock-fill me-1" aria-hidden="true"></i> Decrypted Payload · Audited Read
            </span>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <!-- Left Column: Referral Details, Chronology & State Actions -->
      <div class="col-12 col-lg-7">

        <!-- Decrypted Referral Payload -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-transparent py-3">
            <h3 class="h6 fw-bold mb-0"><i class="bi bi-file-earmark-medical me-1" aria-hidden="true"></i> Presenting Concerns &amp; Narrative</h3>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <div class="small text-muted fw-semibold mb-2">Concern Catalog Selections (v<?= esc($referral['concern_catalog_version'] ?? '1.0.0') ?>)</div>
              <?php if ($concernKeys === []): ?>
                <span class="small text-muted">None specified.</span>
              <?php else: ?>
                <div class="d-flex flex-wrap gap-1">
                  <?php foreach ($concernKeys as $k => $v): ?>
                    <?php
                    $code  = is_string($v) ? $v : (string) $k;
                    $label = $catalogChecklist[$code] ?? (is_string($v) ? ucwords(str_replace('_', ' ', $v)) : (string) $k . ': ' . json_encode($v));
                    ?>
                    <span class="badge bg-info-subtle text-info border border-info-subtle"><?= esc($label) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($otherSpecify !== ''): ?>
                <div class="small mt-2"><strong>Other specified:</strong> <?= esc($otherSpecify) ?></div>
              <?php endif; ?>
            </div>

            <div class="mb-3">
              <div class="small text-muted fw-semibold mb-1">Referral Narrative</div>
              <div class="p-3 rounded border bg-body-tertiary small" style="white-space: pre-wrap;"><?= esc($referral['narrative'] ?? '') ?></div>
            </div>

            <?php if (!empty($referral['prior_actions'])): ?>
              <div class="mb-3">
                <div class="small text-muted fw-semibold mb-1">Prior Actions Taken by Referrer</div>
                <div class="p-3 rounded border bg-body-tertiary small" style="white-space: pre-wrap;"><?= esc($referral['prior_actions']) ?></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($referral['triage_notes'])): ?>
              <div class="mb-3">
                <div class="small text-muted fw-semibold mb-1">Guidance Triage Notes</div>
                <div class="p-3 rounded border bg-body-tertiary small" style="white-space: pre-wrap;"><?= esc($referral['triage_notes']) ?></div>
              </div>
            <?php endif; ?>

            <?php if (!empty($referral['decline_reason'])): ?>
              <div class="alert alert-danger small mb-0">
                <strong>Decline Reason:</strong> <?= esc($referral['decline_reason']) ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($referral['external_facility'])): ?>
              <div class="alert alert-secondary small mb-0">
                <strong>External Handoff Facility:</strong> <?= esc($referral['external_facility']) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Intake Lifecycle Actions -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-transparent py-3">
            <h3 class="h6 fw-bold mb-0"><i class="bi bi-sliders me-1" aria-hidden="true"></i> Intake Workflow Actions</h3>
          </div>
          <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
              <?php if ($status === 'submitted'): ?>
                <form action="<?= esc(site_url('/guidance/intake/' . $refId . '/receive'), 'attr') ?>" method="POST" data-background-submit>
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-info btn-sm">
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Mark Received
                  </button>
                </form>
              <?php endif; ?>

              <?php if ($canWithdraw): ?>
                <form action="<?= esc(site_url('/guidance/intake/' . $refId . '/withdraw'), 'attr') ?>"
                      method="POST"
                      data-background-submit
                      data-confirm="Withdraw this referral prior to triage?"
                      data-confirm-title="Withdraw Referral"
                      data-confirm-label="Withdraw"
                      data-confirm-tone="danger">
                  <?= csrf_field() ?>
                  <input type="hidden" name="confirmed" value="1">
                  <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i> Withdraw Referral
                  </button>
                </form>
              <?php endif; ?>
            </div>

            <?php if (!$isTerminal): ?>
              <!-- Triage Attention Hint -->
              <form action="<?= esc(site_url('/guidance/intake/' . $refId . '/triage'), 'attr') ?>" method="POST" data-background-submit class="p-3 rounded border bg-body-tertiary mb-3">
                <?= csrf_field() ?>
                <h4 class="h6 fw-semibold mb-2">Update Intake Attention Hint &amp; Triage Notes</h4>
                <div class="row g-2">
                  <div class="col-md-4">
                    <label for="triagePriorityHint" class="form-label small fw-semibold mb-1">Priority Hint</label>
                    <select id="triagePriorityHint" name="priority_hint" class="form-select form-select-sm" required>
                      <?php foreach ($priorityHints as $hint): ?>
                        <option value="<?= esc($hint, 'attr') ?>" <?= ($referral['priority_hint'] ?? '') === $hint ? 'selected' : '' ?>>
                          <?= esc(ucfirst($hint)) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-8">
                    <label for="triageNotes" class="form-label small fw-semibold mb-1">Triage Notes</label>
                    <input type="text" id="triageNotes" name="triage_notes" class="form-control form-control-sm" value="<?= esc($referral['triage_notes'] ?? '', 'attr') ?>" placeholder="Intake coordination notes">
                  </div>
                </div>
                <div class="mt-2 text-end">
                  <button type="submit" class="btn btn-outline-primary btn-sm">Save Triage</button>
                </div>
              </form>

              <!-- Accept & Assign Counselor -->
              <form action="<?= esc(site_url('/guidance/intake/' . $refId . '/accept'), 'attr') ?>" method="POST" data-background-submit class="p-3 rounded border bg-body-tertiary mb-3">
                <?= csrf_field() ?>
                <h4 class="h6 fw-semibold mb-2">Accept Referral</h4>
                <div class="row g-2 align-items-end">
                  <div class="col-md-8">
                    <label for="assignCounselorSelect" class="form-label small fw-semibold mb-1">Assign Counselor (Optional)</label>
                    <select id="assignCounselorSelect" name="assigned_counsellor_user_id" class="form-select form-select-sm">
                      <option value="">Keep unassigned / decide later</option>
                      <?php foreach ($counselors as $c): ?>
                        <?php $cName = trim((string) ($c['first_name'] ?? '') . ' ' . (string) ($c['last_name'] ?? '')); ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($referral['assigned_counsellor_user_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                          <?= esc(($cName !== '' ? $cName : $c['username']) . ' (' . $c['role'] . ')') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4 d-grid">
                    <button type="submit" class="btn btn-success btn-sm">Accept Referral</button>
                  </div>
                </div>
              </form>

              <!-- Link or Create Counseling Case -->
              <?php if ($canLinkCase && empty($referral['linked_case_id'])): ?>
                <form action="<?= esc(site_url('/guidance/intake/' . $refId . '/link-case'), 'attr') ?>" method="POST" data-background-submit class="p-3 rounded border bg-body-tertiary mb-3">
                  <?= csrf_field() ?>
                  <h4 class="h6 fw-semibold mb-2">Link to Counseling Case</h4>
                  <div class="row g-2 align-items-end">
                    <div class="col-md-8">
                      <label for="existingCaseSelect" class="form-label small fw-semibold mb-1">Target Case</label>
                      <select id="existingCaseSelect" name="existing_case_id" class="form-select form-select-sm">
                        <option value="">Open a brand-new counseling case for this referral</option>
                        <?php foreach ($existingCases as $ec): ?>
                          <option value="<?= (int) $ec['id'] ?>">
                            <?= esc($ec['case_number'] . ' — ' . $ec['title'] . ' (' . $ec['status'] . ')') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4 d-grid">
                      <button type="submit" class="btn btn-primary btn-sm">Link / Create Case</button>
                    </div>
                  </div>
                </form>
              <?php endif; ?>

              <!-- Decline or External Handoff -->
              <?php if ($status !== 'case_linked'): ?>
                <div class="accordion" id="intakeTerminalActions">
                  <div class="accordion-item">
                    <h4 class="accordion-header">
                      <button class="accordion-button collapsed py-2 small fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDecline">
                        Decline Referral
                      </button>
                    </h4>
                    <div id="collapseDecline" class="accordion-collapse collapse" data-bs-parent="#intakeTerminalActions">
                      <div class="accordion-body">
                        <form action="<?= esc(site_url('/guidance/intake/' . $refId . '/decline'), 'attr') ?>"
                              method="POST"
                              data-background-submit
                              data-confirm="Decline this referral? This closes the intake referral."
                              data-confirm-title="Decline Referral"
                              data-confirm-label="Decline"
                              data-confirm-tone="danger">
                          <?= csrf_field() ?>
                          <input type="hidden" name="confirmed" value="1">
                          <div class="mb-2">
                            <label for="declineReason" class="form-label small fw-semibold">Mandatory Decline Reason</label>
                            <textarea id="declineReason" name="decline_reason" rows="2" class="form-control form-control-sm" required placeholder="Provide rationale for declining"></textarea>
                          </div>
                          <button type="submit" class="btn btn-outline-danger btn-sm">Confirm Decline</button>
                        </form>
                      </div>
                    </div>
                  </div>

                  <div class="accordion-item">
                    <h4 class="accordion-header">
                      <button class="accordion-button collapsed py-2 small fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseHandoff">
                        External Facility Handoff
                      </button>
                    </h4>
                    <div id="collapseHandoff" class="accordion-collapse collapse" data-bs-parent="#intakeTerminalActions">
                      <div class="accordion-body">
                        <form action="<?= esc(site_url('/guidance/intake/' . $refId . '/external-handoff'), 'attr') ?>"
                              method="POST"
                              data-background-submit
                              data-confirm="Record external facility handoff and close this intake?"
                              data-confirm-title="External Handoff"
                              data-confirm-label="Record Handoff"
                              data-confirm-tone="warning">
                          <?= csrf_field() ?>
                          <input type="hidden" name="confirmed" value="1">
                          <div class="mb-2">
                            <label for="externalFacility" class="form-label small fw-semibold">External Facility / Specialist</label>
                            <input type="text" id="externalFacility" name="external_facility" class="form-control form-control-sm" required placeholder="Hospital, external psychologist, or agency">
                          </div>
                          <div class="mb-2">
                            <label for="handoffNotes" class="form-label small fw-semibold">Handoff Notes</label>
                            <textarea id="handoffNotes" name="handoff_notes" rows="2" class="form-control form-control-sm" placeholder="Summary of external referral"></textarea>
                          </div>
                          <button type="submit" class="btn btn-outline-secondary btn-sm">Record External Handoff</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Chronology -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-transparent py-3">
            <h3 class="h6 fw-bold mb-0"><i class="bi bi-clock-history me-1" aria-hidden="true"></i> Referral Chronology</h3>
          </div>
          <div class="card-body">
            <ul class="list-unstyled small mb-0">
              <?php
              $milestones = [
                  'created_at'   => 'Draft Created',
                  'submitted_at' => 'Submitted by Referrer',
                  'received_at'  => 'Received by Guidance',
                  'triaged_at'   => 'Triaged',
                  'responded_at' => 'Responded / Dispositioned',
                  'closed_at'    => 'Closed / Terminal State',
              ];
              foreach ($milestones as $col => $label):
                if (!empty($referral[$col])): ?>
                  <li class="d-flex justify-content-between py-1 border-bottom">
                    <span class="fw-semibold"><?= esc($label) ?></span>
                    <span class="text-muted"><?= esc(date('M j, Y g:i A', strtotime((string) $referral[$col]))) ?></span>
                  </li>
                <?php endif;
              endforeach; ?>
            </ul>
          </div>
        </div>

      </div>

      <!-- Right Column: Clinical Safety Triage & Escalation Panel -->
      <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
            <div>
              <h3 class="h6 fw-bold mb-0"><i class="bi bi-shield-plus me-1" aria-hidden="true"></i> Clinical Safety Triage &amp; Escalation</h3>
              <small class="text-muted">Authoritative practitioner risk determination (separate from referrer hint)</small>
            </div>
          </div>
          <div class="card-body">
            <?php if (!$safetyGateOpen): ?>
              <div class="alert alert-warning small mb-3">
                <strong>Safety SOP Gate Pending (<code>safety_escalation_sop_signoff</code>):</strong>
                Live crisis escalation dispatch is blocked until Guidance Head, QA Office, and DPO sign off on the Safety Escalation SOP.
              </div>
            <?php endif; ?>

            <?php if (!$isQualifiedSafety): ?>
              <div class="alert alert-secondary small mb-3">
                <i class="bi bi-person-lock me-1" aria-hidden="true"></i>
                Only users with an active qualified Guidance practitioner profile and <code>guidance.safety.triage</code> permission may record clinical safety assessments or crisis escalations.
              </div>
            <?php else: ?>
              <!-- Record Clinical Safety Assessment -->
              <form action="<?= esc(site_url('/guidance/safety/assess'), 'attr') ?>" method="POST" data-background-submit class="p-3 rounded border bg-body-tertiary mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="person_id" value="<?= (int) $referral['subject_person_id'] ?>">
                <input type="hidden" name="intake_referral_id" value="<?= $refId ?>">
                <?php if (!empty($referral['linked_case_id'])): ?>
                  <input type="hidden" name="case_id" value="<?= (int) $referral['linked_case_id'] ?>">
                <?php endif; ?>

                <h4 class="h6 fw-semibold mb-2">Record Authoritative Safety Assessment</h4>
                <div class="mb-2">
                  <label for="safetyRiskLevel" class="form-label small fw-semibold mb-1">Clinical Risk Level</label>
                  <select id="safetyRiskLevel" name="risk_level" class="form-select form-select-sm" required>
                    <?php foreach ($riskLevels as $rl): ?>
                      <option value="<?= esc($rl, 'attr') ?>"><?= esc(ucwords(str_replace('_', ' ', $rl))) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="d-flex gap-3 mb-2">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="selfHarmRisk" name="self_harm_risk" value="1">
                    <label class="form-check-label small" for="selfHarmRisk">Self-harm / suicide risk</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="harmOthersRisk" name="harm_to_others_risk" value="1">
                    <label class="form-check-label small" for="harmOthersRisk">Harm to others risk</label>
                  </div>
                </div>

                <div class="mb-2">
                  <label for="safetyIndicators" class="form-label small fw-semibold mb-1">Clinical Indicators (one per line or comma-separated, encrypted)</label>
                  <textarea id="safetyIndicators" name="indicators" rows="2" class="form-control form-control-sm" placeholder="E.g., expressed hopelessness, acute panic, recent loss"></textarea>
                </div>

                <div class="mb-2">
                  <label for="immediateActions" class="form-label small fw-semibold mb-1">Immediate Clinical Actions Taken <span class="text-danger">*</span></label>
                  <textarea id="immediateActions" name="immediate_actions_taken" rows="2" class="form-control form-control-sm" required placeholder="Containment, grounding, or stabilization steps"></textarea>
                </div>

                <div class="mb-3">
                  <label for="safetyPlan" class="form-label small fw-semibold mb-1">Safety Plan (Optional, encrypted)</label>
                  <textarea id="safetyPlan" name="safety_plan" rows="2" class="form-control form-control-sm" placeholder="Protective contacts, coping strategies, follow-up plan"></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-sm w-100">
                  <i class="bi bi-shield-check me-1" aria-hidden="true"></i> Record Immutable Safety Assessment
                </button>
              </form>

              <!-- Record Crisis Escalation (Requires existing assessment + SOP gate) -->
              <?php if ($assessments !== []): ?>
                <form action="<?= esc(site_url('/guidance/safety/escalate'), 'attr') ?>"
                      method="POST"
                      data-background-submit
                      data-confirm="Log and route this crisis safety escalation?"
                      data-confirm-title="Record Crisis Escalation"
                      data-confirm-label="Dispatch Escalation"
                      data-confirm-tone="danger"
                      class="p-3 rounded border bg-body-tertiary mb-3">
                  <?= csrf_field() ?>
                  <input type="hidden" name="intake_referral_id" value="<?= $refId ?>">
                  <h4 class="h6 fw-semibold mb-2 text-danger"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> Log Crisis Escalation</h4>

                  <div class="mb-2">
                    <label for="escAssessmentId" class="form-label small fw-semibold mb-1">Linked Safety Assessment</label>
                    <select id="escAssessmentId" name="safety_assessment_id" class="form-select form-select-sm" required>
                      <?php foreach ($assessments as $a): ?>
                        <option value="<?= (int) $a['id'] ?>">
                          #<?= (int) $a['id'] ?> — <?= esc(ucwords(str_replace('_', ' ', (string) $a['risk_level']))) ?> (<?= esc(date('M j, g:i A', strtotime((string) $a['assessed_at']))) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-2">
                    <label for="escChannel" class="form-label small fw-semibold mb-1">Escalation Channel</label>
                    <select id="escChannel" name="escalation_channel" class="form-select form-select-sm" required>
                      <?php foreach ($escalationChannels as $ch): ?>
                        <option value="<?= esc($ch, 'attr') ?>"><?= esc(ucwords(str_replace('_', ' ', $ch))) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-2">
                    <label for="escRecipient" class="form-label small fw-semibold mb-1">Recipient Agency / Official</label>
                    <input type="text" id="escRecipient" name="recipient_agency_or_person" class="form-control form-control-sm" required placeholder="E.g., University Physician / Campus Crisis Team">
                  </div>

                  <div class="mb-3">
                    <label for="escSummary" class="form-label small fw-semibold mb-1">Handover Summary Notes</label>
                    <textarea id="escSummary" name="summary_notes" rows="2" class="form-control form-control-sm" required placeholder="Brief escalation handover details"></textarea>
                  </div>

                  <button type="submit"
                          class="btn btn-danger btn-sm w-100"
                          <?= !$safetyGateOpen ? 'disabled title="Disabled until safety_escalation_sop_signoff gate is enabled"' : '' ?>>
                    <i class="bi bi-broadcast me-1" aria-hidden="true"></i> Record Crisis Escalation
                  </button>
                </form>
              <?php endif; ?>
            <?php endif; ?>

            <!-- Decrypted Safety History -->
            <h4 class="h6 fw-bold mb-2">Subject Safety History</h4>
            <?php if ($safetyDenied): ?>
              <p class="small text-muted mb-0">You do not have permission to view safety assessment history.</p>
            <?php elseif ($events === []): ?>
              <p class="small text-muted mb-0">No clinical safety assessments or escalations recorded for this subject yet.</p>
            <?php else: ?>
              <div class="d-flex flex-column gap-2">
                <?php foreach ($events as $ev): ?>
                  <?php if ($ev['event_type'] === 'safety_assessment'): ?>
                    <div class="p-2 rounded border bg-body-tertiary small">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge border text-capitalize <?= $riskBadge((string) $ev['risk_level']) ?>">
                          <?= esc(str_replace('_', ' ', (string) $ev['risk_level'])) ?> Risk
                        </span>
                        <span class="text-muted"><?= esc(date('M j, Y g:i A', strtotime((string) $ev['timestamp']))) ?></span>
                      </div>
                      <div class="mb-1">
                        <?php if (!empty($ev['self_harm_risk'])): ?><span class="badge bg-danger me-1">Self-Harm Risk</span><?php endif; ?>
                        <?php if (!empty($ev['harm_to_others_risk'])): ?><span class="badge bg-danger me-1">Harm-to-Others Risk</span><?php endif; ?>
                      </div>
                      <div><strong>Immediate Actions:</strong> <?= esc($ev['immediate_actions_taken']) ?></div>
                      <?php if (!empty($ev['indicators'])): ?>
                        <div class="mt-1"><strong>Indicators:</strong> <?= esc(implode(', ', (array) $ev['indicators'])) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($ev['safety_plan'])): ?>
                        <div class="mt-1"><strong>Safety Plan:</strong> <?= esc($ev['safety_plan']) ?></div>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="p-2 rounded border border-danger-subtle bg-danger-subtle small">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>Escalation: <?= esc(ucwords(str_replace('_', ' ', (string) $ev['channel']))) ?></strong>
                        <span class="text-muted"><?= esc(date('M j, Y g:i A', strtotime((string) $ev['timestamp']))) ?></span>
                      </div>
                      <div><strong>Recipient:</strong> <?= esc($ev['recipient']) ?></div>
                      <div><strong>Summary:</strong> <?= esc($ev['summary_notes']) ?></div>
                      <?php if (!empty($ev['acknowledged_at'])): ?>
                        <div class="mt-1 text-success">
                          <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
                          Acknowledged by <?= esc($ev['acknowledged_by']) ?> on <?= esc(date('M j, Y g:i A', strtotime((string) $ev['acknowledged_at']))) ?>
                        </div>
                      <?php elseif ($isQualifiedSafety): ?>
                        <form action="<?= esc(site_url('/guidance/safety/escalations/' . (int) $ev['id'] . '/ack'), 'attr') ?>" method="POST" data-background-submit class="mt-2 d-flex gap-1">
                          <?= csrf_field() ?>
                          <input type="hidden" name="intake_referral_id" value="<?= $refId ?>">
                          <input type="text" name="acknowledged_by" class="form-control form-control-sm" value="<?= esc($actor['name'] ?? '', 'attr') ?>" placeholder="Acknowledging official" required>
                          <button type="submit" class="btn btn-outline-success btn-sm flex-shrink-0">Acknowledge</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
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
