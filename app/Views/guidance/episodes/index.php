<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a href="<?= site_url('guidance') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Guidance Hub
</a>
<a href="<?= site_url('guidance/call-slips') ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-receipt me-1"></i>Call Slips
</a>
<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#openEpisodeModal">
    <i class="bi bi-plus-lg me-1"></i>Open Episode
</button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$episodes = $episodes ?? [];
$currentStatus = (string) ($status ?? '');
$qualifiedCounselors = $qualifiedCounselors ?? [];
$actor = $actor ?? [];
$statusBadges = [
    'open'        => 'primary',
    'active'      => 'success',
    'closed'      => 'secondary',
    'transferred' => 'info',
];
?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex flex-wrap align-items-center gap-1">
            <span class="small text-muted me-2">Filter Status:</span>
            <?php
            $filterOptions = [
                ''            => 'All',
                'open'        => 'Open',
                'active'      => 'Active',
                'closed'      => 'Closed',
                'transferred' => 'Transferred',
            ];
            ?>
            <?php foreach ($filterOptions as $key => $label): ?>
                <?php
                $isActive = ($currentStatus === $key);
                $url = $key === '' ? site_url('guidance/episodes') : site_url('guidance/episodes?status=' . urlencode($key));
                ?>
                <a href="<?= esc($url) ?>" class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= esc($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <span class="small text-muted">
            Showing <?= count($episodes) ?> episode<?= count($episodes) === 1 ? '' : 's' ?>
        </span>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="card-title mb-0 p-0">
            <i class="bi bi-journal-medical me-2 text-primary"></i>Longitudinal Care Episodes
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($episodes)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                No guidance episodes matched the current filter.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Episode</th>
                            <th>Client / Student</th>
                            <th>Primary Counselor</th>
                            <th>Linked Case</th>
                            <th>Status</th>
                            <th>Opened</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($episodes as $ep): ?>
                            <?php
                            $epStatus = (string) ($ep['status'] ?? 'open');
                            $badge = $statusBadges[$epStatus] ?? 'secondary';
                            $clientName = trim((string) (($ep['first_name'] ?? '') . ' ' . ($ep['last_name'] ?? '')));
                            $counselorName = trim((string) (($ep['counselor_first_name'] ?? '') . ' ' . ($ep['counselor_last_name'] ?? '')));
                            if ($counselorName === '') {
                                $counselorName = (string) ($ep['counselor_username'] ?? 'Unassigned');
                            }
                            ?>
                            <tr>
                                <td class="fw-semibold">
                                    <a href="<?= site_url('guidance/episodes/' . (int) $ep['id']) ?>" class="text-decoration-none">
                                        EP-<?= str_pad((string) (int) $ep['id'], 5, '0', STR_PAD_LEFT) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= esc($clientName !== '' ? $clientName : 'Person #' . (int) ($ep['person_id'] ?? 0)) ?></div>
                                    <div class="small text-muted">
                                        <?= esc((string) ($ep['mis_id'] ?? '')) ?>
                                        <?php if (! empty($ep['kind'])): ?>
                                            &middot; <?= esc(ucfirst((string) $ep['kind'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= esc($counselorName) ?></td>
                                <td>
                                    <?php if (! empty($ep['case_id'])): ?>
                                        <a href="<?= site_url('guidance/cases/' . (int) $ep['case_id']) ?>" class="text-decoration-none">
                                            <?= esc((string) ($ep['case_number'] ?? ('Case #' . (int) $ep['case_id']))) ?>
                                        </a>
                                        <?php if (! empty($ep['case_title'])): ?>
                                            <div class="small text-muted text-truncate" style="max-width: 220px;">
                                                <?= esc((string) $ep['case_title']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">None linked</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= esc($badge) ?>"><?= esc(ucfirst($epStatus)) ?></span>
                                </td>
                                <td class="small text-muted">
                                    <?= ! empty($ep['opened_at']) ? esc(date('M d, Y g:i A', strtotime((string) $ep['opened_at']))) : '—' ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= site_url('guidance/episodes/' . (int) $ep['id']) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-clock-history me-1"></i>Timeline
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Open Episode Modal -->
<div class="modal fade" id="openEpisodeModal" tabindex="-1" aria-labelledby="openEpisodeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= site_url('guidance/episodes/open') ?>" class="modal-content" data-background-submit>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="openEpisodeModalLabel">
                    <i class="bi bi-journal-plus me-2 text-primary"></i>Open Care Episode
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" x-data="episodePersonPicker('<?= esc(site_url('api/patients/search')) ?>')">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Client / Student <span class="text-danger">*</span></label>
                    <input type="hidden" name="person_id" :value="selected ? selected.id : ''" required>

                    <template x-if="selected">
                        <div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-2">
                            <div>
                                <div class="fw-semibold" x-text="selected.first_name + ' ' + selected.last_name"></div>
                                <div class="small text-muted" x-text="(selected.mis_id || 'ID #' + selected.id) + (selected.kind ? ' · ' + selected.kind : '')"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="clear()">Change</button>
                        </div>
                    </template>

                    <div x-show="!selected">
                        <input type="text" class="form-control" x-model="query" @input.debounce.300ms="search()" placeholder="Search by name or MIS / Student ID...">
                        <div class="list-group mt-1 shadow-sm" x-show="results.length > 0" style="max-height: 200px; overflow-y: auto;">
                            <template x-for="p in results" :key="p.id">
                                <button type="button" class="list-group-item list-group-item-action py-2" @click="pick(p)">
                                    <div class="fw-semibold small" x-text="p.first_name + ' ' + p.last_name"></div>
                                    <div class="text-muted" style="font-size: 0.75rem;" x-text="(p.mis_id || 'ID #' + p.id) + (p.kind ? ' · ' + p.kind : '')"></div>
                                </button>
                            </template>
                        </div>
                        <div class="form-text" x-show="searching">Searching directory...</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="openEpisodeCounselor" class="form-label fw-semibold">Primary Counselor</label>
                    <select id="openEpisodeCounselor" name="primary_counselor_user_id" class="form-select">
                        <?php $actorId = (int) ($actor['id'] ?? 0); ?>
                        <?php if (empty($qualifiedCounselors)): ?>
                            <option value="<?= $actorId ?>">Current User (#<?= $actorId ?>)</option>
                        <?php else: ?>
                            <?php foreach ($qualifiedCounselors as $qc): ?>
                                <?php
                                $qcId = (int) ($qc['user_id'] ?? 0);
                                $qcName = trim((string) (($qc['first_name'] ?? '') . ' ' . ($qc['last_name'] ?? '')));
                                if ($qcName === '') {
                                    $qcName = (string) ($qc['username'] ?? 'User #' . $qcId);
                                }
                                $tier = (string) ($qc['credential_tier'] ?? '');
                                ?>
                                <option value="<?= $qcId ?>" <?= $qcId === $actorId ? 'selected' : '' ?>>
                                    <?= esc($qcName) ?><?= $tier !== '' ? ' (' . esc(str_replace('_', ' ', $tier)) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <label for="openEpisodeStatus" class="form-label fw-semibold">Initial Status</label>
                        <select id="openEpisodeStatus" name="status" class="form-select">
                            <option value="open" selected>Open</option>
                            <option value="active">Active</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="openEpisodeCaseId" class="form-label fw-semibold">Linked Case ID (Optional)</label>
                        <input type="number" min="1" id="openEpisodeCaseId" name="case_id" class="form-control" placeholder="e.g. 12">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Open Episode
                </button>
            </div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function episodePersonPicker(searchUrl) {
    return {
        query: '',
        results: [],
        selected: null,
        searching: false,
        async search() {
            const q = (this.query || '').trim();
            if (q.length < 2) {
                this.results = [];
                return;
            }
            this.searching = true;
            try {
                const resp = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
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
