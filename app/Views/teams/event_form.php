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
<div class="tws-editor">
  <div class="tws-editor-intro">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <p>One-time internal event for <?= esc($unitLabel) ?>. Times are in Asia/Manila. Does not reserve a patient appointment or change provider availability.</p>
  </div>
  <form action="<?= esc($formAction) ?>" method="post" data-modal-submit>
    <?= csrf_field() ?>
    <?php if ($event !== null): ?><input type="hidden" name="version" value="<?= $event['version'] ?>"><?php endif; ?>

    <section class="tws-editor-section" aria-labelledby="teamEventDetails">
      <div class="tws-section-head">
        <span class="tws-section-icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
        <div>
          <h2 class="tws-section-title" id="teamEventDetails">Event details</h2>
          <p class="tws-section-sub">Concise internal event title and operational context for <?= esc($unitLabel) ?> staff.</p>
        </div>
      </div>
      <div class="tws-field">
        <label class="form-label" for="teamEventTitle">Title *</label>
        <input class="form-control" id="teamEventTitle" name="title" maxlength="160" required placeholder="e.g., Weekly Clinic Supply Sync" value="<?= esc($event['title'] ?? '') ?>">
      </div>
      <div class="tws-field">
        <label class="form-label" for="teamEventDescription">Description</label>
        <textarea class="form-control" id="teamEventDescription" name="description" rows="3" maxlength="4000" placeholder="Agenda or operational notes…" aria-describedby="teamEventPrivacy"><?= esc($event['description'] ?? '') ?></textarea>
        <div class="form-text" id="teamEventPrivacy">All workstation members can view this event. Do not include patient details or confidential notes.</div>
      </div>
    </section>

    <section class="tws-editor-section" aria-labelledby="teamEventSchedule">
      <div class="tws-section-head">
        <span class="tws-section-icon" aria-hidden="true"><i class="bi bi-clock"></i></span>
        <div>
          <h2 class="tws-section-title" id="teamEventSchedule">Schedule &amp; location</h2>
          <p class="tws-section-sub">All start and end times use Asia/Manila (UTC+08:00).</p>
        </div>
      </div>
      <div class="tws-date-fields mb-2">
        <div>
          <label class="form-label" for="teamEventStartDisplay">Starts at (Asia/Manila) *</label>
          <input class="form-control" type="text" id="teamEventStartDisplay" data-datetimepicker data-target="#teamEventStart" placeholder="DD/MM/YYYY hh:mm A" autocomplete="off" required>
          <input type="hidden" name="starts_at" id="teamEventStart" value="<?= esc($startsValue) ?>">
        </div>
        <div>
          <label class="form-label" for="teamEventEndDisplay">Ends at (Asia/Manila) *</label>
          <input class="form-control" type="text" id="teamEventEndDisplay" data-datetimepicker data-target="#teamEventEnd" placeholder="DD/MM/YYYY hh:mm A" autocomplete="off" required aria-describedby="teamEventDuration">
          <input type="hidden" name="ends_at" id="teamEventEnd" value="<?= esc($endsValue) ?>">
        </div>
      </div>
      <p class="form-text mb-3" id="teamEventDuration">End must be later than start. Maximum duration: 31 days. An event ending at midnight does not extend into the following day.</p>
      <div class="tws-field">
        <label class="form-label" for="teamEventLocation">Location</label>
        <input class="form-control" id="teamEventLocation" name="location" maxlength="160" value="<?= esc($event['location'] ?? '') ?>" placeholder="Room or meeting location">
      </div>
    </section>

    <fieldset class="tws-editor-section">
      <legend class="tws-section-head">
        <span class="tws-section-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
        <span>
          <span class="tws-section-title d-block">Participants</span>
          <span class="tws-section-sub d-block">Optional, up to 100 active workstation members.</span>
        </span>
      </legend>
      <p class="form-text mt-0 mb-3">Participants are included in My schedule. Event changes use targeted in-app notices, with optional mobile push when configured.</p>
      <div class="team-calendar-participants tws-participant-picker">
        <?php foreach ($members as $member): ?>
          <label class="tws-participant-card form-check" for="eventMember<?= $member['id'] ?>">
            <input class="form-check-input" type="checkbox" name="participant_ids[]" id="eventMember<?= $member['id'] ?>" value="<?= $member['id'] ?>" <?= in_array($member['id'], $event['participant_ids'] ?? [], true) ? 'checked' : '' ?>>
            <span class="tws-avatar tws-avatar--sm is-<?= esc($avatarTone($member['id'])) ?>" aria-hidden="true"><?= $memberAvatarHtml((int) $member['id'], $initialsOf($member['name']), (string) $member['name']) ?></span>
            <span class="tws-participant-card-meta">
              <span class="tws-participant-card-name"><?= esc($member['name']) ?></span>
              <small class="text-muted"><?= esc(ucwords(str_replace('_', ' ', $member['role']))) ?></small>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="form-actions tws-form-actions">
      <a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Cancel</a>
      <button class="btn btn-primary" type="submit"><?= $event === null ? 'Create event' : 'Save changes' ?></button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
