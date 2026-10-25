<?= $this->extend('layouts/app') ?>

<?= $this->section('page_actions') ?>
<a class="btn btn-primary" href="<?= esc($baseUrl . '/create') ?>" data-app-modal-open><i class="bi bi-plus-lg me-2" aria-hidden="true"></i>New task</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$link = static fn(array $changes): string => $baseUrl . '?' . http_build_query(array_replace($filters + ['view' => $mode], $changes));
?>
<div class="teams-workspace" data-live-filters data-filter-url="<?= esc($baseUrl) ?>" data-filter-key="teams-tasks-<?= esc($unit) ?>" data-mutation-refresh>
  <section class="card teams-intro">
    <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div><span class="teams-eyebrow">Work together · <?= esc(ucfirst($unit)) ?></span><h2 class="h5 mt-1 mb-1">One team. A clear plan.</h2><p class="text-muted small mb-0">Plan operational work, assign responsibilities, and follow progress.</p></div>
      <div class="teams-members" aria-label="<?= count($members) ?> active workstation members"><i class="bi bi-people me-1" aria-hidden="true"></i><?= count($members) ?> members</div>
    </div>
  </section>
  <div class="alert alert-light border small"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Visible to authorized <?= esc(ucfirst($unit)) ?> staff. Do not enter patient information, diagnoses, or confidential counseling notes.</div>
  <nav class="d-flex flex-wrap align-items-center gap-2 mb-3" aria-label="Teams views" data-filter-region="navigation">
    <a class="btn <?= $filters['mine'] !== '1' ? 'btn-primary' : 'btn-outline-secondary' ?>" data-filter-link href="<?= esc($link(['mine' => '', 'page' => 1])) ?>" <?= $filters['mine'] !== '1' ? 'aria-current="page"' : '' ?>><i class="bi bi-kanban me-2" aria-hidden="true"></i>Team board</a>
    <a class="btn <?= $filters['mine'] === '1' ? 'btn-primary' : 'btn-outline-secondary' ?>" data-filter-link href="<?= esc($link(['mine' => '1', 'view' => 'list', 'page' => 1])) ?>" <?= $filters['mine'] === '1' ? 'aria-current="page"' : '' ?>><i class="bi bi-list-check me-2" aria-hidden="true"></i>My tasks</a>
    <a class="btn btn-outline-secondary" href="<?= esc($baseUrl . '/chat') ?>"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Team chat</a>
    <a class="btn btn-outline-secondary" href="<?= esc($baseUrl . '/calendar') ?>"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Team calendar</a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-sm <?= $mode === 'board' ? 'btn-primary' : 'btn-outline-secondary' ?>" data-filter-link href="<?= esc($link(['view' => 'board'])) ?>" aria-label="Board view"><i class="bi bi-kanban" aria-hidden="true"></i></a>
      <a class="btn btn-sm <?= $mode === 'list' ? 'btn-primary' : 'btn-outline-secondary' ?>" data-filter-link href="<?= esc($link(['view' => 'list'])) ?>" aria-label="List view"><i class="bi bi-list-task" aria-hidden="true"></i></a>
    </div>
  </nav>
  <form class="card mb-3" action="<?= esc($baseUrl) ?>" method="get" role="search" data-filter-form>
    <div class="card-body p-3 row g-2 align-items-end">
      <input type="hidden" name="view" value="<?= esc($mode) ?>"><input type="hidden" name="mine" value="<?= esc($filters['mine']) ?>">
      <div class="col-md-5"><label class="form-label" for="teamSearch">Search tasks</label><input class="form-control" id="teamSearch" name="q" value="<?= esc($filters['q']) ?>" maxlength="160" placeholder="Title or description"></div>
      <div class="col-md-3"><label class="form-label" for="teamStatus">Status</label><select class="form-select" id="teamStatus" name="status"><option value="">All statuses</option><?php foreach ($statuses as $value => $label): ?><option value="<?= esc($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label" for="teamPriority">Priority</label><select class="form-select" id="teamPriority" name="priority"><option value="">All priorities</option><?php foreach ($priorities as $value => $label): ?><option value="<?= esc($value) ?>" <?= $filters['priority'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-outline-secondary" href="<?= esc($baseUrl . '?' . http_build_query(['view' => $mode])) ?>" data-filter-reset>Reset</a></div>
    </div>
  </form>
  <?= view('partials/live_filter_feedback') ?>
  <div data-filter-region="results">
  <?php if ($mode === 'board'): ?>
    <p class="small text-muted" id="task-drag-help">Drag an editable task to another status column. Keyboard: focus its move handle, press Space, choose a column with arrow keys, then Enter. Escape cancels.</p>
    <div class="teams-board" aria-label="Task board" data-team-board aria-describedby="task-drag-help">
      <?php foreach ($statuses as $status => $label): $columnTasks = array_filter($tasks, static fn(array $task): bool => $task['status'] === $status); ?>
      <section class="teams-column" data-task-column="<?= esc($status, 'attr') ?>" aria-label="<?= esc($label, 'attr') ?>" aria-labelledby="column-<?= esc($status) ?>">
        <header class="teams-column-header"><h2 class="h6 mb-0" id="column-<?= esc($status) ?>"><span class="teams-status-dot is-<?= esc($status) ?>" aria-hidden="true"></span><?= esc($label) ?></h2><span class="teams-count" aria-label="<?= count($columnTasks) ?> shown in this column"><?= count($columnTasks) ?></span></header>
        <div class="teams-column-body">
          <?php foreach ($columnTasks as $task): ?>
          <article class="teams-task" data-task-id="<?= $task['id'] ?>" data-task-status="<?= esc($task['status'], 'attr') ?>"
                   <?php if ($task['canEdit']): ?>draggable="true" data-task-version="<?= (int) $task['version'] ?>" data-task-move-url="<?= esc($baseUrl . '/' . $task['id'] . '/move', 'attr') ?>"<?php endif; ?>>
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><span class="teams-priority is-<?= esc($task['priority']) ?>"><?= esc($priorities[$task['priority']]) ?></span><span class="text-muted small">#<?= $task['id'] ?></span></div>
            <h3 class="teams-task-title"><a href="<?= esc($baseUrl . '/' . $task['id']) ?>" data-app-modal-open><?= esc($task['title']) ?></a></h3>
            <?php if ($task['description'] !== ''): ?><p class="teams-task-excerpt"><?= esc(mb_strimwidth($task['description'], 0, 150, '…')) ?></p><?php endif; ?>
            <div class="teams-task-meta"><span><i class="bi bi-person me-1" aria-hidden="true"></i><?= esc($memberNames[$task['assignee_user_id']] ?? ($task['assignee_user_id'] ? 'Former member' : 'Unassigned')) ?></span>
              <?php if ($task['due_date']): ?><span class="<?= $task['status'] !== 'done' && $task['due_date'] < $today ? 'text-danger' : '' ?>"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i><?= esc($task['due_date']) ?><?= $task['status'] !== 'done' && $task['due_date'] < $today ? ' · Overdue' : '' ?></span><?php endif; ?>
              <?php if ($task['checklist'] !== []): ?><span><i class="bi bi-check2-square me-1" aria-hidden="true"></i><?= count(array_filter($task['checklist'], static fn(array $item): bool => $item['done'])) ?>/<?= count($task['checklist']) ?> checklist</span><?php endif; ?>
            </div>
            <?php if ($task['canEdit']): ?><div class="d-flex align-items-center gap-2 mt-3"><button type="button" class="btn btn-sm btn-outline-secondary teams-drag-handle" data-task-drag-handle aria-label="<?= esc('Move task: ' . $task['title'], 'attr') ?>" aria-describedby="task-drag-help"><i class="bi bi-grip-vertical" aria-hidden="true"></i><span class="visually-hidden">Move task</span></button><a href="<?= esc($baseUrl . '/' . $task['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" data-app-modal-open>Edit / move</a></div><?php endif; ?>
          </article>
          <?php endforeach; ?>
          <?php if ($columnTasks === []): ?><p class="teams-column-empty">No matching tasks on this page.</p><?php endif; ?>
        </div>
      </section>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><caption class="visually-hidden"><?= $filters['mine'] === '1' ? 'Tasks assigned to you' : 'Team tasks' ?></caption><thead><tr><th>Task</th><th>Status</th><th>Priority</th><th>Assigned to</th><th>Due date</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($tasks as $task): ?><tr><td><a href="<?= esc($baseUrl . '/' . $task['id']) ?>" data-app-modal-open><?= esc($task['title']) ?></a><small class="text-muted d-block">#<?= $task['id'] ?></small></td><td><?= esc($statuses[$task['status']]) ?></td><td><span class="teams-priority is-<?= esc($task['priority']) ?>"><?= esc($priorities[$task['priority']]) ?></span></td><td><?= esc($memberNames[$task['assignee_user_id']] ?? ($task['assignee_user_id'] ? 'Former member' : 'Unassigned')) ?></td><td><?= esc($task['due_date'] ?? 'No due date') ?></td><td><?php if ($task['canEdit']): ?><a class="btn btn-outline-primary btn-sm" href="<?= esc($baseUrl . '/' . $task['id'] . '/edit') ?>" data-app-modal-open>Edit</a><?php else: ?><a class="btn btn-outline-secondary btn-sm" href="<?= esc($baseUrl . '/' . $task['id']) ?>" data-app-modal-open>View</a><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if ($tasks === []): ?><tr><td colspan="6" class="text-center text-muted py-4">No matching tasks. Create a task or adjust your filters.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <?php endif; ?>
  <nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 my-3" aria-label="Task pages"><span class="small text-muted"><?= $total ?> matching tasks · Page <?= $page ?> of <?= $pages ?> · Up to <?= $limit ?> tasks per page</span><div class="d-flex gap-2"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" data-filter-link href="<?= esc($link(['page' => $page - 1])) ?>" rel="prev">Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" data-filter-link href="<?= esc($link(['page' => $page + 1])) ?>" rel="next">Next</a><?php endif; ?></div></nav>
  </div>
  <details class="card"><summary class="p-3 fw-semibold">Workstation members (<?= count($members) ?>)</summary><div class="card-body"><p class="small text-muted">Membership follows active workstation roles. Contact an administrator to change access.</p><ul class="list-unstyled teams-member-list"><?php foreach ($members as $member): ?><li><i class="bi bi-person-circle me-2" aria-hidden="true"></i><span><?= esc($member['name']) ?></span><small class="text-muted ms-auto"><?= esc(ucwords(str_replace('_', ' ', $member['role']))) ?></small></li><?php endforeach; ?></ul></div></details>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/team-board.js') ?>"></script>
<?= $this->endSection() ?>
