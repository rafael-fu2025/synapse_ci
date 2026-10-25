<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$initial = $history + ['after_mode' => $afterMode, 'browsing_history' => $browsingHistory, 'members' => array_map(static fn(array $member): array => ['id' => (int) $member['id'], 'name' => $member['name']], $members)];
$initialJson = json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$stamp = static fn(string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A');

$currentUser = session('user') ?? [];
$actorTenantId = (int) ($actor['tenant_id'] ?? $currentUser['tenant_id'] ?? 0);
$actorPersonId = (int) ($actor['person_id'] ?? $currentUser['person_id'] ?? 0);
$actorHasPhoto = $actorTenantId > 0 && $actorPersonId > 0 && (new \App\Services\ProfilePhotoService())->exists($actorTenantId, $actorPersonId);
$memberAvatar = static function (array $member) use ($actor, $actorHasPhoto): string {
    if ($actorHasPhoto && (int) ($member['id'] ?? 0) === (int) ($actor['id'] ?? 0)) {
        return site_url('account/photo');
    }
    return \App\Services\ProfileAvatar::dataUri($member);
};
$maxVisible = 4;
$visibleMembers = array_slice($members, 0, $maxVisible);
$hasMoreMembers = count($members) > $maxVisible;
?>
<div class="teams-workspace" data-team-chat data-base-url="<?= esc($baseUrl) ?>" data-teams-url="<?= esc($teamsUrl) ?>" data-actor-id="<?= (int) $actor['id'] ?>" data-initial="<?= esc($initialJson, 'attr') ?>">
  <nav class="d-flex flex-wrap align-items-center gap-2 mb-3" aria-label="Teams views">
    <a class="btn btn-outline-secondary" href="<?= esc($teamsUrl) ?>"><i class="bi bi-kanban me-2" aria-hidden="true"></i>Team board</a>
    <a class="btn btn-outline-secondary" href="<?= esc($teamsUrl . '?mine=1&view=list') ?>"><i class="bi bi-list-check me-2" aria-hidden="true"></i>My tasks</a>
    <a class="btn btn-primary" href="<?= esc($baseUrl) ?>" aria-current="page"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Team chat <span class="badge bg-light text-dark ms-2" data-chat-unread><?= $history['unread_count'] ?></span></a>
    <a class="btn btn-outline-secondary" href="<?= esc($teamsUrl . '/calendar') ?>"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Team calendar</a>
  </nav>
  <div class="team-chat-layout">
    <aside class="card team-chat-sidebar" aria-label="Department channel">
      <div class="card-body p-3">
        <div class="d-flex align-items-center gap-1">
          <span class="teams-eyebrow">Team messages</span>
          <button type="button"
                  class="team-chat-info-btn"
                  data-bs-content="<?= esc('Operational discussion only. Do not share patient details, diagnoses, counseling notes, passwords, or tokens. All authorized ' . ucfirst($unit) . ' workstation members can read this channel. Not for urgent clinical communication.') ?>"

                  aria-label="Channel operational guidelines">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </button>
        </div>
        <h2 class="h6 mt-3">Channels</h2>
        <a class="team-chat-channel" href="<?= esc($baseUrl) ?>" aria-current="page"><i class="bi bi-hash" aria-hidden="true"></i><?= esc(ucfirst($unit)) ?> operations</a>
        <details class="mt-3">
          <summary class="team-chat-members-summary" aria-label="Members (<?= count($members) ?>)">
            <?php if (!empty($members)): ?>
              <div class="avatar-group" aria-hidden="true">
                <?php foreach ($visibleMembers as $m): ?>
                  <img class="avatar"
                       src="<?= esc($memberAvatar($m)) ?>"
                       alt=""
                       title="<?= esc($m['name']) ?>"
                       width="32"
                       height="32"
                       onerror="this.onerror=null;this.src='<?= esc(\App\Services\ProfileAvatar::dataUri($m), 'attr') ?>'">
                <?php endforeach; ?>
                <?php if ($hasMoreMembers): ?>
                  <span class="avatar-more" title="More members">+</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span class="small text-muted">No members</span>
            <?php endif; ?>
            <i class="bi bi-chevron-down team-chat-members-chevron" aria-hidden="true"></i>
          </summary>
          <ul class="list-unstyled teams-member-list mt-2">
            <?php foreach ($members as $member): ?>
              <li class="small">
                <img class="avatar"
                     src="<?= esc($memberAvatar($member)) ?>"
                     alt=""
                     width="24"
                     height="24"
                     onerror="this.onerror=null;this.src='<?= esc(\App\Services\ProfileAvatar::dataUri($member), 'attr') ?>'">
                <span><?= esc($member['name']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
      </div>
    </aside>
    <section class="card team-chat-main" aria-labelledby="chatChannelTitle">
      <header class="team-chat-header"><div><h2 class="h6 mb-0" id="chatChannelTitle"># <?= esc(ucfirst($unit)) ?> operations</h2><span class="small text-muted" data-chat-connection></span></div><button class="btn btn-outline-secondary btn-sm" type="button" data-chat-latest>Show latest</button></header>
      <div class="team-chat-tools"><button type="button" class="btn btn-outline-secondary btn-sm" data-chat-older hidden>Load older messages</button><span class="small text-muted" data-chat-history-status></span></div>
      <div class="team-chat-log" data-chat-log tabindex="0" role="region" aria-label="Channel messages, chronological order">
        <?php foreach ($history['messages'] as $message): ?>
          <article class="team-chat-message <?= $message['sender_user_id'] === (int) $actor['id'] ? 'is-own' : '' ?>">
            <div class="team-chat-bubble">
              <div class="team-chat-message-header"><strong><?= esc($message['sender_name']) ?></strong><time><?= esc($stamp($message['created_at'])) ?></time></div>
              <?php if (!empty($message['reply_to'])): ?>
                <div class="team-chat-reply-quote"><strong>Reply to <?= esc($message['reply_to']['sender_name']) ?></strong><p><?= esc($message['reply_to']['body']) ?></p></div>
              <?php endif; ?>
              <p class="teams-preserve mb-0"><?= esc($message['body']) ?></p>
              <?php foreach ($message['tasks'] ?? ($message['task_id'] !== null ? [['id' => $message['task_id'], 'title' => $message['task_title'] ?? 'Task']] : []) as $reference): ?>
                <a class="team-chat-task" href="<?= esc($teamsUrl . '/' . $reference['id']) ?>" data-app-modal-open>Task #<?= (int) $reference['id'] ?>: <?= esc($reference['title']) ?></a>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="team-chat-update-bar"><button type="button" class="btn btn-outline-primary btn-sm" data-chat-new hidden>Go to new messages</button><span class="small text-muted" data-chat-read-status>Unread: <?= $history['unread_count'] ?></span></div>
      <div class="alert alert-warning m-3" data-chat-error role="alert" hidden></div>
      <form class="team-chat-composer" action="<?= esc($baseUrl . '/send') ?>" method="post" data-chat-form data-loading="false" data-navigation="false">
        <?= csrf_field() ?>
        <div class="team-chat-reply-preview" data-chat-reply-preview hidden></div>
        <div class="team-chat-selected-tasks" data-chat-selected-tasks aria-label="Selected task references"></div>
        <dialog class="team-chat-task-picker" id="teamChatTaskPicker" data-chat-task-picker aria-labelledby="teamChatTaskTitle" aria-describedby="teamChatTaskHelp" hidden>
          <header class="team-chat-task-modal-header">
            <div><h3 class="h6 mb-1" id="teamChatTaskTitle">Reference tasks</h3><p class="small text-muted mb-0" id="teamChatTaskHelp">Choose up to 20 active tasks to attach to your message.</p></div>
            <button type="button" class="header-action" data-chat-task-dismiss aria-label="Close task references"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
          </header>
          <div class="team-chat-task-modal-body">
            <label class="visually-hidden" for="teamChatTaskSearch">Search active tasks</label>
            <input type="search" id="teamChatTaskSearch" class="form-control" placeholder="Search active tasks…" data-chat-task-search autofocus>
            <div class="team-chat-task-results" data-chat-task-results aria-live="polite"></div>
            <p class="small text-danger mt-2 mb-0" data-chat-task-error role="alert" hidden></p>
          </div>
          <footer class="team-chat-task-modal-footer"><span class="small text-muted" data-chat-task-count role="status">0 of 20 selected</span><button type="button" class="btn btn-primary btn-sm" data-chat-task-close>Done</button></footer>
        </dialog>
        <div class="team-chat-mention-picker" id="teamChatMentions" role="listbox" aria-label="Mention a team member" data-chat-mentions hidden></div>
        <div class="team-chat-input-row">
          <div class="header-action-wrap" data-bs-title="Add task references" data-bs-toggle="tooltip" data-bs-placement="top"><button type="button" class="header-action team-chat-icon-button" data-chat-add-task aria-label="Add task references" aria-haspopup="dialog" aria-controls="teamChatTaskPicker" aria-expanded="false"><i class="bi bi-plus-lg" aria-hidden="true"></i></button></div>
          <label class="visually-hidden" for="teamChatBody">Message</label>
          <textarea class="form-control" id="teamChatBody" name="body" rows="1" maxlength="2000" required placeholder="Message your team… Type @ to mention" aria-controls="teamChatMentions" aria-autocomplete="list" aria-expanded="false" data-chat-body></textarea>
          <div class="header-action-wrap" data-bs-title="Send message" data-bs-toggle="tooltip" data-bs-placement="top"><button class="header-action team-chat-icon-button" type="submit" data-chat-send aria-label="Send" title="Send" disabled><i class="bi bi-send" aria-hidden="true"></i></button></div>
        </div>

        <p class="small text-muted mt-2 mb-0" data-chat-send-status role="status" aria-live="polite"></p>
      </form>
      <noscript><p class="alert alert-warning m-3">Enable JavaScript to send messages, track unread position, and load updates. Messages above remain readable.</p><?php if ($history['next_before']): ?><a class="btn btn-outline-secondary m-3" href="<?= esc($baseUrl . '?before=' . $history['next_before']) ?>">Older messages</a><?php endif; ?></noscript>
    </section>
  </div>
</div>
<?= $this->endSection() ?>
