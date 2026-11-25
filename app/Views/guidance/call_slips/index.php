<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= site_url('guidance') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Guidance Hub
  </a>
  <a href="<?= site_url('guidance/episodes') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-journal-medical" aria-hidden="true"></i> Episodes
  </a>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#issueCallSlipModal">
    <i class="bi bi-plus-lg" aria-hidden="true"></i> Issue Call Slip
  </button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$callSlips = $callSlips ?? [];
$validStatuses = $validStatuses ?? \App\Services\Guidance\GuidanceCallSlipService::VALID_STATUSES;
$validPurposes = $validPurposes ?? \App\Services\Guidance\GuidanceCallSlipService::VALID_PURPOSES;
$currentStatus = (string) ($status ?? '');
if (!in_array($currentStatus, $validStatuses, true)) {
    $currentStatus = '';
}
$prefillPerson = $prefillPerson ?? null;
$prefillEpisodeId = (int) ($prefillEpisodeId ?? 0);
$defaultPurpose = (string) ($validPurposes[0] ?? 'personal_counseling');
$refreshOwner = 'guidance.call-slips';
$regionalRefresh = config(\Config\RefreshRegions::class)->enables($refreshOwner);
$prefillQuery = [];
if (!empty($prefillPerson['id'])) {
    $prefillQuery['person_id'] = (int) $prefillPerson['id'];
}
if ($prefillEpisodeId > 0) {
    $prefillQuery['episode_id'] = $prefillEpisodeId;
}
$filterTabs = [
    ''             => ['label' => 'All', 'icon' => 'bi-collection'],
    'pending'      => ['label' => 'Pending', 'icon' => 'bi-clock'],
    'acknowledged' => ['label' => 'Acknowledged', 'icon' => 'bi-envelope-check'],
    'attended'     => ['label' => 'Attended', 'icon' => 'bi-check2-circle'],
    'missed'       => ['label' => 'Missed', 'icon' => 'bi-exclamation-circle'],
    'cancelled'    => ['label' => 'Cancelled', 'icon' => 'bi-x-circle'],
];
$pagination = $pagination ?? \App\Libraries\TablePage::forList([])->metadata(count($callSlips), count($callSlips));
$perPage = (int) $pagination['per_page'];
$matchingCount = (int) $pagination['filtered'];
?>
<section class="guidance-ui ce-workspace gcs-workspace"
         data-guidance-registry="call-slips"
         data-live-filters
         data-filter-key="guidance-call-slips"
         data-filter-url="<?= esc(site_url('guidance/call-slips'), 'attr') ?>"
         data-refresh-owner="<?= esc($refreshOwner, 'attr') ?>">

  <div class="ce-notice ce-notice-sky">
    <div class="ce-notice-body">
      <i class="bi bi-file-earmark-lock ce-notice-icon" aria-hidden="true"></i>
      <span><strong>Controlled call slips</strong> — Preview a Single A4 or 4-Up tear-off sheet before printing. Official issuance finalizes the linked form and remains subject to document permissions and co-sign requirements.</span>
    </div>
  </div>

  <div class="ge-folder-bar ce-registry-toolbar">
    <nav class="nav nav-pills ge-folder-tabs gcs-folder-tabs" data-filter-region="tabs" data-refresh-region="tabs" aria-label="Filter call slips by attendance status">
      <?php foreach ($filterTabs as $key => $meta): ?>
        <?php
        $isActive = $currentStatus === $key;
        $filterQuery = $prefillQuery + ['per_page' => $perPage];
        if ($key !== '') {
            $filterQuery['status'] = $key;
        }
        $filterUrl = site_url('guidance/call-slips') . ($filterQuery ? '?' . http_build_query($filterQuery) : '');
        ?>
        <a class="nav-link ge-folder-tab <?= $isActive ? 'active' : '' ?>"
           href="<?= esc($filterUrl, 'attr') ?>"
           <?= $key === '' ? 'data-filter-reset' : 'data-filter-link' ?>
           <?= $isActive ? 'aria-current="page"' : '' ?>>
          <i class="bi <?= esc($meta['icon'], 'attr') ?>" aria-hidden="true"></i>
          <span class="ge-folder-label"><?= esc($meta['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <form method="get" action="<?= esc(site_url('guidance/call-slips'), 'attr') ?>" data-filter-form class="ce-registry-page-size">
      <input type="hidden" name="status" value="<?= esc($currentStatus, 'attr') ?>">
      <input type="hidden" name="page" value="<?= (int) $pagination['page'] ?>">
      <?php foreach ($prefillQuery as $name => $value): ?>
        <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= $value ?>">
      <?php endforeach; ?>
      <label for="callSlipsPerPage" class="form-label mb-0">Rows per page</label>
      <select id="callSlipsPerPage" name="per_page" class="form-select form-select-sm w-auto" onchange="this.form.elements.page.value='1'">
        <?php foreach ([20, 25, 50, 100] as $size): ?>
          <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
    </form>
  </div>

  <p class="visually-hidden" data-filter-status role="status" aria-live="polite">
    <?= $matchingCount ?> matching call slip<?= $matchingCount === 1 ? '' : 's' ?> · <?= $currentStatus === '' ? 'All attendance statuses' : esc(ucfirst($currentStatus)) ?>.
  </p>
  <div class="alert ce-notice ce-notice-crimson" data-banner-dismiss="off" data-filter-error role="alert" hidden>
    <div class="ce-notice-body">
      <i class="bi bi-exclamation-octagon ce-notice-icon" aria-hidden="true"></i>
      <span data-filter-error-text></span>
    </div>
    <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" data-filter-retry>Retry</button>
  </div>

  <!-- Call Slip Ledger -->
  <div class="ce-card gcs-ledger" data-filter-region="list" data-refresh-region="list" tabindex="-1">
    <div class="ce-card-header">
      <div>
        <h2 class="ce-card-title"><i class="bi bi-receipt" aria-hidden="true"></i> Guidance Call Slips</h2>
        <p class="ce-filter-status mt-1">Scheduled invitations, acknowledgment, and attendance.</p>
      </div>
      <span class="ce-tag ce-mono"><?= $matchingCount ?> matching</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle ce-table ce-table-mobile gcs-callslips-table" data-mobile-cards="false">
        <thead>
          <tr>
            <th scope="col">Call Slip</th>
            <th scope="col">Student / Client</th>
            <th scope="col">Schedule</th>
            <th scope="col">Purpose</th>
            <th scope="col">Attendance</th>
            <th scope="col">Print Options</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($callSlips)): ?>
            <tr>
              <td colspan="6" class="ce-empty-row">
                <i class="bi bi-receipt" aria-hidden="true"></i>
                <strong>No call slips found<?= $currentStatus !== '' ? ' with ' . esc($currentStatus) . ' attendance' : '' ?>.</strong>
                <span class="d-block mt-1">Choose another status or issue a call slip to schedule a guidance invitation.</span>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($callSlips as $cs): ?>
              <?php
              $csId = (int) ($cs['id'] ?? 0);
              $slipNumber = (string) ($cs['call_slip_number'] ?? ('CS-' . $csId));
              $csStatus = (string) ($cs['attendance_status'] ?? 'pending');
              $statusDotClass = match ($csStatus) {
                  'pending'      => 'ce-status-amber',
                  'acknowledged' => 'ce-status-sky',
                  'attended'     => 'ce-status-emerald',
                  'missed'       => 'ce-status-crimson',
                  default        => 'ce-status-slate',
              };
              $clientName = trim((string) (($cs['first_name'] ?? '') . ' ' . ($cs['last_name'] ?? '')));
              $purposeLabel = ucwords(str_replace('_', ' ', (string) ($cs['purpose'] ?? 'personal_counseling')));
              $callTime = (string) ($cs['call_time'] ?? '');
              $callTimeStamp = $callTime !== '' ? strtotime($callTime) : false;
              ?>
              <tr>
                <td data-label="Call Slip">
                  <div class="gcs-slip-number ce-mono fw-semibold"><?= esc($slipNumber) ?></div>
                  <?php if (!empty($cs['form_instance_number'])): ?>
                    <div class="ce-patient-sub ce-mono"><?= esc((string) $cs['form_instance_number']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($cs['episode_id'])): ?>
                    <a href="<?= site_url('guidance/episodes/' . (int) $cs['episode_id']) ?>" class="ce-patient-link ce-mono small">
                      Episode #<?= (int) $cs['episode_id'] ?>
                    </a>
                  <?php endif; ?>
                </td>
                <td data-label="Student / Client">
                  <div class="fw-semibold"><?= esc($clientName !== '' ? $clientName : 'Person #' . (int) ($cs['person_id'] ?? 0)) ?></div>
                  <?php if (!empty($cs['mis_id'])): ?>
                    <div class="ce-patient-sub ce-mono">ID: <?= esc((string) $cs['mis_id']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($cs['program']) || !empty($cs['year_level'])): ?>
                    <div class="ce-patient-sub"><?= esc(trim((string) ($cs['program'] ?? '') . ' ' . (string) ($cs['year_level'] ?? ''))) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($cs['parent_guardian_delivery'])): ?>
                    <span class="ce-tag mt-2"><i class="bi bi-person-heart" aria-hidden="true"></i> Guardian Delivery</span>
                  <?php endif; ?>
                </td>
                <td data-label="Schedule">
                  <div class="ce-date-primary ce-mono"><?= !empty($cs['call_date']) ? esc(date('M d, Y', strtotime((string) $cs['call_date']))) : '—' ?></div>
                  <div class="ce-date-sub ce-mono"><?= esc($callTimeStamp !== false ? date('g:i A', $callTimeStamp) : ($callTime ?: '—')) ?></div>
                </td>
                <td data-label="Purpose">
                  <div><?= esc($purposeLabel) ?></div>
                  <?php if (!empty($cs['purpose_other'])): ?>
                    <div class="ce-patient-sub"><?= esc((string) $cs['purpose_other']) ?></div>
                  <?php endif; ?>
                </td>
                <td data-label="Attendance">
                  <div class="gcs-attendance">
                    <span class="ce-status-dot <?= $statusDotClass ?>"><?= esc(ucfirst($csStatus)) ?></span>
                    <?php if (!empty($cs['acknowledged_at'])): ?>
                      <div class="ce-date-sub ce-mono">Ack: <?= esc(date('M d, g:i A', strtotime((string) $cs['acknowledged_at']))) ?></div>
                    <?php endif; ?>
                    <form method="post" action="<?= site_url('guidance/call-slips/' . $csId . '/status') ?>" class="gcs-status-form" data-background-submit
                          <?php if ($regionalRefresh): ?>data-refresh-target-owner="<?= esc($refreshOwner, 'attr') ?>" data-refresh-regions="tabs list"<?php endif; ?>>
                      <?= csrf_field() ?>
                      <label for="callSlipStatus<?= $csId ?>" class="visually-hidden">Attendance status for <?= esc($slipNumber) ?></label>
                      <select id="callSlipStatus<?= $csId ?>" name="attendance_status" class="form-select form-select-sm" data-native-select required>
                        <?php foreach ($validStatuses as $st): ?>
                          <option value="<?= esc($st, 'attr') ?>" <?= $csStatus === $st ? 'selected' : '' ?>><?= esc(ucfirst($st)) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="Save attendance for <?= esc($slipNumber, 'attr') ?>">Save</button>
                    </form>
                  </div>
                </td>
                <td data-label="Print Options">
                  <div class="gcs-print-controls">
                    <div class="gcs-preview-links" role="group" aria-label="Preview options for call slip <?= esc($slipNumber, 'attr') ?>">
                      <a href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=single&mode=preview') ?>" target="_blank" rel="noopener" data-navigation="false" class="btn btn-outline-secondary btn-sm" aria-label="Preview Single A4 for <?= esc($slipNumber, 'attr') ?>">
                        <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Single A4
                      </a>
                      <a href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=quad&mode=preview') ?>" target="_blank" rel="noopener" data-navigation="false" class="btn btn-outline-secondary btn-sm" aria-label="Preview 4-Up Tear-Off for <?= esc($slipNumber, 'attr') ?>">
                        <i class="bi bi-grid" aria-hidden="true"></i> 4-Up Tear-Off
                      </a>
                    </div>
                    <div class="dropdown gcs-official-print">
                      <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Official print options for <?= esc($slipNumber, 'attr') ?>">
                        <i class="bi bi-printer" aria-hidden="true"></i> Official print
                      </button>
                      <ul class="dropdown-menu dropdown-menu-xl-end">
                        <li class="dropdown-header">Issue controlled PDF</li>
                        <li>
                          <a class="dropdown-item" target="_blank" rel="noopener" data-navigation="false" href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=single&mode=issue') ?>">
                            <i class="bi bi-file-earmark-pdf me-2" aria-hidden="true"></i>Issue Official — Single A4
                          </a>
                        </li>
                        <li>
                          <a class="dropdown-item" target="_blank" rel="noopener" data-navigation="false" href="<?= site_url('guidance/call-slips/' . $csId . '/pdf?layout=quad&mode=issue') ?>">
                            <i class="bi bi-grid me-2" aria-hidden="true"></i>Issue Official — 4-Up Tear-Off Sheet
                          </a>
                        </li>
                      </ul>
                    </div>
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
        'paginationExtraQuery' => $prefillQuery,
        'baseUrl' => site_url('guidance/call-slips'),
        'paginationLabel' => 'Guidance call slip pages',
        'paginationLiveFilters' => true,
        'paginationCardFooter' => true,
    ]) ?>
  </div>

</section>

<section class="guidance-ui ce-workspace gcs-workspace">
  <!-- Issue Call Slip Modal -->
  <div class="modal" id="issueCallSlipModal" tabindex="-1" aria-labelledby="issueCallSlipModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <form method="post" action="<?= site_url('guidance/call-slips/issue') ?>" class="modal-content gc-modal-scope gcs-issue-form" data-background-submit
            x-data="callSlipPersonPicker(<?= htmlspecialchars(json_encode([
                'searchUrl'      => site_url('api/patients/search'),
                'prefill'        => $prefillPerson,
                'defaultPurpose' => $defaultPurpose,
            ]), ENT_QUOTES, 'UTF-8') ?>)">
        <?= csrf_field() ?>
        <div class="modal-header">
          <div>
            <h2 class="modal-title fs-5" id="issueCallSlipModalLabel"><i class="bi bi-receipt-cutoff me-2" aria-hidden="true"></i>Issue Guidance Call Slip</h2>
            <p class="ce-filter-status mt-1">Create a pending invitation and its draft controlled form.</p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <fieldset class="gcs-form-section">
            <legend class="gcs-section-title"><i class="bi bi-person" aria-hidden="true"></i> Client &amp; Episode</legend>
            <div class="row g-3">
              <div class="col-md-8">
                <label for="callSlipPersonSearch" class="form-label">Student / Client <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="hidden" name="person_id" :value="selected ? selected.id : ''" required>
                <template x-if="selected">
                  <div class="card gc-selected-person-card p-3 mb-0">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                      <div class="gcs-selected-person">
                        <div class="fw-semibold" x-text="selected.first_name + ' ' + selected.last_name"></div>
                        <div class="small text-muted" x-text="(selected.mis_id || 'ID #' + selected.id) + (selected.kind ? ' · ' + selected.kind : '')"></div>
                      </div>
                      <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" @click="clear()">Change</button>
                    </div>
                  </div>
                </template>
                <div class="syn-person-lookup" data-person-lookup x-show="!selected">
                  <div data-person-lookup-anchor>
                    <input type="search" id="callSlipPersonSearch" x-ref="personSearch" class="form-control" x-model="query" @input.debounce.300ms="search()" autocomplete="off" placeholder="Search by name or MIS ID..." aria-describedby="callSlipPersonHelp">
                  </div>
                  <div class="syn-person-lookup-menu" data-person-lookup-menu x-show="searching || results.length > 0 || searched || searchError" style="display: none;">
                    <div class="syn-person-lookup-status" role="status" aria-live="polite" x-show="searching">Searching local directory...</div>
                    <div class="syn-person-lookup-status" role="status" x-show="!searching && searched && results.length === 0 && !searchError">No matching clients. Try another name or MIS ID.</div>
                    <div class="syn-person-lookup-status" role="alert" x-show="searchError">
                      <span x-text="searchError"></span>
                      <button type="button" class="btn btn-outline-secondary btn-sm mt-2" @click="search()">Retry lookup</button>
                    </div>
                    <div class="list-group syn-person-lookup-list" x-show="results.length > 0">
                      <template x-for="p in results" :key="p.id">
                        <button type="button" class="list-group-item list-group-item-action py-2" @click="pick(p)">
                          <div class="fw-semibold small" x-text="p.first_name + ' ' + p.last_name"></div>
                          <div class="small text-muted" x-text="(p.mis_id || 'ID #' + p.id) + (p.kind ? ' · ' + p.kind : '')"></div>
                        </button>
                      </template>
                    </div>
                  </div>
                  <div class="form-text" id="callSlipPersonHelp">Type at least two characters, then select the exact client.</div>
                </div>
              </div>
              <div class="col-md-4">
                <label for="callSlipEpisodeId" class="form-label">Episode ID <span class="text-muted fw-normal">(Optional)</span></label>
                <input type="number" min="1" id="callSlipEpisodeId" name="episode_id" class="form-control ce-mono" value="<?= $prefillEpisodeId > 0 ? $prefillEpisodeId : '' ?>" placeholder="Episode #">
                <div class="form-text">Link this invitation to an existing care episode.</div>
              </div>
            </div>
          </fieldset>

          <fieldset class="gcs-form-section">
            <legend class="gcs-section-title"><i class="bi bi-calendar3" aria-hidden="true"></i> Schedule &amp; Purpose</legend>
            <div class="row g-3">
              <div class="col-md-4">
                <label for="callSlipDateDisplay" class="form-label">Call Date <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" id="callSlipDateDisplay" class="form-control" data-datepicker data-target="#callSlipDate" placeholder="DD/MM/YYYY" required autocomplete="off">
                <input type="hidden" id="callSlipDate" name="call_date" value="<?= esc(date('Y-m-d'), 'attr') ?>">
              </div>
              <div class="col-md-4">
                <label for="callSlipTimeDisplay" class="form-label">Call Time <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" id="callSlipTimeDisplay" class="form-control" data-timepicker data-target="#callSlipTime" data-default-hours="9" data-default-minutes="0" placeholder="hh:mm A" required autocomplete="off">
                <input type="hidden" id="callSlipTime" name="call_time" value="09:00">
              </div>
              <div class="col-md-4">
                <label for="callSlipPurpose" class="form-label">Purpose <span class="text-danger" aria-hidden="true">*</span></label>
                <select id="callSlipPurpose" name="purpose" class="form-select" required x-model="purpose">
                  <?php foreach ($validPurposes as $pKey): ?>
                    <option value="<?= esc($pKey, 'attr') ?>" <?= $pKey === $defaultPurpose ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', $pKey))) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12" x-show="purpose === 'others'" x-cloak>
                <label for="callSlipPurposeOther" class="form-label">Specify Other Purpose <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" id="callSlipPurposeOther" name="purpose_other" class="form-control" maxlength="255" :required="purpose === 'others'" :disabled="purpose !== 'others'" placeholder="Describe the reason for the call slip...">
              </div>
            </div>
          </fieldset>

          <fieldset class="gcs-form-section">
            <legend class="gcs-section-title"><i class="bi bi-send" aria-hidden="true"></i> Delivery &amp; Routing</legend>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="guardianDeliveryCheck" name="parent_guardian_delivery" value="1">
              <label class="form-check-label" for="guardianDeliveryCheck">Flag for Parent / Guardian Delivery</label>
            </div>
            <label for="callSlipDeliveryNotes" class="form-label">Delivery / Routing Notes <span class="text-muted fw-normal">(Optional)</span></label>
            <textarea id="callSlipDeliveryNotes" name="delivery_notes" rows="2" class="form-control" maxlength="500" placeholder="Messenger, adviser, or guardian delivery instructions..." aria-describedby="callSlipDeliveryHelp"></textarea>
            <div class="form-text" id="callSlipDeliveryHelp">Delivery instructions only; keep confidential session notes in the case workspace. Up to 500 characters.</div>
          </fieldset>
        </div>
        <div class="modal-footer">
          <span class="gcs-submit-hint" x-show="!selected">Select a client to continue.</span>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" disabled :disabled="!selected">
            <i class="bi bi-check-lg" aria-hidden="true"></i> Issue Controlled Call Slip
          </button>
        </div>
      </form>
    </div>
  </div>
</section>
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
        searched: false,
        searchError: '',
        searchSequence: 0,
        searchController: null,
        purpose: config.defaultPurpose || 'personal_counseling',
        async search() {
            const sequence = ++this.searchSequence;
            this.searchController?.abort();
            const q = (this.query || '').trim();
            this.results = [];
            this.searched = false;
            this.searchError = '';
            this.searching = false;
            if (q.length < 2 || this.selected) return;
            const controller = new AbortController();
            this.searchController = controller;
            this.searching = true;
            try {
                const resp = await fetch(this.searchUrl + '?q=' + encodeURIComponent(q), {
                    signal: controller.signal,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!resp.ok) throw new Error('Directory lookup could not be completed. Try again.');
                const data = await resp.json();
                if (sequence !== this.searchSequence || this.selected || (this.query || '').trim() !== q) return;
                const results = Array.isArray(data) ? data : (data.results || data.patients || data.items || []);
                this.results = Array.isArray(results) ? results : [];
                this.searched = true;
            } catch (e) {
                if (sequence === this.searchSequence && !controller.signal.aborted) {
                    this.searchError = 'Directory lookup could not be completed. Try again.';
                }
            } finally {
                if (sequence === this.searchSequence) {
                    this.searching = false;
                    this.searchController = null;
                }
            }
        },
        pick(p) {
            this.searchController?.abort();
            ++this.searchSequence;
            this.selected = p;
            this.results = [];
            this.query = '';
            this.searching = false;
            this.searched = false;
            this.searchError = '';
        },
        clear() {
            this.searchController?.abort();
            ++this.searchSequence;
            this.selected = null;
            this.results = [];
            this.query = '';
            this.searching = false;
            this.searched = false;
            this.searchError = '';
            const input = this.$refs.personSearch;
            this.$nextTick(() => input?.focus());
        },
        destroy() {
            this.searchController?.abort();
            ++this.searchSequence;
        }
    };
}

(function () {
    function init(root) {
        const modal = root.querySelector('#issueCallSlipModal');
        if (!modal || modal.dataset.callSlipLifecycle) return;
        modal.dataset.callSlipLifecycle = '1';
        window.SynapseNavigation?.registerCleanup(() => {
            window.bootstrap?.Modal.getInstance(modal)?.hide();
        });
    }
    document.addEventListener('synapse:page-load', event => init(event.detail.root), { once: true });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init(document), { once: true });
    } else {
        init(document);
    }
})();
</script>
<?= $this->endSection() ?>
