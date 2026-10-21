<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<?php if (($actor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $actor['permissions'] ?? [], true)): ?>
<a href="<?= esc(site_url('/' . $unit . '/referrals/create')) ?>" class="btn btn-primary btn-sm" data-app-modal-open><i class="bi bi-send-plus me-1" aria-hidden="true"></i> Send Referral</a>
<?php endif; ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card mb-3"><div class="card-body pt-3">
  <p class="text-muted small mb-3"><?= ucfirst($unit) ?> Workstation · <?= $unit === 'clinic' ? 'Send to Guidance; receive from Guidance.' : 'Send to Clinic; receive from Clinic.' ?> Shared handoff summaries only.</p>
  <nav class="nav nav-pills gap-2" aria-label="Referral folders">
    <?php foreach (['incoming' => 'Incoming', 'sent' => 'Sent', 'history' => 'Closed / History'] as $key => $label): ?>
      <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= esc($baseUrl . '?' . http_build_query(['tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= $label ?> <span class="badge <?= $tab === $key ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= (int) ($counts[$key] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>
</div></div>
<section data-live-table data-endpoint="<?= esc($baseUrl . '?' . http_build_query(['tab' => $tab])) ?>">
  <div class="card mb-3"><div class="card-body pt-3">
    <form data-table-filters method="GET" action="<?= esc($baseUrl) ?>" class="row g-3 align-items-end" role="search">
      <input type="hidden" name="tab" value="<?= esc($tab) ?>">
      <div class="col-md-6"><label for="referralSearch" class="form-label">Search referrals</label><input type="search" name="q" id="referralSearch" class="form-control" maxlength="150" value="<?= esc($filters['q']) ?>" placeholder="Referral code, person ID, name or sender"></div>
      <div class="col-md-3"><label for="referralPageSize" class="form-label">Per page</label><select name="per_page" id="referralPageSize" class="form-select"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-outline-secondary" data-table-reset href="<?= esc($baseUrl . '?' . http_build_query(['tab' => $tab])) ?>">Reset</a></div>
    </form>
  </div></div>
  <p data-table-status role="status" aria-live="polite" class="small text-muted"></p>
  <div data-table-error class="alert alert-danger" role="alert" hidden><span data-table-error-message></span><button data-table-retry type="button" class="btn btn-outline-danger btn-sm ms-2">Retry</button></div>
  <div data-table-results><?= view('referrals/_results', ['referrals' => $referrals, 'unit' => $unit, 'tab' => $tab, 'actor' => $actor, 'pagination' => $pagination, 'filters' => $filters, 'baseUrl' => $baseUrl], ['saveData' => false]) ?></div>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>
