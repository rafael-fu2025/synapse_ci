<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<p class="small text-muted">One-time internal event for <?= esc(ucfirst($unit)) ?>. Times are in Asia/Manila. This does not reserve a patient appointment or change provider availability.</p>
<form action="<?= esc($formAction) ?>" method="post" data-modal-submit>
  <?= csrf_field() ?>
  <?php if ($event !== null): ?><input type="hidden" name="version" value="<?= $event['version'] ?>"><?php endif; ?>
  <div class="mb-3"><label class="form-label" for="teamEventTitle">Title *</label><input class="form-control" id="teamEventTitle" name="title" maxlength="160" required value="<?= esc($event['title'] ?? '') ?>"></div>
  <div class="mb-3"><label class="form-label" for="teamEventDescription">Description</label><textarea class="form-control" id="teamEventDescription" name="description" rows="3" maxlength="4000" aria-describedby="teamEventPrivacy"><?= esc($event['description'] ?? '') ?></textarea><div class="form-text" id="teamEventPrivacy">All workstation members can view this event. Do not include patient details or confidential notes.</div></div>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><label class="form-label" for="teamEventStartDisplay">Starts at (Asia/Manila) *</label><input class="form-control" type="text" id="teamEventStartDisplay" data-datetimepicker data-target="#teamEventStart" placeholder="DD/MM/YYYY hh:mm A" autocomplete="off" required><input type="hidden" name="starts_at" id="teamEventStart" value="<?= esc($startsValue) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="teamEventEndDisplay">Ends at (Asia/Manila) *</label><input class="form-control" type="text" id="teamEventEndDisplay" data-datetimepicker data-target="#teamEventEnd" placeholder="DD/MM/YYYY hh:mm A" autocomplete="off" required aria-describedby="teamEventDuration"><input type="hidden" name="ends_at" id="teamEventEnd" value="<?= esc($endsValue) ?>"></div>
  </div>
  <p class="form-text" id="teamEventDuration">End must be later than start. Maximum duration: 31 days. An event ending at midnight does not extend into the following day.</p>
  <div class="mb-3"><label class="form-label" for="teamEventLocation">Location</label><input class="form-control" id="teamEventLocation" name="location" maxlength="160" value="<?= esc($event['location'] ?? '') ?>" placeholder="Room or meeting location"></div>
  <fieldset class="border rounded p-3"><legend class="float-none w-auto px-1 fs-6 fw-semibold">Participants</legend><p class="form-text mt-0">Optional, up to 100 active workstation members. Participants are included in My schedule; no invitations or reminders are sent.</p>
    <div class="team-calendar-participants"><?php foreach ($members as $member): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="participant_ids[]" id="eventMember<?= $member['id'] ?>" value="<?= $member['id'] ?>" <?= in_array($member['id'], $event['participant_ids'] ?? [], true) ? 'checked' : '' ?>><label class="form-check-label" for="eventMember<?= $member['id'] ?>"><?= esc($member['name']) ?><small class="text-muted ms-1"><?= esc(ucwords(str_replace('_', ' ', $member['role']))) ?></small></label></div><?php endforeach; ?></div>
  </fieldset>
  <div class="form-actions"><a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Cancel</a><button class="btn btn-primary" type="submit"><?= $event === null ? 'Create event' : 'Save changes' ?></button></div>
</form>
<?= $this->endSection() ?>
