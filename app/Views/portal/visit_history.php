<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/portal'), 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Health Portal
</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$localTime = static function (?string $value): string {
    if ($value === null || $value === '') return 'Not recorded';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, \App\Services\Scheduling\SchedulingClock::timezone());
    return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date->format('M j, Y · g:i A') : 'Not recorded';
};
$visitCount = max(0, (int) ($pagination['total'] ?? 0));
?>
<section class="portal-engagement pv-workspace" data-live-filters data-filter-url="<?= esc($baseUrl, 'attr') ?>" data-filter-key="personal-visit-history" data-live-events="portal.queue.changed" aria-label="My Visit History">
  <div class="pv-sheet">
    <header class="pv-band pv-header-band">
      <div class="pv-intro">
        <h2 class="pv-intro-title">Completed visits</h2>
        <p class="pv-intro-desc">Your attendance at the University Clinic and Guidance &amp; Counseling, newest first.</p>
      </div>
      <div class="pv-privacy" role="note">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <p><strong>Attendance only.</strong> This is an attendance history, not your clinical chart. Confidential medical and counseling notes are not displayed. Contact the department for records or follow-up instructions.</p>
      </div>
      <form method="get" action="<?= esc($baseUrl, 'attr') ?>" class="pv-filter-grid" data-filter-form aria-label="Filter visit history">
        <div class="pv-filter-field">
          <label class="pv-filter-label" for="visitHistoryService">Service</label>
          <select class="form-select form-select-sm" id="visitHistoryService" name="service">
            <?php foreach (['all' => 'All services', 'clinic' => 'University Clinic', 'guidance' => 'Guidance & Counseling'] as $value => $label): ?>
              <option value="<?= esc($value, 'attr') ?>" <?= $filters['service'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="pv-filter-actions">
          <button class="btn btn-primary btn-sm pv-btn" type="submit"><i class="bi bi-filter" aria-hidden="true"></i>Filter</button>
          <a class="btn btn-outline-secondary btn-sm pv-btn" href="<?= esc($baseUrl, 'attr') ?>" data-filter-reset><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset</a>
        </div>
      </form>
      <?= view('partials/live_filter_feedback') ?>
    </header>
    <div class="pv-results-region" data-filter-region="history">
      <div class="pv-results-head">
        <div class="pv-results-copy">
          <h3 class="pv-results-title">Visit ledger</h3>
          <span class="pv-count" data-visit-count><?= number_format($visitCount) ?> completed <?= $visitCount === 1 ? 'visit' : 'visits' ?></span>
        </div>
        <p class="pv-timezone"><i class="bi bi-clock" aria-hidden="true"></i>Times shown in Asia/Manila</p>
      </div>
      <?php if ($visits === []): ?>
        <div class="pv-empty" role="status">
          <i class="bi bi-calendar2-check" aria-hidden="true"></i>
          <h3>No completed visits<?= $filters['service'] === 'all' ? '' : ' for this service' ?> are recorded yet.</h3>
          <p>Appointment requests, cancellations, and visits still in progress are not included.</p>
        </div>
      <?php else: ?>
        <div class="pv-table-wrap">
          <table class="pv-table" role="table">
            <caption class="visually-hidden">Your completed visits, newest first. Times shown in Asia/Manila.</caption>
            <colgroup><col class="pv-col-date"><col class="pv-col-service"><col class="pv-col-staff"><col class="pv-col-attendance"><col class="pv-col-summary"></colgroup>
            <thead role="rowgroup"><tr role="row"><th scope="col">Visit date</th><th scope="col">Service</th><th scope="col">Attending staff</th><th scope="col">Attendance</th><th scope="col">Summary</th></tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($visits as $visit): ?>
                <tr data-visit-row role="row">
                  <td class="pv-visit-date" data-label="Visit date" role="cell">
                    <span class="pv-cell-label" aria-hidden="true">Visit date</span>
                    <span class="pv-started"><?= esc($localTime($visit['started_at'])) ?></span>
                    <small class="pv-finished">Completed: <?= esc($localTime($visit['completed_at'])) ?></small>
                  </td>
                  <td class="pv-service" data-label="Service" role="cell">
                    <span class="pv-cell-label" aria-hidden="true">Service</span>
                    <div class="pv-service-cell">
                      <?= view('partials/unit_vector_icon', ['unit' => $visit['unit'], 'class' => 'pv-unit-icon']) ?>
                      <span class="pv-service-name"><?= esc($visit['unit'] === 'clinic' ? 'University Clinic' : 'Guidance & Counseling') ?></span>
                    </div>
                  </td>
                  <td class="pv-staff" data-label="Attending staff" role="cell">
                    <span class="pv-cell-label" aria-hidden="true">Attending staff</span>
                    <span class="pv-staff-name"><?= esc($visit['staff_name']) ?></span>
                  </td>
                  <td class="pv-attendance" data-label="Attendance" role="cell">
                    <span class="pv-cell-label" aria-hidden="true">Attendance</span>
                    <span class="pv-attendance-label"><?= $visit['attendance'] === 'appointment' ? 'By appointment' : 'Walk-in' ?></span>
                  </td>
                  <td class="pv-summary" data-label="Summary" role="cell">
                    <span class="pv-cell-label" aria-hidden="true">Summary</span>
                    <span class="pv-status">Completed</span>
                    <span class="pv-summary-text"><?= esc($visit['summary']) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="pv-pagination-band">
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
