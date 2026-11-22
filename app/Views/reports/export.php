<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section class="adm-workspace adm-reports-workspace" data-daterange-container>

<?php if (in_array('clinic', $units ?? [], true)): ?>
  <div class="adm-note adm-reports-clinic-note mb-4 report-export-alert" role="note">
    <i class="bi bi-info-circle-fill text-muted flex-shrink-0" aria-hidden="true"></i>
    <div class="adm-note-content flex-grow-1">
      <div class="adm-note-title fw-semibold">Need &gt;31-day Clinic Monthly, Semester, or CHED School-Year (June–Present) Reports?</div>
      <div class="adm-note-desc text-muted">Use the extended Clinic Periodic &amp; CHED Summary Report workspace for multi-month trends, FEFO dispensing totals, isolation cases, and health clearances up to 366 days.</div>
    </div>
    <a href="<?= site_url('/clinic/reports') ?>" class="btn btn-outline-secondary btn-sm adm-btn adm-btn-secondary text-nowrap align-self-start align-self-sm-center">
      <i class="bi bi-file-earmark-bar-graph me-1" aria-hidden="true"></i> Open Clinic Periodic Reports
    </a>
  </div>
<?php endif; ?>

<form method="post" action="<?= esc(site_url('reports/export/operational')) ?>" data-operational-export data-report-download>
  <?= csrf_field() ?>
  <div class="adm-sheet mb-4">
    <!-- Filters Band -->
    <div class="adm-band adm-reports-filters-band" aria-labelledby="export-filters-title">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
          <h3 id="export-filters-title" class="adm-section-title h6 fw-semibold mb-1">
            <i class="bi bi-sliders me-2 text-muted" aria-hidden="true"></i>Report filters
          </h3>
          <p class="adm-section-desc text-muted small mb-0">Choose a department and date range, then download a report or spreadsheet.</p>
        </div>
        <span class="adm-status-badge">
          <span class="adm-status-dot"></span>
          Up to 31 calendar days
        </span>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-4">
          <label class="form-label small fw-semibold adm-filter-label" for="export-unit">Department</label>
          <select id="export-unit" class="form-select form-select-sm adm-input" name="unit" aria-describedby="export-unit-help" required>
            <?php if (count($units) === 3): ?><option value="all">All supported units</option><?php endif; ?>
            <?php foreach ($units as $unit): ?>
              <option value="<?= esc($unit) ?>"><?= esc(['clinic' => 'Clinic', 'guidance' => 'Guidance', 'bmg' => 'BMG'][$unit]) ?></option>
            <?php endforeach; ?>
          </select>
          <div id="export-unit-help" class="form-text small text-muted">Only departments available to your role are listed.</div>
        </div>
        <div class="col-12 col-lg-8">
          <label class="form-label small fw-semibold adm-filter-label" for="export-date-range">Date range</label>
          <input id="export-date-range" class="form-control form-control-sm adm-input" type="text" data-daterangepicker data-target="#export-from" data-range-end="#export-to" data-max-days="31" value="<?= esc($fromDisplay . ' – ' . $toDisplay) ?>" autocomplete="off" placeholder="DD/MM/YYYY – DD/MM/YYYY" aria-describedby="export-date-help" required>
          <input id="export-from" type="hidden" name="from" value="<?= esc($from) ?>">
          <input id="export-to" type="hidden" name="to" value="<?= esc($to) ?>">
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 pt-3 mt-3 border-top small text-muted" id="export-date-help">
        <i class="bi bi-calendar3" aria-hidden="true"></i>
        <span>Both dates are included. All counts use Asia/Manila calendar days, with a maximum of 31 days per export.</span>
      </div>

      <?php if (count($units) === 3): ?>
        <div class="adm-note adm-reports-all-units-note mt-3" role="note">
          <i class="bi bi-collection" aria-hidden="true"></i>
          <div class="small"><strong>All units, one download.</strong> The PDF includes an executive summary followed by separate Clinic, Guidance, and BMG analysis and daily-register sections. CSV keeps a unit column for filtering; it is not a multi-sheet workbook.</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Download Formats Band -->
    <div class="adm-band adm-reports-download-band" aria-labelledby="export-format-title">
      <h3 id="export-format-title" class="adm-section-title h6 fw-semibold mb-3">Choose your download</h3>
      <div class="row g-3">
        <!-- PDF Card -->
        <div class="col-12 col-md-6">
          <div class="adm-format-card d-flex flex-column h-100">
            <div class="adm-format-header d-flex align-items-center gap-3 mb-3">
              <span class="adm-format-icon adm-format-icon-pdf">
                <i class="bi bi-file-earmark-pdf fs-4" aria-hidden="true"></i>
              </span>
              <div>
                <h4 class="adm-format-name h6 fw-semibold mb-0">Visual report</h4>
                <span class="small text-muted">PDF · Share or print</span>
              </div>
            </div>
            <p class="adm-format-desc text-muted small mb-3">LaTeX-typeset A4 department reports with the university seal, structured headings, separate activity charts, and dated registers.</p>
            <ul class="adm-format-features small ps-3 mb-4 text-muted">
              <li class="mb-2">Per-department totals, daily averages, and activity analysis</li>
              <li>Separate charts, daily registers, and counting definitions</li>
              <?php if (in_array('clinic', $units, true)): ?>
                <li class="mt-2">Clinic: complaint and disposition donuts, plus medicine encounter rankings</li>
              <?php endif; ?>
            </ul>
            <button class="btn btn-primary adm-btn adm-btn-primary w-100 mt-auto" type="submit" name="format" value="pdf">
              <i class="bi bi-download me-2" aria-hidden="true"></i>Download PDF report
            </button>
          </div>
        </div>

        <!-- CSV Card -->
        <div class="col-12 col-md-6">
          <div class="adm-format-card d-flex flex-column h-100">
            <div class="adm-format-header d-flex align-items-center gap-3 mb-3">
              <span class="adm-format-icon adm-format-icon-csv">
                <i class="bi bi-file-earmark-spreadsheet fs-4" aria-hidden="true"></i>
              </span>
              <div>
                <h4 class="adm-format-name h6 fw-semibold mb-0">Spreadsheet data</h4>
                <span class="small text-muted">CSV · Filter or analyze</span>
              </div>
            </div>
            <p class="adm-format-desc text-muted small mb-3">Structured daily counts for Excel or another spreadsheet tool. Use this format for filtering, pivot tables, and further analysis.</p>
            <ul class="adm-format-features small ps-3 mb-4 text-muted">
              <li class="mb-2">Date, unit, metric, and count columns</li>
              <li>Data only; no charts or report formatting</li>
            </ul>
            <button class="btn btn-outline-secondary adm-btn adm-btn-secondary w-100 mt-auto" type="submit" name="format" value="csv">
              <i class="bi bi-download me-2" aria-hidden="true"></i>Download CSV data
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Scope & Governance Disclosures Sheet -->
<div class="adm-sheet adm-reports-info-sheet">
  <div class="row g-0">
    <div class="col-12 col-lg-7 adm-reports-info-col">
      <div class="adm-band h-100" aria-labelledby="export-scope-title">
        <h3 id="export-scope-title" class="adm-section-title h6 fw-semibold mb-2">
          <svg class="unit-btn-icon me-2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><path d="M3 21h18M5 21V3h9v18M14 8h5v13M8 6h3M8 10h3M8 14h3M8 21v-3h3v3M17 11v1M17 15v1"/></svg>Department coverage
        </h3>
        <p class="small text-muted mb-3">Daily aggregate counts within your authorized scope. Department administrators can export only their own unit.</p>

        <div class="accordion adm-scope-accordion mt-3" id="exportScopeAccordion">
          <?php $firstUnit = reset($units); ?>
          <?php if (in_array('clinic', $units, true)): ?>
            <div class="accordion-item adm-accordion-item">
              <h4 class="accordion-header" id="heading-export-clinic">
                <button class="accordion-button<?= $firstUnit === 'clinic' ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-export-clinic" aria-expanded="<?= $firstUnit === 'clinic' ? 'true' : 'false' ?>" aria-controls="collapse-export-clinic" id="export-clinic-title">
                  <?= view('partials/unit_vector_icon', ['unit' => 'clinic', 'class' => 'unit-btn-icon me-2 text-muted']) ?>
                  <span class="adm-subscope-heading">Clinic</span>
                </button>
              </h4>
              <div id="collapse-export-clinic" class="accordion-collapse collapse<?= $firstUnit === 'clinic' ? ' show' : '' ?>" aria-labelledby="heading-export-clinic" data-bs-parent="#exportScopeAccordion">
                <div class="accordion-body adm-accordion-body small text-muted">
                  <p class="mb-0">Completed encounters grouped by start date, not unique patients. The PDF includes complaint and disposition donuts plus a top-five medicine ranking by encounters, with counts and shares. Free-text complaints use exact category matching only; unclassified values remain Other recorded.</p>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (in_array('guidance', $units, true)): ?>
            <div class="accordion-item adm-accordion-item">
              <h4 class="accordion-header" id="heading-export-guidance">
                <button class="accordion-button<?= $firstUnit === 'guidance' ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-export-guidance" aria-expanded="<?= $firstUnit === 'guidance' ? 'true' : 'false' ?>" aria-controls="collapse-export-guidance" id="export-guidance-title">
                  <?= view('partials/unit_vector_icon', ['unit' => 'guidance', 'class' => 'unit-btn-icon me-2 text-muted']) ?>
                  <span class="adm-subscope-heading">Guidance</span>
                </button>
              </h4>
              <div id="collapse-export-guidance" class="accordion-collapse collapse<?= $firstUnit === 'guidance' ? ' show' : '' ?>" aria-labelledby="heading-export-guidance" data-bs-parent="#exportScopeAccordion">
                <div class="accordion-body adm-accordion-body small text-muted">
                  <p class="mb-0">Completed counselling sessions grouped by start date, not unique people.</p>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (in_array('bmg', $units, true)): ?>
            <div class="accordion-item adm-accordion-item">
              <h4 class="accordion-header" id="heading-export-bmg">
                <button class="accordion-button<?= $firstUnit === 'bmg' ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-export-bmg" aria-expanded="<?= $firstUnit === 'bmg' ? 'true' : 'false' ?>" aria-controls="collapse-export-bmg" id="export-bmg-title">
                  <svg class="unit-btn-icon me-2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><ellipse cx="18" cy="11" rx="3" ry="6"/><path d="M18 5H6a3 6 0 0 0 0 12h12M7 6v10M11 6v10M5 17l-1 4M18 17l1 4M3 21h4M16 21h4"/></svg>
                  <span class="adm-subscope-heading">BMG</span>
                </button>
              </h4>
              <div id="collapse-export-bmg" class="accordion-collapse collapse<?= $firstUnit === 'bmg' ? ' show' : '' ?>" aria-labelledby="heading-export-bmg" data-bs-parent="#exportScopeAccordion">
                <div class="accordion-body adm-accordion-body small text-muted">
                  <p class="mb-2">Batches started by start date; batches released or voided by completion date.</p>
                  <p class="mb-0">Lifecycle bookkeeping only: no sensor, sanitation, or quality claims.</p>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-5 adm-reports-info-col">
      <div class="adm-band h-100" aria-labelledby="export-privacy-title">
        <h3 id="export-privacy-title" class="adm-section-title h6 fw-semibold mb-2">
          <i class="bi bi-shield-check me-2 text-muted" aria-hidden="true"></i>Privacy &amp; audit
        </h3>
        <ul class="adm-privacy-list small text-muted ps-3 mb-0">
          <li class="mb-3">Aggregate counts only. No names, identifiers, clinical notes, diagnoses, survey answers, batch codes, individual notes, or person-level records.</li>
          <li class="mb-3">Population and active-account snapshots are excluded; they are not historical metrics.</li>
          <li class="mb-3">Downloads are audited. PDF compilation uses temporary files that are removed after generation; no report archive is retained.</li>
          <li>Identified survey answers require the separately authorized export on a survey’s Responses page.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

</section>
<?= $this->endSection() ?>
