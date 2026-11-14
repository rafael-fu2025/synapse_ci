<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= site_url('guidance') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Guidance Hub
</a>
<a href="<?= site_url('guidance/episodes') ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-journal-medical me-1"></i>Episodes
</a>
<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#issueCallSlipModal">
    <i class="bi bi-plus-lg me-1"></i>Issue Call Slip
</button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$callSlips = $callSlips ?? [];
$currentStatus = (string) ($status ?? '');
$validStatuses = $validStatuses ?? ['pending', 'acknowledged', 'attended', 'missed', 'cancelled'];
$validPurposes = $validPurposes ?? ['counseling', 'intake', 'follow_up', 'evaluation', 'guardian_conference', 'other'];
$prefillPerson = $prefillPerson ?? null;
$prefillEpisodeId = (int) ($prefillEpisodeId ?? 0);

$statusBadges = [
    'pending'      => 'warning',
    'acknowledged' => 'info',
    'attended'     => 'success',
    'missed'       => 'danger',
    'cancelled'    => 'secondary',
];
?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex flex-wrap align-items-center gap-1">
            <span class="small text-muted me-2">Status Filter:</span>
            <a href="<?= site_url('guidance/call-slips') ?>" class="btn btn-sm <?= $currentStatus === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                All
            </a>
            <?php foreach ($validStatuses as $st): ?>
                <a href="<?= site_url('guidance/call-slips?status=' . urlencode($st)) ?>"
                   class="btn btn-sm <?= $currentStatus === $st ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= esc(ucfirst($st)) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted">
            <i class="bi bi-printer me-1"></i>Print Options: <strong>Single A4</strong> or <strong>4-Up Tear-Off Sheet</strong> available per slip
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0 p-0">
            <i class="bi bi-receipt me-2 text-primary"></i>Guidance Call Slips
        </h5>
        <span class="badge bg-light text-dark border"><?= count($callSlips) ?> call slip<?= count($callSlips) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($callSlips)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-receipt fs-3 d-block mb-2"></i>
                No call slips found for the selected filter.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Call Slip #</th>
                            <th>Student / Client</th>
                            <th>Schedule</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Print Options (Single A4 vs 4-Up Tear-Off Sheet)</th>
                            <th class="text-end">Attendance Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($callSlips as $cs): ?>
                            <?php
                            $csId = (int) ($cs['id'] ?? 0);
                            $csStatus = (string) ($cs['attendance_status'] ?? 'pending');
                            $badge = $statusBadges[$csStatus] ?? 'secondary';
                            $studentName = trim((string) (($cs['first_name'] ?? '') . ' ' . ($cs['last_name'] ?? '')));
                            $purposeLabel = ucwords(str_replace('_', ' ', (string) ($cs['purpose'] ?? 'counseling')));
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= esc((string) ($cs['call_slip_number'] ?? ('CS-' . $csId))) ?></div>
                                    <?php if (! empty($cs['form_instance_number'])): ?>
                                        <div class="small text-muted"><?= esc((string) $cs['form_instance_number']) ?></div>
                                    <?php endif; ?>
                                    <?php if (! empty($cs['episode_id'])): ?>
                                        <a href="<?= site_url('guidance/episodes/' . (int) $cs['episode_id']) ?>" class="small text-decoration-none">
                                            Episode #<?= (int) $cs['episode_id'] ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= esc($studentName !== '' ? $studentName : 'Person #' . (int) ($cs['person_id'] ?? 0)) ?></div>
                                    <div class="small text-muted">
                                        <?= esc((string) ($cs['mis_id'] ?? '')) ?>
                                        <?php if (! empty($cs['program'])): ?>
                                            &middot; <?= esc((string) $cs['program']) ?>
                                        <?php endif; ?>
                                        <?php if (! empty($cs['year_level'])): ?>
                                            <?= esc((string) $cs['year_level']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (! empty($cs['parent_guardian_delivery'])): ?>
                                        <span class="badge bg-info-subtle text-info border mt-1">Guardian Delivery</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <div class="fw-semibold"><?= ! empty($cs['call_date']) ? esc(date('M d, Y', strtotime((string) $cs['call_date']))) : '—' ?></div>
                                    <div class="text-muted"><?= esc((string) ($cs['call_time'] ?? '')) ?></div>
                                </td>
                                <td class="small">
                                    <div><?= esc($purposeLabel) ?></div>
                                    <?php if (! empty($cs['purpose_other'])): ?>
                                        <div class="text-muted"><?= esc((string) $cs['purpose_other']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= esc($badge) ?>"><?= esc(ucfirst($csStatus)) ?></span>
                                    <?php if (! empty($cs['acknowledged_at'])): ?>
                                        <div class="text-muted" style="font-size: 0.72rem;">
                                            Ack: <?= esc(date('M d g:i A', strtotime((string) $cs['acknowledged_at']))) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Print options for call slip <?= $csId ?>">
                                        <a href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=single&mode=preview') ?>" target="_blank" class="btn btn-outline-secondary" title="Preview Single A4">
                                            <i class="bi bi-file-earmark-pdf me-1"></i>Single A4
                                        </a>
                                        <a href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=quad&mode=preview') ?>" target="_blank" class="btn btn-outline-secondary" title="Preview 4-Up Tear-Off Sheet">
                                            <i class="bi bi-grid-2x2 me-1"></i>4-Up Tear-Off
                                        </a>
                                        <button type="button" class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">
                                            <span class="visually-hidden">Official Issue Print Options</span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li class="dropdown-header">Print Options</li>
                                            <li>
                                                <a class="dropdown-item" target="_blank" href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=single&mode=preview') ?>">
                                                    <i class="bi bi-eye me-2"></i>Preview — Single A4
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" target="_blank" href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=quad&mode=preview') ?>">
                                                    <i class="bi bi-grid-2x2 me-2"></i>Preview — 4-Up Tear-Off Sheet
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-primary fw-semibold" target="_blank" href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=single&mode=issue') ?>">
                                                    <i class="bi bi-printer me-2"></i>Issue Official — Single A4
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-primary fw-semibold" target="_blank" href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=quad&mode=issue') ?>">
                                                    <i class="bi bi-printer-fill me-2"></i>Issue Official — 4-Up Tear-Off Sheet
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <form method="post" action="<?= site_url('guidance/call-slips/' . $csId . '/status') ?>" class="d-inline-flex align-items-center gap-1" data-background-submit>
                                        <?= csrf_field() ?>
                                        <select name="attendance_status" class="form-select form-select-sm" style="width: auto;">
                                            <?php foreach ($validStatuses as $st): ?>
                                                <option value="<?= esc($st) ?>" <?= $csStatus === $st ? 'selected' : '' ?>>
                                                    <?= esc(ucfirst($st)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-success">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Issue Call Slip Modal -->
<div class="modal fade" id="issueCallSlipModal" tabindex="-1" aria-labelledby="issueCallSlipModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="post" action="<?= site_url('guidance/call-slips/issue') ?>" class="modal-content" data-background-submit>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="issueCallSlipModalLabel">
                    <i class="bi bi-receipt-cutoff me-2 text-primary"></i>Issue Guidance Call Slip
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" x-data="callSlipPersonPicker(<?= htmlspecialchars(json_encode([
                'searchUrl' => site_url('api/patients/search'),
                'prefill'   => $prefillPerson,
            ]), ENT_QUOTES, 'UTF-8') ?>)">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Student / Client <span class="text-danger">*</span></label>
                        <input type="hidden" name="person_id" :value="selected ? selected.id : ''" required>

                        <template x-if="selected">
                            <div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-0">
                                <div>
                                    <div class="fw-semibold" x-text="selected.first_name + ' ' + selected.last_name"></div>
                                    <div class="small text-muted" x-text="(selected.mis_id || 'ID #' + selected.id) + (selected.kind ? ' · ' + selected.kind : '')"></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" @click="clear()">Change</button>
                            </div>
                        </template>

                        <div x-show="!selected">
                            <input type="text" class="form-control" x-model="query" @input.debounce.300ms="search()" placeholder="Search exact student/client by name or MIS ID...">
                            <div class="list-group mt-1 shadow-sm" x-show="results.length > 0" style="max-height: 200px; overflow-y: auto;">
                                <template x-for="p in results" :key="p.id">
                                    <button type="button" class="list-group-item list-group-item-action py-2" @click="pick(p)">
                                        <div class="fw-semibold small" x-text="p.first_name + ' ' + p.last_name"></div>
                                        <div class="text-muted" style="font-size: 0.75rem;" x-text="(p.mis_id || 'ID #' + p.id) + (p.kind ? ' · ' + p.kind : '')"></div>
                                    </button>
                                </template>
                            </div>
                            <div class="form-text" x-show="searching">Searching...</div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label for="callSlipEpisodeId" class="form-label fw-semibold">Episode ID (Optional)</label>
                        <input type="number" min="1" id="callSlipEpisodeId" name="episode_id" class="form-control" value="<?= $prefillEpisodeId > 0 ? $prefillEpisodeId : '' ?>" placeholder="Auto or Episode #">
                    </div>

                    <div class="col-md-4">
                        <label for="callSlipDateDisplay" class="form-label fw-semibold">Call Date <span class="text-danger">*</span></label>
                        <input type="text" id="callSlipDateDisplay" class="form-control" data-datepicker data-target="#callSlipDate" placeholder="DD/MM/YYYY" required autocomplete="off">
                        <input type="hidden" id="callSlipDate" name="call_date" value="<?= esc(date('Y-m-d')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="callSlipTimeDisplay" class="form-label fw-semibold">Call Time <span class="text-danger">*</span></label>
                        <input type="text" id="callSlipTimeDisplay" class="form-control" data-timepicker data-target="#callSlipTime" data-default-hours="9" data-default-minutes="0" placeholder="hh:mm A" required autocomplete="off">
                        <input type="hidden" id="callSlipTime" name="call_time" value="09:00">
                    </div>

                    <div class="col-md-4">
                        <label for="callSlipPurpose" class="form-label fw-semibold">Purpose <span class="text-danger">*</span></label>
                        <select id="callSlipPurpose" name="purpose" class="form-select" required x-model="purpose">
                            <?php foreach ($validPurposes as $pKey): ?>
                                <option value="<?= esc($pKey) ?>"><?= esc(ucwords(str_replace('_', ' ', $pKey))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12" x-show="purpose === 'other'">
                        <label for="callSlipPurposeOther" class="form-label fw-semibold">Specify Other Purpose</label>
                        <input type="text" id="callSlipPurposeOther" name="purpose_other" class="form-control" maxlength="255" placeholder="Describe the reason for the call slip...">
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="guardianDeliveryCheck" name="parent_guardian_delivery" value="1">
                            <label class="form-check-label fw-semibold" for="guardianDeliveryCheck">
                                Flag for Parent / Guardian Delivery
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="callSlipDeliveryNotes" class="form-label fw-semibold">Delivery / Routing Notes</label>
                        <textarea id="callSlipDeliveryNotes" name="delivery_notes" rows="2" class="form-control" placeholder="Optional messenger, adviser, or guardian delivery instructions..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Issue Controlled Call Slip
                </button>
            </div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function callSlipPersonPicker(config) {
    return {
        searchUrl: config.searchUrl,
        query: '',
        results: [],
        selected: config.prefill || null,
        searching: false,
        purpose: 'counseling',
        async search() {
            const q = (this.query || '').trim();
            if (q.length < 2) {
                this.results = [];
                return;
            }
            this.searching = true;
            try {
                const resp = await fetch(this.searchUrl + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!resp.ok) {
                    this.results = [];
                    return;
                }
                const data = await resp.json();
                this.results = Array.isArray(data) ? data : (data.results || data.patients || data.items || []);
            } catch (e) {
                this.results = [];
            } finally {
                this.searching = false;
            }
        },
        pick(p) {
            this.selected = p;
            this.results = [];
            this.query = '';
        },
        clear() {
            this.selected = null;
            this.results = [];
        }
    };
}
</script>
<?= $this->endSection() ?>
