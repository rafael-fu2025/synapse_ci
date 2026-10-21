<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a class="btn btn-primary" href="<?= esc($baseUrl . '/events/create?' . http_build_query(['date' => $date])) ?>" data-app-modal-open data-filter-context-link="teams-calendar-<?= esc($unit) ?>"><i class="bi bi-plus-lg me-2" aria-hidden="true"></i>New event</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$anchor = new DateTimeImmutable($date, new DateTimeZone('Asia/Manila'));
$last = (new DateTimeImmutable($endDate, new DateTimeZone('Asia/Manila')))->modify('-1 day');
$heading = $view === 'month' ? $anchor->format('F Y') : (new DateTimeImmutable($startDate))->format('M j') . ' – ' . $last->format('M j, Y');
$link = static fn(array $changes): string => $baseUrl . '?' . http_build_query(array_replace(['date' => $date, 'view' => $view, 'mine' => $mine, 'status' => $status], $changes));
?>
<div class="teams-workspace" data-live-filters data-filter-url="<?= esc($baseUrl) ?>" data-filter-key="teams-calendar-<?= esc($unit) ?>">
  <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Teams views"><a class="btn btn-outline-secondary" href="<?= esc($teamsUrl) ?>">Team board</a><a class="btn btn-outline-secondary" href="<?= esc($teamsUrl . '?mine=1&view=list') ?>">My tasks</a><a class="btn btn-outline-secondary" href="<?= esc($teamsUrl . '/chat') ?>">Team chat</a><a class="btn btn-primary" href="<?= esc($baseUrl) ?>" aria-current="page">Team calendar</a></nav>
  <div class="alert alert-light border small"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>Internal events and task deadlines only. This calendar does not reserve patient appointments, block provider availability, or send invitations/reminders. All times are <strong>Asia/Manila</strong>. Do not enter patient details or confidential counseling notes.</div>
  <div data-filter-region="notice">
  <?php if ($truncated): ?><div class="alert alert-warning" role="status">This range contains <?= $totalEvents ?> events and <?= $totalTasks ?> task deadlines. Only the first <?= \App\Services\TeamCalendarService::LIST_LIMIT ?> of each are shown. Switch to Week or My schedule to narrow the range.</div><?php endif; ?>
  </div>
  <?= view('partials/live_filter_feedback') ?>
  <div class="team-calendar-layout">
    <aside aria-label="Calendar filters and legend">
      <div class="card"><div class="card-body p-3">
        <span class="teams-eyebrow"><?= esc(ucfirst($unit)) ?> workspace</span><h2 class="h6 mt-2">Plan your schedule</h2>
        <form action="<?= esc($baseUrl) ?>" method="get" data-filter-form>
          <input type="hidden" name="view" value="<?= esc($view) ?>">
          <label class="form-label" for="calendarDateDisplay">Go to date</label><input class="form-control mb-3" type="text" id="calendarDateDisplay" data-datepicker data-target="#calendarDate" placeholder="DD/MM/YYYY" required><input type="hidden" id="calendarDate" name="date" value="<?= esc($date) ?>">
          <label class="form-label" for="calendarScope">Show</label><select class="form-select mb-3" id="calendarScope" name="mine"><option value="" <?= $mine === '' ? 'selected' : '' ?>>Workstation schedule</option><option value="1" <?= $mine === '1' ? 'selected' : '' ?>>My schedule</option></select>
          <label class="form-label" for="calendarEventStatus">Event status</label><select class="form-select mb-3" id="calendarEventStatus" name="status"><?php foreach (['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'all' => 'All events'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select>
          <button class="btn btn-primary w-100" type="submit">Apply filters</button>
        </form>
        <p class="small text-muted mt-3 mb-0">My schedule shows events you created or participate in, and tasks assigned to you.</p>
      </div></div>
      <div class="card"><div class="card-body p-3"><h2 class="h6">Calendar key</h2><p class="small"><span class="team-calendar-key is-event" aria-hidden="true"></span>Team event</p><p class="small"><span class="team-calendar-key is-task" aria-hidden="true"></span>Task due date</p><p class="small text-muted mb-0" data-filter-region="summary"><?= $totalEvents ?> matching events<br><?= $totalTasks ?> task deadlines<br><?= count($members) ?> active members</p></div></div>
    </aside>
    <section class="card team-calendar-main" aria-labelledby="calendarHeading" data-filter-region="results">
      <header class="team-calendar-toolbar">
        <div class="d-flex flex-wrap align-items-center gap-2"><a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($link(['date' => $prevDate])) ?>" aria-label="Previous calendar period"><i class="bi bi-chevron-left" aria-hidden="true"></i></a><a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($link(['date' => $today])) ?>">Today</a><a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($link(['date' => $nextDate])) ?>" aria-label="Next calendar period"><i class="bi bi-chevron-right" aria-hidden="true"></i></a><h2 class="h5 mb-0 ms-1" id="calendarHeading"><?= esc($heading) ?></h2></div>
        <nav class="d-flex gap-1" aria-label="Calendar display"><?php foreach (['month' => 'Month', 'week' => 'Week', 'agenda' => 'Agenda'] as $value => $label): ?><a class="btn btn-sm <?= $view === $value ? 'btn-primary' : 'btn-outline-secondary' ?>" data-filter-link href="<?= esc($link(['view' => $value])) ?>" <?= $view === $value ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a><?php endforeach; ?></nav>
      </header>
      <?php if ($view === 'agenda'): ?>
        <div class="team-calendar-agenda">
          <?php $hasItems = false; foreach ($days as $key => $day): if ($day['items'] === []) continue; $hasItems = true; ?>
          <section class="team-calendar-agenda-day"><h3 class="h6"><time datetime="<?= esc($key) ?>"><?= esc($day['date']->format('D, M j, Y')) ?></time></h3><ul class="list-unstyled mb-0"><?php foreach ($day['items'] as $item): ?><li><?= view('teams/_calendar_item', ['item' => $item], ['saveData' => false]) ?></li><?php endforeach; ?></ul></section>
          <?php endforeach; if (!$hasItems): ?><p class="text-center text-muted p-4 mb-0">No events or task deadlines in this range.</p><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="team-calendar-scroll" tabindex="0" role="region" aria-label="<?= esc(ucfirst($view)) ?> calendar; scroll horizontally on small screens">
          <table class="team-calendar-grid <?= $view === 'week' ? 'is-week' : '' ?>"><caption class="visually-hidden"><?= esc($heading) ?>, Asia/Manila. Events ending at midnight do not occupy the next day.</caption><thead><tr><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?><th scope="col"><?= $weekday ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach (array_chunk($days, 7, true) as $week): ?><tr><?php foreach ($week as $key => $day): ?><td class="<?= $key === $today ? 'is-today ' : '' ?><?= $view === 'month' && $day['date']->format('Y-m') !== $anchor->format('Y-m') ? 'is-other-month' : '' ?>">
              <div class="team-calendar-day-heading"><time datetime="<?= esc($key) ?>" <?= $key === $today ? 'aria-current="date"' : '' ?>><span class="visually-hidden"><?= esc($day['date']->format('F')) ?> </span><?= $day['date']->format('j') ?></time><a class="team-calendar-add" href="<?= esc($baseUrl . '/events/create?' . http_build_query(['date' => $key])) ?>" data-app-modal-open aria-label="New event on <?= esc($day['date']->format('F j, Y')) ?>"><i class="bi bi-plus" aria-hidden="true"></i></a></div>
              <ul class="team-calendar-day-items list-unstyled"><?php foreach ($day['items'] as $item): ?><li><?= view('teams/_calendar_item', ['item' => $item], ['saveData' => false]) ?></li><?php endforeach; ?></ul>
            </td><?php endforeach; ?></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?= $this->endSection() ?>
