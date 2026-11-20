<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex gap-2">
  <a href="<?= esc(site_url('/guidance')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Guidance Dashboard
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="guidance-ui">
<?php
$statusFilter = $statusFilter ?? '';
$programFilter = $programFilter ?? '';
$miGateEnabled = (bool) ($miGateEnabled ?? false);
$canAssign = (bool) ($canAssign ?? false);
$assignments = $assignments ?? [];
$students = $students ?? [];
$programs = $programs ?? [];
$yearLevels = $yearLevels ?? ['1', '2', '3', '4'];
$activeLookup = array_flip(array_map('intval', $activePersonIds ?? []));

$badgeFor = static fn (string $status): string => match ($status) {
    'assigned'    => 'bg-info text-dark',
    'in_progress' => 'bg-warning text-dark',
    'completed'   => 'bg-success',
    'cancelled'   => 'bg-secondary',
    default       => 'bg-secondary',
};
?>

<?php if (!$miGateEnabled): ?>
  <div class="alert alert-warning border-0 shadow-sm d-flex align-items-start gap-2 mb-4" role="status">
    <i class="bi bi-shield-exclamation fs-5 mt-1" aria-hidden="true"></i>
    <div>
      <strong>Activation Gate Pending (<code>mi_reproduction_signoff</code>):</strong>
      Multi-authority sign-off (Guidance Head, QA Office, DPO) is still pending for Multiple Intelligence reproduction and interpretation policy. Students can view assigned surveys and save encrypted drafts, while live production finalization remains gated until sign-off is complete.
    </div>
  </div>
<?php endif; ?>

<div class="row g-4">
  <?php if ($canAssign): ?>
  <div class="col-lg-4 mi-assign-left-rail">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent py-3">
        <h5 class="card-title mb-0 fw-bold">
          <i class="bi bi-person-plus me-2 text-primary" aria-hidden="true"></i>Assign 80-Item MI Survey
        </h5>
      </div>
      <div class="card-body">
        <form method="POST" action="<?= esc(site_url('/guidance/assessments/mi/assign'), 'attr') ?>" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <span class="form-label fw-semibold d-block">Assignment Mode</span>
            <div class="btn-group w-100" role="group" aria-label="Assignment mode">
              <input type="radio" class="btn-check" name="assign_scope" id="mi-scope-individual" value="individual" autocomplete="off" checked>
              <label class="btn btn-primary" for="mi-scope-individual">Specific Students <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="top" title="Search and check one or multiple students below. Students with an active assignment are skipped in batch mode." aria-label="Search and check one or multiple students below. Students with an active assignment are skipped in batch mode."></i></label>
              <input type="radio" class="btn-check" name="assign_scope" id="mi-scope-cohort" value="cohort" autocomplete="off">
              <label class="btn btn-outline-secondary" for="mi-scope-cohort">Cohort / Group <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="top" title="If both Programs and Year Levels are checked, matching students in those programs and years are assigned. Existing active assignments are skipped automatically." aria-label="If both Programs and Year Levels are checked, matching students in those programs and years are assigned. Existing active assignments are skipped automatically."></i></label>
            </div>
          </div>

          <div id="mi-assign-individual-panel" class="mb-3">
            <div>
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label for="mi-student-checklist-search" class="form-label fw-semibold mb-0">Select Student(s)</label>
                <span class="badge bg-primary-subtle text-primary border"><span id="mi-selected-count">0</span> selected</span>
              </div>
              <input id="mi-student-checklist-search" type="search" class="form-control mb-2" placeholder="Filter by name, MIS ID, or program…" autocomplete="off">
              <div class="d-flex gap-2 mb-2">
                <button type="button" id="mi-select-visible-btn" class="btn btn-outline-secondary flex-fill">Select all visible</button>
                <button type="button" id="mi-clear-selected-btn" class="btn btn-outline-secondary">Clear</button>
              </div>
              <div class="border rounded p-2 bg-body-tertiary" style="max-height: 220px; overflow-y: auto;">
                <?php if ($students === []): ?>
                  <div class="small text-muted text-center py-2">No active student records found.</div>
                <?php else: ?>
                  <?php foreach ($students as $stu): ?>
                    <?php
                    $stuId = (int) $stu['id'];
                    $stuName = trim(($stu['last_name'] ?? '') . ', ' . ($stu['first_name'] ?? '') . (!empty($stu['middle_name']) ? ' ' . $stu['middle_name'] : ''));
                    $stuMeta = trim(($stu['mis_id'] ?? '') . (!empty($stu['program']) ? ' · ' . $stu['program'] : '') . (!empty($stu['year_level']) ? ' Y' . $stu['year_level'] : ''));
                    $hasActive = isset($activeLookup[$stuId]);
                    ?>
                    <div class="form-check py-1 border-bottom border-opacity-25 mi-student-check-row" data-search="<?= esc(strtolower($stuName . ' ' . $stuMeta), 'attr') ?>">
                      <input class="form-check-input mi-student-checkbox" type="checkbox" name="person_ids[]" value="<?= $stuId ?>" id="mi-stu-check-<?= $stuId ?>">
                      <label class="form-check-label d-flex justify-content-between align-items-start gap-2 w-100 small" for="mi-stu-check-<?= $stuId ?>">
                        <span>
                          <span class="fw-semibold d-block"><?= esc($stuName) ?></span>
                          <span class="text-muted"><?= esc($stuMeta) ?></span>
                        </span>
                        <?php if ($hasActive): ?>
                          <span class="badge bg-info-subtle text-info-emphasis border flex-shrink-0">Active</span>
                        <?php endif; ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                  <div id="mi-student-checklist-empty" class="small text-muted text-center py-2 d-none">No students match your filter.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div id="mi-assign-cohort-panel" class="mb-3 d-none">
            <div class="border rounded p-3 bg-body-tertiary">
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="mi-assign-all-students" name="assign_all_students" value="1">
                <label class="form-check-label fw-semibold" for="mi-assign-all-students">All Active Students</label>
                <div class="form-text mt-0">Assign to every active student in the tenant without an active MI survey.</div>
              </div>

              <div class="mb-3">
                <span class="d-block small fw-semibold mb-1">Programs</span>
                <?php if ($programs === []): ?>
                  <p class="small text-muted mb-0">No student programs found yet.</p>
                <?php else: ?>
                  <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($programs as $idx => $program): ?>
                      <?php $progId = 'mi-program-' . $idx; ?>
                      <div class="form-check me-2">
                        <input class="form-check-input mi-cohort-filter-input" type="checkbox" id="<?= esc($progId, 'attr') ?>" name="target_programs[]" value="<?= esc($program, 'attr') ?>">
                        <label class="form-check-label small" for="<?= esc($progId, 'attr') ?>"><?= esc($program) ?></label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <div>
                <span class="d-block small fw-semibold mb-1">Year Levels</span>
                <div class="d-flex flex-wrap gap-3">
                  <?php foreach ($yearLevels as $idx => $yr): ?>
                    <?php $yrId = 'mi-year-' . $idx; ?>
                    <div class="form-check">
                      <input class="form-check-input mi-cohort-filter-input" type="checkbox" id="<?= esc($yrId, 'attr') ?>" name="target_year_levels[]" value="<?= esc((string) $yr, 'attr') ?>">
                      <label class="form-check-label small" for="<?= esc($yrId, 'attr') ?>">Year <?= esc((string) $yr) ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label for="mi-academic-term" class="form-label fw-semibold">Academic Term</label>
              <input id="mi-academic-term" type="text" name="academic_term" class="form-control" maxlength="64" placeholder="e.g. 1st Trimester">
            </div>
            <div class="col-sm-6">
              <label for="mi-academic-year" class="form-label fw-semibold">Academic Year</label>
              <input id="mi-academic-year" type="text" name="academic_year" class="form-control" maxlength="32" placeholder="e.g. 2026-2027">
            </div>
          </div>

          <div class="mb-3">
            <label for="mi-due-at-display" class="form-label fw-semibold">Target Due Date <span class="text-muted fw-normal">(Optional)</span></label>
            <input id="mi-due-at-display" type="text" class="form-control" data-datepicker data-target="#mi-due-at" data-min="today" placeholder="DD/MM/YYYY" autocomplete="off">
            <input id="mi-due-at" type="hidden" name="due_at" value="">
          </div>

          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-send-check me-1" aria-hidden="true"></i>Assign MI Survey
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="<?= $canAssign ? 'col-lg-8' : 'col-12' ?>">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent d-flex flex-column flex-md-row flex-wrap justify-content-between align-items-stretch align-items-md-center gap-2 py-3">
        <h5 class="card-title mb-0 fw-bold">
          <i class="bi bi-ui-checks-grid me-2 text-primary" aria-hidden="true"></i>Assigned Multiple Intelligence Surveys
        </h5>
        <form method="GET" action="<?= esc(site_url('/guidance/assessments/mi'), 'attr') ?>" class="d-flex flex-wrap flex-md-nowrap gap-2 align-items-center gc-filter-bar w-100 w-md-auto ms-md-auto">
          <select name="program" class="form-select" aria-label="Filter by program">
            <option value="">All Programs</option>
            <?php foreach ($programs as $prog): ?>
              <option value="<?= esc($prog, 'attr') ?>" <?= $programFilter === $prog ? 'selected' : '' ?>><?= esc($prog) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" class="form-select" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <?php foreach (['assigned' => 'Assigned', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $val => $label): ?>
              <option value="<?= esc($val) ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline-primary">Filter</button>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-muted small text-uppercase">
              <tr>
                <th class="ps-3">Student</th>
                <th>Term / Year</th>
                <th>Assigned</th>
                <th>Status</th>
                <th class="text-end pe-3">Results &amp; PDF</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($assignments === []): ?>
                <tr>
                  <td colspan="5" class="text-center py-5 text-muted">
                    <i class="bi bi-clipboard2-data fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
                    No Multiple Intelligence survey assignments found.
                  </td>
                </tr>
              <?php else: ?>
                <?php
                $groupedAssignments = [];
                $unassignedRows = [];
                foreach ($assignments as $row) {
                    $progName = trim((string) ($row['program'] ?? ''));
                    if ($progName === '') {
                        $unassignedRows[] = $row;
                    } else {
                        $groupedAssignments[$progName][] = $row;
                    }
                }
                uksort($groupedAssignments, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
                if ($unassignedRows !== []) {
                    $groupedAssignments['Unassigned Program'] = $unassignedRows;
                }
                ?>
                <?php foreach ($groupedAssignments as $programLabel => $groupRows): ?>
                  <?php
                  $completedCount = count(array_filter(
                      $groupRows,
                      static fn (array $r): bool => ($r['status'] ?? '') === 'completed'
                  ));
                  ?>
                  <tr class="table-secondary mi-program-group-header">
                    <td colspan="5" class="ps-3 py-2">
                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="fw-bold small text-uppercase">
                          <i class="bi bi-mortarboard-fill me-2" aria-hidden="true"></i><?= esc($programLabel) ?>
                        </span>
                        <span class="badge bg-body text-body border"><?= count($groupRows) ?> assigned · <?= $completedCount ?> completed</span>
                      </div>
                    </td>
                  </tr>
                  <?php foreach ($groupRows as $row): ?>
                    <?php
                    $fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : ''));
                    $isDone = ($row['status'] ?? '') === 'completed' && !empty($row['submission_id']);
                    ?>
                    <tr>
                      <td class="ps-3">
                        <div class="fw-semibold"><?= esc($fullName) ?></div>
                        <small class="text-muted">
                          ID: <?= esc($row['mis_id'] ?? 'N/A') ?>
                          <?php if (!empty($row['program'])): ?> · <?= esc($row['program']) ?><?php endif; ?>
                          <?php if (!empty($row['year_level'])): ?> · Year <?= esc((string) $row['year_level']) ?><?php endif; ?>
                        </small>
                      </td>
                      <td>
                        <span class="small"><?= esc($row['academic_term'] ?: '—') ?></span>
                        <?php if (!empty($row['academic_year'])): ?>
                          <small class="d-block text-muted"><?= esc($row['academic_year']) ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="small"><?= esc(substr((string) ($row['assigned_at'] ?? ''), 0, 10)) ?></span>
                        <?php if (!empty($row['completed_at'])): ?>
                          <small class="d-block text-success">Completed <?= esc(substr((string) $row['completed_at'], 0, 10)) ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge <?= $badgeFor((string) ($row['status'] ?? '')) ?>">
                          <?= esc(ucwords(str_replace('_', ' ', (string) ($row['status'] ?? '')))) ?>
                        </span>
                      </td>
                      <td class="text-md-end pe-md-3">
                        <?php if ($isDone): ?>
                          <div class="d-flex flex-wrap justify-content-md-end gap-2 gc-card-actions">
                            <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $row['submission_id']), 'attr') ?>" class="btn btn-outline-primary btn-sm">
                              <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i>8-Domain Profile
                            </a>
                            <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $row['submission_id'] . '/pdf'), 'attr') ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                              <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                            </a>
                          </div>
                        <?php else: ?>
                          <span class="text-muted small">Awaiting student completion</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="px-3 pb-3 border-top">
          <?= view('partials/table_pagination', [
              'pagination' => $pagination ?? [
                  'per_page' => 25,
                  'filtered' => count($assignments),
                  'total'    => count($assignments),
                  'pages'    => 1,
                  'page'     => 1,
              ],
              'rowCount'             => count($assignments),
              'filters'              => array_filter(['status' => $statusFilter], static fn (string $v): bool => $v !== ''),
              'paginationExtraQuery' => array_filter(['program' => $programFilter], static fn (string $v): bool => $v !== ''),
              'baseUrl'              => site_url('/guidance/assessments/mi'),
              'totalLabel'           => 'MI assignments',
              'paginationLabel'      => 'Multiple Intelligence assignment pages',
          ], ['saveData' => false]) ?>
        </div>
      </div>
    </div>
  </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
  /* overflow-x: clip preserves horizontal containment without creating a scroll container that blocks sticky. */
  #main.main,
  #main.main > .main-content {
    overflow-x: clip;
    overflow-y: visible;
  }
  @media (min-width: 992px) {
    .mi-assign-left-rail {
      position: sticky;
      top: calc(var(--header-height, 64px) + 1rem);
      align-self: flex-start;
      z-index: 10;
    }
  }
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
  (function() {
    'use strict';

    function initMiAssignmentForm(root) {
      var scope = root && typeof root.querySelector === 'function' ? root : document;
      var form = scope.querySelector('form[action*="/guidance/assessments/mi/assign"]');
      if (!form || form.dataset.miAssignBound === '1') return;
      form.dataset.miAssignBound = '1';

      var scopeRadios = form.querySelectorAll('input[name="assign_scope"]');
      var individualPanel = form.querySelector('#mi-assign-individual-panel');
      var cohortPanel = form.querySelector('#mi-assign-cohort-panel');
      var searchInput = form.querySelector('#mi-student-checklist-search');
      var checkRows = Array.prototype.slice.call(form.querySelectorAll('.mi-student-check-row'));
      var checkboxes = Array.prototype.slice.call(form.querySelectorAll('.mi-student-checkbox'));
      var emptyRow = form.querySelector('#mi-student-checklist-empty');
      var selectedCountEl = form.querySelector('#mi-selected-count');
      var selectVisibleBtn = form.querySelector('#mi-select-visible-btn');
      var clearBtn = form.querySelector('#mi-clear-selected-btn');
      var allStudentsToggle = form.querySelector('#mi-assign-all-students');
      var cohortFilters = Array.prototype.slice.call(form.querySelectorAll('.mi-cohort-filter-input'));

      function updateSelectedCount() {
        if (!selectedCountEl) return;
        var count = 0;
        for (var i = 0; i < checkboxes.length; i++) {
          if (checkboxes[i].checked) count++;
        }
        selectedCountEl.textContent = String(count);
      }

      function syncCohortFilters() {
        var isCohort = cohortPanel && !cohortPanel.classList.contains('d-none');
        var allChecked = Boolean(allStudentsToggle && allStudentsToggle.checked);
        if (allStudentsToggle) {
          allStudentsToggle.disabled = !isCohort;
        }
        for (var i = 0; i < cohortFilters.length; i++) {
          cohortFilters[i].disabled = !isCohort || allChecked;
        }
      }

      function syncScopePanels() {
        var selectedScope = 'individual';
        for (var i = 0; i < scopeRadios.length; i++) {
          if (scopeRadios[i].checked) {
            selectedScope = scopeRadios[i].value;
            break;
          }
        }
        var isCohort = selectedScope === 'cohort';
        for (var k = 0; k < scopeRadios.length; k++) {
          var label = scopeRadios[k].id ? form.querySelector('label[for="' + scopeRadios[k].id + '"]') : null;
          if (!label) continue;
          var active = scopeRadios[k].value === selectedScope;
          label.classList.toggle('btn-primary', active);
          label.classList.toggle('btn-outline-secondary', !active);
        }
        if (individualPanel) {
          individualPanel.classList.toggle('d-none', isCohort);
          var indControls = individualPanel.querySelectorAll('input, select, button');
          for (var j = 0; j < indControls.length; j++) {
            indControls[j].disabled = isCohort;
          }
        }
        if (cohortPanel) {
          cohortPanel.classList.toggle('d-none', !isCohort);
        }
        syncCohortFilters();
      }

      function filterChecklist() {
        var term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var visibleCount = 0;
        for (var i = 0; i < checkRows.length; i++) {
          var haystack = checkRows[i].getAttribute('data-search') || '';
          var visible = term === '' || haystack.indexOf(term) !== -1;
          checkRows[i].classList.toggle('d-none', !visible);
          if (visible) visibleCount++;
        }
        if (emptyRow) {
          emptyRow.classList.toggle('d-none', visibleCount > 0 || checkRows.length === 0);
        }
      }

      for (var i = 0; i < scopeRadios.length; i++) {
        scopeRadios[i].addEventListener('change', syncScopePanels);
      }
      if (searchInput) {
        searchInput.addEventListener('input', filterChecklist);
      }
      if (selectVisibleBtn) {
        selectVisibleBtn.addEventListener('click', function() {
          for (var i = 0; i < checkRows.length; i++) {
            if (checkRows[i].classList.contains('d-none')) continue;
            var cb = checkRows[i].querySelector('.mi-student-checkbox');
            if (cb) cb.checked = true;
          }
          updateSelectedCount();
        });
      }
      if (clearBtn) {
        clearBtn.addEventListener('click', function() {
          for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = false;
          }
          updateSelectedCount();
        });
      }
      for (var j = 0; j < checkboxes.length; j++) {
        checkboxes[j].addEventListener('change', updateSelectedCount);
      }
      if (allStudentsToggle) {
        allStudentsToggle.addEventListener('change', syncCohortFilters);
      }

      syncScopePanels();
      filterChecklist();
      updateSelectedCount();
    }

    if (!document.body.classList.contains('is-navigating')) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { initMiAssignmentForm(document); }, { once: true });
      } else {
        initMiAssignmentForm(document);
      }
    }
    document.addEventListener('synapse:page-load', function(event) {
      initMiAssignmentForm(event && event.detail && event.detail.root);
    }, { once: true });
  })();
</script>
<?= $this->endSection() ?>
