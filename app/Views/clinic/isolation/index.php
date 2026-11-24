<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 iso-page-actions">
  <a href="<?= esc(site_url('/clinic/reports'), 'attr') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Periodic Reports
  </a>
  <?php if (!empty($canWrite)): ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#logIsolationModal">
      <i class="bi bi-plus-circle" aria-hidden="true"></i> Log Isolation Case
    </button>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$currentMonth = substr((string) $today, 0, 7);
$isFiltered = $month !== $currentMonth || $filters['condition'] !== 'all' || $filters['status'] !== 'all';
$statusTabs = [
    'all'              => 'All',
    'active_isolation' => 'Active',
    'hospitalized'     => 'Hospitalized',
    'cleared'          => 'Cleared',
];
if (!empty($canArchive) || ($filters['status'] ?? '') === 'archived') {
    $statusTabs['archived'] = 'Archived';
}
?>
<section class="iso-workspace"
         data-iso-workspace
         data-endpoint="<?= esc(site_url('/clinic/isolation'), 'attr') ?>"
         data-current-month="<?= esc($currentMonth, 'attr') ?>">

  <!-- Unified Instant-Switch Command Bar (Month · Status · Condition) -->
  <div class="iso-toolbar-shell">
    <div class="iso-toolbar" data-iso-toolbar>
      <div class="iso-month-nav" role="group" aria-label="Month navigation">
        <a href="<?= esc(site_url('/clinic/isolation?' . http_build_query(['month' => $prevMonth, 'condition' => $filters['condition'], 'status' => $filters['status']])), 'attr') ?>"
           class="btn btn-outline-secondary btn-sm iso-month-btn"
           data-iso-month-nav="prev"
           data-month-target="<?= esc($prevMonth, 'attr') ?>"
           data-navigation="false"
           aria-label="Previous month"
           title="Previous month">
          <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </a>
        <strong class="iso-month-title" data-iso-month-label><?= esc($monthLabel) ?></strong>
        <a href="<?= esc(site_url('/clinic/isolation?' . http_build_query(['month' => $nextMonth, 'condition' => $filters['condition'], 'status' => $filters['status']])), 'attr') ?>"
           class="btn btn-outline-secondary btn-sm iso-month-btn"
           data-iso-month-nav="next"
           data-month-target="<?= esc($nextMonth, 'attr') ?>"
           data-navigation="false"
           aria-label="Next month"
           title="Next month">
          <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </a>
        <button type="button"
                class="btn btn-outline-secondary btn-sm iso-today-btn"
                data-iso-month-nav="today"
                data-month-target="<?= esc($currentMonth, 'attr') ?>"
                <?= $month === $currentMonth ? 'hidden' : '' ?>>
          Today
        </button>
      </div>

      <nav class="iso-status-tabs" aria-label="Filter by clearance status">
        <?php foreach ($statusTabs as $sKey => $sLabel):
            $isActiveTab = $filters['status'] === $sKey;
            $tabHref = site_url('/clinic/isolation?' . http_build_query(['month' => $month, 'condition' => $filters['condition'], 'status' => $sKey]));
        ?>
          <a href="<?= esc($tabHref, 'attr') ?>"
             class="iso-status-tab<?= $isActiveTab ? ' is-active' : '' ?>"
             data-iso-status-tab="<?= esc($sKey, 'attr') ?>"
             data-navigation="false"
             <?= $isActiveTab ? 'aria-current="page"' : '' ?>><?= esc($sLabel) ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="iso-filter-right">
        <div class="iso-cond-select-wrap">
          <label for="isoFilterCondition" class="visually-hidden">Filter by condition</label>
          <select id="isoFilterCondition"
                  name="condition"
                  class="form-select form-select-sm iso-cond-select"
                  data-iso-condition-select
                  aria-label="Filter by condition">
            <option value="all" <?= $filters['condition'] === 'all' ? 'selected' : '' ?>>All Conditions</option>
            <?php foreach ($conditions as $code => $label): ?>
              <option value="<?= esc($code, 'attr') ?>" <?= $filters['condition'] === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <a href="<?= esc(site_url('/clinic/isolation'), 'attr') ?>"
           class="btn btn-outline-secondary btn-sm iso-reset-btn"
           data-iso-reset
           data-navigation="false"
           <?= $isFiltered ? '' : 'hidden' ?>>
          Reset
        </a>
      </div>
    </div>

    <div class="iso-error-bar" data-iso-error role="alert" hidden>
      <span data-iso-error-message></span>
      <button type="button" class="btn btn-outline-danger btn-sm" data-iso-retry>Retry</button>
    </div>
  </div>

  <!-- Live Replaceable Workspace Body -->
  <div data-iso-body>
    <?= view('clinic/isolation/_body', [
        'month'           => $month,
        'monthLabel'      => $monthLabel,
        'prevMonth'       => $prevMonth,
        'nextMonth'       => $nextMonth,
        'monthStart'      => $monthStart,
        'monthEnd'        => $monthEnd,
        'today'           => $today,
        'cases'           => $cases,
        'activeCount'     => $activeCount,
        'conditionCounts' => $conditionCounts,
        'alerts'          => $alerts,
        'filters'         => $filters,
        'canWrite'        => $canWrite,
        'canArchive'      => $canArchive ?? false,
        'conditions'      => $conditions,
        'defaultDays'     => $defaultDays,
    ], ['saveData' => false]) ?>
  </div>
</section>

<?php if (!empty($canWrite)): ?>
<!-- Single Reusable Update / Clear Isolation Status Modal -->
<div class="modal fade iso-modal" id="clearIsolationModal" tabindex="-1" aria-labelledby="clearIsolationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="" class="modal-content" data-iso-clear-form>
      <?= csrf_field() ?>
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="clearIsolationModalLabel">Update Isolation Status</h5>
          <p class="iso-modal-sub mb-0">Record return-to-campus clearance or hospital referral.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="iso-modal-summary">
          <div class="iso-modal-summary-patient" data-iso-clear-patient>—</div>
          <div class="iso-modal-summary-meta">
            <span data-iso-clear-condition>—</span>
            <span aria-hidden="true">·</span>
            <span class="iso-mono" data-iso-clear-window>—</span>
          </div>
        </div>

        <fieldset class="iso-modal-section">
          <legend class="iso-section-eyebrow">01 · Clearance Decision</legend>
          <div class="iso-choice-stack">
            <label class="iso-choice-card is-selected" data-iso-choice-card>
              <input type="radio" name="clearance_status" value="cleared" class="iso-choice-radio" checked>
              <span class="iso-choice-copy">
                <strong class="iso-choice-title">
                  <span class="iso-dot iso-dot--cleared" aria-hidden="true"></span>Cleared to Return to Class / Work
                </strong>
                <span class="iso-choice-desc">Isolation window completed or patient medically cleared to resume campus activity.</span>
              </span>
            </label>
            <label class="iso-choice-card" data-iso-choice-card>
              <input type="radio" name="clearance_status" value="hospitalized" class="iso-choice-radio">
              <span class="iso-choice-copy">
                <strong class="iso-choice-title">
                  <span class="iso-dot iso-dot--hospitalized" aria-hidden="true"></span>Referred / Hospitalized
                </strong>
                <span class="iso-choice-desc">Patient escalated to external hospital or inpatient care for monitoring.</span>
              </span>
            </label>
          </div>
        </fieldset>

        <div class="iso-modal-section mb-0">
          <label class="iso-section-eyebrow" for="clearIsolationNotes">02 · Clearance Notes</label>
          <textarea name="notes" id="clearIsolationNotes" rows="3" class="form-control form-control-sm" placeholder="Afebrile >24h, lesions crusted, fit for return to class..." maxlength="1000"></textarea>
        </div>
      </div>
      <div class="modal-footer iso-modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-check2-circle" aria-hidden="true"></i> Confirm Status Update
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Log Communicable Disease / Isolation Case Modal -->
<div class="modal fade iso-modal" id="logIsolationModal" tabindex="-1" aria-labelledby="logIsolationModalLabel" aria-hidden="true"
     x-data="isolationLogForm(<?= esc((string) json_encode(['defaultDays' => $defaultDays, 'today' => $today, 'searchUrl' => site_url('/api/patients/search?q=')]), 'attr') ?>)">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="POST" action="<?= esc(site_url('/clinic/isolation/store'), 'attr') ?>" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="logIsolationModalLabel">Log Communicable Disease / Isolation Case</h5>
          <p class="iso-modal-sub mb-0">Register a student or employee isolation window for outbreak tracking and clearance monitoring.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- 01 · Patient -->
        <div class="iso-modal-section">
          <div class="iso-section-eyebrow">01 · Patient (Student or Employee)</div>
          <input type="hidden" name="person_id" :value="selectedPerson ? selectedPerson.id : ''" required>

          <template x-if="!selectedPerson">
            <div class="syn-person-lookup" data-person-lookup>
              <div class="iso-search-wrap" data-person-lookup-anchor>
                <i class="bi bi-search iso-search-icon" aria-hidden="true"></i>
                <input type="text"
                       class="form-control form-control-sm iso-search-input"
                       x-model="searchTerm"
                       @input.debounce.300ms="lookupPatient()"
                       autocomplete="off"
                       placeholder="Type name or MIS ID (min 2 chars)...">
                <span class="iso-search-spin" x-show="loading" aria-hidden="true">
                  <span class="spinner-border spinner-border-sm"></span>
                </span>
              </div>
              <div class="syn-person-lookup-menu"
                   data-person-lookup-menu
                   x-show="loading || searchError || (searchComplete && !loading && results.length === 0) || results.length > 0"
                   style="display:none;">
                <div role="status" aria-live="polite" class="syn-person-lookup-status" x-show="loading || searchError || (searchComplete && !loading && !searchError && results.length === 0)">
                  <span x-show="loading">Searching local directory...</span>
                  <span x-show="searchComplete && !loading && !searchError && results.length === 0">No matching local records.</span>
                  <span class="iso-field-error m-0" x-show="searchError" x-text="searchError"></span>
                </div>
                <div class="list-group syn-person-lookup-list iso-lookup-list" x-show="results.length > 0">
                  <template x-for="p in results" :key="p.id">
                    <button type="button"
                            class="list-group-item list-group-item-action iso-lookup-item"
                            @click="pick(p)">
                      <span class="iso-lookup-name">
                        <strong x-text="p.last_name + ', ' + p.first_name"></strong>
                        <span class="iso-mono iso-lookup-id" x-text="p.mis_id"></span>
                      </span>
                      <span class="iso-lookup-kind" x-text="p.kind"></span>
                    </button>
                  </template>
                </div>
              </div>
            </div>
          </template>

          <template x-if="selectedPerson">
            <div class="iso-selected-person">
              <div class="iso-selected-person-copy">
                <strong x-text="selectedPerson.last_name + ', ' + selectedPerson.first_name"></strong>
                <span class="iso-mono" x-text="'MIS ID · ' + selectedPerson.mis_id"></span>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm" @click="clearPick()">Change</button>
            </div>
          </template>
        </div>

        <!-- 02 · Condition & Isolation Window -->
        <div class="iso-modal-section">
          <div class="iso-section-eyebrow">02 · Condition &amp; Isolation Window</div>
          <div class="row g-3">
            <div :class="condition === 'other' ? 'col-12 col-md-6' : 'col-12'">
              <label for="isoConditionCode" class="form-label">Communicable Condition</label>
              <select name="condition_code" id="isoConditionCode" class="form-select form-select-sm" x-model="condition" @change="applyDefaultDays()" required>
                <?php foreach ($conditions as $code => $label): ?>
                  <option value="<?= esc($code, 'attr') ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6" x-show="condition === 'other'">
              <label for="isoConditionOther" class="form-label">Specify Illness</label>
              <input type="text" name="condition_other" id="isoConditionOther" class="form-control form-control-sm" placeholder="Enter illness name" maxlength="100" :required="condition === 'other'">
            </div>
            <div class="col-12 col-sm-4">
              <label for="isoStartDateDisplay" class="form-label">Isolation Start Date</label>
              <input type="text" class="form-control form-control-sm" id="isoStartDateDisplay"
                     data-datepicker data-target="#isoStartDateIso" placeholder="DD/MM/YYYY">
              <input type="hidden" name="isolation_start_date" id="isoStartDateIso" value="<?= esc($today, 'attr') ?>" x-model="startDate" @input="startDate = $el.value" @change="startDate = $el.value" required>
            </div>
            <div class="col-6 col-sm-4">
              <label for="isoDays" class="form-label">Duration (Days)</label>
              <input type="number" name="isolation_days" id="isoDays" min="1" max="90" class="form-control form-control-sm" x-model.number="days" required>
            </div>
            <div class="col-6 col-sm-4">
              <label for="isoStatus" class="form-label">Initial Status</label>
              <select name="clearance_status" id="isoStatus" class="form-select form-select-sm">
                <option value="active_isolation">Active Isolation</option>
                <option value="hospitalized">Hospitalized</option>
              </select>
            </div>
          </div>

          <div class="iso-schedule-preview">
            <span class="iso-schedule-preview-label">Estimated isolation window</span>
            <strong class="iso-mono" x-text="(startDate || '—') + ' → ' + previewEndDate()"></strong>
            <span class="iso-schedule-preview-days" x-text="(days || 1) + ((Number(days) === 1) ? ' calendar day' : ' calendar days')"></span>
          </div>
        </div>

        <!-- 03 · Clinical & Tracing Notes -->
        <div class="iso-modal-section mb-0">
          <div class="iso-notes-label-row">
            <label for="isoNotes" class="iso-section-eyebrow mb-0">03 · Clinical &amp; Tracing Notes</label>
            <span class="iso-char-count iso-mono" x-text="(notes.length).toLocaleString() + ' / 2,000'">0 / 2,000</span>
          </div>
          <textarea name="notes" id="isoNotes" rows="3" class="form-control form-control-sm" x-model="notes" placeholder="Symptom onset, quarantine advisory issued, close contacts notified..." maxlength="2000"></textarea>
        </div>
      </div>
      <div class="modal-footer iso-modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" :disabled="!selectedPerson">
          <i class="bi bi-check2-circle" aria-hidden="true"></i> Save Isolation Case
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/clinic-isolation.js'), 'attr') ?>"></script>
<?= $this->endSection() ?>
