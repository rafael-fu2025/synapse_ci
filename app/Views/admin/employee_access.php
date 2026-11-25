<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="adm-workspace">
  <section class="adm-sheet"
           data-live-table
           data-endpoint="<?= esc($baseUrl) ?>"
           data-refresh-owner="admin.access"
           data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>"
           aria-labelledby="admin-access-heading">

    <div class="adm-band">
      <h2 class="adm-intro-title" id="admin-access-heading">Employee Access &amp; Workstation Roles</h2>
      <?php if ($actor['role'] !== 'superadmin'): ?>
        <p class="adm-intro-desc">You can assign or revoke the staff role for employees in your department only. Administrator, supervisor and cross-department appointments require a superadmin.</p>
      <?php else: ?>
        <p class="adm-intro-desc">One role per employee account. Replacing a role removes its previous workstation access. Returning an account to Employee revokes workstation access but preserves its records.</p>
      <?php endif; ?>

      <div class="adm-note" role="note">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        <div><strong>Onboard real employees:</strong> ask them to sign in through MIS on the main portal first, then find their account here. This page changes Synapse access only—not MIS identities, passwords, employment details or appointment-provider enrollment.</div>
      </div>
    </div>

    <div class="adm-band adm-filter-band">
      <form data-table-filters action="<?= esc($baseUrl) ?>" method="GET" class="adm-filter-form" role="search" aria-label="Search employee accounts">
        <div class="adm-filter-field">
          <label for="employee-search" class="adm-filter-label">Find an employee</label>
          <input id="employee-search" type="search" name="q" maxlength="100" value="<?= esc($search) ?>" class="form-control adm-input" placeholder="Name, employee ID or username">
        </div>
        <div class="adm-filter-field">
          <label for="employee-per-page" class="adm-filter-label">Per page</label>
          <select id="employee-per-page" name="per_page" class="form-select">
            <?php foreach ([25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= (int) ($perPage ?? 25) === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="adm-filter-actions">
          <button class="btn btn-primary adm-btn" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Search</button>
          <a class="btn btn-outline-secondary adm-btn" href="<?= esc($baseUrl) ?>" data-table-reset>Clear</a>
        </div>
      </form>

      <p data-table-status role="status" aria-live="polite" class="visually-hidden"></p>
      <div data-table-error class="alert alert-danger mt-2 mb-0" role="alert" hidden>
        <span data-table-error-message></span>
        <button data-table-retry type="button" class="btn btn-outline-danger btn-sm ms-2">Retry</button>
      </div>
    </div>

    <div class="adm-results-region" data-table-results id="employeeAccessResults" data-refresh-region="results" data-refresh-heading tabindex="-1">
      <?= view('admin/_employee_access_results', [
          'employees' => $employees ?? [], 'pagination' => $pagination ?? null,
          'filters' => ['q' => $search ?? ''], 'search' => $search ?? '',
          'actor' => $actor, 'baseUrl' => $baseUrl,
      ], ['saveData' => false]) ?>
    </div>

  </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>
