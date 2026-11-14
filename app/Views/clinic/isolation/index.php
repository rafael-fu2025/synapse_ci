<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2">
  <a href="<?= site_url('/clinic/reports') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-file-earmark-bar-graph me-1"></i> Periodic Reports
  </a>
  <?php if (!empty($canWrite)): ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logIsolationModal">
      <i class="bi bi-plus-circle me-1"></i> Log Isolation Case
    </button>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$conditionColors = [
    'chickenpox' => 'danger',
    'measles'    => 'danger',
    'dengue'     => 'warning',
    'covid19'    => 'primary',
    'influenza'  => 'info',
    'ptb'        => 'dark',
    'mumps'      => 'secondary',
    'other'      => 'secondary',
];
?>

<?php if (!empty($alerts)): ?>
  <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
    <div class="d-flex align-items-start gap-2">
      <i class="bi bi-exclamation-octagon-fill fs-4 mt-1"></i>
      <div>
        <h6 class="fw-bold mb-1">Anonymized Communicable Threshold Alerts (Last 14 Days)</h6>
        <p class="small mb-2">Threshold (&ge;2 cases per condition within 14 days). Aggregates exclude patient identity to preserve medical confidentiality.</p>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($alerts as $alert): ?>
            <span class="badge bg-white text-danger border border-danger-subtle px-3 py-2">
              <strong><?= esc($alert['condition_label']) ?>:</strong> <?= (int) ($alert['case_count'] ?? 0) ?> cases · Cohort: <?= esc($alert['cohort_summary'] ?? 'Campus-wide') ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Condition KPI Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
      <div class="card-body">
        <div class="text-muted small text-uppercase fw-semibold">Currently Under Isolation</div>
        <div class="h2 fw-bold text-danger mt-1 mb-0"><?= (int) ($activeCount ?? 0) ?></div>
        <div class="small text-muted">Active campus monitoring</div>
      </div>
    </div>
  </div>
  <?php foreach (['chickenpox', 'measles', 'dengue'] as $highlightCode): ?>
    <?php $counts = $conditionCounts[$highlightCode] ?? ['active' => 0, 'total' => 0]; ?>
    <div class="col-md-3 col-sm-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase fw-semibold"><?= esc($conditions[$highlightCode] ?? $highlightCode) ?></div>
          <div class="d-flex align-items-baseline gap-2 mt-1">
            <span class="h2 fw-bold mb-0"><?= (int) $counts['active'] ?></span>
            <span class="small text-muted">active / <?= (int) $counts['total'] ?> total</span>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Filter & Month Navigation Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <form method="GET" action="<?= site_url('/clinic/isolation') ?>" class="row g-2 align-items-center">
      <div class="col-md-4 d-flex align-items-center gap-2">
        <a href="<?= site_url('/clinic/isolation?' . http_build_query(['month' => $prevMonth, 'condition' => $filters['condition'], 'status' => $filters['status']])) ?>" class="btn btn-outline-secondary btn-sm" title="Previous month">
          <i class="bi bi-chevron-left"></i>
        </a>
        <h5 class="fw-bold mb-0 text-center flex-grow-1"><?= esc($monthLabel) ?></h5>
        <a href="<?= site_url('/clinic/isolation?' . http_build_query(['month' => $nextMonth, 'condition' => $filters['condition'], 'status' => $filters['status']])) ?>" class="btn btn-outline-secondary btn-sm" title="Next month">
          <i class="bi bi-chevron-right"></i>
        </a>
        <input type="hidden" name="month" value="<?= esc($month) ?>">
      </div>
      <div class="col-md-3">
        <select name="condition" class="form-select form-select-sm" aria-label="Filter by condition">
          <option value="all">All Conditions</option>
          <?php foreach ($conditions as $code => $label): ?>
            <option value="<?= esc($code) ?>" <?= $filters['condition'] === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="status" class="form-select form-select-sm" aria-label="Filter by clearance status">
          <option value="all">All Statuses</option>
          <option value="active_isolation" <?= $filters['status'] === 'active_isolation' ? 'selected' : '' ?>>Active Isolation</option>
          <option value="cleared" <?= $filters['status'] === 'cleared' ? 'selected' : '' ?>>Cleared</option>
          <option value="hospitalized" <?= $filters['status'] === 'hospitalized' ? 'selected' : '' ?>>Hospitalized</option>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-outline-primary btn-sm flex-grow-1">Apply</button>
        <a href="<?= site_url('/clinic/isolation') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Monthly Isolation Calendar -->
<?php
$monthStartDt = new \DateTimeImmutable($monthStart, new \DateTimeZone('Asia/Manila'));
$daysInMonth = (int) $monthStartDt->format('t');
$leadingEmpty = ((int) $monthStartDt->format('N')) - 1; // Mon = 0 .. Sun = 6
$casesByDay = [];
foreach ($cases as $c) {
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $iso = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
        if ($c['isolation_start_date'] <= $iso && $c['isolation_end_date'] >= $iso) {
            $casesByDay[$iso][] = $c;
        }
    }
}
?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
    <h6 class="fw-bold mb-0"><i class="bi bi-calendar3 me-1 text-primary"></i> Monthly Isolation Calendar (<?= esc($monthLabel) ?>)</h6>
    <span class="small text-muted"><?= count($cases) ?> case(s) overlapping this month</span>
  </div>
  <div class="card-body p-2">
    <div class="table-responsive">
      <table class="table table-bordered mb-0 small" style="table-layout: fixed;">
        <thead class="table-light text-center text-muted text-uppercase">
          <tr>
            <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $cell = 0;
          $totalCells = $leadingEmpty + $daysInMonth;
          $rows = (int) ceil($totalCells / 7);
          for ($r = 0; $r < $rows; $r++): ?>
            <tr>
              <?php for ($col = 0; $col < 7; $col++):
                $dayNum = ($r * 7 + $col) - $leadingEmpty + 1;
                if ($dayNum < 1 || $dayNum > $daysInMonth): ?>
                  <td class="bg-light-subtle" style="height: 86px;"></td>
                <?php else:
                  $isoDate = $month . '-' . str_pad((string) $dayNum, 2, '0', STR_PAD_LEFT);
                  $dayCases = $casesByDay[$isoDate] ?? [];
                  $isToday = ($isoDate === $today);
                ?>
                  <td class="<?= $isToday ? 'bg-primary-subtle' : '' ?> align-top p-1" style="height: 86px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <span class="fw-bold <?= $isToday ? 'text-primary' : 'text-muted' ?>"><?= $dayNum ?></span>
                      <?php if (count($dayCases) > 0): ?>
                        <span class="badge bg-danger-subtle text-danger rounded-pill"><?= count($dayCases) ?></span>
                      <?php endif; ?>
                    </div>
                    <?php foreach (array_slice($dayCases, 0, 2) as $dc): ?>
                      <?php $colTone = $conditionColors[$dc['condition_code']] ?? 'secondary'; ?>
                      <div class="badge bg-<?= $colTone ?>-subtle text-<?= $colTone ?> text-truncate d-block text-start mb-1" title="<?= esc($dc['last_name'] . ', ' . $dc['first_name'] . ' — ' . ($conditions[$dc['condition_code']] ?? $dc['condition_code'])) ?>">
                        <?= esc($dc['last_name']) ?> · <?= esc(ucfirst($dc['condition_code'])) ?>
                      </div>
                    <?php endforeach; ?>
                    <?php if (count($dayCases) > 2): ?>
                      <div class="text-muted" style="font-size: 0.7rem;">+<?= count($dayCases) - 2 ?> more</div>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              <?php endfor; ?>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Case List Table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent py-3">
    <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-1 text-primary"></i> Tracked Isolation Cases</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle small mb-0">
        <thead class="table-light text-muted text-uppercase">
          <tr>
            <th class="ps-3">Patient</th>
            <th>Condition</th>
            <th>Isolation Window</th>
            <th>Status</th>
            <th>Notes &amp; Attribution</th>
            <?php if (!empty($canWrite)): ?><th class="text-end pe-3">Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($cases)): ?>
            <tr>
              <td colspan="<?= !empty($canWrite) ? 6 : 5 ?>" class="text-center py-4 text-muted">
                No communicable isolation cases match the current filters.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($cases as $c): ?>
              <tr>
                <td class="ps-3">
                  <a href="<?= site_url('/clinic/patients/' . (int) $c['person_id']) ?>" class="fw-bold text-decoration-none">
                    <?= esc($c['last_name'] . ', ' . $c['first_name']) ?>
                  </a>
                  <div class="text-muted">
                    <?= esc($c['mis_id']) ?>
                    <?php if (!empty($c['program'])): ?> · <?= esc($c['program']) ?><?php elseif (!empty($c['emp_dept'])): ?> · <?= esc($c['emp_dept']) ?><?php endif; ?>
                  </div>
                </td>
                <td>
                  <span class="fw-semibold"><?= esc($conditions[$c['condition_code']] ?? $c['condition_code']) ?></span>
                  <?php if (!empty($c['condition_other'])): ?>
                    <div class="text-muted"><?= esc($c['condition_other']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?= esc($c['isolation_start_date']) ?> &rarr; <?= esc($c['isolation_end_date']) ?></div>
                  <div class="text-muted"><?= (int) $c['isolation_days'] ?> day(s)</div>
                </td>
                <td>
                  <?php $sClass = match ($c['clearance_status']) { 'cleared' => 'bg-success', 'hospitalized' => 'bg-warning text-dark', default => 'bg-danger' }; ?>
                  <span class="badge <?= $sClass ?> text-uppercase"><?= esc(str_replace('_', ' ', (string) $c['clearance_status'])) ?></span>
                </td>
                <td class="text-break" style="max-width: 260px;">
                  <?= esc($c['notes'] ?? '—') ?>
                  <div class="text-muted" style="font-size: 0.75rem;">
                    Logged by <?= esc($c['reported_by_name'] ?? 'Clinic staff') ?>
                    <?php if (!empty($c['cleared_by_name'])): ?> · Updated by <?= esc($c['cleared_by_name']) ?><?php endif; ?>
                  </div>
                </td>
                <?php if (!empty($canWrite)): ?>
                  <td class="text-end pe-3">
                    <?php if ($c['clearance_status'] === 'active_isolation'): ?>
                      <button type="button" class="btn btn-outline-success btn-sm"
                              data-bs-toggle="modal"
                              data-bs-target="#clearIsolationModal<?= (int) $c['id'] ?>">
                        <i class="bi bi-check2-circle me-1"></i> Update / Clear
                      </button>
                    <?php else: ?>
                      <span class="text-muted">Closed</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!empty($canWrite) && !empty($cases)): ?>
  <?php foreach ($cases as $c): ?>
    <?php if (($c['clearance_status'] ?? '') === 'active_isolation'): ?>
      <div class="modal fade text-start" id="clearIsolationModal<?= (int) $c['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <form method="POST" action="<?= site_url('/clinic/isolation/' . (int) $c['id'] . '/clear') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
              <h5 class="modal-title">Update Isolation Status</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <p class="small text-muted mb-3">
                Patient: <strong><?= esc($c['last_name'] . ', ' . $c['first_name']) ?></strong> · <?= esc($conditions[$c['condition_code']] ?? $c['condition_code']) ?>
              </p>
              <div class="mb-3">
                <label class="form-label small fw-semibold" for="clearStatus<?= (int) $c['id'] ?>">Clearance Decision</label>
                <select name="clearance_status" id="clearStatus<?= (int) $c['id'] ?>" class="form-select form-select-sm">
                  <option value="cleared">Cleared to Return to Class / Work</option>
                  <option value="hospitalized">Referred / Hospitalized</option>
                </select>
              </div>
              <div class="mb-0">
                <label class="form-label small fw-semibold" for="clearNotes<?= (int) $c['id'] ?>">Clearance Notes</label>
                <textarea name="notes" id="clearNotes<?= (int) $c['id'] ?>" rows="2" class="form-control form-control-sm" placeholder="Afebrile, lesions crusted, fit for class..."></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-success btn-sm">Confirm Status Update</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($canWrite)): ?>
<!-- Log Isolation Case Modal -->
<div class="modal fade" id="logIsolationModal" tabindex="-1" aria-labelledby="logIsolationModalLabel" aria-hidden="true"
     x-data="isolationLogForm(<?= esc((string) json_encode(['defaultDays' => $defaultDays, 'today' => $today, 'searchUrl' => site_url('/api/patients/search?q=')]), 'attr') ?>)">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="<?= site_url('/clinic/isolation/store') ?>" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="logIsolationModalLabel"><i class="bi bi-shield-exclamation text-danger me-2"></i>Log Communicable Disease / Isolation Case</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Patient Lookup -->
        <div class="mb-3">
          <label class="form-label small fw-semibold">Patient (Student or Employee)</label>
          <input type="hidden" name="person_id" :value="selectedPerson ? selectedPerson.id : ''" required>
          <template x-if="!selectedPerson">
            <div>
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" x-model="searchTerm" @input.debounce.300ms="lookupPatient()" placeholder="Type name or MIS ID (min 2 chars)...">
                <span class="input-group-text" x-show="loading"><span class="spinner-border spinner-border-sm"></span></span>
              </div>
              <div class="list-group mt-1 shadow-sm" x-show="results.length > 0">
                <template x-for="p in results" :key="p.id">
                  <button type="button" class="list-group-item list-group-item-action py-2 small d-flex justify-content-between align-items-center" @click="pick(p)">
                    <span><strong x-text="p.last_name + ', ' + p.first_name"></strong> (<span x-text="p.mis_id"></span>)</span>
                    <span class="badge bg-light text-dark border text-uppercase" x-text="p.kind"></span>
                  </button>
                </template>
              </div>
              <div class="text-danger small mt-1" x-show="searchError" x-text="searchError"></div>
            </div>
          </template>
          <template x-if="selectedPerson">
            <div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-0 small">
              <span>
                <strong x-text="selectedPerson.last_name + ', ' + selectedPerson.first_name"></strong>
                · MIS ID: <span x-text="selectedPerson.mis_id"></span>
              </span>
              <button type="button" class="btn btn-outline-secondary btn-sm py-0" @click="clearPick()">Change</button>
            </div>
          </template>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label for="isoConditionCode" class="form-label small fw-semibold">Communicable Condition</label>
            <select name="condition_code" id="isoConditionCode" class="form-select form-select-sm" x-model="condition" @change="applyDefaultDays()" required>
              <?php foreach ($conditions as $code => $label): ?>
                <option value="<?= esc($code) ?>"><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6" x-show="condition === 'other'">
            <label for="isoConditionOther" class="form-label small fw-semibold">Specify Illness</label>
            <input type="text" name="condition_other" id="isoConditionOther" class="form-control form-control-sm" placeholder="Enter illness name" maxlength="100" :required="condition === 'other'">
          </div>
          <div class="col-md-4">
            <label for="isoStartDateDisplay" class="form-label small fw-semibold">Isolation Start Date</label>
            <input type="text" class="form-control form-control-sm" id="isoStartDateDisplay"
                   data-datepicker data-target="#isoStartDateIso" placeholder="DD/MM/YYYY">
            <input type="hidden" name="isolation_start_date" id="isoStartDateIso" value="<?= esc($today) ?>" x-model="startDate" @input="startDate = $el.value" @change="startDate = $el.value" required>
          </div>
          <div class="col-md-4">
            <label for="isoDays" class="form-label small fw-semibold">Duration (Days)</label>
            <input type="number" name="isolation_days" id="isoDays" min="1" max="90" class="form-control form-control-sm" x-model.number="days" required>
            <div class="form-text small" x-text="'Estimated clearance: ' + previewEndDate()"></div>
          </div>
          <div class="col-md-4">
            <label for="isoStatus" class="form-label small fw-semibold">Initial Status</label>
            <select name="clearance_status" id="isoStatus" class="form-select form-select-sm">
              <option value="active_isolation">Active Isolation</option>
              <option value="hospitalized">Hospitalized</option>
            </select>
          </div>
        </div>

        <div class="mb-0">
          <label for="isoNotes" class="form-label small fw-semibold">Clinical &amp; Tracing Notes</label>
          <textarea name="notes" id="isoNotes" rows="2" class="form-control form-control-sm" placeholder="Symptom onset, quarantine advisory issued..." maxlength="2000"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger btn-sm" :disabled="!selectedPerson"><i class="bi bi-check2-circle me-1"></i> Save Isolation Case</button>
      </div>
    </form>
  </div>
</div>

<script>
function isolationLogForm(cfg) {
  return {
    searchTerm: '',
    results: [],
    selectedPerson: null,
    loading: false,
    searchError: '',
    condition: 'chickenpox',
    days: cfg.defaultDays?.chickenpox ?? 7,
    startDate: cfg.today || '',
    applyDefaultDays() {
      if (cfg.defaultDays && cfg.defaultDays[this.condition]) {
        this.days = cfg.defaultDays[this.condition];
      }
    },
    previewEndDate() {
      const hiddenEl = document.getElementById('isoStartDateIso');
      const raw = this.startDate || (hiddenEl ? hiddenEl.value : '');
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw || '');
      if (!m) return '—';
      const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
      if (Number.isNaN(d.getTime())) return '—';
      const offset = Math.max(1, Number(this.days || 1)) - 1;
      d.setDate(d.getDate() + offset);
      return [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, '0'),
        String(d.getDate()).padStart(2, '0'),
      ].join('-');
    },
    async lookupPatient() {
      const q = this.searchTerm.trim();
      this.searchError = '';
      this.results = [];
      if (q.length < 2) return;
      this.loading = true;
      try {
        const res = await fetch(cfg.searchUrl + encodeURIComponent(q));
        if (!res.ok) throw new Error();
        const data = await res.json();
        this.results = Array.isArray(data.results) ? data.results : [];
      } catch {
        this.searchError = 'Unable to query local directory.';
      } finally {
        this.loading = false;
      }
    },
    pick(p) {
      this.selectedPerson = p;
      this.results = [];
      this.searchTerm = '';
    },
    clearPick() {
      this.selectedPerson = null;
    }
  };
}
</script>
<?php endif; ?>
<?= $this->endSection() ?>

