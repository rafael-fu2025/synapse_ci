<?php require __DIR__ . '/_dates.php'; ?>
<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<p class="text-muted small"><?= esc(ucfirst($unit)) ?> announcement · Saving creates a draft only. Publication is a separate, confirmed action.</p>
<form action="<?= esc($formAction) ?>" method="POST" data-modal-submit x-data="{ audience: <?= esc(json_encode($announcement['audience'] ?? 'all'), 'attr') ?> }">
  <?= csrf_field() ?>
  <?php if ($announcement !== null): ?><input type="hidden" name="version" value="<?= (int) $announcement['version'] ?>"><?php endif; ?>
  <div class="mb-3"><label for="announcementTitle" class="form-label">Title</label><input id="announcementTitle" name="title" class="form-control" maxlength="160" required value="<?= esc($announcement['title'] ?? '') ?>"></div>
  <div class="mb-3"><label for="announcementBody" class="form-label">Announcement text</label><textarea id="announcementBody" name="body" class="form-control" rows="8" maxlength="10000" required><?= esc($announcement['body'] ?? '') ?></textarea><div class="form-text">Plain text only, up to 10,000 characters. No HTML or rich-text formatting.</div></div>
  <div class="mb-3"><label for="announcementAudience" class="form-label">Audience</label><select id="announcementAudience" name="audience" class="form-select" x-model="audience" required><?php foreach (['all' => 'All students and employees', 'students' => 'Students', 'employees' => 'Employees (including staff self-service)'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= ($announcement['audience'] ?? 'all') === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
  <?= view('announcements/_targeting', ['targetRecord' => $announcement ?? [], 'targetPrefix' => 'announcement'], ['saveData' => false]) ?>
  <p class="form-text">Optional visibility window, in Asia/Manila time (UTC+08:00). Publishing with a future start keeps the notice hidden until then; expiry removes it from the portal.</p>
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <label for="announcement_starts_at_display" class="form-label fw-semibold">Visible from</label>
      <input type="text"
             id="announcement_starts_at_display"
             class="form-control"
             data-datetimepicker
             data-target="#announcement_starts_at"
             data-min="today"
             placeholder="DD/MM/YYYY hh:mm A"
             autocomplete="off">
      <input type="hidden"
             id="announcement_starts_at"
             name="starts_at"
             value="<?= esc($announcementDate($announcement['starts_at'] ?? null, 'Y-m-d\TH:i')) ?>">
    </div>
    <div class="col-md-6">
      <label for="announcement_ends_at_display" class="form-label fw-semibold">Expires at</label>
      <input type="text"
             id="announcement_ends_at_display"
             class="form-control"
             data-datetimepicker
             data-target="#announcement_ends_at"
             data-min="today"
             data-default-hours="23"
             data-default-minutes="59"
             placeholder="DD/MM/YYYY hh:mm A"
             autocomplete="off">
      <input type="hidden"
             id="announcement_ends_at"
             name="ends_at"
             value="<?= esc($announcementDate($announcement['ends_at'] ?? null, 'Y-m-d\TH:i')) ?>">
    </div>
  </div>
  <div class="form-actions"><a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a><button type="submit" class="btn btn-primary">Save draft</button></div>
</form>
<?= $this->endSection() ?>
