<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$time = static function (string $stamp): string {
    return (new DateTimeImmutable($stamp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A');
};
$name = static fn(int $id): string => $memberNames[$id] ?? 'Former member';
$events = ['created' => 'created the task', 'updated' => 'updated the task', 'moved' => 'changed status', 'commented' => 'added a comment'];
$initialsOf = static function (string $person): string {
    $parts = preg_split('/\s+/', trim($person)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : 'TM';
};
$avatarTone = static function (int|string $seed): string {
    $tones = ['maroon', 'emerald', 'sky', 'amber', 'slate'];
    return $tones[abs(crc32((string) $seed)) % count($tones)];
};
$assigneeName = $task['assignee_user_id'] ? $name((int) $task['assignee_user_id']) : 'Unassigned';
$creatorName = $name((int) $task['created_by_user_id']);
$checkTotal = is_array($task['checklist']) ? count($task['checklist']) : 0;
$checkDone = $checkTotal > 0 ? count(array_filter($task['checklist'], static fn(array $item): bool => !empty($item['done']))) : 0;
$checkPct = $checkTotal > 0 ? (int) round(($checkDone / $checkTotal) * 100) : 0;
$commentsPagination = $task['commentsPagination'] ?? ['page' => 1, 'per_page' => $task['comments_limit'], 'total' => $task['comments_total']];
$activityPagination = $task['activityPagination'] ?? ['page' => 1, 'per_page' => $task['activity_limit'], 'total' => $task['activity_total']];
$historyQuery = [
    'comments_page' => $commentsPagination['page'], 'comments_per_page' => $commentsPagination['per_page'],
    'activity_page' => $activityPagination['page'], 'activity_per_page' => $activityPagination['per_page'],
];
$detailUrl = $baseUrl . '/' . (int) $task['id'];
?>
<div class="teams-detail tws-detail">
  <div class="tws-inspector-grid">
    <aside class="tws-inspector-rail" aria-label="Task properties">
      <?php if ($task['canEdit']): ?>
        <section class="tws-rail-block">
          <form method="post" action="<?= esc($baseUrl . '/' . $task['id'] . '/move') ?>" data-modal-submit>
            <?= csrf_field() ?><input type="hidden" name="version" value="<?= $task['version'] ?>">
            <label class="form-label tws-rail-label" for="taskMoveStatus">Move task</label>
            <div class="tws-inline-form tws-inline-form--rail">
              <select class="form-select teams-status-select" id="taskMoveStatus" name="status">
                <?php foreach ($statuses as $key => $label): ?>
                  <option value="<?= esc($key) ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary">Update status</button>
            </div>
            <p class="form-text mb-0">To edit details or the checklist, close this dialog and choose Edit / move on the board.</p>
          </form>
        </section>
      <?php endif; ?>
      <?php if (!empty($canArchive) && empty($task['deleted_at']) && ($task['status'] ?? '') === 'done'): ?>
        <form class="tws-rail-block tws-cancel-form" action="<?= esc($baseUrl . '/' . $task['id'] . '/archive') ?>" method="post" data-modal-submit data-confirm="Archive this completed team task reversibly? You can restore it from the Archived view or Archive Center." data-confirm-title="Archive team task" data-confirm-label="Archive task" data-confirm-tone="warning">
          <?= csrf_field() ?>
          <button class="btn btn-outline-warning w-100" type="submit"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive task</button>
        </form>
      <?php endif; ?>

      <dl class="tws-meta-ledger tws-meta-ledger--rail">
        <div>
          <dt>Status</dt>
          <dd><span class="tws-status is-<?= esc($task['status']) ?>"><?= esc($statuses[$task['status']]) ?></span></dd>
        </div>
        <div>
          <dt>Priority</dt>
          <dd><span class="teams-priority is-<?= esc($task['priority']) ?>"><?= esc($priorities[$task['priority']]) ?></span></dd>
        </div>
        <div>
          <dt>Assigned to</dt>
          <dd>
            <span class="tws-rail-person">
              <span class="tws-avatar tws-avatar--sm is-<?= esc($task['assignee_user_id'] ? $avatarTone($task['assignee_user_id']) : 'slate') ?>" aria-hidden="true"><?= esc($task['assignee_user_id'] ? $initialsOf($assigneeName) : '—') ?></span>
              <span><?= esc($assigneeName) ?></span>
            </span>
          </dd>
        </div>
        <div>
          <dt>Due date</dt>
          <dd><span class="tws-date"><?= esc($task['due_date'] ?? 'Not set') ?></span><small class="tws-meta-note">Asia/Manila</small></dd>
        </div>
        <div>
          <dt>Created by</dt>
          <dd><?= esc($creatorName) ?></dd>
        </div>
      </dl>
    </aside>

    <div class="tws-inspector-main">
      <header class="tws-detail-head">
        <div class="tws-detail-badges">
          <span class="tws-id-badge">TASK #<?= (int) $task['id'] ?></span>
          <span class="tws-status is-<?= esc($task['status']) ?>"><?= esc($statuses[$task['status']]) ?></span>
          <span class="teams-priority is-<?= esc($task['priority']) ?>"><?= esc($priorities[$task['priority']]) ?></span>
        </div>
        <h2 class="tws-detail-title"><?= esc($task['title']) ?></h2>
      </header>

      <?php if ($task['description'] !== ''): ?>
        <section class="tws-detail-section">
          <h3 class="tws-section-title">Description</h3>
          <p class="teams-preserve tws-detail-text"><?= esc($task['description']) ?></p>
        </section>
      <?php endif; ?>

      <?php if ($checkTotal > 0): ?>
        <section class="tws-detail-section">
          <div class="tws-checklist-card">
            <div class="tws-checklist-card-head">
              <h3 class="tws-section-title">Checklist</h3>
              <span class="tws-checklist-summary <?= $checkDone === $checkTotal ? 'is-complete' : '' ?>"><?= $checkDone ?> of <?= $checkTotal ?> completed</span>
            </div>
            <div class="tws-progress-track" aria-hidden="true">
              <div class="tws-progress-fill <?= $checkDone === $checkTotal ? 'is-complete' : '' ?>" style="width: <?= $checkPct ?>%;"></div>
            </div>
            <ul class="list-unstyled tws-checklist-view">
              <?php foreach ($task['checklist'] as $item): ?>
                <li class="<?= $item['done'] ? 'is-done' : '' ?>">
                  <i class="bi <?= $item['done'] ? 'bi-check-square-fill text-success' : 'bi-square text-muted' ?>" aria-hidden="true"></i>
                  <span><span class="visually-hidden"><?= $item['done'] ? 'Complete: ' : 'Incomplete: ' ?></span><?= esc($item['text']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </section>
      <?php endif; ?>

      <section class="tws-detail-section" aria-labelledby="teamComments">
        <h3 class="tws-section-title" id="teamComments">Discussion (<?= $task['comments_total'] ?>)</h3>
        <form method="post" action="<?= esc($baseUrl . '/' . $task['id'] . '/comments') ?>" data-modal-submit class="tws-comment-form">
          <?= csrf_field() ?>
          <label class="form-label" for="taskComment">Add an operational update</label>
          <textarea class="form-control" id="taskComment" name="body" required maxlength="2000" rows="3" placeholder="Share an operational update… (No PHI)" aria-describedby="commentPrivacy"></textarea>
          <div class="tws-comment-form-footer">
            <p class="form-text mb-0" id="commentPrivacy">Do not include patient details or confidential notes. Comments are visible to this workstation.</p>
            <div class="tws-inline-actions"><button type="submit" class="btn btn-primary">Post comment</button></div>
          </div>
        </form>
        <div class="tws-discussion">
          <?php foreach ($task['comments'] as $comment):
              $author = $name((int) $comment['actor_user_id']);
          ?>
            <article class="teams-comment">
              <span class="tws-avatar tws-avatar--sm is-<?= esc($avatarTone($comment['actor_user_id'])) ?>" aria-hidden="true"><?= esc($initialsOf($author)) ?></span>
              <div class="tws-comment-body">
                <div class="tws-comment-head"><strong><?= esc($author) ?></strong><time><?= esc($time($comment['created_at'])) ?></time></div>
                <p class="teams-preserve mb-0"><?= esc($comment['body']) ?></p>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if ($task['comments'] === []): ?><p class="tws-field-note mb-0">No comments yet.</p><?php endif; ?>
        </div>
        <?= view('partials/table_pagination', [
            'pagination' => $commentsPagination, 'filters' => [], 'baseUrl' => $detailUrl,
            'paginationPageParam' => 'comments_page', 'paginationPerPageParam' => 'comments_per_page',
            'paginationExtraQuery' => $historyQuery, 'paginationLabel' => 'Task discussion pages',
            'paginationModalPages' => true,
        ], ['saveData' => false]) ?>
      </section>

      <details class="tws-activity"<?= (int) $activityPagination['page'] > 1 ? ' open' : '' ?>>
        <summary>Activity history (<?= $task['activity_total'] ?>)</summary>
        <div class="tws-detail-section">
          <p class="tws-field-note">Newest entries first. All times Asia/Manila.</p>
          <ol class="teams-activity">
            <?php foreach ($task['activity'] as $entry): ?>
              <li>
                <strong><?= esc($name((int) $entry['actor_user_id'])) ?></strong> <?= esc($events[$entry['event']] ?? 'changed the task') ?>
                <?php if ($entry['old_status'] !== null && $entry['new_status'] !== null && $entry['old_status'] !== $entry['new_status']): ?>: <?= esc($statuses[$entry['old_status']]) ?> → <?= esc($statuses[$entry['new_status']]) ?><?php endif; ?>
                <br><small><?= esc($time($entry['created_at'])) ?></small>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php if ($task['activity'] === []): ?><p class="tws-field-note mb-0">No activity yet.</p><?php endif; ?>
          <?= view('partials/table_pagination', [
              'pagination' => $activityPagination, 'filters' => [], 'baseUrl' => $detailUrl,
              'paginationPageParam' => 'activity_page', 'paginationPerPageParam' => 'activity_per_page',
              'paginationExtraQuery' => $historyQuery, 'paginationLabel' => 'Task activity pages',
              'paginationModalPages' => true,
          ], ['saveData' => false]) ?>
        </div>
      </details>
    </div>
  </div>

  <div class="form-actions tws-form-actions">
    <a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Close</a>
  </div>
</div>
<?= $this->endSection() ?>
