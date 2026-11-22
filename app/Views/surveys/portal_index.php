<?php
$filters = $filters ?? ['q' => '', 'status' => 'all'];
$counts = $counts ?? array_fill_keys(['all', 'available', 'submitted', 'closed'], 0);
$miAssignments = is_array($miAssignments ?? null) ? $miAssignments : [];
$miGateEnabled = (bool) ($miGateEnabled ?? false);
$now = new \DateTimeImmutable($nowUtc ?? gmdate('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
$tabUrl = static fn (string $state): string => $baseUrl . '?' . http_build_query(['q' => $filters['q'], 'status' => $state]);

$miQuery = mb_strtolower(trim((string) ($filters['q'] ?? '')), 'UTF-8');
$miSearchMatches = array_values(array_filter($miAssignments, static function (array $item) use ($miQuery): bool {
    if ($miQuery === '') {
        return true;
    }
    $haystack = mb_strtolower(implode(' ', [
        'Multiple Intelligences Survey',
        'Walter McKenzie',
        'Guidance Office',
        'Assessment',
        (string) ($item['academic_term'] ?? ''),
        (string) ($item['academic_year'] ?? ''),
    ]), 'UTF-8');
    return str_contains($haystack, $miQuery);
}));

$displayCounts = $counts;
foreach ($miSearchMatches as $miItem) {
    $miStatus = (string) ($miItem['status'] ?? 'assigned');
    $miCompleted = $miStatus === 'completed' && !empty($miItem['submission_id']);
    $miCancelled = $miStatus === 'cancelled';
    $displayCounts['all']++;
    if ($miCompleted) {
        $displayCounts['submitted']++;
        $displayCounts['closed']++;
    } elseif ($miCancelled) {
        $displayCounts['closed']++;
    } else {
        $displayCounts['available']++;
    }
}

$visibleMiAssignments = array_values(array_filter($miSearchMatches, static function (array $item) use ($filters): bool {
    $miStatus = (string) ($item['status'] ?? 'assigned');
    $miCompleted = $miStatus === 'completed' && !empty($item['submission_id']);
    $miCancelled = $miStatus === 'cancelled';
    return match ($filters['status']) {
        'available' => !$miCompleted && !$miCancelled,
        'submitted' => $miCompleted,
        'closed'    => $miCompleted || $miCancelled,
        default     => true,
    };
}));

$currentPage = max(1, (int) ($pagination['page'] ?? 1));
$pageMiAssignments = $currentPage === 1 ? $visibleMiAssignments : [];
$displayPagination = $pagination ?? ['page' => 1, 'pages' => 1, 'filtered' => count($items), 'per_page' => 12];
if ($visibleMiAssignments !== []) {
    $displayPagination['filtered'] = (int) ($displayPagination['filtered'] ?? 0) + count($visibleMiAssignments);
    $displayPagination['per_page'] = max((int) ($displayPagination['per_page'] ?? 12), count($items) + count($pageMiAssignments));
}
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a href="<?= esc(site_url('/portal'), 'attr') ?>" class="portal-back-btn">
  <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Health Portal
</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="portal-engagement pe-surveys" aria-labelledby="campus-surveys-title"
         data-live-filters
         data-filter-url="<?= esc($baseUrl) ?>"
         data-filter-key="portal-surveys"
         data-live-events="notifications.changed">
  <div class="sv-sheet">

    <!-- Band 1: Intro & Identified-Response Disclosure -->
    <div class="sv-band">
      <h2 class="sv-intro-title" id="campus-surveys-title">Your voice matters</h2>
      <p class="sv-intro-desc">Share your perspective, track what you’ve submitted, and see when surveys close.</p>

      <?php if (!($hasProfile ?? false)): ?>
        <div class="sv-inline-notice" role="status">
          <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
          <div><strong>University profile required.</strong> Personal surveys are addressed to linked student and employee profiles. An administrative account without one cannot participate.</div>
        </div>
      <?php endif; ?>

      <div class="pe-privacy">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <p class="mb-0"><strong>These surveys are identified, not anonymous.</strong> Your name and answers may be read by authorized Guidance administrators and supervisors. You can submit each survey once.</p>
      </div>

      <?php if (!empty($miAssignmentsUnavailable)): ?>
        <div class="sv-inline-notice" role="status">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
          <div>Assigned assessments are temporarily unavailable. Your general surveys remain accessible below.</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Band 2: Search, Participation Tabs & Unified Survey Grid -->
    <div class="sv-band">
      <form method="get" action="<?= esc($baseUrl) ?>" class="sv-search-form" role="search" aria-label="Search surveys" data-filter-form>
        <input type="hidden" name="status" value="<?= esc($filters['status']) ?>">
        <div class="sv-search-field">
          <label for="survey-search" class="sv-search-label">Find a survey</label>
          <input id="survey-search" type="search" name="q" class="form-control sv-search-input" maxlength="120" placeholder="Search a title or topic" value="<?= esc($filters['q']) ?>">
        </div>
        <div class="sv-search-actions">
          <button class="btn btn-primary sv-btn" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Search</button>
          <a class="btn btn-outline-secondary sv-btn" href="<?= esc($baseUrl) ?>" data-filter-reset>Reset</a>
        </div>
      </form>

      <?= view('partials/live_filter_feedback') ?>

      <div data-filter-region="results">
        <nav class="pe-tabs" aria-label="Survey participation status">
          <?php foreach (['all' => 'All surveys', 'available' => 'Available', 'submitted' => 'Submitted', 'closed' => 'Closed'] as $state => $label): ?>
            <a data-filter-link href="<?= esc($tabUrl($state)) ?>" class="pe-tab <?= $filters['status'] === $state ? 'is-active' : '' ?>" <?= $filters['status'] === $state ? 'aria-current="page"' : '' ?>>
              <?= esc($label) ?><span class="pe-tab-count"><?= number_format($displayCounts[$state]) ?></span>
            </a>
          <?php endforeach; ?>
        </nav>

        <p class="pe-filter-note">Counts reflect your search. Submitted surveys can also appear under Closed.</p>

        <?php if (!$miGateEnabled && $pageMiAssignments !== []): ?>
          <div class="sv-inline-notice mb-3" role="status">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <div>You may open an assigned Multiple Intelligences assessment and save encrypted drafts, but final submission is paused pending Guidance, QA, and DPO sign-off.</div>
          </div>
        <?php endif; ?>

        <?php if ($pageMiAssignments !== [] || $items !== []): ?>
          <div class="pe-survey-grid">
            <?php if ($pageMiAssignments !== []): ?>
              <?= view('surveys/_mi_assignments', ['assignments' => $pageMiAssignments, 'miGateEnabled' => $miGateEnabled]) ?>
            <?php endif; ?>
            <?php foreach ($items as $survey):
              $submitted = (bool) $survey['submitted'];
              $closed = (bool) ($survey['closed'] ?? false);
              $externalUrl = trim((string) ($survey['external_url'] ?? ''));
              $deadline = empty($survey['ends_at']) ? null : new \DateTimeImmutable($survey['ends_at'], new \DateTimeZone('UTC'));
              $closingSoon = !$closed && !$submitted && $deadline !== null && $deadline > $now && $deadline <= $now->modify('+48 hours');
              $description = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $survey['description'] ?? '') ?? ''), 0, 260, '…', 'UTF-8');
            ?>
            <article class="pe-content-card pe-survey-card <?= $closed ? 'is-closed' : '' ?>" aria-labelledby="survey-title-<?= (int) $survey['id'] ?>">
              <div class="pe-card-top">
                <span class="pe-department">
                  <?= view('partials/unit_vector_icon', ['unit' => 'guidance']) ?>Guidance Office<?php if ($externalUrl !== ''): ?><span class="sv-external-note">External</span><?php endif; ?>
                </span>
                <span class="pe-state <?= $submitted ? 'pe-state-success' : ($closed ? '' : 'pe-state-pending') ?>"><?= $submitted ? 'Submitted' : ($closed ? 'Closed' : 'Not yet answered') ?></span>
              </div>

              <h3 class="pe-card-title" id="survey-title-<?= (int) $survey['id'] ?>"><?= esc($survey['title']) ?></h3>

              <?php if ($description !== ''): ?>
                <p class="pe-excerpt"><?= esc($description) ?></p>
              <?php endif; ?>

              <div class="pe-survey-deadline <?= $closingSoon ? 'is-urgent' : '' ?>">
                <i class="bi <?= $closed ? 'bi-lock' : 'bi-clock' ?>" aria-hidden="true"></i>
                <div>
                  <span class="pe-date-label"><?= $closed ? 'Participation closed' : ($closingSoon ? 'Closing soon' : 'Closing date') ?></span>
                  <?php if ($deadline !== null): ?>
                    <time datetime="<?= esc($deadline->format('c')) ?>"><?= $closed ? 'Scheduled deadline: ' : '' ?><?= esc($deadline->setTimezone(new \DateTimeZone('Asia/Manila'))->format('d M Y, g:i A')) ?> <small>(Manila)</small></time>
                  <?php else: ?>
                    <span><?= $closed ? 'Closed by Guidance' : 'No closing date set' ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="pe-survey-action">
                <?php if ($submitted): ?>
                  <p class="pe-completion"><i class="bi bi-check-circle" aria-hidden="true"></i>Your submission is recorded<?= $closed ? '; this survey is now closed' : '' ?>.</p>
                  <?php if (!$closed): ?>
                    <div class="sv-action-row">
                      <a class="btn btn-outline-secondary sv-btn" href="<?= esc(site_url('portal/surveys/' . (int) $survey['id'])) ?>" aria-describedby="survey-title-<?= (int) $survey['id'] ?>"><?= $externalUrl !== '' ? 'View status' : 'View submitted answers' ?></a>
                    </div>
                  <?php endif; ?>
                <?php elseif ($closed): ?>
                  <div class="sv-action-row">
                    <button class="btn btn-outline-secondary sv-btn" type="button" disabled>Survey closed</button>
                  </div>
                  <p class="pe-action-note">This survey no longer accepts responses.</p>
                <?php elseif ($externalUrl !== ''): ?>
                  <div class="sv-action-row">
                    <a class="btn btn-primary sv-btn" href="<?= esc($externalUrl, 'attr') ?>" target="_blank" rel="noopener noreferrer" aria-describedby="survey-title-<?= (int) $survey['id'] ?>">Open external survey<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                    <a class="btn btn-outline-secondary sv-btn" href="<?= esc(site_url('portal/surveys/' . (int) $survey['id'])) ?>" aria-describedby="survey-title-<?= (int) $survey['id'] ?>">Details / Mark completed</a>
                  </div>
                <?php else: ?>
                  <div class="sv-action-row">
                    <a class="btn btn-primary sv-btn" href="<?= esc(site_url('portal/surveys/' . (int) $survey['id'])) ?>" aria-describedby="survey-title-<?= (int) $survey['id'] ?>">Answer survey<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                  </div>
                <?php endif; ?>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="pe-empty">
            <i class="bi bi-clipboard-check" aria-hidden="true"></i>
            <h3><?= $filters['status'] === 'submitted' ? 'No submissions yet' : ($filters['status'] === 'closed' ? 'No closed surveys' : 'No surveys to show') ?></h3>
            <p>No surveys match your profile and selected filters. Check back for new opportunities to share your feedback.</p>
            <a href="<?= esc($baseUrl) ?>" class="btn btn-outline-secondary sv-btn" data-filter-reset>View all surveys</a>
          </div>
        <?php endif; ?>

        <?= view('partials/content_pagination', ['pagination' => $displayPagination, 'filters' => $filters, 'baseUrl' => $baseUrl, 'paginationLabel' => 'Survey pages', 'liveFilters' => true], ['saveData' => false]) ?>
      </div>
    </div>

  </div>
</section>
<?= $this->endSection() ?>
