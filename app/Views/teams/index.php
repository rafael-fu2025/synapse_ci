<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$unitLabel = ['clinic' => 'Clinic', 'guidance' => 'Guidance', 'facilities' => 'BMG'][$unit];
$link = static fn(array $changes): string => $baseUrl . '?' . http_build_query(array_replace($filters + ['view' => $mode], $changes));

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
$inProgressCount = 0;
$overdueCount = 0;
$doneCount = 0;
foreach ($tasks as $t) {
    if ($t['status'] === 'in_progress') $inProgressCount++;
    if ($t['status'] === 'done') $doneCount++;
    if ($t['status'] !== 'done' && !empty($t['due_date']) && $t['due_date'] < $today) $overdueCount++;
}
?>
<div class="teams-workspace tws-workspace" data-live-filters data-filter-url="<?= esc($baseUrl) ?>" data-filter-key="teams-tasks-<?= esc($unit) ?>" data-mutation-refresh data-refresh-owner="teams.tasks.<?= esc($unit, 'attr') ?>">
  <div class="tws-sheet">
    <header class="tws-band tws-intro teams-intro">
      <div class="tws-intro-head">
        <div class="tws-intro-primary">
          <div class="tws-title-block">
            <span class="tws-eyebrow"><?= esc($unitLabel) ?> workspace · Teams</span>
            <h2 class="tws-intro-title"><?= $filters['mine'] === '1' ? 'My Tasks & Operational Queue' : 'Team Tasks & Coordination' ?></h2>
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
          <div class="tws-notice tws-notice--compact" role="note" title="<?= esc('Visible to authorized ' . $unitLabel . ' staff. Do not enter patient information, diagnoses, or confidential counseling notes.') ?>">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <p>Internal ops only · No PHI <span class="visually-hidden">Visible to authorized <?= esc($unitLabel) ?> staff. Do not enter patient information, diagnoses, or confidential counseling notes.</span></p>
          </div>
          <a class="btn btn-primary tws-primary-cta" href="<?= esc($baseUrl . '/create') ?>" data-app-modal-open><i class="bi bi-plus-lg me-2" aria-hidden="true"></i>New task</a>
        </div>
      </div>
    </header>

    <div class="tws-navigation" data-filter-region="navigation" data-refresh-region="navigation">
      <nav class="tws-nav" aria-label="Teams views">
        <a class="tws-nav-link" data-filter-link href="<?= esc($link(['mine' => '', 'archived' => '', 'page' => 1])) ?>" <?= empty($archivedMode) && $filters['mine'] !== '1' ? 'aria-current="page"' : '' ?>><i class="bi bi-kanban me-2" aria-hidden="true"></i>Team board</a>
        <a class="tws-nav-link" data-filter-link href="<?= esc($link(['mine' => '1', 'archived' => '', 'view' => 'list', 'page' => 1])) ?>" <?= empty($archivedMode) && $filters['mine'] === '1' ? 'aria-current="page"' : '' ?>><i class="bi bi-list-check me-2" aria-hidden="true"></i>My tasks</a>
        <?php if (!empty($canViewArchived) || !empty($canArchive) || !empty($archivedMode)): ?>
          <a class="tws-nav-link" data-filter-link href="<?= esc($link(['archived' => '1', 'mine' => '', 'view' => 'list', 'page' => 1])) ?>" <?= !empty($archivedMode) ? 'aria-current="page"' : '' ?>><i class="bi bi-archive me-2" aria-hidden="true"></i>Archived</a>
        <?php endif; ?>
        <a class="tws-nav-link" href="<?= esc($baseUrl . '/chat') ?>"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Team chat</a>
        <a class="tws-nav-link" href="<?= esc($baseUrl . '/calendar') ?>"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Team calendar</a>
      </nav>
      <div class="tws-view-switch" role="group" aria-label="Task display">
        <a class="tws-display-link" data-filter-link href="<?= esc($link(['view' => 'board'])) ?>" aria-label="Board view" <?= $mode === 'board' ? 'aria-current="true"' : '' ?>><i class="bi bi-kanban" aria-hidden="true"></i><span>Board</span></a>
        <a class="tws-display-link" data-filter-link href="<?= esc($link(['view' => 'list'])) ?>" aria-label="List view" <?= $mode === 'list' ? 'aria-current="true"' : '' ?>><i class="bi bi-list-task" aria-hidden="true"></i><span>List</span></a>
      </div>
    </div>

    <form class="tws-filter-band" action="<?= esc($baseUrl) ?>" method="get" role="search" data-filter-form>
      <input type="hidden" name="view" value="<?= esc($mode) ?>"><input type="hidden" name="mine" value="<?= esc($filters['mine']) ?>"><input type="hidden" name="archived" value="<?= esc($filters['archived'] ?? '') ?>">
      <div class="tws-filter-grid">
        <div class="tws-search-field">
          <label class="form-label visually-hidden" for="teamSearch">Search tasks</label>
          <span class="tws-input-icon" aria-hidden="true"><i class="bi bi-search"></i></span>
          <input class="form-control tws-search-input" id="teamSearch" name="q" value="<?= esc($filters['q']) ?>" maxlength="160" placeholder="Search tasks by title or description…">
        </div>
        <div>
          <label class="form-label visually-hidden" for="teamStatus">Status</label>
          <select class="form-select" id="teamStatus" name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $value => $label): ?>
              <option value="<?= esc($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label visually-hidden" for="teamPriority">Priority</label>
          <select class="form-select" id="teamPriority" name="priority">
            <option value="">All priorities</option>
            <?php foreach ($priorities as $value => $label): ?>
              <option value="<?= esc($value) ?>" <?= $filters['priority'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="tws-filter-actions">
          <button class="btn btn-primary" type="submit">Apply</button>
          <a class="btn btn-outline-secondary" href="<?= esc($baseUrl . '?' . http_build_query(['view' => $mode])) ?>" data-filter-reset>Reset</a>
        </div>
      </div>
    </form>

    <div class="tws-feedback"><?= view('partials/live_filter_feedback') ?></div>

    <div class="tws-results" data-filter-region="results" data-refresh-region="results">
      <?php if ($mode === 'list'): ?>
        <div class="tws-kpi-deck" aria-label="Task summary">
          <div class="tws-kpi-cell">
            <span class="tws-kpi-label"><span class="tws-kpi-dot is-brand" aria-hidden="true"></span><?= $filters['mine'] === '1' ? 'Assigned to me' : 'Matching tasks' ?></span>
            <strong class="tws-kpi-value"><?= number_format($total) ?></strong>
            <span class="tws-kpi-sub"><?= count($tasks) ?> shown on this page</span>
          </div>
          <div class="tws-kpi-cell">
            <span class="tws-kpi-label"><span class="tws-kpi-dot is-brand" aria-hidden="true"></span>In progress</span>
            <strong class="tws-kpi-value is-brand"><?= number_format($inProgressCount) ?></strong>
            <span class="tws-kpi-sub">Active on current page</span>
          </div>
          <div class="tws-kpi-cell">
            <span class="tws-kpi-label"><span class="tws-kpi-dot is-danger" aria-hidden="true"></span>Overdue</span>
            <strong class="tws-kpi-value <?= $overdueCount > 0 ? 'is-danger' : '' ?>"><?= number_format($overdueCount) ?></strong>
            <span class="tws-kpi-sub"><?= $overdueCount > 0 ? 'Needs attention' : 'All deadlines on track' ?></span>
          </div>
          <div class="tws-kpi-cell">
            <span class="tws-kpi-label"><span class="tws-kpi-dot is-success" aria-hidden="true"></span>Completed</span>
            <strong class="tws-kpi-value is-success"><?= number_format($doneCount) ?></strong>
            <span class="tws-kpi-sub">Marked done in current view</span>
          </div>
        </div>
      <?php endif; ?>

      <div class="tws-results-head">
        <div class="tws-results-head-left">
          <h3 class="tws-section-title"><?= $filters['mine'] === '1' ? 'Tasks assigned to you' : 'Workstation tasks' ?></h3>
          <span class="tws-count"><?= count($tasks) ?> shown · <?= number_format($total) ?> matching</span>
        </div>
        <?php if ($mode === 'board'): ?>
          <p class="tws-board-help" id="task-drag-help">
            <i class="bi bi-grip-vertical me-1" aria-hidden="true"></i>Drag or press <kbd>Space</kbd> + arrows + <kbd>Enter</kbd> to move
          </p>
        <?php endif; ?>
      </div>

      <?php if ($mode === 'board'): ?>
        <div class="tws-board-band">
          <div class="teams-board" aria-label="Task board" data-team-board aria-describedby="task-drag-help">
            <?php foreach ($statuses as $status => $label): $columnTasks = array_filter($tasks, static fn(array $task): bool => $task['status'] === $status); ?>
              <section class="teams-column" data-task-column="<?= esc($status, 'attr') ?>" aria-label="<?= esc($label, 'attr') ?>" aria-labelledby="column-<?= esc($status) ?>">
                <header class="teams-column-header">
                  <div class="tws-column-title-group">
                    <h2 class="h6 mb-0" id="column-<?= esc($status) ?>"><span class="teams-status-dot is-<?= esc($status) ?>" aria-hidden="true"></span><?= esc($label) ?></h2>
                    <span class="teams-count" aria-label="<?= count($columnTasks) ?> shown in this column"><?= count($columnTasks) ?></span>
                  </div>
                  <a class="tws-column-add" href="<?= esc($baseUrl . '/create') ?>" data-app-modal-open aria-label="<?= esc('Add task to ' . $label, 'attr') ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i></a>
                </header>
                <div class="teams-column-body">
                  <?php foreach ($columnTasks as $task):
                      $assigneeName = $memberNames[$task['assignee_user_id']] ?? ($task['assignee_user_id'] ? 'Former member' : 'Unassigned');
                      $isOverdue = $task['status'] !== 'done' && !empty($task['due_date']) && $task['due_date'] < $today;
                      $checkTotal = is_array($task['checklist']) ? count($task['checklist']) : 0;
                      $checkDone = $checkTotal > 0 ? count(array_filter($task['checklist'], static fn(array $item): bool => !empty($item['done']))) : 0;
                      $checkPct = $checkTotal > 0 ? (int) round(($checkDone / $checkTotal) * 100) : 0;
                  ?>
                    <article class="teams-task" data-task-id="<?= $task['id'] ?>" data-task-status="<?= esc($task['status'], 'attr') ?>"
                             <?php if ($task['canEdit']): ?>draggable="true" data-task-version="<?= (int) $task['version'] ?>" data-task-move-url="<?= esc($baseUrl . '/' . $task['id'] . '/move', 'attr') ?>"<?php endif; ?>>
                      <div class="tws-task-top">
                        <div class="tws-task-top-left">
                          <span class="teams-priority is-<?= esc($task['priority']) ?>"><?= esc($priorities[$task['priority']]) ?></span>
                          <span class="tws-task-id">#<?= $task['id'] ?></span>
                        </div>
                        <?php if ($task['canEdit']): ?>
                          <button type="button" class="tws-drag-icon-btn teams-drag-handle" data-task-drag-handle aria-label="<?= esc('Move task: ' . $task['title'], 'attr') ?>" aria-describedby="task-drag-help"><i class="bi bi-grip-vertical" aria-hidden="true"></i><span class="visually-hidden">Move task</span></button>
                        <?php endif; ?>
                      </div>
                      <h3 class="teams-task-title"><a href="<?= esc($baseUrl . '/' . $task['id']) ?>" data-app-modal-open><?= esc($task['title']) ?></a></h3>
                      <?php if ($task['description'] !== ''): ?>
                        <p class="teams-task-excerpt"><?= esc(mb_strimwidth($task['description'], 0, 140, '…')) ?></p>
                      <?php endif; ?>
                      <?php if ($checkTotal > 0): ?>
                        <div class="tws-card-checklist" aria-label="<?= $checkDone ?> of <?= $checkTotal ?> checklist items complete">
                          <div class="tws-card-checklist-head">
                            <span><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Checklist</span>
                            <span class="tws-card-checklist-count <?= $checkDone === $checkTotal ? 'is-complete' : '' ?>"><?= $checkDone ?>/<?= $checkTotal ?></span>
                          </div>
                          <div class="tws-progress-track" aria-hidden="true"><div class="tws-progress-fill <?= $checkDone === $checkTotal ? 'is-complete' : '' ?>" style="width: <?= $checkPct ?>%;"></div></div>
                        </div>
                      <?php endif; ?>
                      <div class="teams-task-meta tws-card-footer">
                        <span class="tws-card-assignee">
                          <span class="tws-avatar tws-avatar--sm is-<?= esc($task['assignee_user_id'] ? $avatarTone($task['assignee_user_id']) : 'slate') ?>" aria-hidden="true"><?= esc($task['assignee_user_id'] ? $initialsOf($assigneeName) : '—') ?></span>
                          <span class="tws-card-assignee-name"><?= esc($assigneeName) ?></span>
                        </span>
                        <?php if ($task['due_date']): ?>
                          <span class="tws-due-pill <?= $isOverdue ? 'tws-overdue' : '' ?>"><?= esc($task['due_date']) ?><?= $isOverdue ? ' · Overdue' : '' ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if ($task['canEdit']): ?>
                        <div class="tws-task-actions">
                          <a href="<?= esc($baseUrl . '/' . $task['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary tws-card-edit-link" data-app-modal-open>Edit / move</a>
                        </div>
                      <?php endif; ?>
                    </article>
                  <?php endforeach; ?>
                  <?php if ($columnTasks === []): ?><p class="teams-column-empty">No matching tasks on this page.</p><?php endif; ?>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="tws-table-wrap" data-mobile-cards="false">
          <table class="table tws-table tws-task-table" role="table">
            <caption class="visually-hidden"><?= $filters['mine'] === '1' ? 'Tasks assigned to you' : 'Team tasks' ?></caption>
            <thead role="rowgroup"><tr role="row"><th scope="col">Task</th><th scope="col">Status</th><th scope="col">Priority</th><th scope="col">Assigned to</th><th scope="col">Due date</th><th scope="col">Actions</th></tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($tasks as $task):
                  $assigneeName = $memberNames[$task['assignee_user_id']] ?? ($task['assignee_user_id'] ? 'Former member' : 'Unassigned');
                  $isOverdue = $task['status'] !== 'done' && !empty($task['due_date']) && $task['due_date'] < $today;
                  $checkTotal = is_array($task['checklist']) ? count($task['checklist']) : 0;
                  $checkDone = $checkTotal > 0 ? count(array_filter($task['checklist'], static fn(array $item): bool => !empty($item['done']))) : 0;
                  $checkPct = $checkTotal > 0 ? (int) round(($checkDone / $checkTotal) * 100) : 0;
              ?>
                <tr role="row">
                  <td class="tws-title-cell" role="cell">
                    <?php if (!empty($archivedMode) || !empty($task['deleted_at'])): ?>
                      <span class="tws-task-link text-muted"><?= esc($task['title']) ?></span>
                    <?php else: ?>
                      <a class="tws-task-link" href="<?= esc($baseUrl . '/' . $task['id']) ?>" data-app-modal-open><?= esc($task['title']) ?></a>
                    <?php endif; ?>
                    <div class="tws-task-submeta">
                      <span class="tws-task-id">#<?= $task['id'] ?></span>
                      <?php if ($checkTotal > 0): ?>
                        <span class="tws-inline-check"><span class="tws-progress-track tws-progress-track--inline" aria-hidden="true"><span class="tws-progress-fill <?= $checkDone === $checkTotal ? 'is-complete' : '' ?>" style="width: <?= $checkPct ?>%;"></span></span><?= $checkDone ?>/<?= $checkTotal ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td role="cell"><span class="tws-cell-label" aria-hidden="true">Status</span><span class="tws-status is-<?= esc($task['status']) ?>"><?= esc($statuses[$task['status']]) ?></span></td>
                  <td role="cell"><span class="tws-cell-label" aria-hidden="true">Priority</span><span class="teams-priority is-<?= esc($task['priority']) ?>"><?= esc($priorities[$task['priority']]) ?></span></td>
                  <td role="cell">
                    <span class="tws-cell-label" aria-hidden="true">Assigned to</span>
                    <span class="tws-table-assignee">
                      <span class="tws-avatar tws-avatar--sm is-<?= esc($task['assignee_user_id'] ? $avatarTone($task['assignee_user_id']) : 'slate') ?>" aria-hidden="true"><?= esc($task['assignee_user_id'] ? $initialsOf($assigneeName) : '—') ?></span>
                      <span><?= esc($assigneeName) ?></span>
                    </span>
                  </td>
                  <td role="cell">
                    <span class="tws-cell-label" aria-hidden="true">Due date</span>
                    <span class="tws-date <?= $isOverdue ? 'tws-due-pill tws-overdue' : '' ?>"><?= esc($task['due_date'] ?? 'No due date') ?><?= $isOverdue ? ' · Overdue' : '' ?></span>
                  </td>
                  <td class="tws-action-cell" role="cell"><span class="tws-cell-label" aria-hidden="true">Actions</span><?php if (!empty($archivedMode) || !empty($task['deleted_at'])): ?><?php if (!empty($canArchive)): ?><form method="post" action="<?= esc($baseUrl . '/' . $task['id'] . '/restore') ?>" class="d-inline" data-archive-async="restore" data-archive-undo-url="<?= esc($baseUrl . '/' . $task['id'] . '/archive', 'attr') ?>" data-archive-undo-label="Re-archive"><?= csrf_field() ?><button type="submit" class="btn btn-outline-primary btn-sm">Restore</button></form><?php else: ?><span class="text-muted small">Archived</span><?php endif; ?><?php else: ?><div class="d-inline-flex align-items-center gap-1"><?php if ($task['canEdit']): ?><a class="btn btn-outline-primary btn-sm" href="<?= esc($baseUrl . '/' . $task['id'] . '/edit') ?>" data-app-modal-open>Edit</a><?php else: ?><a class="btn btn-outline-secondary btn-sm" href="<?= esc($baseUrl . '/' . $task['id']) ?>" data-app-modal-open>View</a><?php endif; ?><?php if (!empty($canArchive) && ($task['status'] ?? '') === 'done'): ?><form method="post" action="<?= esc($baseUrl . '/' . $task['id'] . '/archive') ?>" class="d-inline" data-archive-async="archive" data-archive-undo-url="<?= esc($baseUrl . '/' . $task['id'] . '/restore', 'attr') ?>" data-archive-undo-label="Undo"><?= csrf_field() ?><button type="submit" class="btn btn-outline-warning btn-sm">Archive</button></form><?php endif; ?></div><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($tasks === []): ?><tr role="row"><td colspan="6" class="tws-empty-cell" role="cell">No matching tasks. Create a task or adjust your filters.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="tws-pagination-band">
        <?= view('partials/table_pagination', [
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => $limit],
            'filters' => $filters, 'baseUrl' => $baseUrl,
            'paginationExtraQuery' => ['mine' => $filters['mine'], 'priority' => $filters['priority'], 'view' => $mode, 'archived' => $filters['archived'] ?? ''],
            'paginationLiveFilters' => true, 'paginationLabel' => 'Task pages',
        ], ['saveData' => false]) ?>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/team-board.js') ?>"></script>
<?= $this->endSection() ?>
