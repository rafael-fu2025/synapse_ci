<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$time = static function (string $stamp): string {
    return (new DateTimeImmutable($stamp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A');
};
$name = static fn(int $id): string => $memberNames[$id] ?? 'Former member';
$events = ['created' => 'created the task', 'updated' => 'updated the task', 'moved' => 'changed status', 'commented' => 'added a comment'];
?>
<div class="teams-detail">
  <h2 class="h5 mb-3 text-break"><?= esc($task['title']) ?></h2>
  <dl class="row small mb-3"><dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= esc($statuses[$task['status']]) ?></dd><dt class="col-sm-3">Priority</dt><dd class="col-sm-9"><?= esc($priorities[$task['priority']]) ?></dd><dt class="col-sm-3">Assigned to</dt><dd class="col-sm-9"><?= esc($task['assignee_user_id'] ? $name($task['assignee_user_id']) : 'Unassigned') ?></dd><dt class="col-sm-3">Due date</dt><dd class="col-sm-9"><?= esc($task['due_date'] ?? 'Not set') ?> (Asia/Manila)</dd><dt class="col-sm-3">Created by</dt><dd class="col-sm-9"><?= esc($name($task['created_by_user_id'])) ?></dd></dl>
  <?php if ($task['description'] !== ''): ?><h3 class="h6">Description</h3><p class="teams-preserve"><?= esc($task['description']) ?></p><?php endif; ?>
  <?php if ($task['checklist'] !== []): ?><h3 class="h6 mt-3">Checklist</h3><ul class="list-unstyled"><?php foreach ($task['checklist'] as $item): ?><li class="mb-2 text-break"><i class="bi <?= $item['done'] ? 'bi-check-square text-success' : 'bi-square text-muted' ?> me-2" aria-hidden="true"></i><span class="visually-hidden"><?= $item['done'] ? 'Complete: ' : 'Incomplete: ' ?></span><?= esc($item['text']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($task['canEdit']): ?>
    <form method="post" action="<?= esc($baseUrl . '/' . $task['id'] . '/move') ?>" data-modal-submit class="border rounded p-3 my-3">
      <?= csrf_field() ?><input type="hidden" name="version" value="<?= $task['version'] ?>">
      <label class="form-label" for="taskMoveStatus">Move task</label><div class="d-flex flex-wrap gap-2"><select class="form-select flex-grow-1 teams-status-select" id="taskMoveStatus" name="status"><?php foreach ($statuses as $key => $label): ?><option value="<?= esc($key) ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-primary">Update status</button></div>
      <p class="form-text mb-0">To edit details or the checklist, close this dialog and choose Edit / move on the board.</p>
    </form>
  <?php endif; ?>
  <section class="mt-4" aria-labelledby="teamComments"><h3 class="h6" id="teamComments">Discussion (<?= $task['comments_total'] ?>)</h3>
    <form method="post" action="<?= esc($baseUrl . '/' . $task['id'] . '/comments') ?>" data-modal-submit>
      <?= csrf_field() ?><label class="form-label small" for="taskComment">Add an operational update</label><textarea class="form-control" id="taskComment" name="body" required maxlength="2000" rows="3" aria-describedby="commentPrivacy"></textarea><p class="form-text" id="commentPrivacy">Do not include patient details or confidential notes. Comments are visible to this workstation.</p><div class="d-flex justify-content-end"><button type="submit" class="btn btn-primary">Post comment</button></div>
    </form>
    <?php if ($task['comments_total'] > $task['comments_limit']): ?><p class="text-muted small mt-3">Showing the latest <?= $task['comments_limit'] ?> comments.</p><?php endif; ?>
    <div class="mt-3"><?php foreach ($task['comments'] as $comment): ?><article class="teams-comment"><div class="d-flex flex-wrap justify-content-between gap-1 small mb-2"><strong><?= esc($name($comment['actor_user_id'])) ?></strong><time class="text-muted"><?= esc($time($comment['created_at'])) ?></time></div><p class="teams-preserve mb-0"><?= esc($comment['body']) ?></p></article><?php endforeach; ?><?php if ($task['comments'] === []): ?><p class="text-muted small">No comments yet.</p><?php endif; ?></div>
  </section>
  <details class="mt-4"><summary class="fw-semibold">Activity history (<?= $task['activity_total'] ?>)</summary><p class="text-muted small mt-2">Latest <?= $task['activity_limit'] ?> entries. All times Asia/Manila.</p><ol class="teams-activity"><?php foreach ($task['activity'] as $entry): ?><li><strong><?= esc($name($entry['actor_user_id'])) ?></strong> <?= esc($events[$entry['event']] ?? 'changed the task') ?><?php if ($entry['old_status'] !== null && $entry['new_status'] !== null && $entry['old_status'] !== $entry['new_status']): ?>: <?= esc($statuses[$entry['old_status']]) ?> → <?= esc($statuses[$entry['new_status']]) ?><?php endif; ?><br><small class="text-muted"><?= esc($time($entry['created_at'])) ?></small></li><?php endforeach; ?></ol></details>
  <div class="form-actions"><a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Close</a></div>
</div>
<?= $this->endSection() ?>
