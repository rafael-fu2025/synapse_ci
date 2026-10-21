<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="mb-4">
  <h2 class="h5 fw-semibold mb-2">Export operational reports</h2>
  <p class="text-muted mb-0">Choose a department and date range, then download a report or spreadsheet.</p>
</div>

<form method="post" action="<?= esc(site_url('reports/export/operational')) ?>" data-operational-export data-report-download>
  <?= csrf_field() ?>
  <div class="card border-0 shadow-sm mb-4" aria-labelledby="export-filters-title">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <h3 id="export-filters-title" class="h6 fw-semibold mb-0"><i class="bi bi-sliders text-primary me-2" aria-hidden="true"></i>Report filters</h3>
        <span class="badge bg-light text-dark border fw-normal">Up to 31 days</span>
      </div>
      <div class="row g-4">
        <div class="col-lg-4">
          <label class="form-label fw-semibold" for="export-unit">Department</label>
          <select id="export-unit" class="form-select" name="unit" aria-describedby="export-unit-help" required>
            <?php if (count($units) === 3): ?><option value="all">All supported units</option><?php endif; ?>
            <?php foreach ($units as $unit): ?>
              <option value="<?= esc($unit) ?>"><?= esc(['clinic' => 'Clinic', 'guidance' => 'Guidance', 'bmg' => 'BMG'][$unit]) ?></option>
            <?php endforeach; ?>
          </select>
          <div id="export-unit-help" class="form-text">Only departments available to your role are listed.</div>
        </div>
        <div class="col-md-6 col-lg-4">
          <label class="form-label fw-semibold" for="export-from-display">Start date</label>
          <input id="export-from-display" class="form-control" type="text" data-datepicker data-target="#export-from" value="<?= esc($fromDisplay) ?>" autocomplete="off" placeholder="DD/MM/YYYY" aria-describedby="export-date-help" required>
          <input id="export-from" type="hidden" name="from" value="<?= esc($from) ?>">
        </div>
        <div class="col-md-6 col-lg-4">
          <label class="form-label fw-semibold" for="export-to-display">End date</label>
          <input id="export-to-display" class="form-control" type="text" data-datepicker data-target="#export-to" value="<?= esc($toDisplay) ?>" autocomplete="off" placeholder="DD/MM/YYYY" aria-describedby="export-date-help" required>
          <input id="export-to" type="hidden" name="to" value="<?= esc($to) ?>">
        </div>
      </div>
      <div class="d-flex align-items-start gap-2 border-top pt-3 mt-4 small text-muted" id="export-date-help">
        <i class="bi bi-calendar3" aria-hidden="true"></i>
        <span>Both dates are included. All counts use Asia/Manila calendar days, with a maximum of 31 days per export.</span>
      </div>
      <?php if (count($units) === 3): ?>
        <div class="alert alert-info d-flex align-items-start gap-2 mt-3 mb-0" role="note">
          <i class="bi bi-collection mt-1" aria-hidden="true"></i>
          <div class="small"><strong>All units, one download.</strong> The PDF includes an executive summary followed by separate Clinic, Guidance, and BMG analysis and daily-register sections. CSV keeps a unit column for filtering; it is not a multi-sheet workbook.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <section aria-labelledby="export-format-title" class="mb-4">
    <h3 id="export-format-title" class="h6 fw-semibold mb-3">Choose your download</h3>
    <div class="row g-4">
      <div class="col-md-6">
        <div class="card border border-primary shadow-sm h-100 mb-0">
          <div class="card-body p-4 d-flex flex-column">
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="bg-light text-primary border rounded-3 p-3"><i class="bi bi-file-earmark-pdf fs-3" aria-hidden="true"></i></span>
              <div>
                <h4 class="h5 fw-semibold mb-1">Visual report</h4>
                <span class="small text-muted">PDF · Share or print</span>
              </div>
            </div>
            <p class="text-muted small mb-3">LaTeX-typeset A4 department reports with the university seal, structured headings, separate activity charts, and dated registers.</p>
            <ul class="small ps-3 mb-4">
              <li class="mb-2">Per-department totals, daily averages, and activity analysis</li>
              <li>Separate charts, daily registers, and counting definitions</li>
                            <?php if (in_array('clinic', $units, true)): ?><li class="mt-2">Clinic: complaint and disposition donuts, plus medicine encounter rankings</li><?php endif; ?>
            </ul>
            <button class="btn btn-primary w-100 mt-auto" type="submit" name="format" value="pdf"><i class="bi bi-download me-2" aria-hidden="true"></i>Download PDF report</button>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card border shadow-sm h-100 mb-0">
          <div class="card-body p-4 d-flex flex-column">
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="bg-light text-success border rounded-3 p-3"><i class="bi bi-file-earmark-spreadsheet fs-3" aria-hidden="true"></i></span>
              <div>
                <h4 class="h5 fw-semibold mb-1">Spreadsheet data</h4>
                <span class="small text-muted">CSV · Filter or analyze</span>
              </div>
            </div>
            <p class="text-muted small mb-3">Structured daily counts for Excel or another spreadsheet tool. Use this format for filtering, pivot tables, and further analysis.</p>
            <ul class="small ps-3 mb-4">
              <li class="mb-2">Date, unit, metric, and count columns</li>
              <li>Data only; no charts or report formatting</li>
            </ul>
            <button class="btn btn-outline-primary w-100 mt-auto" type="submit" name="format" value="csv"><i class="bi bi-download me-2" aria-hidden="true"></i>Download CSV data</button>
          </div>
        </div>
      </div>
    </div>
  </section>
</form>

<div class="row g-4">
  <div class="col-lg-7">
    <section class="card border-0 shadow-sm h-100 mb-0" aria-labelledby="export-scope-title">
      <div class="card-body p-4">
        <h3 id="export-scope-title" class="h6 fw-semibold mb-3"><i class="bi bi-building text-primary me-2" aria-hidden="true"></i>Department coverage</h3>
        <p class="small text-muted mb-3">Daily aggregate counts within your authorized scope. Department administrators can export only their own unit.</p>
        <?php if (in_array('clinic', $units, true)): ?>
          <section class="border-top pt-3 mt-3" aria-labelledby="export-clinic-title">
            <h4 id="export-clinic-title" class="h6 mb-2">Clinic</h4>
            <p class="small text-muted mb-0">Completed encounters grouped by start date, not unique patients. The PDF includes complaint and disposition donuts plus a top-five medicine ranking by encounters, with counts and shares. Free-text complaints use exact category matching only; unclassified values remain Other recorded.</p>
          </section>
        <?php endif; ?>
        <?php if (in_array('guidance', $units, true)): ?>
          <section class="border-top pt-3 mt-3" aria-labelledby="export-guidance-title">
            <h4 id="export-guidance-title" class="h6 mb-2">Guidance</h4>
            <p class="small text-muted mb-0">Completed counselling sessions grouped by start date, not unique people.</p>
          </section>
        <?php endif; ?>
        <?php if (in_array('bmg', $units, true)): ?>
          <section class="border-top pt-3 mt-3" aria-labelledby="export-bmg-title">
            <h4 id="export-bmg-title" class="h6 mb-2">BMG</h4>
            <p class="small text-muted mb-2">Batches started by start date; batches released or voided by completion date.</p>
            <p class="small text-muted mb-0">Lifecycle bookkeeping only: no sensor, sanitation, or quality claims.</p>
          </section>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <div class="col-lg-5">
    <section class="card border-0 shadow-sm h-100 mb-0" aria-labelledby="export-privacy-title">
      <div class="card-body p-4">
        <h3 id="export-privacy-title" class="h6 fw-semibold mb-3"><i class="bi bi-shield-check text-primary me-2" aria-hidden="true"></i>Privacy &amp; audit</h3>
        <ul class="small text-muted ps-3 mb-0">
          <li class="mb-3">Aggregate counts only. No names, identifiers, clinical notes, diagnoses, survey answers, batch codes, individual notes, or person-level records.</li>
          <li class="mb-3">Population and active-account snapshots are excluded; they are not historical metrics.</li>
          <li class="mb-3">Downloads are audited. PDF compilation uses temporary files that are removed after generation; no report archive is retained.</li>
          <li>Identified survey answers require the separately authorized export on a survey’s Responses page.</li>
        </ul>
      </div>
    </section>
  </div>
</div>
<?= $this->endSection() ?>
