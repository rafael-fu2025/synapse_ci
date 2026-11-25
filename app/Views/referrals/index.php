<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<?php if (($actor['role'] ?? '') === 'superadmin' || in_array('referrals.create', $actor['permissions'] ?? [], true)): ?>
<a href="<?= esc(site_url('/' . $unit . '/referrals/create')) ?>" class="btn btn-primary btn-sm rw-send-cta" data-app-modal-open><i class="bi bi-send-plus" aria-hidden="true"></i> Send Referral</a>
<?php endif; ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $refreshOwner = 'referrals.' . $unit; ?>
<section class="rw-workspace" data-refresh-owner="<?= esc($refreshOwner, 'attr') ?>" data-refresh-url="<?= esc((string) current_url(true), 'attr') ?>">
  <div data-refresh-region="referrals.folders">
    <div class="rw-header">
      <p class="rw-subtitle mb-0"><?= ucfirst($unit) ?> Workstation · <?= $unit === 'clinic' ? 'Send to Guidance; receive from Guidance.' : 'Send to Clinic; receive from Clinic.' ?> Shared handoff summaries only.</p>
    </div>

    <div class="rw-folder-bar">
      <nav class="nav nav-pills referral-folder-nav rw-folder-tabs" aria-label="Referral folders">
        <?php foreach ([
            'incoming' => ['label' => 'Incoming', 'icon' => 'bi-inbox'],
            'sent' => ['label' => 'Sent', 'icon' => 'bi-send'],
            'history' => ['label' => 'Closed / History', 'icon' => 'bi-archive'],
        ] as $key => $meta): ?>
          <a class="nav-link rw-folder-tab <?= $tab === $key ? 'active' : '' ?>" href="<?= esc($baseUrl . '?' . http_build_query(['tab' => $key])) ?>" data-table-tab="<?= esc($key, 'attr') ?>" data-navigation="false" <?= $tab === $key ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= esc($meta['icon'], 'attr') ?>" aria-hidden="true"></i>
            <span class="rw-folder-label"><?= esc($meta['label']) ?></span>
            <span class="rw-folder-count" data-folder-count="<?= esc($key, 'attr') ?>"><?= (int) ($counts[$key] ?? 0) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>

  <div data-refresh-region="referrals.results">
    <section class="rw-sheet" data-live-table data-endpoint="<?= esc($baseUrl . '?' . http_build_query(['tab' => $tab])) ?>">
      <div class="rw-toolbar">
        <form data-table-filters method="GET" action="<?= esc($baseUrl) ?>" class="rw-filter-form" role="search">
          <input type="hidden" name="tab" value="<?= esc($tab) ?>">
          <div class="rw-filter-field rw-filter-field--search">
            <label for="referralSearch" class="form-label">Search referrals</label>
            <div class="rw-search-input">
              <i class="bi bi-search" aria-hidden="true"></i>
              <input type="search" name="q" id="referralSearch" class="form-control" maxlength="150" value="<?= esc($filters['q']) ?>" placeholder="Referral code, person ID, name or sender">
            </div>
          </div>
          <div class="rw-filter-field">
            <label for="referralStatus" class="form-label">Status</label>
            <select name="status" id="referralStatus" class="form-select">
              <?php foreach (['all' => 'All statuses'] + \App\Services\Referrals\ReferralService::LIST_STATUS_LABELS as $key => $label): ?>
                <option value="<?= esc($key) ?>" <?= (string) $filters['status'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rw-filter-field">
            <label for="referralUnit" class="form-label">Receiving unit</label>
            <select name="unit" id="referralUnit" class="form-select">
              <?php foreach (['all' => 'All units'] + \App\Services\Referrals\ReferralService::LIST_UNIT_LABELS as $key => $label): ?>
                <option value="<?= esc($key) ?>" <?= (string) $filters['target_unit'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rw-filter-field rw-filter-field--narrow">
            <label for="referralPageSize" class="form-label">Per page</label>
            <select name="per_page" id="referralPageSize" class="form-select">
              <?php foreach ([25, 50, 100] as $size): ?>
                <option value="<?= $size ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rw-filter-actions referral-filter-actions">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-outline-secondary" data-table-reset href="<?= esc($baseUrl . '?' . http_build_query(['tab' => $tab])) ?>">Reset</a>
          </div>
        </form>
        <p data-table-status role="status" aria-live="polite" class="visually-hidden"></p>
        <div data-table-error class="alert alert-danger rw-table-error" role="alert" hidden>
          <span data-table-error-message></span>
          <button data-table-retry type="button" class="btn btn-outline-danger btn-sm">Retry</button>
        </div>
      </div>

      <div data-table-results><?= view('referrals/_results', ['referrals' => $referrals, 'unit' => $unit, 'tab' => $tab, 'actor' => $actor, 'pagination' => $pagination, 'filters' => $filters, 'counts' => $counts, 'baseUrl' => $baseUrl], ['saveData' => false]) ?></div>
    </section>
  </div>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/live-table.js')) ?>"></script>
<?= $this->endSection() ?>
