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
$initials = strtoupper(substr((string) ($person['first_name'] ?? ''), 0, 1) . substr((string) ($person['last_name'] ?? ''), 0, 1));
if ($initials === '') {
    $initials = 'GC';
}
$affiliationText = !empty($studentProfile)
    ? trim((string) ($studentProfile['program'] ?? 'Program not on file')
        . (!empty($studentProfile['year_level']) ? ' (Year ' . (int) $studentProfile['year_level'] . ')' : '')
        . (!empty($studentProfile['section']) ? ' · Sec ' . (string) $studentProfile['section'] : ''))
    : (!empty($employeeProfile)
        ? trim((string) ($employeeProfile['department'] ?? 'Department not on file')
            . (!empty($employeeProfile['position']) ? ' · ' . (string) $employeeProfile['position'] : ''))
        : 'University Member');
?>

<?= $this->section('page_actions') ?>
<div class="d-flex flex-wrap gap-2 cp-page-actions gc-page-links">
  <a href="<?= esc(site_url('/guidance/clients')) ?>" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Clients &amp; Records
  </a>
  <a href="<?= esc(site_url('/guidance/cases')) ?>" class="btn btn-outline-primary">
    <i class="bi bi-folder2-open" aria-hidden="true"></i>Counseling Cases
  </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="guidance-ui guidance-client-record cp-chart" data-guidance-client-record data-endpoint="<?= esc($recordBaseUrl) ?>" data-refresh-owner="guidance.client.<?= (int) $person['id'] ?>">
  <div class="cp-notice" role="note">
    <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
    <strong>Confidential Guidance Record</strong> — Read-only cumulative history. Only permitted records are shown; clinical notes, safety plans, and form contents stay in their authorized workspaces. This access is logged. Clinic medical records and anonymous service evaluations are not included.
  </div>

  <div class="cp-hero" data-refresh-region="profile">
    <div class="cp-hero-top">
      <div class="cp-hero-identity">
        <?php
          $crTenantId = (int) (session()->get('user')['tenant_id'] ?? 0);
          $crPhotoUrl = $crTenantId > 0 ? (new \App\Services\ProfilePhotoService())->personPhotoUrl($crTenantId, (int) ($person['id'] ?? 0)) : null;
        ?>
        <div class="cp-avatar" aria-hidden="true"><?= \App\Services\ProfileAvatar::contentHtml($crPhotoUrl, $initials, is_array($person ?? null) ? $person : ['avatar_initials' => $initials]) ?></div>
        <div class="cp-hero-copy">
          <div class="cp-hero-eyebrow">
            Guidance Client · <?= esc(ucfirst((string) ($person['kind'] ?? 'member'))) ?> · <?= esc($affiliationText) ?>
          </div>
          <h2 class="cp-hero-name"><?= esc($fullName) ?></h2>
          <div class="cp-hero-meta">
            <span>MIS ID: <strong class="cp-mono"><?= esc((string) ($person['mis_id'] ?? 'Not on file')) ?></strong></span>
            <span>· Gender: <?= esc($displayStatus($person['gender'] ?? 'Unspecified')) ?></span>
            <?php if (!empty($person['date_of_birth'])): ?>
              <span>· DOB: <span class="cp-mono"><?= esc($formatDate($person['date_of_birth'], false)) ?></span><?= $age !== null ? ' (Age ' . (int) $age . ')' : '' ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <span class="cp-tag"><?= esc(ucfirst((string) ($person['kind'] ?? 'member'))) ?></span>
    </div>

    <div class="cp-snapshot-grid">
      <div class="cp-snapshot-cell">
        <span class="cp-snapshot-label">Affiliation</span>
        <span class="cp-snapshot-value"><?= esc($affiliationText) ?></span>
        <span class="cp-snapshot-sub"><?= esc((string) ($studentProfile['department'] ?? $employeeProfile['department'] ?? 'Campus directory')) ?></span>
      </div>
      <div class="cp-snapshot-cell">
        <span class="cp-snapshot-label">Email on File</span>
        <span class="cp-snapshot-value"><?= esc((string) ($person['email'] ?: 'Not provided')) ?></span>
        <span class="cp-snapshot-sub">Campus identity</span>
      </div>
      <div class="cp-snapshot-cell">
        <span class="cp-snapshot-label">Contact Phone</span>
        <span class="cp-snapshot-value cp-mono"><?= esc((string) ($person['phone'] ?: 'Not provided')) ?></span>
        <span class="cp-snapshot-sub">Shared directory</span>
      </div>
      <div class="cp-snapshot-cell">
        <span class="cp-snapshot-label">Current Address</span>
        <span class="cp-snapshot-value"><?= esc((string) ($person['address'] ?: 'Not provided')) ?></span>
        <span class="cp-snapshot-sub">Recorded location</span>
      </div>
    </div>
  </div>

  <div class="row g-3 cp-kpi-row">
    <?php foreach ([
        ['Visible cases', $metrics['cases_total'], $metrics['cases_active'] . ' active · ' . $metrics['cases_closed'] . ' closed', 'cases'],
        ['Visible episodes', $metrics['episodes_total'], 'Authorized Guidance journeys', 'cases'],
        ['Recorded sessions', $metrics['sessions_total'], 'Across visible cases', 'sessions'],
        ['Pending follow-ups', $metrics['followups_pending'], 'Check-ins for visible cases', 'sessions'],
    ] as [$label, $value, $hint, $tab]): ?>
      <div class="col-6 col-xl-3">
        <a href="<?= esc($sectionUrl($tab)) ?>" data-record-tab-link="<?= esc($tab) ?>" class="cp-kpi-card">
          <span class="cp-kpi-label"><?= esc($label) ?></span>
          <strong class="cp-kpi-val cp-mono"><?= number_format((int) $value) ?></strong>
          <span class="cp-kpi-sub"><?= esc($hint) ?></span>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cp-main-panel" data-refresh-region="history">
    <div class="cp-tabs-bar">
      <nav class="nav nav-tabs cp-chart-tabs" aria-label="Guidance record sections">
        <?php foreach ($tabLabels as $key => [$label, $icon]): ?>
          <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" href="<?= esc($sectionUrl($key)) ?>" data-record-tab-link="<?= esc($key) ?>" <?= $activeTab === $key ? 'aria-current="page"' : '' ?> aria-controls="guidance-record-section">
            <i class="bi <?= esc($icon) ?>" aria-hidden="true"></i><?= esc($label) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="cp-tab-body" id="guidance-record-section">
      <div class="cp-tab-toolbar">
        <h3 class="cp-tab-heading" data-refresh-heading tabindex="-1"><?= esc($tabLabels[$activeTab][0]) ?></h3>
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
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(base_url('assets/js/guidance-client-record.js')) ?>"></script>
<?= $this->endSection() ?>
