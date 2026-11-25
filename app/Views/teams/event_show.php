<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$unitLabel = ['clinic' => 'Clinic', 'guidance' => 'Guidance', 'facilities' => 'BMG'][$unit];
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
$organizerName = $memberNames[$event['created_by_user_id']] ?? 'Former member';
$memberPhotos = is_array($memberPhotos ?? null) ? $memberPhotos : [];
$memberAvatarHtml = static function (?int $userId, string $initials, string $name = '') use ($memberPhotos): string {
    $url = $userId ? ($memberPhotos[$userId] ?? null) : null;
    if ($url !== null && $url !== '') {
        return \App\Services\ProfileAvatar::contentHtml(
            $url,
            $initials,
            $name !== '' ? ['name' => $name] : ['avatar_initials' => $initials]
        );
    }
    return esc($initials);
};
?>
<div class="tws-detail">
  <div class="tws-inspector-grid">
    <aside class="tws-inspector-rail" aria-label="Event schedule and actions">
      <dl class="tws-meta-ledger tws-meta-ledger--rail" aria-labelledby="teamEventOverview">
        <div class="tws-rail-heading-wrap"><dt id="teamEventOverview" class="tws-section-title">Event overview</dt><dd class="visually-hidden">Schedule details</dd></div>
        <div><dt>Starts</dt><dd><span class="tws-date"><?= esc($startLocal->format('M j, Y · g:i A')) ?></span></dd></div>
        <div><dt>Ends</dt><dd><span class="tws-date"><?= esc($endLocal->format('M j, Y · g:i A')) ?></span></dd></div>
        <div><dt>Timezone</dt><dd>Asia/Manila</dd></div>
        <div><dt>Location</dt><dd class="text-break"><?= esc($event['location'] !== '' ? $event['location'] : 'Not specified') ?></dd></div>
        <div>
          <dt>Organizer</dt>
          <dd>
            <span class="tws-rail-person">
              <span class="tws-avatar tws-avatar--sm is-<?= esc($avatarTone($event['created_by_user_id'])) ?>" aria-hidden="true"><?= $memberAvatarHtml((int) $event['created_by_user_id'], $initialsOf($organizerName), (string) $organizerName) ?></span>
              <span><?= esc($organizerName) ?></span>
            </span>
          </dd>
        </div>
      </dl>

      <?php if ($event['canEdit'] && $event['status'] !== 'cancelled'): ?>
        <form class="tws-rail-block tws-cancel-form" action="<?= esc($baseUrl . '/events/' . $event['id'] . '/cancel') ?>" method="post" data-modal-submit data-confirm="Cancel this internal team event? It will remain in calendar history. Patient appointments are unaffected." data-confirm-title="Cancel team event" data-confirm-label="Cancel event" data-confirm-tone="danger">
          <?= csrf_field() ?><input type="hidden" name="version" value="<?= $event['version'] ?>">
          <button class="btn btn-outline-danger w-100" type="submit">Cancel event</button>
        </form>
      <?php endif; ?>
      <?php
        $eventIsArchivable = ($event['status'] === 'cancelled')
            || ((string) ($event['ends_at'] ?? '') !== '' && (string) ($event['ends_at'] ?? '') < gmdate('Y-m-d H:i:s'));
      ?>
      <?php if (!empty($canArchive) && empty($event['deleted_at']) && $eventIsArchivable): ?>
        <form class="tws-rail-block tws-cancel-form" action="<?= esc($baseUrl . '/events/' . $event['id'] . '/archive') ?>" method="post" data-modal-submit data-confirm="Archive this team event reversibly? It can be restored anytime from the Archive Center." data-confirm-title="Archive team event" data-confirm-label="Archive event" data-confirm-tone="warning">
          <?= csrf_field() ?>
          <button class="btn btn-outline-warning w-100" type="submit"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive event</button>
        </form>
      <?php endif; ?>
    </aside>

    <div class="tws-inspector-main">
      <header class="tws-detail-head">
        <div class="tws-detail-badges">
          <span class="tws-id-badge"><?= esc(strtoupper($unitLabel)) ?> EVENT</span>
          <span class="tws-status is-<?= esc($event['status']) ?>"><?= $event['status'] === 'cancelled' ? 'Cancelled' : 'Scheduled' ?></span>
        </div>
        <h2 class="tws-detail-title mb-0"><?= esc($event['title']) ?></h2>
      </header>

      <?php if ($event['status'] === 'cancelled'): ?>
        <div class="tws-detail-section">
          <div class="tws-cancelled-banner" role="status"><i class="bi bi-x-circle me-2" aria-hidden="true"></i>This event has been cancelled.</div>
        </div>
      <?php endif; ?>

      <?php if ($event['description'] !== ''): ?>
        <section class="tws-detail-section" aria-labelledby="teamEventDescriptionHeading">
          <h3 class="tws-section-title" id="teamEventDescriptionHeading">Description</h3>
          <p class="teams-preserve tws-detail-text"><?= esc($event['description']) ?></p>
        </section>
      <?php endif; ?>

      <section class="tws-detail-section" aria-labelledby="teamEventParticipants">
        <h3 class="tws-section-title" id="teamEventParticipants">Participants (<?= count($event['participants']) ?>)</h3>
        <?php if ($event['participants'] !== []): ?>
          <ul class="tws-participant-list">
            <?php foreach ($event['participants'] as $member): ?>
              <li>
                <span class="tws-avatar tws-avatar--sm is-<?= esc($avatarTone($member['id'] ?? $member['name'])) ?>" aria-hidden="true"><?= $memberAvatarHtml(isset($member['id']) ? (int) $member['id'] : null, $initialsOf($member['name']), (string) $member['name']) ?></span>
                <span><?= esc($member['name']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="text-muted small mb-0 mt-2">No current workstation participants selected.</p>
        <?php endif; ?>
      </section>

      <div class="tws-detail-section">
        <div class="tws-detail-note" role="note">
          <i class="bi bi-shield-lock" aria-hidden="true"></i>
          <p class="small text-muted mb-0">Internal coordination only. This event does not reserve a patient appointment or block provider availability. Event changes use targeted in-app notices, with optional mobile push when configured. All workstation members can view this event; do not include patient details or confidential notes.</p>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="form-actions tws-form-actions">
  <a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Close</a>
  <?php if ($event['canEdit'] && $event['status'] !== 'cancelled'): ?>
    <a class="btn btn-primary" href="<?= esc($baseUrl . '/events/' . $event['id'] . '/edit') ?>" data-app-modal-route>Edit event</a>
  <?php endif; ?>
</div>
<?= $this->endSection() ?>
