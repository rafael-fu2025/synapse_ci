<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="adm-workspace adm-mis-sync">
  <section class="adm-sheet" data-mis-sync data-sync-action="references" data-status-url="<?= esc(site_url('admin/mis-sync'), 'attr') ?>" aria-label="MIS reference synchronization">
    <div class="adm-band">
      <div class="adm-sheet-head">
        <h2 class="adm-intro-title">Local reference status</h2>
        <span class="adm-tenant-tag">Tenant <?= esc((string) $actor['tenant_id']) ?></span>
      </div>
      <p class="adm-intro-desc">Synchronize authoritative MIS department, program and year-level reference catalogs for tenant <?= esc((string) $actor['tenant_id']) ?>. Opening or refreshing this page reads local status only; it does not contact MIS or start synchronization.</p>

      <div class="adm-note" role="note">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>This is not employee or student directory synchronization. No people, accounts, roles, credentials or clinical records are changed. Existing references absent from a response are retained. Empty, malformed or inconsistent catalogs are rejected together.</div>
      </div>
    </div>

    <div class="adm-results-region">
      <div class="adm-table-wrap">
        <table class="adm-table">
          <thead>
            <tr>
              <th scope="col">Catalog</th>
              <th scope="col">Local entries</th>
              <th scope="col">Latest entry synchronization</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (['departments' => 'Departments', 'programs' => 'Programs', 'year_levels' => 'Year levels'] as $key => $label): ?>
              <tr data-sync-catalog="<?= esc($key, 'attr') ?>">
                <th scope="row"><?= esc($label) ?></th>
                <td class="adm-mono" data-sync-total><?= esc((string) $catalogs[$key]['total']) ?></td>
                <td class="adm-mono-muted" data-sync-synced><?= esc($catalogs[$key]['last_synced_at'] ?? 'Never') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="adm-band">
      <div class="adm-sync-meta">
        <p class="adm-sync-meta-row">
          Last committed synchronization:
          <span class="adm-mono" data-sync-last><?= esc($lastSync['occurred_at'] ?? 'None recorded') ?></span><?php if ($lastSync !== null): ?><span class="adm-mono-muted" data-sync-actor> — actor user ID <?= esc((string) $lastSync['actor_user_id']) ?></span><?php endif; ?>.
        </p>
        <p class="adm-sync-meta-note">All three catalogs must pass validation. Reference updates and count-only audit metadata commit in one transaction.</p>
      </div>

      <form method="post" action="<?= esc(site_url('admin/mis-sync/run')) ?>" data-mis-sync-form>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary adm-btn">
          <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Synchronize MIS reference catalogs
        </button>
      </form>

      <div class="adm-sync-progress" data-sync-progress hidden>
        <div class="adm-sync-progress-head">
          <span>Synchronization progress</span>
          <span class="adm-mono-muted" data-sync-percent>Waiting…</span>
        </div>
        <div class="progress">
          <div class="progress-bar progress-bar-striped progress-bar-animated" data-sync-bar
               role="progressbar" aria-label="Synchronization progress" aria-valuemin="0" aria-valuemax="100"
               style="width: 0%;"></div>
        </div>
        <p class="adm-sync-msg" data-sync-message role="status" aria-live="polite" aria-atomic="true"></p>
        <p class="adm-sync-hint">Progress reports completed stages, not estimated time. Changes are final only after the database commit succeeds.</p>
      </div>

      <div class="alert mt-3 mb-0" data-sync-result role="status" aria-live="polite" aria-atomic="true" hidden></div>
      <a class="btn btn-outline-secondary adm-btn mt-2" data-sync-refresh href="<?= esc(site_url('admin/mis-sync')) ?>" hidden>Refresh local status</a>
    </div>
  </section>

  <?php if (isset($peopleStatus)): ?>
    <section class="adm-sheet" data-mis-sync data-sync-action="people" data-status-url="<?= esc(site_url('admin/mis-sync/people/status'), 'attr') ?>" aria-label="MIS people synchronization">
      <div class="adm-band">
        <h2 class="adm-intro-title">Student and employee directory synchronization</h2>
        <p class="adm-intro-desc">This separate action imports people and student/employee profiles only. No accounts, passwords, roles, staff assignments or clinical records are created or changed. Missing records are retained; soft-deleted people are skipped.</p>

        <div class="adm-note" role="note">
          <i class="bi bi-people" aria-hidden="true"></i>
          <div>Live mode retrieves unfiltered student and employee listings in 25-record pages (up to 400 pages / 10,000 records per kind). Both directories must pass validation before any directory changes or count-only audit commit. Opening this page never contacts MIS.</div>
        </div>
      </div>

      <div class="adm-stat-strip">
        <div class="adm-stat-cell">
          <span class="adm-stat-label">Students</span>
          <span class="adm-stat-value" data-people-count="student"><?= esc((string) $peopleStatus['counts']['student']) ?></span>
        </div>
        <div class="adm-stat-cell">
          <span class="adm-stat-label">Employees</span>
          <span class="adm-stat-value" data-people-count="employee"><?= esc((string) $peopleStatus['counts']['employee']) ?></span>
        </div>
        <div class="adm-stat-cell">
          <span class="adm-stat-label">Last committed people sync</span>
          <span class="adm-stat-value" data-sync-last><?= esc($peopleStatus['lastSync']['occurred_at'] ?? 'None recorded') ?></span>
        </div>
      </div>

      <div class="adm-band">
        <?php if ($peopleStatus['unavailableReason'] !== null): ?>
          <div class="adm-caution" role="note">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div><?= esc($peopleStatus['unavailableReason']) ?></div>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= esc(site_url('admin/mis-sync/people'), 'attr') ?>" data-mis-sync-form>
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-primary adm-btn" <?= $peopleStatus['unavailableReason'] !== null ? 'disabled data-sync-unavailable' : '' ?>>
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Synchronize MIS people directory
          </button>
        </form>

        <div class="adm-sync-progress" data-sync-progress hidden>
          <div class="adm-sync-progress-head">
            <span>Synchronization progress</span>
            <span class="adm-mono-muted" data-sync-percent>Waiting…</span>
          </div>
          <div class="progress">
            <div class="progress-bar progress-bar-striped progress-bar-animated" data-sync-bar
                 role="progressbar" aria-label="Synchronization progress" aria-valuemin="0" aria-valuemax="100"
                 style="width: 0%;"></div>
          </div>
          <p class="adm-sync-msg" data-sync-message role="status" aria-live="polite" aria-atomic="true"></p>
          <p class="adm-sync-hint">Progress reports completed stages, not estimated time. Changes are final only after the database commit succeeds.</p>
        </div>

        <div class="alert mt-3 mb-0" data-sync-result role="status" aria-live="polite" aria-atomic="true" hidden></div>
        <a class="btn btn-outline-secondary adm-btn mt-2" data-sync-refresh href="<?= esc(site_url('admin/mis-sync')) ?>" hidden>Refresh local status</a>
      </div>
    </section>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
