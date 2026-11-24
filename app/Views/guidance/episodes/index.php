<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= site_url('guidance') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Guidance Hub
  </a>
  <a href="<?= site_url('guidance/call-slips') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-receipt" aria-hidden="true"></i> Call Slips
  </a>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#openEpisodeModal">
    <i class="bi bi-plus-lg" aria-hidden="true"></i> Open Episode
  </button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$episodes = $episodes ?? [];
$currentStatus = (string) ($status ?? '');
$qualifiedCounselors = $qualifiedCounselors ?? [];
$actor = $actor ?? [];
$filterTabs = [
    ''            => ['label' => 'All', 'icon' => 'bi-collection'],
    'open'        => ['label' => 'Open', 'icon' => 'bi-folder2-open'],
    'active'      => ['label' => 'Active', 'icon' => 'bi-activity'],
    'closed'      => ['label' => 'Closed', 'icon' => 'bi-archive'],
    'transferred' => ['label' => 'Transferred', 'icon' => 'bi-arrow-left-right'],
];
if (!isset($statusCounts) || !is_array($statusCounts)) {
    $statusCounts = ['' => count($episodes), 'open' => 0, 'active' => 0, 'closed' => 0, 'transferred' => 0];
    foreach ($episodes as $epRow) {
        $stKey = (string) ($epRow['status'] ?? '');
        if (isset($statusCounts[$stKey])) {
            $statusCounts[$stKey]++;
        }
    }
}
$pagination = $pagination ?? \App\Libraries\TablePage::forList([])->metadata((int) $statusCounts[''], count($episodes));
$perPage = (int) $pagination['per_page'];
?>
<section class="guidance-ui ce-workspace"
         data-guidance-registry="episodes"
         data-live-filters
         data-filter-key="guidance-episodes"
         data-filter-url="<?= esc(site_url('guidance/episodes'), 'attr') ?>">

  <p class="visually-hidden" data-filter-status role="status" aria-live="polite">Showing guidance episodes.</p>
  <div class="alert ce-notice ce-notice-crimson" data-banner-dismiss="off" data-filter-error role="alert" hidden>
    <div class="ce-notice-body">
      <i class="bi bi-exclamation-octagon ce-notice-icon" aria-hidden="true"></i>
      <span data-filter-error-text></span>
    </div>
    <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" data-filter-retry>Retry</button>
  </div>

  <div class="ce-notice ce-notice-sky">
    <div class="ce-notice-body">
      <i class="bi bi-diagram-3 ce-notice-icon" aria-hidden="true"></i>
      <span>
        <strong>Longitudinal Care Episode Registry</strong> — Tracks end-to-end student and employee guidance journeys across intake referrals, counseling cases, appointments, call slips, and controlled clinical forms.
      </span>
    </div>
  </div>

  <!-- Referrals-Style Folder Tabs -->
  <div class="ge-folder-bar ce-registry-toolbar">
    <nav class="nav nav-pills ge-folder-tabs" data-filter-region="tabs" aria-label="Filter episodes by status">
      <?php foreach ($filterTabs as $key => $meta): ?>
        <?php
          $isActive = ($currentStatus === $key);
          $tabQuery = ['per_page' => $perPage];
          if ($key !== '') {
              $tabQuery['status'] = $key;
          }
          $url = site_url('guidance/episodes') . '?' . http_build_query($tabQuery);
        ?>
        <a class="nav-link ge-folder-tab <?= $isActive ? 'active' : '' ?>"
           href="<?= esc($url) ?>"
           <?= $key === '' ? 'data-filter-reset' : 'data-filter-link' ?>
           <?= $isActive ? 'aria-current="page"' : '' ?>>
          <i class="bi <?= esc($meta['icon'], 'attr') ?>" aria-hidden="true"></i>
          <span class="ge-folder-label"><?= esc($meta['label']) ?></span>
          <span class="ge-folder-count"><?= (int) ($statusCounts[$key] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <form method="get" action="<?= esc(site_url('guidance/episodes'), 'attr') ?>" data-filter-form class="ce-registry-page-size">
      <input type="hidden" name="status" value="<?= esc($currentStatus) ?>">
      <input type="hidden" name="page" value="<?= (int) $pagination['page'] ?>">
      <label for="episodesPerPage" class="form-label mb-0">Rows per page</label>
      <select id="episodesPerPage" name="per_page" class="form-select form-select-sm w-auto" onchange="this.form.elements.page.value='1'">
        <?php foreach ([20, 25, 50, 100] as $size): ?>
          <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
    </form>
  </div>

  <!-- Episodes Ledger -->
  <div class="ce-card" data-filter-region="list" tabindex="-1">
    <div class="ce-card-header">
      <h2 class="ce-card-title">
        <i class="bi bi-journal-medical" aria-hidden="true"></i> Longitudinal Care Episodes
      </h2>
      <span class="ce-tag ce-mono"><?= (int) $pagination['filtered'] ?> <?= $currentStatus === '' ? 'total' : 'matching' ?></span>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle ce-table ce-table-mobile ge-episodes-table" data-mobile-cards="false">
        <thead>
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
          <?php if (empty($episodes)): ?>
            <tr>
              <td colspan="7" class="ce-empty-row">
                <i class="bi bi-inbox" aria-hidden="true"></i>
                No guidance episodes matched the current filter.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($episodes as $ep): ?>
              <?php
                $epStatus = (string) ($ep['status'] ?? 'open');
                $statusDotClass = match ($epStatus) {
                    'active'      => 'ce-status-emerald',
                    'open'        => 'ce-status-sky',
                    'transferred' => 'ce-status-amber',
                    default       => 'ce-status-slate',
                };
                $clientName = trim((string) (($ep['first_name'] ?? '') . ' ' . ($ep['last_name'] ?? '')));
                $counselorName = trim((string) (($ep['counselor_first_name'] ?? '') . ' ' . ($ep['counselor_last_name'] ?? '')));
                if ($counselorName === '') {
                    $counselorName = (string) ($ep['counselor_username'] ?? 'Unassigned');
                }
              ?>
              <tr>
                <td data-label="Episode">
                  <a href="<?= site_url('guidance/episodes/' . (int) $ep['id']) ?>" class="ce-patient-link ce-mono">
                    EP-<?= str_pad((string) (int) $ep['id'], 5, '0', STR_PAD_LEFT) ?>
                  </a>
                </td>
                <td data-label="Client / Student">
                  <div class="d-flex flex-wrap align-items-center gap-1">
                    <span class="fw-semibold"><?= esc($clientName !== '' ? $clientName : 'Person #' . (int) ($ep['person_id'] ?? 0)) ?></span>
                    <?php if (! empty($ep['kind'])): ?>
                      <span class="ce-tag ce-tag-upper"><?= esc((string) $ep['kind']) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (! empty($ep['mis_id'])): ?>
                    <div class="ce-patient-sub">
                      <span class="ce-mono">ID: <?= esc((string) $ep['mis_id']) ?></span>
                    </div>
                  <?php endif; ?>
                </td>
                <td data-label="Primary Counselor">
                  <span class="ce-staff-cell">
                    <i class="bi bi-person-check" aria-hidden="true"></i>
                    <?= esc($counselorName) ?>
                  </span>
                </td>
                <td data-label="Linked Case">
                  <?php if (! empty($ep['case_id'])): ?>
                    <a href="<?= site_url('guidance/cases/' . (int) $ep['case_id']) ?>" class="ce-patient-link ce-mono">
                      <?= esc((string) ($ep['case_number'] ?? ('Case #' . (int) $ep['case_id']))) ?>
                    </a>
                    <?php if (! empty($ep['case_title'])): ?>
                      <div class="ce-patient-sub">
                        <?= esc((string) $ep['case_title']) ?>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted small">None linked</span>
                  <?php endif; ?>
                </td>
                <td data-label="Status">
                  <span class="ce-status-dot <?= $statusDotClass ?>"><?= esc(ucfirst($epStatus)) ?></span>
                </td>
                <td data-label="Opened">
                  <?php if (! empty($ep['opened_at'])): ?>
                    <div class="ce-date-primary ce-mono"><?= esc(date('M d, Y', strtotime((string) $ep['opened_at']))) ?></div>
                    <div class="ce-date-sub ce-mono"><?= esc(date('g:i A', strtotime((string) $ep['opened_at']))) ?></div>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td data-label="Actions" class="text-end">
                  <div class="d-flex justify-content-md-end gc-card-actions">
                    <a href="<?= site_url('guidance/episodes/' . (int) $ep['id']) ?>" class="btn btn-sm btn-outline-primary ce-action-btn">
                      <i class="bi bi-clock-history" aria-hidden="true"></i> Timeline
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
        'pagination' => $pagination,
        'filters' => ['status' => $currentStatus],
        'baseUrl' => site_url('guidance/episodes'),
        'paginationLabel' => 'Guidance episode pages',
        'paginationLiveFilters' => true,
        'paginationCardFooter' => true,
    ]) ?>
  </div>

  <!-- Open Episode Modal -->
  <div class="modal fade" id="openEpisodeModal" tabindex="-1" aria-labelledby="openEpisodeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" action="<?= site_url('guidance/episodes/open') ?>" class="modal-content gc-modal-scope" data-background-submit>
        <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="openEpisodeModalLabel">
            <i class="bi bi-journal-plus me-2 text-primary" aria-hidden="true"></i>Open Care Episode
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" x-data="episodePersonPicker('<?= esc(site_url('api/patients/search')) ?>')">
          <div class="mb-3">
            <label class="form-label fw-semibold">Client / Student <span class="text-danger">*</span></label>
            <input type="hidden" name="person_id" :value="selected ? selected.id : ''" required>

            <template x-if="selected">
              <div class="card gc-selected-person-card p-3 mb-2">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <div>
                    <div class="fw-semibold" x-text="selected.first_name + ' ' + selected.last_name"></div>
                    <div class="small text-muted" x-text="(selected.mis_id || 'ID #' + selected.id) + (selected.kind ? ' · ' + selected.kind : '')"></div>
                  </div>
                  <button type="button" class="btn btn-sm btn-outline-secondary" @click="clear()">Change</button>
                </div>
              </div>
            </template>

            <div class="syn-person-lookup" data-person-lookup x-show="!selected">
              <div data-person-lookup-anchor>
                <input type="text" class="form-control" x-model="query" @input.debounce.300ms="search()" autocomplete="off" placeholder="Search by name or MIS / Student ID...">
              </div>
              <div class="syn-person-lookup-menu"
                   data-person-lookup-menu
                   x-show="searching || results.length > 0"
                   style="display: none;">
                <div class="syn-person-lookup-status" role="status" x-show="searching">Searching directory...</div>
                <div class="list-group syn-person-lookup-list" x-show="results.length > 0">
                  <template x-for="p in results" :key="p.id">
                    <button type="button" class="list-group-item list-group-item-action py-2" @click="pick(p)">
                      <div class="fw-semibold small" x-text="p.first_name + ' ' + p.last_name"></div>
                      <div class="text-muted" style="font-size: 0.75rem;" x-text="(p.mis_id || 'ID #' + p.id) + (p.kind ? ' · ' + p.kind : '')"></div>
                    </button>
                  </template>
                </div>
              </div>
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
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Open Episode
          </button>
        </div>
      </form>
    </div>
  </div>
</section>
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
