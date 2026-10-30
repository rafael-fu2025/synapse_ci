<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('portal')) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>My Health Portal</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$localTime = static function (?string $value): string {
    if ($value === null || $value === '') return 'Not recorded';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, \App\Services\Scheduling\SchedulingClock::timezone());
    return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date->format('M j, Y · g:i A') : 'Not recorded';
};
?>
<section data-live-filters data-filter-url="<?= esc($baseUrl, 'attr') ?>" data-filter-key="personal-visit-history" data-live-events="portal.queue.changed" aria-labelledby="visitHistoryTitle">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent py-3">
      <h2 class="h6 fw-bold mb-1" id="visitHistoryTitle"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>My Visit History</h2>
      <p class="small text-muted mb-0">Completed clinic visits and guidance sessions, including walk-ins. Dates and times are shown in Asia/Manila.</p>
    </div>
    <div class="card-body p-3">
      <p class="small text-muted">This is an attendance history, not your clinical chart. Confidential medical and counseling notes are not displayed. Contact the department for records or follow-up instructions.</p>
      <form method="get" action="<?= esc($baseUrl) ?>" class="d-flex flex-wrap align-items-end gap-2 mb-3" data-filter-form aria-label="Filter visit history">
        <div class="col-12 col-sm-6 col-xl-4"><label class="form-label small mb-1" for="visitHistoryService">Service</label><select class="form-select form-select-sm" id="visitHistoryService" name="service">
          <?php foreach (['all' => 'All services', 'clinic' => 'University Clinic', 'guidance' => 'Guidance & Counseling'] as $value => $label): ?>
            <option value="<?= esc($value) ?>" <?= $filters['service'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select></div>
        <button class="btn btn-primary btn-sm" type="submit">Filter</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc($baseUrl) ?>" data-filter-reset>Reset</a>
      </form>
      <?= view('partials/live_filter_feedback') ?>
      <div data-filter-region="history">
        <?php if ($visits === []): ?>
          <div class="alert alert-light border mb-0" role="status">No completed visits<?= $filters['service'] === 'all' ? '' : ' for this service' ?> are recorded yet. Appointment requests, cancellations, and visits still in progress are not included.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <caption class="visually-hidden">Your completed visits, newest first</caption>
              <thead><tr><th scope="col">Visit date</th><th scope="col">Service</th><th scope="col">Attending staff</th><th scope="col">Attendance</th><th scope="col">Summary</th></tr></thead>
              <tbody>
                <?php foreach ($visits as $visit): ?>
                  <tr data-visit-row>
                    <td class="text-nowrap"><?= esc($localTime($visit['started_at'])) ?><small class="d-block text-muted">Completed: <?= esc($localTime($visit['completed_at'])) ?></small></td>
                    <td><?= esc($visit['unit'] === 'clinic' ? 'University Clinic' : 'Guidance & Counseling') ?></td>
                    <td class="text-break"><?= esc($visit['staff_name']) ?></td>
                    <td><?= $visit['attendance'] === 'appointment' ? 'By appointment' : 'Walk-in' ?></td>
                    <td><span class="badge bg-success mb-1">Completed</span><span class="d-block small"><?= esc($visit['summary']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?= view('partials/table_pagination', [
            'pagination' => $pagination,
            'filters' => $filters,
            'baseUrl' => $baseUrl,
            'paginationPageParam' => 'page',
            'paginationExtraQuery' => ['service' => $filters['service']],
            'paginationLiveFilters' => true,
            'paginationLabel' => 'Visit history pages',
        ]) ?>
      </div>
    </div>
  </div>
</section>
<?= $this->endSection() ?>
