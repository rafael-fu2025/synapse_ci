<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$formatRole = static fn(array $member): string => !empty($member['role']) ? ucwords(str_replace('_', ' ', (string) $member['role'])) : '';
$initial = $history + [
    'after_mode' => $afterMode,
    'browsing_history' => $browsingHistory,
    'target_message_id' => $targetMessageId ?? null,
    'members' => array_map(static fn(array $member): array => [
        'id' => (int) $member['id'],
        'name' => $member['name'],
        'role' => $formatRole($member),
    ], $members),
];
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
$unitLabel = ['clinic' => 'Clinic', 'guidance' => 'Guidance', 'facilities' => 'BMG'][$unit];
$channelSlug = strtolower($unitLabel) . '-operations';
$visibleMembers = array_slice($members, 0, 4);

$recentTasks = [];
foreach (array_reverse($history['messages'] ?? []) as $msg) {
    $refs = $msg['tasks'] ?? (!empty($msg['task_id']) ? [['id' => $msg['task_id'], 'title' => $msg['task_title'] ?? 'Task']] : []);
    foreach ($refs as $ref) {
        $refId = (int) ($ref['id'] ?? 0);
        if ($refId > 0 && !isset($recentTasks[$refId])) {
            $recentTasks[$refId] = ['id' => $refId, 'title' => (string) ($ref['title'] ?? 'Task')];
            if (count($recentTasks) >= 4) {
                break 2;
            }
        }
    }
}
?>
<div class="teams-workspace tws-workspace tws-chat-workspace" data-team-chat data-base-url="<?= esc($baseUrl) ?>" data-teams-url="<?= esc($teamsUrl) ?>" data-actor-id="<?= (int) $actor['id'] ?>" data-initial="<?= esc($initialJson, 'attr') ?>">
  <div class="tws-sheet">
    <header class="tws-band tws-intro tws-chat-intro">
      <div class="tws-intro-head">
        <div class="tws-intro-primary">
          <div class="tws-title-block">
            <span class="tws-eyebrow"><?= esc($unitLabel) ?> workspace · Teams</span>
            <h2 class="tws-intro-title">Team Chat &amp; Channel Updates</h2>
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
          <div class="tws-notice tws-notice--compact" role="note" title="<?= esc('Visible to authorized ' . $unitLabel . ' staff. Do not share patient details, diagnoses, counseling notes, passwords, or tokens. Not for urgent clinical communication.') ?>">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <p>Internal ops only · Not for urgent alerts <span class="visually-hidden">Visible to authorized <?= esc($unitLabel) ?> staff. Do not share patient details, diagnoses, counseling notes, passwords, or tokens. Not for urgent clinical communication.</span></p>
          </div>
        </div>
      </div>
    </header>

    <div class="tws-navigation">
      <nav class="tws-nav" aria-label="Teams views">
        <a class="tws-nav-link" href="<?= esc($teamsUrl) ?>"><i class="bi bi-kanban me-2" aria-hidden="true"></i>Team board</a>
        <a class="tws-nav-link" href="<?= esc($teamsUrl . '?mine=1&view=list') ?>"><i class="bi bi-list-check me-2" aria-hidden="true"></i>My tasks</a>
        <a class="tws-nav-link" href="<?= esc($baseUrl) ?>" aria-current="page"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Team chat <span class="badge bg-light text-dark ms-2" data-chat-unread><?= $history['unread_count'] ?></span></a>
        <a class="tws-nav-link" href="<?= esc($teamsUrl . '/calendar') ?>"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Team calendar</a>
      </nav>
    </div>

    <div class="team-chat-layout">
      <aside class="team-chat-sidebar" aria-label="Department channel">
        <div class="tws-band tws-chat-sidebar-band">
          <div class="tws-chat-sidebar-head">
            <span class="teams-eyebrow tws-eyebrow">Channels</span>
            <button type="button"
                    class="team-chat-info-btn"
                    data-bs-content="<?= esc('Operational discussion only. Do not share patient details, diagnoses, counseling notes, passwords, or tokens. All authorized ' . $unitLabel . ' workstation members can read this channel. Not for urgent clinical communication.') ?>"
                    aria-label="Channel operational guidelines">
              <i class="bi bi-info-circle" aria-hidden="true"></i>
            </button>
          </div>

          <a class="team-chat-channel" href="<?= esc($baseUrl) ?>" aria-current="page">
            <i class="bi bi-hash" aria-hidden="true"></i>
            <span class="tws-channel-name"><?= esc($channelSlug) ?></span>
            <span class="tws-channel-unread" data-chat-unread-pill data-count="<?= (int) $history['unread_count'] ?>"><?= (int) $history['unread_count'] ?></span>
          </a>

          <div class="tws-chat-guidelines-card" role="note">
            <i class="bi bi-shield" aria-hidden="true"></i>
            <p>Operational discussion only. Never share patient info, diagnoses, or tokens.</p>
          </div>

          <?php if ($recentTasks !== []): ?>
            <div class="tws-chat-refs">
              <h3 class="tws-sidebar-label">Recently referenced tasks</h3>
              <ul class="list-unstyled tws-chat-ref-list">
                <?php foreach ($recentTasks as $ref): ?>
                  <li>
                    <a class="tws-chat-ref-link" href="<?= esc($teamsUrl . '/' . $ref['id']) ?>" data-app-modal-open>
                      <span class="tws-chat-ref-dot" aria-hidden="true"></span>
                      <span class="tws-chat-ref-id">#<?= $ref['id'] ?></span>
                      <span class="tws-chat-ref-title"><?= esc($ref['title']) ?></span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <details class="tws-chat-members" open>
            <summary class="team-chat-members-summary" aria-label="Members (<?= count($members) ?>)">
              <span class="tws-sidebar-label">Workstation members · <?= count($members) ?></span>
              <i class="bi bi-chevron-down team-chat-members-chevron" aria-hidden="true"></i>
            </summary>
            <?php if (!empty($members)): ?>
              <ul class="list-unstyled teams-member-list mt-2">
                <?php foreach ($members as $member):
                    $roleText = $formatRole($member);
                ?>
                  <li class="tws-chat-member-row">
                    <img class="avatar"
                         src="<?= esc($memberAvatar($member)) ?>"
                         alt=""
                         width="30"
                         height="30"
                         onerror="this.onerror=null;this.src='<?= esc(\App\Services\ProfileAvatar::dataUri($member), 'attr') ?>'">
                    <span class="tws-chat-member-copy">
                      <span class="tws-chat-member-name"><?= esc($member['name']) ?></span>
                      <?php if ($roleText !== ''): ?>
                        <small class="tws-chat-member-role"><?= esc($roleText) ?></small>
                      <?php endif; ?>
                    </span>
                    <span class="tws-online-dot" aria-hidden="true"></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="small text-muted mt-2 mb-0">No active members</p>
            <?php endif; ?>
          </details>
        </div>
      </aside>

      <section class="team-chat-main" aria-labelledby="chatChannelTitle">
        <header class="team-chat-header">
          <div class="tws-chat-header-title">
            <h2 class="h6 mb-0" id="chatChannelTitle"><span class="tws-channel-hash" aria-hidden="true">#</span> <?= esc($channelSlug) ?></h2>
            <div class="tws-chat-header-meta">
              <span class="small text-muted" data-chat-connection></span>
              <span class="small text-muted" data-chat-history-status></span>
            </div>
          </div>
          <div class="tws-chat-header-actions">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-chat-older hidden>Load older messages</button>
          </div>
        </header>

        <div class="team-chat-log" data-chat-log tabindex="0" role="region" aria-label="Channel messages, chronological order">
          <?php foreach ($history['messages'] as $message):
              $isOwn = $message['sender_user_id'] === (int) $actor['id'];
          ?>
            <article id="chat-message-<?= (int) $message['id'] ?>" tabindex="-1" class="team-chat-message <?= $isOwn ? 'is-own' : '' ?><?= ($targetMessageId ?? null) === $message['id'] ? ' is-targeted' : '' ?>">
              <?php if (!$isOwn): ?>
                <span class="tws-msg-avatar is-<?= esc($avatarTone($message['sender_user_id'] ?? $message['sender_name'])) ?>" aria-hidden="true"><?= esc($initialsOf($message['sender_name'])) ?></span>
              <?php endif; ?>
              <div class="team-chat-bubble">
                <div class="team-chat-message-header"><strong><?= esc($message['sender_name']) ?></strong><time><?= esc($stamp($message['created_at'])) ?></time></div>
                <?php if (!empty($message['reply_to'])): ?>
                  <div class="team-chat-reply-quote"><strong>Reply to <?= esc($message['reply_to']['sender_name']) ?></strong><p><?= esc($message['reply_to']['body']) ?></p></div>
                <?php endif; ?>
                <p class="teams-preserve mb-0"><?= esc($message['body']) ?></p>
                <?php foreach ($message['tasks'] ?? ($message['task_id'] !== null ? [['id' => $message['task_id'], 'title' => $message['task_title'] ?? 'Task']] : []) as $reference): ?>
                  <a class="team-chat-task" href="<?= esc($teamsUrl . '/' . $reference['id']) ?>" data-app-modal-open><span class="tws-chat-task-id">Task #<?= (int) $reference['id'] ?>: </span><span class="tws-chat-task-title"><?= esc($reference['title']) ?></span></a>
                <?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="team-chat-update-bar"><button type="button" class="team-chat-scroll-latest" data-chat-new aria-label="Scroll to latest messages" title="Scroll to latest" hidden><i class="bi bi-arrow-down" aria-hidden="true"></i></button></div>
        <div class="alert alert-warning m-3" data-chat-error role="alert" hidden></div>

        <form class="team-chat-composer" action="<?= esc($baseUrl . '/send') ?>" method="post" data-chat-form data-loading="false" data-navigation="false">
          <?= csrf_field() ?>
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

          <div class="tws-composer-card">
            <div class="team-chat-reply-preview" data-chat-reply-preview hidden></div>
            <div class="team-chat-selected-tasks" data-chat-selected-tasks aria-label="Selected task references"></div>
            <div class="team-chat-input-row">
              <div class="header-action-wrap" data-bs-title="Add task references" data-bs-toggle="tooltip" data-bs-placement="top"><button type="button" class="header-action team-chat-icon-button" data-chat-add-task aria-label="Add task references" aria-haspopup="dialog" aria-controls="teamChatTaskPicker" aria-expanded="false"><i class="bi bi-plus-lg" aria-hidden="true"></i></button></div>
              <label class="visually-hidden" for="teamChatBody">Message</label>
              <textarea class="form-control" id="teamChatBody" name="body" rows="1" maxlength="2000" required placeholder="Message #<?= esc($channelSlug) ?>… Type @ to mention a team member" aria-controls="teamChatMentions" aria-autocomplete="list" aria-expanded="false" data-chat-body></textarea>
              <div class="header-action-wrap" data-bs-title="Send message" data-bs-toggle="tooltip" data-bs-placement="top"><button class="header-action team-chat-icon-button" type="submit" data-chat-send aria-label="Send" title="Send" disabled><i class="bi bi-send" aria-hidden="true"></i></button></div>
            </div>
            <div class="tws-composer-footer">
              <span class="tws-composer-hint"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Do not include patient details or PHI</span>
              <span class="tws-composer-status">
                <span data-chat-send-status role="status" aria-live="polite"></span>
                <span data-chat-read-status>Unread: <?= $history['unread_count'] ?></span>
              </span>
            </div>
          </div>
        </form>
        <noscript><p class="alert alert-warning m-3">Enable JavaScript to send messages, track unread position, and load updates. Messages above remain readable.</p><?php if ($history['next_before']): ?><a class="btn btn-outline-secondary m-3" href="<?= esc($baseUrl . '?before=' . $history['next_before']) ?>">Older messages</a><?php endif; ?></noscript>
      </section>
    </div>
  </div>
</div>
<?= $this->endSection() ?>
