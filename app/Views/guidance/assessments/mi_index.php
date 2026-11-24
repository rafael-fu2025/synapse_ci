<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="ce-page-actions">
  <a href="<?= esc(site_url('/guidance'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> Guidance Hub
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$statusFilter = (string) ($statusFilter ?? '');
$programFilter = (string) ($programFilter ?? '');
$miGateEnabled = (bool) ($miGateEnabled ?? false);
$canAssign = (bool) ($canAssign ?? false);
$assignments = $assignments ?? [];
$students = $students ?? [];
$programs = $programs ?? [];
$yearLevels = $yearLevels ?? ['1', '2', '3', '4'];
$activeLookup = array_flip(array_map('intval', $activePersonIds ?? []));
$pagination = $pagination ?? ['per_page' => 25, 'filtered' => count($assignments), 'total' => count($assignments), 'pages' => 1, 'page' => 1];
$perPage = (int) ($pagination['per_page'] ?? 25);
$perPage = in_array($perPage, [20, 25, 50, 100], true) ? $perPage : 25;
$statusLabels = ['assigned' => 'Assigned', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$statusColor = static fn (string $status): string => match ($status) {
    'assigned'    => 'ce-status-sky',
    'in_progress' => 'ce-status-amber',
    'completed'   => 'ce-status-emerald',
    default       => 'ce-status-slate',
};
$groupedAssignments = [];
$unassignedRows = [];
foreach ($assignments as $row) {
    $program = trim((string) ($row['program'] ?? ''));
    if ($program === '') {
        $unassignedRows[] = $row;
    } else {
        $groupedAssignments[$program][] = $row;
    }
}
uksort($groupedAssignments, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
$groups = [];
foreach ($groupedAssignments as $label => $rows) {
    $groups[] = ['label' => $label, 'rows' => $rows];
}
if ($unassignedRows !== []) {
    $groups[] = ['label' => 'Unassigned Program', 'rows' => $unassignedRows];
}
?>

<section class="guidance-ui ce-workspace gmi-workspace" aria-label="Multiple Intelligence registry workspace">
  <?php if (!$miGateEnabled): ?>
    <div class="ce-notice ce-notice-amber" role="status">
      <div class="ce-notice-body">
        <i class="bi bi-shield-exclamation ce-notice-icon" aria-hidden="true"></i>
        <span><strong>Activation Gate Pending (<code>mi_reproduction_signoff</code>)</strong> — Guidance Head, QA Office, and DPO sign-off is still pending for reproduction and interpretation policy. Surveys can be assigned and students can save encrypted drafts; live production finalization remains gated until sign-off is complete.</span>
      </div>
    </div>
  <?php else: ?>
    <div class="ce-notice ce-notice-sky">
      <div class="ce-notice-body">
        <i class="bi bi-ui-checks-grid ce-notice-icon" aria-hidden="true"></i>
        <span><strong>80-item self-assessment</strong> — Track survey assignments and open completed profiles across all eight domains. Assigning a survey does not submit or finalize student responses.</span>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$canAssign): ?>
    <p class="gmi-readonly"><i class="bi bi-eye" aria-hidden="true"></i> Read-only registry. Survey assignment requires the corresponding Guidance assessment permission.</p>
  <?php endif; ?>

  <div class="gmi-grid <?= $canAssign ? 'gmi-grid-managed' : '' ?>">
    <?php if ($canAssign): ?>
      <div class="mi-assign-left-rail gmi-assignment-rail">
        <div class="ce-card gmi-assignment-card" aria-labelledby="mi-assignment-heading">
          <div class="ce-card-header">
            <h2 class="ce-card-title" id="mi-assignment-heading"><i class="bi bi-person-plus" aria-hidden="true"></i> Assign 80-Item MI Survey</h2>
          </div>
          <div class="ce-card-body">
            <form method="POST" action="<?= esc(site_url('/guidance/assessments/mi/assign'), 'attr') ?>" autocomplete="off" class="gmi-assignment-form" data-mi-assignment-form data-background-submit>
              <?= csrf_field() ?>

              <fieldset class="gmi-form-section">
                <legend class="gmi-section-title">Recipients</legend>
                <div class="gmi-mode-switch" role="group" aria-label="Assignment mode">
                  <input type="radio" class="btn-check" name="assign_scope" id="mi-scope-individual" value="individual" autocomplete="off" checked>
                  <label class="btn btn-primary btn-sm" for="mi-scope-individual">Specific Students</label>
                  <input type="radio" class="btn-check" name="assign_scope" id="mi-scope-cohort" value="cohort" autocomplete="off">
                  <label class="btn btn-outline-secondary btn-sm" for="mi-scope-cohort">Cohort / Group</label>
                </div>

                <div id="mi-assign-individual-panel" class="gmi-recipient-panel">
                  <div class="gmi-checklist-heading">
                    <label for="mi-student-checklist-search" class="form-label mb-0">Select Student(s)</label>
                    <span class="ce-tag"><output id="mi-selected-count" class="ce-mono" aria-live="polite">0</output> selected</span>
                  </div>
                  <input id="mi-student-checklist-search" type="search" class="form-control form-control-sm" placeholder="Filter by name, MIS ID, or program…" autocomplete="off">
                  <div class="gmi-checklist-actions">
                    <button type="button" id="mi-select-visible-btn" class="btn btn-outline-secondary btn-sm">Select all visible</button>
                    <button type="button" id="mi-clear-selected-btn" class="btn btn-outline-secondary btn-sm">Clear</button>
                  </div>
                  <div class="gmi-student-checklist" role="group" aria-label="Students available for assignment">
                    <?php if ($students === []): ?>
                      <p class="gmi-checklist-empty">No active student records found.</p>
                    <?php else: ?>
                      <?php foreach ($students as $stu): ?>
                        <?php
                        $stuId = (int) $stu['id'];
                        $stuName = trim(($stu['last_name'] ?? '') . ', ' . ($stu['first_name'] ?? '') . (!empty($stu['middle_name']) ? ' ' . $stu['middle_name'] : ''));
                        $stuMeta = trim(($stu['mis_id'] ?? '') . (!empty($stu['program']) ? ' · ' . $stu['program'] : '') . (!empty($stu['year_level']) ? ' Y' . $stu['year_level'] : ''));
                        $hasActive = isset($activeLookup[$stuId]);
                        ?>
                        <div class="form-check mi-student-check-row gmi-check-option" data-search="<?= esc(strtolower($stuName . ' ' . $stuMeta), 'attr') ?>">
                          <input class="form-check-input mi-student-checkbox" type="checkbox" name="person_ids[]" value="<?= $stuId ?>" id="mi-stu-check-<?= $stuId ?>">
                          <label class="form-check-label" for="mi-stu-check-<?= $stuId ?>">
                            <span class="gmi-person-name"><?= esc($stuName) ?></span>
                            <span class="gmi-meta"><?= esc($stuMeta) ?></span>
                            <?php if ($hasActive): ?>
                              <span class="ce-tag gmi-active-assignment"><span class="ce-status-dot ce-status-sky">Active assignment</span></span>
                            <?php endif; ?>
                          </label>
                        </div>
                      <?php endforeach; ?>
                      <p id="mi-student-checklist-empty" class="gmi-checklist-empty d-none">No students match your filter.</p>
                    <?php endif; ?>
                  </div>
                  <p class="form-text m-0">Checklist shows up to 500 student records. Multi-student batches skip existing active assignments; selecting only a student with an active assignment returns a conflict.</p>
                </div>

                <div id="mi-assign-cohort-panel" class="gmi-recipient-panel d-none">
                  <div class="form-check gmi-check-option gmi-all-students">
                    <input class="form-check-input" type="checkbox" id="mi-assign-all-students" name="assign_all_students" value="1" disabled>
                    <label class="form-check-label" for="mi-assign-all-students">
                      <span class="gmi-person-name">All Active Students</span>
                      <span class="gmi-meta">Every active student in this tenant without an active MI survey.</span>
                    </label>
                  </div>
                  <div class="gmi-cohort-section">
                    <h3 class="gmi-field-heading">Programs</h3>
                    <?php if ($programs === []): ?>
                      <p class="gmi-meta m-0">No student programs found yet.</p>
                    <?php else: ?>
                      <div class="gmi-cohort-options">
                        <?php foreach ($programs as $idx => $program): ?>
                          <?php $progId = 'mi-program-' . $idx; ?>
                          <div class="form-check gmi-check-option">
                            <input class="form-check-input mi-cohort-filter-input" type="checkbox" id="<?= esc($progId, 'attr') ?>" name="target_programs[]" value="<?= esc($program, 'attr') ?>" disabled>
                            <label class="form-check-label" for="<?= esc($progId, 'attr') ?>"><?= esc($program) ?></label>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="gmi-cohort-section">
                    <h3 class="gmi-field-heading">Year Levels</h3>
                    <div class="gmi-year-options">
                      <?php foreach ($yearLevels as $idx => $yr): ?>
                        <?php $yrId = 'mi-year-' . $idx; ?>
                        <div class="form-check gmi-check-option">
                          <input class="form-check-input mi-cohort-filter-input" type="checkbox" id="<?= esc($yrId, 'attr') ?>" name="target_year_levels[]" value="<?= esc((string) $yr, 'attr') ?>" disabled>
                          <label class="form-check-label" for="<?= esc($yrId, 'attr') ?>">Year <?= esc((string) $yr) ?></label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <p class="form-text m-0">Programs and year levels are combined as an intersection. Existing active surveys are skipped automatically. All Active Students overrides these filters.</p>
                </div>
              </fieldset>

              <fieldset class="gmi-form-section">
                <legend class="gmi-section-title">Academic details</legend>
                <div class="gmi-academic-fields">
                  <div>
                    <label for="mi-academic-term" class="form-label">Academic Term</label>
                    <input id="mi-academic-term" type="text" name="academic_term" class="form-control form-control-sm" maxlength="64" placeholder="e.g. 1st Trimester">
                  </div>
                  <div>
                    <label for="mi-academic-year" class="form-label">Academic Year</label>
                    <input id="mi-academic-year" type="text" name="academic_year" class="form-control form-control-sm" maxlength="32" placeholder="e.g. 2026-2027">
                  </div>
                </div>
                <div>
                  <label for="mi-due-at-display" class="form-label">Target Due Date <span class="gmi-meta">(Optional)</span></label>
                  <input id="mi-due-at-display" type="text" class="form-control form-control-sm" data-datepicker data-target="#mi-due-at" data-min="today" placeholder="DD/MM/YYYY" autocomplete="off">
                  <input id="mi-due-at" type="hidden" name="due_at" value="">
                </div>
              </fieldset>

              <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-send-check" aria-hidden="true"></i> Assign MI Survey</button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="gmi-ledger-area">
      <div class="ce-card gmi-ledger" aria-labelledby="mi-registry-heading">
        <div class="ce-card-header">
          <div>
            <h2 class="ce-card-title" id="mi-registry-heading"><i class="bi bi-ui-checks-grid" aria-hidden="true"></i> Assigned Multiple Intelligence Surveys</h2>
            <p class="gmi-caption">Program-grouped assignments and completed eight-domain profiles.</p>
          </div>
          <span class="ce-tag"><span class="ce-mono"><?= count($assignments) ?></span> on this page</span>
        </div>
        <form method="GET" action="<?= esc(site_url('/guidance/assessments/mi'), 'attr') ?>" class="gmi-filter-form" aria-label="Filter Multiple Intelligence assignments">
          <div>
            <label for="mi-filter-program" class="form-label">Program</label>
            <select id="mi-filter-program" name="program" class="form-select form-select-sm" aria-label="Filter by program" data-searchable>
              <option value="">All Programs</option>
              <?php foreach ($programs as $prog): ?>
                <option value="<?= esc($prog, 'attr') ?>" <?= $programFilter === $prog ? 'selected' : '' ?>><?= esc($prog) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="mi-filter-status" class="form-label">Status</label>
            <select id="mi-filter-status" name="status" class="form-select form-select-sm" aria-label="Filter by status">
              <option value="">All Statuses</option>
              <?php foreach ($statusLabels as $val => $label): ?>
                <option value="<?= esc($val, 'attr') ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="mi-filter-per-page" class="form-label">Rows / Page</label>
            <select id="mi-filter-per-page" name="per_page" class="form-select form-select-sm" aria-label="Assignments per page">
              <?php foreach ([20, 25, 50, 100] as $size): ?>
                <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </form>

        <div class="table-responsive gmi-table-wrap" data-mobile-cards="false">
          <table class="table table-hover align-middle ce-table gmi-table" aria-labelledby="mi-registry-heading">
            <colgroup><col><col><col><col><col></colgroup>
            <thead>
              <tr>
                <th scope="col">Student</th>
                <th scope="col">Term / Year</th>
                <th scope="col">Schedule</th>
                <th scope="col">Status</th>
                <th scope="col">Results &amp; PDF</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($assignments === []): ?>
                <tr>
                  <td colspan="5" class="ce-empty-row">
                    <div class="gmi-empty-state">
                      <i class="bi bi-clipboard2-data" aria-hidden="true"></i>
                      <strong>No Multiple Intelligence survey assignments found.</strong>
                      <span><?= $statusFilter !== '' || $programFilter !== '' ? 'Try a different program or status filter.' : 'Assignments will appear here when surveys are assigned to students.' ?></span>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($groups as $group): ?>
                  <?php
                  $groupRows = $group['rows'];
                  $completedCount = count(array_filter($groupRows, static fn (array $r): bool => ($r['status'] ?? '') === 'completed'));
                  ?>
                  <tr class="mi-program-group-header gmi-program-group">
                    <td colspan="5">
                      <div class="gmi-group-heading">
                        <h3><i class="bi bi-mortarboard-fill" aria-hidden="true"></i> <?= esc($group['label']) ?></h3>
                        <span class="gmi-meta"><span class="ce-mono"><?= count($groupRows) ?></span> on this page · <span class="ce-mono"><?= $completedCount ?></span> completed</span>
                      </div>
                    </td>
                  </tr>
                  <?php foreach ($groupRows as $row): ?>
                    <?php
                    $fullName = trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : ''));
                    $status = (string) ($row['status'] ?? '');
                    $isDone = $status === 'completed' && !empty($row['submission_id']);
                    $assignedAt = !empty($row['assigned_at']) ? strtotime((string) $row['assigned_at']) : false;
                    $completedAt = !empty($row['completed_at']) ? strtotime((string) $row['completed_at']) : false;
                    $dueAt = !empty($row['due_at']) ? strtotime((string) $row['due_at']) : false;
                    ?>
                    <tr class="gmi-assignment-row">
                      <td data-label="Student">
                        <div class="gmi-person-name"><?= esc($fullName) ?></div>
                        <div class="gmi-meta ce-mono">ID: <?= esc($row['mis_id'] ?? 'N/A') ?></div>
                        <div class="gmi-meta"><?= esc($row['program'] ?? 'Unassigned Program') ?><?= !empty($row['year_level']) ? ' · Year ' . esc((string) $row['year_level']) : '' ?></div>
                      </td>
                      <td data-label="Term / Year">
                        <div><?= esc($row['academic_term'] ?? '') ?: '—' ?></div>
                        <?php if (!empty($row['academic_year'])): ?>
                          <div class="gmi-meta ce-mono"><?= esc($row['academic_year']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td data-label="Schedule">
                        <div class="gmi-meta">Assigned <?= $assignedAt !== false ? '<time class="ce-mono" datetime="' . esc(date('Y-m-d', $assignedAt), 'attr') . '">' . esc(date('M j, Y', $assignedAt)) . '</time>' : '—' ?></div>
                        <?php if ($dueAt !== false): ?>
                          <div class="gmi-meta">Due <time class="ce-mono" datetime="<?= esc(date('Y-m-d', $dueAt), 'attr') ?>"><?= esc(date('M j, Y', $dueAt)) ?></time></div>
                        <?php endif; ?>
                        <?php if ($completedAt !== false): ?>
                          <div class="gmi-meta">Completed <time class="ce-mono" datetime="<?= esc(date('Y-m-d', $completedAt), 'attr') ?>"><?= esc(date('M j, Y', $completedAt)) ?></time></div>
                        <?php endif; ?>
                      </td>
                      <td data-label="Status"><span class="ce-status-dot <?= $statusColor($status) ?>"><?= esc($statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status))) ?></span></td>
                      <td data-label="Results &amp; PDF">
                        <?php if ($isDone): ?>
                          <div class="gmi-result-actions">
                            <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $row['submission_id']), 'attr') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-bar-chart-steps" aria-hidden="true"></i> 8-Domain Profile</a>
                            <a href="<?= esc(site_url('/guidance/assessments/mi/results/' . (int) $row['submission_id'] . '/pdf'), 'attr') ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" data-navigation="false"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</a>
                          </div>
                        <?php else: ?>
                          <span class="gmi-meta"><?= $status === 'cancelled' ? 'Assignment cancelled' : ($status === 'completed' ? 'Result not available' : 'Awaiting student completion') ?></span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="gmi-pagination">
          <?= view('partials/table_pagination', [
              'pagination' => $pagination,
              'rowCount' => count($assignments),
              'filters' => array_filter(['status' => $statusFilter], static fn (string $v): bool => $v !== ''),
              'paginationExtraQuery' => array_filter(['program' => $programFilter], static fn (string $v): bool => $v !== ''),
              'baseUrl' => site_url('/guidance/assessments/mi'),
              'totalLabel' => 'MI assignments',
              'paginationLabel' => 'Multiple Intelligence assignment pages',
          ], ['saveData' => false]) ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/pages/guidance-mi-registry.css'), 'attr') ?>">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/pages/guidance-mi-registry.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>
