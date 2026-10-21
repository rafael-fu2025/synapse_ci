<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<h1>MIS reference synchronization</h1>
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
            <tr><th scope="row"><?= esc($label) ?></th><td><?= esc((string) $catalogs[$key]['total']) ?></td>
                <td><?= esc($catalogs[$key]['last_synced_at'] ?? 'Never') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>Last committed synchronization: <?= esc($lastSync['occurred_at'] ?? 'None recorded') ?>
        <?php if ($lastSync !== null): ?> — actor user ID <?= esc((string) $lastSync['actor_user_id']) ?><?php endif; ?>.</p>
    <p>All three catalogs must pass validation. Reference updates and count-only audit metadata commit in one transaction.</p>
    <form method="post" action="<?= esc(site_url('admin/mis-sync/run')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary">Synchronize MIS reference catalogs</button>
    </form>
</div></div>
<?= $this->endSection() ?>
