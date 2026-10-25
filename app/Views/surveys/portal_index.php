<?php
$filters = $filters ?? ['q' => '', 'status' => 'all'];
$counts = $counts ?? array_fill_keys(['all', 'available', 'submitted', 'closed'], 0);
$now = new \DateTimeImmutable($nowUtc ?? gmdate('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
$tabUrl = static fn (string $state): string => $baseUrl . '?' . http_build_query(['q' => $filters['q'], 'status' => $state]);
?>
<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<section class="portal-engagement" aria-labelledby="campus-surveys-title"
         data-live-filters
         data-filter-url="<?= esc($baseUrl) ?>"
         data-filter-key="portal-surveys"
         data-live-events="notifications.changed">
  <header class="pe-header"><span class="pe-header-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span><div><p class="pe-eyebrow">CAMPUS VOICES</p><h2 id="campus-surveys-title">Your voice matters</h2><p class="text-muted mb-0">Share your perspective, track what you’ve submitted, and see when surveys close.</p></div></header>
  <?php if (!($hasProfile ?? false)): ?><div class="alert alert-warning"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i><strong>University profile required.</strong> Personal surveys are addressed to linked student and employee profiles. An administrative account without one cannot participate.</div><?php endif; ?>
  <div class="pe-privacy"><i class="bi bi-shield-lock" aria-hidden="true"></i><p class="mb-0"><strong>These surveys are identified, not anonymous.</strong> Your name and answers may be read by authorized Guidance administrators and supervisors. You can submit each survey once.</p></div>
  <div class="card pe-toolbar"><div class="card-body p-3 p-lg-4">
    <form method="get" action="<?= esc($baseUrl) ?>" class="row g-3 align-items-end" role="search" aria-label="Search surveys" data-filter-form>
      <input type="hidden" name="status" value="<?= esc($filters['status']) ?>">
      <div class="col-md-8"><label for="survey-search" class="form-label">Find a survey</label><input id="survey-search" type="search" name="q" class="form-control" maxlength="120" placeholder="Search a title or topic" value="<?= esc($filters['q']) ?>"></div>
      <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button><a class="btn btn-outline-secondary" href="<?= esc($baseUrl) ?>" data-filter-reset>Reset</a></div>
    </form>
  </div></div>
  <?= view('partials/live_filter_feedback') ?>
  <div data-filter-region="results">
  <nav class="pe-tabs" aria-label="Survey participation status">
    <?php foreach (['all' => 'All surveys', 'available' => 'Available', 'submitted' => 'Submitted', 'closed' => 'Closed'] as $state => $label): ?><a data-filter-link href="<?= esc($tabUrl($state)) ?>" class="pe-tab <?= $filters['status'] === $state ? 'is-active' : '' ?>" <?= $filters['status'] === $state ? 'aria-current="page"' : '' ?>><?= esc($label) ?><span class="pe-tab-count"><?= number_format($counts[$state]) ?></span></a><?php endforeach; ?>
  </nav>
  <p class="pe-filter-note">Counts reflect your search. Submitted surveys can also appear under Closed.</p>
  <div class="pe-survey-grid">
    <?php foreach ($items as $survey):
      $submitted = (bool) $survey['submitted'];
      $closed = (bool) ($survey['closed'] ?? false);
      $deadline = empty($survey['ends_at']) ? null : new \DateTimeImmutable($survey['ends_at'], new \DateTimeZone('UTC'));
      $closingSoon = !$closed && !$submitted && $deadline !== null && $deadline > $now && $deadline <= $now->modify('+48 hours');
      $description = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $survey['description'] ?? '') ?? ''), 0, 260, '…', 'UTF-8');
    ?>
    <article class="card pe-content-card pe-survey-card" aria-labelledby="survey-title-<?= (int) $survey['id'] ?>">
      <div class="card-body p-4">
        <div class="pe-card-top"><span class="pe-department"><i class="bi bi-chat-heart" aria-hidden="true"></i>Guidance Office</span><span class="pe-state <?= $submitted ? 'pe-state-success' : ($closed ? '' : 'pe-state-pending') ?>"><?= $submitted ? 'Submitted' : ($closed ? 'Closed' : 'Not yet answered') ?></span></div>
        <h3 class="pe-card-title" id="survey-title-<?= (int) $survey['id'] ?>"><?= esc($survey['title']) ?></h3>
        <?php if ($description !== ''): ?><p class="pe-excerpt"><?= esc($description) ?></p><?php endif; ?>
        <div class="pe-survey-deadline"><i class="bi <?= $closed ? 'bi-lock' : 'bi-clock' ?>" aria-hidden="true"></i><div><span class="pe-date-label"><?= $closed ? 'Participation closed' : ($closingSoon ? 'Closing soon' : 'Closing date') ?></span><?php if ($deadline !== null): ?><time datetime="<?= esc($deadline->format('c')) ?>"><?= $closed ? 'Scheduled deadline: ' : '' ?><?= esc($deadline->setTimezone(new \DateTimeZone('Asia/Manila'))->format('d M Y, g:i A')) ?> <small>(Manila)</small></time><?php else: ?><span><?= $closed ? 'Closed by Guidance' : 'No closing date set' ?></span><?php endif; ?></div></div>
        <div class="pe-survey-action">
          <?php if ($submitted): ?><p class="pe-completion"><i class="bi bi-check-circle" aria-hidden="true"></i>Your submission is recorded<?= $closed ? '; this survey is now closed' : '' ?>.</p><?php if (!$closed): ?><a class="btn btn-outline-primary w-100 mt-2" href="<?= esc(site_url('portal/surveys/' . (int) $survey['id'])) ?>" aria-describedby="survey-title-<?= (int) $survey['id'] ?>">View status</a><?php endif; ?>
          <?php elseif ($closed): ?><button class="btn btn-outline-secondary w-100" type="button" disabled>Survey closed</button><p class="pe-action-note">This survey no longer accepts responses.</p>
          <?php else: ?><a class="btn btn-primary w-100" href="<?= esc(site_url('portal/surveys/' . (int) $survey['id'])) ?>" aria-describedby="survey-title-<?= (int) $survey['id'] ?>">Answer survey<i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a><?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php if ($items === []): ?><div class="card pe-empty"><i class="bi bi-clipboard-check" aria-hidden="true"></i><h3><?= $filters['status'] === 'submitted' ? 'No submissions yet' : ($filters['status'] === 'closed' ? 'No closed surveys' : 'No surveys to show') ?></h3><p>No surveys match your profile and selected filters. Check back for new opportunities to share your feedback.</p><a href="<?= esc($baseUrl) ?>" class="btn btn-outline-primary btn-sm" data-filter-reset>View all surveys</a></div><?php endif; ?>
  <?= view('partials/content_pagination', ['pagination' => $pagination, 'filters' => $filters, 'baseUrl' => $baseUrl, 'paginationLabel' => 'Survey pages', 'liveFilters' => true], ['saveData' => false]) ?>
  </div>
</section>
<?= $this->endSection() ?>
