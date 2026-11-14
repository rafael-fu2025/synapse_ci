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
<a href="<?= site_url('guidance/episodes') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>All Episodes
</a>
<a href="<?= site_url('guidance/call-slips?person_id=' . $personId . '&episode_id=' . $epId) ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-receipt me-1"></i>Call Slips
</a>
<?php if ($caseId > 0): ?>
    <a href="<?= site_url('guidance/cases/' . $caseId) ?>" class="btn btn-outline-info btn-sm">
        <i class="bi bi-folder2-open me-1"></i>View Linked Case
    </a>
<?php endif; ?>
<?php if (! $isClosedOrTransferred): ?>
    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#transferEpisodeModal">
        <i class="bi bi-arrow-left-right me-1"></i>Transfer Episode
    </button>
    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#closeEpisodeModal">
        <i class="bi bi-lock me-1"></i>Close Episode
    </button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$statusBadges = [
    'open'        => 'primary',
    'active'      => 'success',
    'closed'      => 'secondary',
    'transferred' => 'info',
];
$badge = $statusBadges[$epStatus] ?? 'secondary';
$clientName = trim((string) (($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
if ($clientName === '') {
    $clientName = 'Person #' . $personId;
}
$counselorName = trim((string) (($primaryCounselor['first_name'] ?? '') . ' ' . ($primaryCounselor['last_name'] ?? '')));
if ($counselorName === '') {
    $counselorName = (string) ($primaryCounselor['username'] ?? 'User #' . (int) ($episode['primary_counselor_user_id'] ?? 0));
}

$kindMeta = [
    'episode_opened' => ['icon' => 'bi-play-circle', 'color' => 'primary', 'label' => 'Episode Opened'],
    'referral'       => ['icon' => 'bi-inbox', 'color' => 'info', 'label' => 'Intake Referral'],
    'appointment'    => ['icon' => 'bi-calendar-event', 'color' => 'primary', 'label' => 'Appointment'],
    'call_slip'      => ['icon' => 'bi-receipt', 'color' => 'warning', 'label' => 'Call Slip'],
    'case'           => ['icon' => 'bi-folder2', 'color' => 'success', 'label' => 'Guidance Case'],
    'session'        => ['icon' => 'bi-chat-left-text', 'color' => 'dark', 'label' => 'Counseling Session'],
    'form'           => ['icon' => 'bi-file-earmark-medical', 'color' => 'secondary', 'label' => 'Controlled Form / Evaluation'],
    'episode_closed' => ['icon' => 'bi-check2-circle', 'color' => 'secondary', 'label' => 'Episode Closed'],
];
?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <div class="text-muted small">Care Episode EP-<?= str_pad((string) $epId, 5, '0', STR_PAD_LEFT) ?></div>
                        <h4 class="mb-1"><?= esc($clientName) ?></h4>
                        <div class="small text-muted">
                            MIS / Student ID: <strong><?= esc((string) ($person['mis_id'] ?? 'N/A')) ?></strong>
                            <?php if (! empty($person['kind'])): ?>
                                &middot; <?= esc(ucfirst((string) $person['kind'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge bg-<?= esc($badge) ?> fs-6"><?= esc(ucfirst($epStatus)) ?></span>
                </div>

                <hr class="my-3">

                <div class="row g-2 small">
                    <div class="col-sm-4">
                        <div class="text-muted">Primary Counselor</div>
                        <div class="fw-semibold"><?= esc($counselorName) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted">Opened At</div>
                        <div class="fw-semibold">
                            <?= ! empty($episode['opened_at']) ? esc(date('M d, Y g:i A', strtotime((string) $episode['opened_at']))) : '—' ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted">Closed At</div>
                        <div class="fw-semibold">
                            <?= ! empty($episode['closed_at']) ? esc(date('M d, Y g:i A', strtotime((string) $episode['closed_at']))) : '—' ?>
                        </div>
                    </div>
                </div>

                <?php if (! empty($episode['closure_reason']) || ! empty($episode['closure_notes'])): ?>
                    <div class="alert alert-light border mt-3 mb-0 small">
                        <div class="fw-semibold mb-1">Closure Summary</div>
                        <?php if (! empty($episode['closure_reason'])): ?>
                            <div><span class="text-muted">Reason:</span> <?= esc((string) $episode['closure_reason']) ?></div>
                        <?php endif; ?>
                        <?php if (! empty($episode['closure_notes'])): ?>
                            <div class="mt-1"><?= nl2br(esc((string) $episode['closure_notes'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="card-title mb-0 p-0">
                    <i class="bi bi-lightning-charge me-1 text-primary"></i>Episode Quick Actions
                </h6>
            </div>
            <div class="card-body d-grid gap-2">
                <a href="<?= site_url('guidance/call-slips?person_id=' . $personId . '&episode_id=' . $epId) ?>" class="btn btn-outline-primary btn-sm text-start">
                    <i class="bi bi-receipt me-2"></i>Issue / Manage Call Slips
                </a>
                <a href="<?= site_url('guidance/clinical/forms/new/psychosocial_support_interview?person_id=' . $personId . '&episode_id=' . $epId . ($caseId > 0 ? '&case_id=' . $caseId : '')) ?>" class="btn btn-outline-secondary btn-sm text-start">
                    <i class="bi bi-file-earmark-plus me-2"></i>New Psychosocial Interview (PIF-008)
                </a>
                <a href="<?= site_url('guidance/clinical/forms/new/psychosocial_support_contact?person_id=' . $personId . '&episode_id=' . $epId . ($caseId > 0 ? '&case_id=' . $caseId : '')) ?>" class="btn btn-outline-secondary btn-sm text-start">
                    <i class="bi bi-file-earmark-text me-2"></i>New Psychosocial Contact (PCF-008)
                </a>
                <?php if ($caseId > 0): ?>
                    <a href="<?= site_url('guidance/cases/' . $caseId) ?>" class="btn btn-outline-info btn-sm text-start">
                        <i class="bi bi-folder2-open me-2"></i>Open Case Workspace (#<?= $caseId ?>)
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Unified Longitudinal Timeline -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 p-0">
            <i class="bi bi-clock-history me-2 text-primary"></i>Unified Longitudinal Timeline
        </h5>
        <span class="badge bg-light text-dark border"><?= count($timeline) ?> events</span>
    </div>
    <div class="card-body">
        <?php if (empty($timeline)): ?>
            <div class="text-center text-muted py-4">
                No timeline events have been recorded for this episode yet.
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($timeline as $item): ?>
                    <?php
                    $kind = (string) ($item['kind'] ?? 'form');
                    $meta = $kindMeta[$kind] ?? ['icon' => 'bi-dot', 'color' => 'secondary', 'label' => ucfirst($kind)];
                    $rec = is_array($item['record'] ?? null) ? $item['record'] : [];
                    $ts = (string) ($item['timestamp'] ?? '');
                    ?>
                    <div class="list-group-item px-0 py-3">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div class="d-flex gap-3 align-items-start">
                                <span class="badge rounded-pill bg-<?= esc($meta['color']) ?>-subtle text-<?= esc($meta['color']) ?> border p-2">
                                    <i class="bi <?= esc($meta['icon']) ?>"></i>
                                </span>
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="fw-semibold"><?= esc((string) ($item['title'] ?? $meta['label'])) ?></span>
                                        <span class="badge bg-light text-dark border"><?= esc($meta['label']) ?></span>
                                        <?php if (! empty($item['status'])): ?>
                                            <span class="badge bg-secondary-subtle text-dark border"><?= esc((string) $item['status']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (! empty($item['detail'])): ?>
                                        <div class="small text-muted mt-1"><?= esc((string) $item['detail']) ?></div>
                                    <?php endif; ?>

                                    <?php if ($kind === 'appointment' && ! empty($rec['id'])): ?>
                                        <div class="mt-2 d-flex flex-wrap gap-1">
                                            <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=single&mode=preview') ?>" target="_blank" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2">
                                                <i class="bi bi-file-pdf me-1"></i>Preview Single A4
                                            </a>
                                            <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=quad&mode=preview') ?>" target="_blank" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2">
                                                <i class="bi bi-grid-2x2 me-1"></i>Preview 4-Up Tear-Off
                                            </a>
                                            <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=single&mode=issue') ?>" target="_blank" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2">
                                                Issue Single A4
                                            </a>
                                            <a href="<?= site_url('guidance/appointments/' . (int) $rec['id'] . '/pdf?layout=quad&mode=issue') ?>" target="_blank" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2">
                                                Issue 4-Up Sheet
                                            </a>
                                        </div>
                                    <?php elseif ($kind === 'call_slip' && ! empty($rec['id'])): ?>
                                        <div class="mt-2 d-flex flex-wrap gap-1">
                                            <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=single&mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2">
                                                <i class="bi bi-file-pdf me-1"></i>Preview Single A4
                                            </a>
                                            <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=quad&mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2">
                                                <i class="bi bi-grid-2x2 me-1"></i>Preview 4-Up Tear-Off
                                            </a>
                                            <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=single&mode=issue') ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                                Issue Single A4
                                            </a>
                                            <a href="<?= site_url('guidance/call-slips/' . (int) $rec['id'] . '/pdf?layout=quad&mode=issue') ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                                Issue 4-Up Sheet
                                            </a>
                                        </div>
                                    <?php elseif ($kind === 'case' && ! empty($rec['id'])): ?>
                                        <div class="mt-2">
                                            <a href="<?= site_url('guidance/cases/' . (int) $rec['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
                                                Open Case Workspace
                                            </a>
                                        </div>
                                    <?php elseif ($kind === 'form' && ! empty($rec['id'])): ?>
                                        <div class="mt-2 d-flex gap-1">
                                            <a href="<?= site_url('guidance/clinical/forms/' . (int) $rec['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
                                                Open Clinical Form
                                            </a>
                                            <a href="<?= site_url('guidance/clinical/forms/' . (int) $rec['id'] . '/pdf?mode=preview') ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2">
                                                Preview PDF
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="small text-muted text-nowrap">
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
            <form method="post" action="<?= site_url('guidance/episodes/' . $epId . '/close') ?>" class="modal-content"
                  data-background-submit
                  data-confirm="Close this care episode? Once closed, new clinical entries will be locked unless reopened under policy."
                  data-confirm-title="Close Care Episode"
                  data-confirm-label="Yes, Close Episode"
                  data-confirm-tone="danger">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="closeEpisodeModalLabel">
                        <i class="bi bi-lock me-2 text-danger"></i>Close Care Episode
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
            <form method="post" action="<?= site_url('guidance/episodes/' . $epId . '/transfer') ?>" class="modal-content" data-background-submit>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="transferEpisodeModalLabel">
                        <i class="bi bi-arrow-left-right me-2 text-warning"></i>Transfer Care Episode
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
                    <button type="submit" class="btn btn-warning">Transfer Episode</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
