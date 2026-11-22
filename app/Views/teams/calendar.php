<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$tz = new DateTimeZone('Asia/Manila');
$unitLabel = ['clinic' => 'Clinic', 'guidance' => 'Guidance', 'facilities' => 'BMG'][$unit];
$anchor = new DateTimeImmutable($date, $tz);
$last = (new DateTimeImmutable($endDate, $tz))->modify('-1 day');
$heading = $view === 'month' ? $anchor->format('F Y') : (new DateTimeImmutable($startDate, $tz))->format('M j') . ' – ' . $last->format('M j, Y');
$link = static fn(array $changes): string => $baseUrl . '?' . http_build_query(array_replace(['date' => $date, 'view' => $view, 'mine' => $mine, 'status' => $status], $changes));

$avatarTone = static function (int|string $seed): string {
    $tones = ['maroon', 'emerald', 'sky', 'amber', 'slate'];
    return $tones[abs(crc32((string) $seed)) % count($tones)];
};
$initialsOf = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : 'TM';
};
$visibleMembers = array_slice($members, 0, 4);

$upcomingItems = [];
$seenUpcoming = [];
foreach ($days as $dayKey => $dayData) {
    if ($dayKey < $date && $dayKey < $today) {
        continue;
    }
    foreach ($dayData['items'] as $item) {
        $sig = $item['type'] . ':' . $item['url'];
        if (isset($seenUpcoming[$sig])) {
            continue;
        }
        $seenUpcoming[$sig] = true;
        $upcomingItems[] = ['date' => $dayData['date']->format('M j'), 'item' => $item];
        if (count($upcomingItems) >= 3) {
            break 2;
        }
    }
}
?>
<div class="teams-workspace tws-workspace tws-calendar-workspace" data-live-filters data-filter-url="<?= esc($baseUrl) ?>" data-filter-key="teams-calendar-<?= esc($unit) ?>" data-mutation-refresh data-refresh-owner="teams.calendar.<?= esc($unit, 'attr') ?>">
  <div class="tws-sheet">
    <header class="tws-band tws-intro">
      <div class="tws-intro-head">
        <div class="tws-intro-primary">
          <div class="tws-title-block">
            <span class="tws-eyebrow"><?= esc($unitLabel) ?> workspace · Teams</span>
            <h2 class="tws-intro-title">Team Calendar &amp; Deadlines</h2>
          </div>
          <details class="tws-members tws-roster-details">
            <summary class="tws-roster-pill" aria-label="Workstation members (<?= count($members) ?>)">
              <span class="tws-avatar-stack" aria-hidden="true">
                <?php foreach ($visibleMembers as $member): ?>
                  <span class="tws-avatar is-<?= esc($avatarTone($member['id'] ?? $member['name'])) ?>"><?= esc($initialsOf($member['name'])) ?></span>
                <?php endforeach; ?>
              </span>
              <span class="tws-count"><?= count($members) ?> members</span>
              <i class="bi bi-chevron-down tws-roster-chevron" aria-hidden="true"></i>
            </summary>
            <div class="tws-roster-panel">
              <p class="tws-field-note mb-2">Membership follows active <?= esc($unitLabel) ?> workstation roles. Contact an administrator to change access.</p>
              <ul class="list-unstyled teams-member-list">
                <?php foreach ($members as $member): ?>
                  <li>
                    <span class="tws-avatar is-<?= esc($avatarTone($member['id'] ?? $member['name'])) ?>" aria-hidden="true"><?= esc($initialsOf($member['name'])) ?></span>
                    <span class="tws-member-name"><?= esc($member['name']) ?></span>
                    <small><?= esc(ucwords(str_replace('_', ' ', $member['role']))) ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </details>
        </div>
        <div class="tws-intro-actions">
          <div class="tws-notice tws-notice--compact" role="note" title="<?= esc('Internal events and task deadlines only. This calendar does not reserve patient appointments or block provider availability. All times are Asia/Manila. Do not enter patient details or confidential counseling notes.') ?>">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <p>Internal ops only · Does not block patient slots <span class="visually-hidden">Internal events and task deadlines only. This calendar does not reserve patient appointments or block provider availability. Event changes use targeted in-app notices, with optional mobile push when configured. All times are Asia/Manila. Do not enter patient details or confidential counseling notes.</span></p>
          </div>
          <a class="btn btn-primary tws-primary-cta" href="<?= esc($baseUrl . '/events/create?' . http_build_query(['date' => $date])) ?>" data-app-modal-open data-filter-context-link="teams-calendar-<?= esc($unit) ?>"><i class="bi bi-plus-lg me-2" aria-hidden="true"></i>New event</a>
        </div>
      </div>
    </header>

    <div class="tws-navigation">
      <nav class="tws-nav" aria-label="Teams views">
        <a class="tws-nav-link" href="<?= esc($teamsUrl) ?>"><i class="bi bi-kanban me-2" aria-hidden="true"></i>Team board</a>
        <a class="tws-nav-link" href="<?= esc($teamsUrl . '?mine=1&view=list') ?>"><i class="bi bi-list-check me-2" aria-hidden="true"></i>My tasks</a>
        <a class="tws-nav-link" href="<?= esc($teamsUrl . '/chat') ?>"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Team chat</a>
        <a class="tws-nav-link" href="<?= esc($baseUrl) ?>" aria-current="page"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Team calendar</a>
      </nav>
      <span class="tws-tz-pill"><i class="bi bi-clock me-1" aria-hidden="true"></i>Asia/Manila (UTC+08)</span>
    </div>

    <div data-filter-region="notice" data-refresh-region="notice">
      <?php if ($truncated): ?><div class="alert alert-warning m-3 mb-0" role="status">This range contains <?= $totalEvents ?> events and <?= $totalTasks ?> task deadlines. Only the first <?= \App\Services\TeamCalendarService::LIST_LIMIT ?> of each are shown. Switch to Week or My schedule to narrow the range.</div><?php endif; ?>
    </div>
    <div class="tws-feedback"><?= view('partials/live_filter_feedback') ?></div>

    <div class="team-calendar-layout">
      <aside aria-label="Calendar filters and legend">
        <div class="tws-band tws-calendar-filter-band">
          <h2 class="tws-sidebar-label mb-3">Plan your schedule</h2>
          <form action="<?= esc($baseUrl) ?>" method="get" data-filter-form>
            <input type="hidden" name="view" value="<?= esc($view) ?>">
            <div class="mb-3">
              <label class="form-label" for="calendarDateDisplay">Go to date</label>
              <input class="form-control" type="text" id="calendarDateDisplay" data-datepicker data-target="#calendarDate" placeholder="DD/MM/YYYY" required>
              <input type="hidden" id="calendarDate" name="date" value="<?= esc($date) ?>">
            </div>
            <div class="mb-3">
              <label class="form-label" for="calendarScope">Show</label>
              <select class="form-select" id="calendarScope" name="mine">
                <option value="" <?= $mine === '' ? 'selected' : '' ?>>Workstation schedule</option>
                <option value="1" <?= $mine === '1' ? 'selected' : '' ?>>My schedule</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label" for="calendarEventStatus">Event status</label>
              <select class="form-select" id="calendarEventStatus" name="status">
                <?php foreach (['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'all' => 'All events'] as $value => $label): ?>
                  <option value="<?= esc($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-primary w-100" type="submit">Apply filters</button>
          </form>
          <p class="small text-muted mt-3 mb-0">My schedule shows events you created or participate in, and tasks assigned to you.</p>
        </div>

        <div class="tws-band tws-calendar-key-band">
          <h2 class="tws-sidebar-label mb-2">Calendar key</h2>
          <div class="tws-calendar-legend">
            <p class="small mb-1"><span class="team-calendar-key is-event" aria-hidden="true"></span>Team event</p>
            <p class="small mb-2"><span class="team-calendar-key is-task" aria-hidden="true"></span>Task due date</p>
          </div>
          <p class="small text-muted mb-0 tws-calendar-summary" data-filter-region="summary" data-refresh-region="summary"><?= $totalEvents ?> matching events<br><?= $totalTasks ?> task deadlines<br><?= count($members) ?> active members</p>
        </div>
      </aside>

      <section class="team-calendar-main" aria-labelledby="calendarHeading" data-filter-region="results" data-refresh-region="results">
        <header class="team-calendar-toolbar">
          <div class="tws-cal-toolbar-left">
            <div class="tws-cal-nav-group">
              <a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($link(['date' => $prevDate])) ?>" aria-label="Previous calendar period"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
              <a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($link(['date' => $today])) ?>">Today</a>
              <a class="btn btn-outline-secondary btn-sm" data-filter-link href="<?= esc($link(['date' => $nextDate])) ?>" aria-label="Next calendar period"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </div>
            <h2 class="h5 mb-0" id="calendarHeading"><?= esc($heading) ?></h2>
          </div>
          <nav class="tws-view-switch" aria-label="Calendar display">
            <?php foreach (['month' => 'Month', 'week' => 'Week', 'agenda' => 'Agenda'] as $value => $label): ?>
              <a class="tws-display-link" data-filter-link href="<?= esc($link(['view' => $value])) ?>" <?= $view === $value ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a>
            <?php endforeach; ?>
          </nav>
        </header>

        <?php if ($upcomingItems !== []): ?>
          <div class="tws-cal-upcoming-strip" aria-label="Upcoming events and deadlines">
            <span class="tws-sidebar-label tws-upcoming-strip-label">Coming up</span>
            <ul class="list-unstyled tws-upcoming-list mb-0">
              <?php foreach ($upcomingItems as $entry): ?>
                <li>
                  <a class="tws-upcoming-item is-<?= esc($entry['item']['type']) ?>" href="<?= esc($entry['item']['url']) ?>" data-app-modal-open>
                    <span class="tws-upcoming-bar" aria-hidden="true"></span>
                    <span class="tws-upcoming-body">
                      <span class="tws-upcoming-title"><?= esc($entry['item']['title']) ?></span>
                      <span class="tws-upcoming-meta"><?= esc($entry['date']) ?> · <?= esc($entry['item']['label']) ?></span>
                    </span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($view === 'agenda'): ?>
          <div class="team-calendar-agenda">
            <?php $hasItems = false; foreach ($days as $key => $day): if ($day['items'] === []) continue; $hasItems = true; ?>
              <section class="team-calendar-agenda-day">
                <h3 class="tws-section-title"><time datetime="<?= esc($key) ?>"><?= esc($day['date']->format('D, M j, Y')) ?></time></h3>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($day['items'] as $item): ?>
                    <li><?= view('teams/_calendar_item', ['item' => $item], ['saveData' => false]) ?></li>
                  <?php endforeach; ?>
                </ul>
              </section>
            <?php endforeach; if (!$hasItems): ?>
              <p class="text-center text-muted p-4 mb-0">No events or task deadlines in this range.</p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="team-calendar-scroll" tabindex="0" role="region" aria-label="<?= esc(ucfirst($view)) ?> calendar; scroll horizontally on small screens">
            <table class="team-calendar-grid <?= $view === 'week' ? 'is-week' : '' ?>">
              <caption class="visually-hidden"><?= esc($heading) ?>, Asia/Manila. Events ending at midnight do not occupy the next day.</caption>
              <thead>
                <tr>
                  <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?>
                    <th scope="col"><?= $weekday ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_chunk($days, 7, true) as $week): ?>
                  <tr>
                    <?php foreach ($week as $key => $day): ?>
                      <td class="<?= $key === $today ? 'is-today ' : '' ?><?= $view === 'month' && $day['date']->format('Y-m') !== $anchor->format('Y-m') ? 'is-other-month' : '' ?>">
                        <div class="team-calendar-day-heading">
                          <time datetime="<?= esc($key) ?>" <?= $key === $today ? 'aria-current="date"' : '' ?>><span class="visually-hidden"><?= esc($day['date']->format('F')) ?> </span><?= $day['date']->format('j') ?></time>
                          <a class="team-calendar-add" href="<?= esc($baseUrl . '/events/create?' . http_build_query(['date' => $key])) ?>" data-app-modal-open aria-label="New event on <?= esc($day['date']->format('F j, Y')) ?>"><i class="bi bi-plus" aria-hidden="true"></i></a>
                        </div>
                        <ul class="team-calendar-day-items list-unstyled">
                          <?php foreach ($day['items'] as $item): ?>
                            <li><?= view('teams/_calendar_item', ['item' => $item], ['saveData' => false]) ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
