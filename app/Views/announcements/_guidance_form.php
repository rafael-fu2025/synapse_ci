<?php
$unitLabel = ucfirst($unit);
$advisoryNote = $unit === 'clinic'
    ? 'Use for important Clinic medical exam schedules, outbreak prevention, or service advisories.'
    : 'Use for important Guidance schedules, counselling availability, or service advisories.';
?>
<div class="guidance-ui ga-editor">
  <p class="ga-editor-intro"><?= esc($unitLabel) ?> announcement · Saving creates a draft only. Publication is a separate, confirmed action.</p>
  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit x-data="{ audience: <?= esc(json_encode($announcement['audience'] ?? 'all'), 'attr') ?> }">
    <?= csrf_field() ?>
    <?php if ($announcement !== null): ?><input type="hidden" name="version" value="<?= (int) $announcement['version'] ?>"><?php endif; ?>
    <section class="ga-editor-section" aria-labelledby="ga-content-title">
      <div class="ga-section-head"><span class="ga-section-number" aria-hidden="true">01</span><h2 id="ga-content-title" class="ga-section-title">Announcement content</h2></div>
      <div class="ga-field">
        <label for="announcementTitle" class="form-label">Title</label>
        <input id="announcementTitle" name="title" class="form-control" maxlength="160" required value="<?= esc($announcement['title'] ?? '') ?>" aria-describedby="announcementTitleHelp">
        <div id="announcementTitleHelp" class="form-text">A clear heading, up to 160 characters.</div>
      </div>
      <div class="ga-field">
        <label for="announcementBody" class="form-label">Announcement text</label>
        <textarea id="announcementBody" name="body" class="form-control" rows="8" maxlength="10000" required aria-describedby="announcementBodyHelp"><?= esc($announcement['body'] ?? '') ?></textarea>
        <div id="announcementBodyHelp" class="form-text">Plain text only, up to 10,000 characters. No HTML or rich-text formatting.</div>
      </div>
    </section>
    <section class="ga-editor-section" aria-labelledby="ga-audience-title">
      <div class="ga-section-head"><span class="ga-section-number" aria-hidden="true">02</span><h2 id="ga-audience-title" class="ga-section-title">Audience &amp; targeting</h2></div>
      <div class="ga-field">
        <label for="announcementAudience" class="form-label">Audience</label>
        <select id="announcementAudience" name="audience" class="form-select" x-model="audience" required>
          <?php foreach (['all' => 'All students and employees', 'students' => 'Students', 'employees' => 'Employees (including staff self-service)'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= ($announcement['audience'] ?? 'all') === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="ga-targeting">
        <?= view('announcements/_targeting', ['targetRecord' => $announcement ?? [], 'targetPrefix' => 'announcement'], ['saveData' => false]) ?>
      </div>
    </section>
    <section class="ga-editor-section" aria-labelledby="ga-visibility-title">
      <div class="ga-section-head"><span class="ga-section-number" aria-hidden="true">03</span><h2 id="ga-visibility-title" class="ga-section-title">Visibility &amp; advisory</h2></div>
      <p class="ga-field-note" id="announcementVisibilityHelp">Optional visibility window, in Asia/Manila time (UTC+08:00). Publishing with a future start keeps the notice hidden until then; expiry removes it from the portal.</p>
      <div class="ga-date-fields">
        <div>
          <label for="announcement_starts_at_display" class="form-label">Visible from</label>
          <input type="text" id="announcement_starts_at_display" class="form-control" data-datetimepicker data-target="#announcement_starts_at" data-min="today" placeholder="DD/MM/YYYY hh:mm A" autocomplete="off" aria-describedby="announcementVisibilityHelp announcementStartHelp">
          <input type="hidden" id="announcement_starts_at" name="starts_at" value="<?= esc($announcementDate($announcement['starts_at'] ?? null, 'Y-m-d\TH:i')) ?>">
          <div id="announcementStartHelp" class="form-text">Leave blank to show on publication.</div>
        </div>
        <div>
          <label for="announcement_ends_at_display" class="form-label">Expires at</label>
          <input type="text" id="announcement_ends_at_display" class="form-control" data-datetimepicker data-target="#announcement_ends_at" data-min="today" data-default-hours="23" data-default-minutes="59" placeholder="DD/MM/YYYY hh:mm A" autocomplete="off" aria-describedby="announcementVisibilityHelp announcementEndHelp">
          <input type="hidden" id="announcement_ends_at" name="ends_at" value="<?= esc($announcementDate($announcement['ends_at'] ?? null, 'Y-m-d\TH:i')) ?>">
          <div id="announcementEndHelp" class="form-text">Leave blank for no expiry.</div>
        </div>
      </div>
      <div class="ga-advisory-choice">
        <input class="form-check-input" type="checkbox" name="is_pinned_advisory" value="1" id="announcementPinned" <?= !empty($announcement['is_pinned_advisory']) ? 'checked' : '' ?> aria-describedby="announcementPinnedHelp">
        <div>
          <label class="form-check-label" for="announcementPinned">Pin as prominent portal banner advisory</label>
          <p id="announcementPinnedHelp" class="ga-field-note"><?= esc($advisoryNote) ?></p>
        </div>
      </div>
    </section>
    <div class="form-actions ga-form-actions"><a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a><button type="submit" class="btn btn-primary">Save draft</button></div>
  </form>
</div>
