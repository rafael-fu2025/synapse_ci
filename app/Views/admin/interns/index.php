<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a href="<?= esc($baseUrl . '/create') ?>" class="btn btn-primary btn-sm" data-app-modal-open>
  <i class="bi bi-plus-lg" aria-hidden="true"></i> Assign Student Intern
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$counts = $counts ?? ['all' => 0, 'active' => 0, 'scheduled' => 0, 'expired' => 0, 'revoked' => 0, 'completed' => 0];
$allowedUnits = $allowedUnits ?? [];
$unitFilter = (string) ($unitFilter ?? 'all');
$statusFilter = (string) ($statusFilter ?? 'all');
$search = (string) ($search ?? '');
?>
<div class="adm-workspace">
  <section class="adm-sheet"
           data-live-table
           data-endpoint="<?= esc($baseUrl) ?>"
           data-refresh-owner="admin.interns"
           data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
           aria-label="Student Interns &amp; Supervision">

    <div class="adm-band">
      <div class="mb-3">
        <?php if (($actor['role'] ?? '') === 'superadmin'): ?>
          <p class="adm-intro-desc mb-0">Manage time-bounded Clinic, Guidance, and Facilities (BMG) student intern placements with mandatory staff supervision, automatic expiry, and RA 10173 self-access protection.</p>
        <?php else: ?>
          <p class="adm-intro-desc mb-0">Assign and monitor active student interns for your unit. Placements automatically activate at the scheduled start time, revert cleanly on expiry or revocation, and preserve the student's personal health portal identity.</p>
        <?php endif; ?>
      </div>

      <div class="adm-intern-kpis" aria-label="Placement status summary">
        <div class="adm-intern-kpi">
          <span class="adm-intern-kpi-label">Active Placements</span>
          <strong class="adm-intern-kpi-val is-active"><?= number_format((int) ($counts['active'] ?? 0)) ?></strong>
        </div>
        <div class="adm-intern-kpi">
          <span class="adm-intern-kpi-label">Scheduled</span>
          <strong class="adm-intern-kpi-val is-scheduled"><?= number_format((int) ($counts['scheduled'] ?? 0)) ?></strong>
        </div>
        <div class="adm-intern-kpi">
          <span class="adm-intern-kpi-label">Expired / Ended</span>
          <strong class="adm-intern-kpi-val"><?= number_format((int) (($counts['expired'] ?? 0) + ($counts['completed'] ?? 0))) ?></strong>
        </div>
        <div class="adm-intern-kpi">
          <span class="adm-intern-kpi-label">Revoked</span>
          <strong class="adm-intern-kpi-val is-revoked"><?= number_format((int) ($counts['revoked'] ?? 0)) ?></strong>
        </div>
      </div>

      <div class="adm-note" role="note">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <div><strong>Enrolled students only:</strong> interns keep their student identity (`people.kind = 'student'`), are strictly blocked from viewing or modifying their own clinical/guidance records on staff workstations, and Guidance interns automatically receive supervised `counselor_associate` co-sign enforcement.</div>
      </div>
    </div>

    <div class="adm-band adm-filter-band">
      <form data-table-filters action="<?= esc($baseUrl) ?>" method="GET" class="adm-filter-form" role="search" aria-label="Filter student intern placements">
        <div class="adm-filter-field">
          <label for="intern-search" class="adm-filter-label">Find intern or supervisor</label>
          <input id="intern-search" type="search" name="q" maxlength="100" value="<?= esc($search) ?>" class="form-control adm-input" placeholder="Student name, ID, term, or supervisor">
        </div>
        <?php if (count($allowedUnits) > 1): ?>
          <div class="adm-filter-field">
            <label for="intern-unit-filter" class="adm-filter-label">Unit</label>
            <select id="intern-unit-filter" name="unit" class="form-select">
              <option value="all" <?= $unitFilter === 'all' ? 'selected' : '' ?>>All units</option>
              <?php foreach ($allowedUnits as $uKey => $uLabel): ?>
                <option value="<?= esc($uKey) ?>" <?= $unitFilter === $uKey ? 'selected' : '' ?>><?= esc($uLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="adm-filter-field">
          <label for="intern-status-filter" class="adm-filter-label">Status</label>
          <select id="intern-status-filter" name="status" class="form-select">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses (<?= (int) ($counts['all'] ?? 0) ?>)</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active (<?= (int) ($counts['active'] ?? 0) ?>)</option>
            <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled (<?= (int) ($counts['scheduled'] ?? 0) ?>)</option>
            <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired (<?= (int) ($counts['expired'] ?? 0) ?>)</option>
            <option value="revoked" <?= $statusFilter === 'revoked' ? 'selected' : '' ?>>Revoked (<?= (int) ($counts['revoked'] ?? 0) ?>)</option>
          </select>
        </div>
        <div class="adm-filter-field">
          <label for="intern-per-page" class="adm-filter-label">Per page</label>
          <select id="intern-per-page" name="per_page" class="form-select">
            <?php foreach ([25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= (int) ($perPage ?? 25) === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="adm-filter-actions">
          <button class="btn btn-primary adm-btn" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Filter</button>
          <a class="btn btn-outline-secondary adm-btn" href="<?= esc($baseUrl) ?>" data-table-reset>Clear</a>
        </div>
      </form>

      <p data-table-status role="status" aria-live="polite" class="visually-hidden"></p>
      <div data-table-error class="alert alert-danger mt-2 mb-0" role="alert" hidden>
        <span data-table-error-message></span>
        <button data-table-retry type="button" class="btn btn-outline-danger btn-sm ms-2">Retry</button>
      </div>
    </div>

    <div class="adm-results-region" data-table-results id="studentInternResults" data-refresh-region="results" data-refresh-heading tabindex="-1">
      <?= view('admin/interns/_results', [
          'placements'   => $placements ?? [],
          'pagination'   => $pagination ?? null,
          'search'       => $search,
          'unitFilter'   => $unitFilter,
          'statusFilter' => $statusFilter,
          'baseUrl'      => $baseUrl,
      ], ['saveData' => false]) ?>
    </div>

  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<script src="<?= esc(base_url('assets/js/admin-interns.js')) ?>"></script>
<?= $this->endSection() ?>
