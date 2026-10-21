<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$initial = $history + ['after_mode' => $afterMode, 'browsing_history' => $browsingHistory];
$initialJson = json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$stamp = static fn(string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A');
?>
<div class="teams-workspace" data-team-chat data-base-url="<?= esc($baseUrl) ?>" data-teams-url="<?= esc($teamsUrl) ?>" data-actor-id="<?= (int) $actor['id'] ?>" data-initial="<?= esc($initialJson, 'attr') ?>">
  <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Teams views"><a class="btn btn-outline-secondary" href="<?= esc($teamsUrl) ?>">Team board</a><a class="btn btn-outline-secondary" href="<?= esc($teamsUrl . '?mine=1&view=list') ?>">My tasks</a><a class="btn btn-outline-secondary" href="<?= esc($teamsUrl . '/calendar') ?>">Team calendar</a><a class="btn btn-primary" href="<?= esc($baseUrl) ?>" aria-current="page">Team chat <span class="badge bg-light text-dark ms-2" data-chat-unread><?= $history['unread_count'] ?></span></a></nav>
  <div class="alert alert-light border small"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Operational discussion only. Do not share patient details, diagnoses, counseling notes, passwords, or tokens. All authorized <?= esc(ucfirst($unit)) ?> workstation members can read this channel. Not for urgent clinical communication.</div>
  <div class="team-chat-layout">
    <aside class="card team-chat-sidebar" aria-label="Department channel">
      <div class="card-body p-3"><span class="teams-eyebrow">Team messages</span><h2 class="h6 mt-3">Channels</h2><a class="team-chat-channel" href="<?= esc($baseUrl) ?>" aria-current="page"><i class="bi bi-hash" aria-hidden="true"></i><?= esc(ucfirst($unit)) ?> operations</a><p class="small text-muted mt-3">One shared channel for this workstation. No direct messages or attachments.</p><details><summary class="small fw-semibold">Members (<?= count($members) ?>)</summary><ul class="list-unstyled teams-member-list mt-2"><?php foreach ($members as $member): ?><li class="small"><i class="bi bi-person-circle me-1" aria-hidden="true"></i><?= esc($member['name']) ?></li><?php endforeach; ?></ul></details><p class="small text-muted mt-3 mb-0">Member list is not an online-status indicator. Times shown in Asia/Manila.</p></div>
    </aside>
    <section class="card team-chat-main" aria-labelledby="chatChannelTitle">
      <header class="team-chat-header"><div><h2 class="h6 mb-1" id="chatChannelTitle"># <?= esc(ucfirst($unit)) ?> operations</h2><span class="small text-muted" data-chat-connection>Connecting…</span></div><button class="btn btn-outline-secondary btn-sm" type="button" data-chat-latest>Show latest</button></header>
      <div class="team-chat-tools"><button type="button" class="btn btn-outline-secondary btn-sm" data-chat-older hidden>Load older messages</button><span class="small text-muted" data-chat-history-status></span></div>
      <div class="team-chat-log" data-chat-log tabindex="0" role="region" aria-label="Channel messages, chronological order">
        <?php foreach ($history['messages'] as $message): ?><article class="team-chat-message <?= $message['sender_user_id'] === (int) $actor['id'] ? 'is-own' : '' ?>"><div class="team-chat-bubble"><div class="team-chat-message-header"><strong><?= esc($message['sender_name']) ?></strong><time><?= esc($stamp($message['created_at'])) ?></time></div><p class="teams-preserve mb-0"><?= esc($message['body']) ?></p><?php if ($message['task_id'] !== null): ?><a class="team-chat-task" href="<?= esc($teamsUrl . '/' . $message['task_id']) ?>" data-app-modal-open>Task #<?= $message['task_id'] ?>: <?= esc($message['task_title'] ?? 'Task') ?></a><?php endif; ?></div></article><?php endforeach; ?>
      </div>
      <div class="team-chat-update-bar"><button type="button" class="btn btn-outline-primary btn-sm" data-chat-new hidden>Go to new messages</button><span class="small text-muted" data-chat-read-status>Unread: <?= $history['unread_count'] ?></span></div>
      <div class="alert alert-warning m-3" data-chat-error role="alert" hidden></div>
      <form class="team-chat-composer" action="<?= esc($baseUrl . '/send') ?>" method="post" data-chat-form data-loading="false" data-navigation="false">
        <?= csrf_field() ?>
        <label class="form-label" for="teamChatBody">Message</label><textarea class="form-control" id="teamChatBody" name="body" rows="3" maxlength="2000" required aria-describedby="chatComposeHelp" data-chat-body></textarea>
        <div class="d-flex flex-wrap align-items-end gap-2 mt-2"><div class="team-chat-task-input"><label class="form-label small" for="teamChatTask">Task reference (optional ID)</label><input class="form-control form-control-sm" type="number" id="teamChatTask" name="task_id" min="1" step="1" inputmode="numeric" placeholder="e.g. 12" data-chat-task></div><button class="btn btn-primary ms-auto" type="submit" data-chat-send disabled><i class="bi bi-send me-2" aria-hidden="true"></i>Send</button></div>
        <div class="form-text" id="chatComposeHelp">Up to 2,000 characters. Ctrl/Cmd+Enter sends; Enter adds a line. A task reference must be an active task in this workstation.</div><p class="small text-muted mt-2 mb-0" data-chat-send-status role="status" aria-live="polite">Messages and drafts are not saved in this browser.</p>
      </form>
      <noscript><p class="alert alert-warning m-3">Enable JavaScript to send messages, track unread position, and load updates. Messages above remain readable.</p><?php if ($history['next_before']): ?><a class="btn btn-outline-secondary m-3" href="<?= esc($baseUrl . '?before=' . $history['next_before']) ?>">Older messages</a><?php endif; ?></noscript>
    </section>
  </div>
</div>
<?= $this->endSection() ?>
