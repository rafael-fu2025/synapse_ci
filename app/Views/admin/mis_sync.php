<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section data-mis-sync data-status-url="<?= esc(site_url('admin/mis-sync'), 'attr') ?>" aria-label="MIS reference synchronization">
<p>Synchronize authoritative MIS department, program and year-level reference catalogs for tenant <?= esc((string) $actor['tenant_id']) ?>.</p>
<div class="alert alert-info">
    This is not employee or student directory synchronization. No people, accounts, roles, credentials or clinical records are changed.
    Existing references absent from a response are retained. Empty, malformed or inconsistent catalogs are rejected together.
</div>
<div class="card"><div class="card-body">
    <h2 class="h5">Local reference status</h2>
    <p class="text-muted">Opening or refreshing this page reads local status only; it does not contact MIS or start synchronization.</p>
    <table class="table">
        <thead><tr><th scope="col">Catalog</th><th scope="col">Local entries</th><th scope="col">Latest entry synchronization</th></tr></thead>
        <tbody>
        <?php foreach (['departments' => 'Departments', 'programs' => 'Programs', 'year_levels' => 'Year levels'] as $key => $label): ?>
            <tr data-sync-catalog="<?= esc($key, 'attr') ?>"><th scope="row"><?= esc($label) ?></th><td data-sync-total><?= esc((string) $catalogs[$key]['total']) ?></td>
                <td data-sync-synced><?= esc($catalogs[$key]['last_synced_at'] ?? 'Never') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>Last committed synchronization: <span data-sync-last><?= esc($lastSync['occurred_at'] ?? 'None recorded') ?></span>
        <?php if ($lastSync !== null): ?><span data-sync-actor> — actor user ID <?= esc((string) $lastSync['actor_user_id']) ?></span><?php endif; ?>.</p>
    <p>All three catalogs must pass validation. Reference updates and count-only audit metadata commit in one transaction.</p>
    <form method="post" action="<?= esc(site_url('admin/mis-sync/run')) ?>" data-mis-sync-form>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary">Synchronize MIS reference catalogs</button>
    </form>
    <div class="mt-3" data-sync-progress hidden>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            <span class="fw-semibold">Synchronization progress</span>
            <span class="small text-muted" data-sync-percent>Waiting…</span>
        </div>
        <div class="progress mb-2" style="height: 12px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" data-sync-bar
                 role="progressbar" aria-label="Synchronization progress" aria-valuemin="0" aria-valuemax="100"
                 style="width: 0%;"></div>
        </div>
        <p class="small text-muted mb-2" data-sync-message role="status" aria-live="polite" aria-atomic="true"></p>
        <p class="small text-muted mb-0">Progress reports completed stages, not estimated time. Changes are final only after the database commit succeeds.</p>
    </div>
    <div class="alert mt-3 mb-0" data-sync-result role="status" aria-live="polite" aria-atomic="true" hidden></div>
    <a class="btn btn-outline-secondary btn-sm mt-2" data-sync-refresh href="<?= esc(site_url('admin/mis-sync')) ?>" hidden>Refresh local status</a>
</div></div>
</section>
<?= $this->endSection() ?>
