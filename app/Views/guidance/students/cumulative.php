<?= $this->extend('layouts/app') ?>
<?php
$fullName = trim((string) ($person['last_name'] ?? '') . ', ' . (string) ($person['first_name'] ?? '') . ' ' . (string) ($person['middle_name'] ?? ''));
$recordBaseUrl = $baseUrl ?? site_url('/guidance/clients/' . (int) $person['id']);
$tabLabels = [
    'overview' => ['Overview', 'bi-person-lines-fill'],
    'cases' => ['Cases & Episodes', 'bi-folder2-open'],
    'sessions' => ['Sessions & Follow-ups', 'bi-chat-left-text'],
    'assessments' => ['Assessments & Surveys', 'bi-bar-chart-line'],
    'coordination' => ['Referrals & Coordination', 'bi-arrows-angle-contract'],
    'forms' => ['Forms & Documents', 'bi-file-earmark-text'],
    'appointments' => ['Appointments & Call Slips', 'bi-calendar-check'],
];
$activeTab = $activeTab ?? 'overview';
$recordQuery = $recordQuery ?? ['tab' => $activeTab, 'per_page' => 25];
$sectionUrl = static fn (string $tab): string => $recordBaseUrl . '?' . http_build_query(array_replace($recordQuery, ['tab' => $tab]), '', '&', PHP_QUERY_RFC3986);
$formatDate = static function ($value, bool $timestamp = true): string {
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }
    try {
        return $timestamp
            ? \App\Services\Scheduling\SchedulingClock::formatManila($value, 'M j, Y g:i A')
            : (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable) {
        return '—';
    }
};
$displayStatus = static fn ($value): string => ucwords(str_replace('_', ' ', (string) ($value ?? 'Not set')));
$staffName = static fn ($id): string => $practitionerDirectory[(int) $id]['name'] ?? 'Guidance practitioner';
$historyPager = static function (string $key) use ($historyPagination, $recordQuery, $recordBaseUrl): string {
    return view('partials/table_pagination', [
        'pagination' => $historyPagination[$key] ?? null,
        'baseUrl' => $recordBaseUrl,
        'paginationPageParam' => \App\Services\Guidance\GuidanceCumulativeService::PAGE_PARAMS[$key],
        'paginationExtraQuery' => $recordQuery,
        'paginationLabel' => 'Guidance history pages',
    ], ['saveData' => false]);
};
$age = null;
if (!empty($person['date_of_birth'])) {
    try {
        $age = (new DateTimeImmutable((string) $person['date_of_birth']))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable) {
        $age = null;
    }
}
?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 gc-page-links">
  <a href="<?= esc(site_url('/guidance/clients')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Clients &amp; Records
  </a>
  <a href="<?= esc(site_url('/guidance/cases')) ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-folder2-open me-1" aria-hidden="true"></i> Counseling Cases
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="guidance-ui guidance-client-record" data-guidance-client-record data-endpoint="<?= esc($recordBaseUrl) ?>" data-refresh-owner="guidance.client.<?= (int) $person['id'] ?>">
  <div class="alert alert-light border small d-flex align-items-start gap-2 mb-3">
    <i class="bi bi-shield-lock text-primary mt-1" aria-hidden="true"></i>
    <div><strong>Confidential Guidance Record</strong> — Read-only cumulative history. Only permitted records are shown; clinical notes, safety plans, and form contents stay in their authorized workspaces. This access is logged. Clinic medical records and anonymous service evaluations are not included.</div>
  </div>

  <div class="card border-0 shadow-sm mb-3" data-refresh-region="profile">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="min-width-0">
          <div class="small text-muted mb-1">Guidance client</div>
          <h2 class="h4 fw-bold mb-2"><?= esc($fullName) ?></h2>
          <div class="small text-muted">
            MIS ID: <strong class="text-body"><?= esc((string) ($person['mis_id'] ?? 'Not on file')) ?></strong>
            <?php if (!empty($studentProfile)): ?>
              · <?= esc((string) ($studentProfile['program'] ?? 'Program not on file')) ?>
              <?= !empty($studentProfile['year_level']) ? ' · Year ' . (int) $studentProfile['year_level'] : '' ?>
              <?= !empty($studentProfile['section']) ? ' · ' . esc((string) $studentProfile['section']) : '' ?>
            <?php elseif (!empty($employeeProfile)): ?>
              · <?= esc((string) ($employeeProfile['department'] ?? 'Department not on file')) ?>
              <?= !empty($employeeProfile['position']) ? ' · ' . esc((string) $employeeProfile['position']) : '' ?>
            <?php endif; ?>
          </div>
        </div>
        <span class="badge bg-primary-subtle text-primary-emphasis border px-3 py-2"><?= esc(ucfirst((string) $person['kind'])) ?></span>
      </div>
      <div class="row g-3 border-top pt-3 mt-3 small">
        <div class="col-md-4"><div class="text-muted">Email on file</div><div class="fw-semibold text-break"><?= esc((string) ($person['email'] ?: 'Not provided')) ?></div></div>
        <div class="col-md-3"><div class="text-muted">Contact phone</div><div class="fw-semibold"><?= esc((string) ($person['phone'] ?: 'Not provided')) ?></div></div>
        <div class="col-md-5"><div class="text-muted">Current address</div><div class="fw-semibold text-break"><?= esc((string) ($person['address'] ?: 'Not provided')) ?></div></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ([
        ['Visible cases', $metrics['cases_total'], $metrics['cases_active'] . ' active · ' . $metrics['cases_closed'] . ' closed', 'cases'],
        ['Visible episodes', $metrics['episodes_total'], 'Authorized Guidance journeys', 'cases'],
        ['Recorded sessions', $metrics['sessions_total'], 'Across visible cases', 'sessions'],
        ['Pending follow-ups', $metrics['followups_pending'], 'Check-ins for visible cases', 'sessions'],
    ] as [$label, $value, $hint, $tab]): ?>
      <div class="col-6 col-xl-3"><a href="<?= esc($sectionUrl($tab)) ?>" data-record-tab-link="<?= esc($tab) ?>" class="card border-0 shadow-sm h-100 text-decoration-none text-body">
        <div class="card-body p-3"><div class="small text-muted"><?= esc($label) ?></div><div class="h4 fw-bold my-1"><?= number_format((int) $value) ?></div><div class="small text-muted"><?= esc($hint) ?></div></div>
      </a></div>
    <?php endforeach; ?>
  </div>

  <div class="card border-0 shadow-sm" data-refresh-region="history">
    <div class="card-header bg-transparent p-0">
      <nav class="nav nav-tabs nav-tabs-bordered flex-wrap" aria-label="Guidance record sections">
        <?php foreach ($tabLabels as $key => [$label, $icon]): ?>
          <a class="nav-link text-nowrap px-3 py-3 <?= $activeTab === $key ? 'active' : '' ?>" href="<?= esc($sectionUrl($key)) ?>" data-record-tab-link="<?= esc($key) ?>" <?= $activeTab === $key ? 'aria-current="page"' : '' ?> aria-controls="guidance-record-section">
            <i class="bi <?= esc($icon) ?> me-1" aria-hidden="true"></i><?= esc($label) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
    <div class="card-body p-3 p-md-4" id="guidance-record-section">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h3 class="h5 mb-0" data-refresh-heading tabindex="-1"><?= esc($tabLabels[$activeTab][0]) ?></h3>
        <?php if ($activeTab !== 'overview'): ?>
          <form action="<?= esc($recordBaseUrl) ?>" method="GET" data-record-history-form class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="tab" value="<?= esc($activeTab) ?>">
            <label for="guidanceHistorySize" class="small text-muted mb-0 text-nowrap">Per history</label>
            <select id="guidanceHistorySize" name="per_page" class="form-select form-select-sm" style="width: 5.5rem;" aria-label="Rows per history" data-native-select>
              <?php foreach ([25, 50, 100] as $size): ?>
                <option value="<?= $size ?>" <?= (int) ($recordQuery['per_page'] ?? 25) === $size ? 'selected' : '' ?>><?= $size ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-secondary btn-sm text-nowrap flex-shrink-0" type="submit">Apply</button>
          </form>
        <?php endif; ?>
      </div>
      <?= view('guidance/clients/_' . $activeTab, [
          'person' => $person, 'studentProfile' => $studentProfile, 'employeeProfile' => $employeeProfile,
          'age' => $age, 'can' => $can, 'metrics' => $metrics, 'totals' => $totals,
          'authorizedCases' => $authorizedCases, 'episodes' => $episodes, 'sessions' => $sessions, 'followups' => $followups,
          'miAttempts' => $miAttempts, 'pendingAssessments' => $pendingAssessments, 'surveys' => $surveys,
          'intakes' => $intakes, 'bridgeReferrals' => $bridgeReferrals, 'transfers' => $transfers, 'assignments' => $assignments,
          'safetyReviews' => $safetyReviews, 'forms' => $forms, 'sharedAppointments' => $sharedAppointments,
          'guidanceAppointments' => $guidanceAppointments, 'callSlips' => $callSlips,
          'sectionUrl' => $sectionUrl, 'formatDate' => $formatDate, 'displayStatus' => $displayStatus,
          'staffName' => $staffName, 'historyPager' => $historyPager,
      ], ['saveData' => false]) ?>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/guidance-client-record.js')) ?>"></script>
<?= $this->endSection() ?>
