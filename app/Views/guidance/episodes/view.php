<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<?php
$episode = $episode ?? [];
$person = $person ?? [];
$primaryCounselor = $primaryCounselor ?? [];
$timeline = $timeline ?? [];
$qualifiedCounselors = $qualifiedCounselors ?? [];
$epId = (int) ($episode['id'] ?? 0);
$epStatus = (string) ($episode['status'] ?? 'open');
$personId = (int) ($episode['person_id'] ?? 0);
$caseId = (int) ($episode['case_id'] ?? 0);
$isClosedOrTransferred = in_array($epStatus, ['closed', 'transferred'], true);
?>
<div class="ce-page-actions">
  <a href="<?= site_url('guidance/episodes') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> All Episodes
  </a>
  <a href="<?= site_url('guidance/call-slips?person_id=' . $personId . '&episode_id=' . $epId) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-receipt" aria-hidden="true"></i> Call Slips
  </a>
  <?php if ($caseId > 0): ?>
    <a href="<?= site_url('guidance/cases/' . $caseId) ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-folder2-open" aria-hidden="true"></i> View Linked Case
    </a>
  <?php endif; ?>
  <?php if (! $isClosedOrTransferred): ?>
    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#transferEpisodeModal">
      <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Transfer Episode
    </button>
    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#closeEpisodeModal">
      <i class="bi bi-lock" aria-hidden="true"></i> Close Episode
    </button>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$statusDotClass = match ($epStatus) {
    'active'      => 'ce-status-emerald',
    'open'        => 'ce-status-sky',
    'transferred' => 'ce-status-amber',
    default       => 'ce-status-slate',
};
$clientName = trim((string) (($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
if ($clientName === '') {
    $clientName = 'Person #' . $personId;
}
$initials = strtoupper(
    substr((string) ($person['first_name'] ?? 'C'), 0, 1) .
    substr((string) ($person['last_name'] ?? 'E'), 0, 1)
);
$counselorName = trim((string) (($primaryCounselor['first_name'] ?? '') . ' ' . ($primaryCounselor['last_name'] ?? '')));
if ($counselorName === '') {
    $counselorName = (string) ($primaryCounselor['username'] ?? 'User #' . (int) ($episode['primary_counselor_user_id'] ?? 0));
}

$kindMeta = [
    'episode_opened' => ['icon' => 'bi-play-circle', 'mod' => 'ce-entry-card--normal', 'label' => 'Episode Opened'],
    'referral'       => ['icon' => 'bi-inbox', 'mod' => 'ce-entry-card--addendum', 'label' => 'Intake Referral'],
    'appointment'    => ['icon' => 'bi-calendar-event', 'mod' => 'ce-entry-card--addendum', 'label' => 'Appointment'],
    'call_slip'      => ['icon' => 'bi-receipt', 'mod' => 'ce-entry-card--pending', 'label' => 'Call Slip'],
    'case'           => ['icon' => 'bi-folder2', 'mod' => 'ce-entry-card--normal', 'label' => 'Guidance Case'],
    'session'        => ['icon' => 'bi-chat-left-text', 'mod' => 'ce-entry-card--normal', 'label' => 'Counseling Session'],
    'form'           => ['icon' => 'bi-file-earmark-medical', 'mod' => 'ce-entry-card--addendum', 'label' => 'Controlled Form / Evaluation'],
    'episode_closed' => ['icon' => 'bi-check2-circle', 'mod' => '', 'label' => 'Episode Closed'],
];
?>
<section class="guidance-ui ce-encounter">

  <!-- Episode Identity & Context Header Card -->
  <div class="ce-patient-card">
    <div class="ce-patient-top">
      <div class="ce-patient-identity">
        <div class="ce-avatar" aria-hidden="true"><?= esc($initials !== '' ? $initials : 'EP') ?></div>
        <div style="min-width: 0;">
          <div class="ce-patient-heading">
            <span class="ce-tag ce-mono fw-bold">Care Episode EP-<?= str_pad((string) $epId, 5, '0', STR_PAD_LEFT) ?></span>
            <h4 class="ce-patient-name"><?= esc($clientName) ?></h4>
            <?php if (! empty($person['kind'])): ?>
              <span class="ce-tag ce-tag-upper"><?= esc((string) $person['kind']) ?></span>
            <?php endif; ?>
            <span class="ce-status-dot <?= $statusDotClass ?>"><?= esc(ucfirst($epStatus)) ?></span>
          </div>
          <div class="ce-patient-meta">
            <span>MIS / Student ID: <strong class="ce-mono"><?= esc((string) ($person['mis_id'] ?? 'N/A')) ?></strong></span>
            <span class="ce-meta-sep" aria-hidden="true">·</span>
            <span>Primary Counselor: <strong><?= esc($counselorName) ?></strong></span>
          </div>
        </div>
      </div>

      <?php if ($personId > 0): ?>
        <a href="<?= site_url('guidance/students/' . $personId . '/cumulative') ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">
          <i class="bi bi-person-lines-fill" aria-hidden="true"></i> Cumulative Record
        </a>
      <?php endif; ?>
    </div>

    <div class="ce-flags-bar">
      <div class="ce-flag-cell ce-flag-cell--wide">
        <span class="ce-flag-label"><i class="bi bi-person-badge" aria-hidden="true"></i> Primary Counselor</span>
        <span class="ce-flag-value"><?= esc($counselorName) ?></span>
      </div>
      <div class="ce-flag-cell ce-flag-cell--wide">
        <span class="ce-flag-label"><i class="bi bi-calendar2-plus" aria-hidden="true"></i> Opened At</span>
        <span class="ce-flag-value ce-mono">
          <?= ! empty($episode['opened_at']) ? esc(date('M d, Y g:i A', strtotime((string) $episode['opened_at']))) : '—' ?>
        </span>
      </div>
      <div class="ce-flag-cell ce-flag-cell--wide">
        <span class="ce-flag-label"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Closed At</span>
        <span class="ce-flag-value ce-mono">
          <?= ! empty($episode['closed_at']) ? esc(date('M d, Y g:i A', strtotime((string) $episode['closed_at']))) : '—' ?>
        </span>
      </div>
      <div class="ce-flag-cell">
        <span class="ce-flag-label"><i class="bi bi-clock-history" aria-hidden="true"></i> Events</span>
        <span class="ce-flag-value ce-mono"><?= count($timeline) ?></span>
      </div>
    </div>

    <?php if (! empty($episode['closure_reason']) || ! empty($episode['closure_notes'])): ?>
      <div class="ce-card-body border-top">
        <div class="ce-kv-box">
          <div class="ce-kv-label">Closure Summary</div>
          <?php if (! empty($episode['closure_reason'])): ?>
            <div class="small"><strong>Reason:</strong> <?= esc((string) $episode['closure_reason']) ?></div>
          <?php endif; ?>
          <?php if (! empty($episode['closure_notes'])): ?>
            <div class="small mt-1"><?= nl2br(esc((string) $episode['closure_notes'])) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Episode Quick Actions Card -->
  <div class="ce-card">
    <div class="ce-card-header">
      <h2 class="ce-card-title">
        <i class="bi bi-lightning-charge" aria-hidden="true"></i> Episode Quick Actions
      </h2>
    </div>
    <div class="ce-card-body">
      <div class="ge-quick-actions-grid">
        <a href="<?= site_url('guidance/call-slips?person_id=' . $personId . '&episode_id=' . $epId) ?>" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-receipt" aria-hidden="true"></i> Issue / Manage Call Slips
        </a>
        <a href="<?= site_url('guidance/clinical/forms/new/psychosocial_support_interview?person_id=' . $personId . '&episode_id=' . $epId . ($caseId > 0 ? '&case_id=' . $caseId : '')) ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> New Psychosocial Interview (PIF-008)
        </a>
        <a href="<?= site_url('guidance/clinical/forms/new/psychosocial_support_contact?person_id=' . $personId . '&episode_id=' . $epId . ($caseId > 0 ? '&case_id=' . $caseId : '')) ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-file-earmark-text" aria-hidden="true"></i> New Psychosocial Contact (PCF-008)
        </a>
        <?php if ($caseId > 0): ?>
          <a href="<?= site_url('guidance/cases/' . $caseId) ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-folder2-open" aria-hidden="true"></i> Open Case Workspace (#<?= $caseId ?>)
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Unified Longitudinal Timeline -->
  <div class="ce-card">
    <div class="ce-card-header">
      <h2 class="ce-card-title">
        <i class="bi bi-clock-history" aria-hidden="true"></i> Unified Longitudinal Timeline
      </h2>
      <span class="ce-tag ce-mono"><?= count($timeline) ?> events</span>
    </div>

    <div class="ce-card-body">
      <?php if (empty($timeline)): ?>
        <div class="ce-empty-row">
          <i class="bi bi-clock-history" aria-hidden="true"></i>
          No timeline events have been recorded for this episode yet.
        </div>
      <?php else: ?>
        <div class="ce-entry-list">
          <?php foreach ($timeline as $item): ?>
            <?php
              $kind = (string) ($item['kind'] ?? 'form');
              $meta = $kindMeta[$kind] ?? ['icon' => 'bi-dot', 'mod' => '', 'label' => ucfirst($kind)];
              $rec = is_array($item['record'] ?? null) ? $item['record'] : [];
              $ts = (string) ($item['timestamp'] ?? '');
            ?>
            <div class="ce-entry-card <?= esc($meta['mod']) ?>">
              <div class="ge-timeline-item">
                <div class="ge-timeline-main">
                  <span class="ge-timeline-icon" aria-hidden="true">
                    <i class="bi <?= esc($meta['icon']) ?>"></i>
                  </span>
                  <div class="ge-timeline-content">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <span class="ce-entry-title"><?= esc((string) ($item['title'] ?? $meta['label'])) ?></span>
                      <span class="ce-tag"><?= esc($meta['label']) ?></span>
                      <?php if (! empty($item['status'])): ?>
                        <span class="ce-tag"><?= esc((string) $item['status']) ?></span>
                      <?php endif; ?>
                    </div>
                    <?php if (! empty($item['detail'])): ?>
                      <div class="ce-entry-notes"><?= esc((string) $item['detail']) ?></div>
                    <?php endif; ?>

                    <?php if ($kind === 'appointment' && ! empty($rec['id'])): ?>
                      <div class="mt-2 d-flex flex-wrap gap-2">
                        <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=single&mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                          <i class="bi bi-file-pdf" aria-hidden="true"></i> Preview Single A4
                        </a>
                        <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=quad&mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                          <i class="bi bi-grid-2x2" aria-hidden="true"></i> Preview 4-Up Tear-Off
                        </a>
                        <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=single&mode=issue') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                          Issue Single A4
                        </a>
                        <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=quad&mode=issue') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                          Issue 4-Up Sheet
                        </a>
                      </div>
                    <?php elseif ($kind === 'call_slip' && ! empty($rec['id'])): ?>
                      <div class="mt-2 d-flex flex-wrap gap-2">
                        <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=single&mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                          <i class="bi bi-file-pdf" aria-hidden="true"></i> Preview Single A4
                        </a>
                        <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=quad&mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                          <i class="bi bi-grid-2x2" aria-hidden="true"></i> Preview 4-Up Tear-Off
                        </a>
                        <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=single&mode=issue') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                          Issue Single A4
                        </a>
                        <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=quad&mode=issue') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                          Issue 4-Up Sheet
                        </a>
                      </div>
                    <?php elseif ($kind === 'case' && ! empty($rec['id'])): ?>
                      <div class="mt-2">
                        <a href="<?= site_url('guidance/cases/' . (int) $rec['id']) ?>" class="btn btn-sm btn-outline-primary">
                          Open Case Workspace
                        </a>
                      </div>
                    <?php elseif ($kind === 'form' && ! empty($rec['id'])): ?>
                      <div class="mt-2 d-flex flex-wrap gap-2">
                        <a href="<?= site_url('guidance/clinical/forms/' . (int) $rec['id']) ?>" class="btn btn-sm btn-outline-primary">
                          Open Clinical Form
                        </a>
                        <a href="<?= site_url('guidance/clinical/forms/' . (int) $rec['id'] . '/pdf?mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                          Preview PDF
                        </a>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="ge-timeline-time ce-mono">
                  <?= $ts !== '' ? esc(date('M d, Y g:i A', strtotime($ts))) : '—' ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (! $isClosedOrTransferred): ?>
    <!-- Close Episode Modal -->
    <div class="modal fade" id="closeEpisodeModal" tabindex="-1" aria-labelledby="closeEpisodeModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <form method="post" action="<?= site_url('guidance/episodes/' . $epId . '/close') ?>" class="modal-content gc-modal-scope"
              data-background-submit
              data-confirm="Close this care episode? Once closed, new clinical entries will be locked unless reopened under policy."
              data-confirm-title="Close Care Episode"
              data-confirm-label="Yes, Close Episode"
              data-confirm-tone="danger">
          <?= csrf_field() ?>
          <div class="modal-header">
            <h5 class="modal-title" id="closeEpisodeModalLabel">
              <i class="bi bi-lock me-2 text-danger" aria-hidden="true"></i>Close Care Episode
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="closureReason" class="form-label fw-semibold">Closure Reason <span class="text-danger">*</span></label>
              <input type="text" id="closureReason" name="closure_reason" class="form-control" required maxlength="255" placeholder="e.g., Counseling goals met / Referral completed">
            </div>
            <div class="mb-0">
              <label for="closureNotes" class="form-label fw-semibold">Closure Notes</label>
              <textarea id="closureNotes" name="closure_notes" rows="3" class="form-control" placeholder="Optional transition recommendations or summary..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Close Episode</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Transfer Episode Modal -->
    <div class="modal fade" id="transferEpisodeModal" tabindex="-1" aria-labelledby="transferEpisodeModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <form method="post" action="<?= site_url('guidance/episodes/' . $epId . '/transfer') ?>" class="modal-content gc-modal-scope" data-background-submit>
          <?= csrf_field() ?>
          <div class="modal-header">
            <h5 class="modal-title" id="transferEpisodeModalLabel">
              <i class="bi bi-arrow-left-right me-2 text-primary" aria-hidden="true"></i>Transfer Care Episode
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="newCounselorUserId" class="form-label fw-semibold">Receiving Qualified Counselor <span class="text-danger">*</span></label>
              <select id="newCounselorUserId" name="new_counselor_user_id" class="form-select" required>
                <option value="">-- Select Qualified Counselor --</option>
                <?php foreach ($qualifiedCounselors as $qc): ?>
                  <?php
                    $qcId = (int) ($qc['user_id'] ?? 0);
                    if ($qcId === (int) ($episode['primary_counselor_user_id'] ?? 0)) {
                        continue;
                    }
                    $qcName = trim((string) (($qc['first_name'] ?? '') . ' ' . ($qc['last_name'] ?? '')));
                    if ($qcName === '') {
                        $qcName = (string) ($qc['username'] ?? 'User #' . $qcId);
                    }
                    $tier = (string) ($qc['credential_tier'] ?? '');
                  ?>
                  <option value="<?= $qcId ?>">
                    <?= esc($qcName) ?><?= $tier !== '' ? ' (' . esc(str_replace('_', ' ', $tier)) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-0">
              <label for="episodeTransferReason" class="form-label fw-semibold">Reason for Transfer <span class="text-danger">*</span></label>
              <textarea id="episodeTransferReason" name="reason" rows="3" class="form-control" required placeholder="Clinical rationale or workload rebalancing notes..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Transfer Episode</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</section>
<?= $this->endSection() ?>
