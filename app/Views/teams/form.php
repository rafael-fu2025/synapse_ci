<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$value = static fn(string $key, mixed $default = ''): mixed => $task[$key] ?? $default;
$checklist = $value('checklist', []);
$checklistJson = json_encode($checklist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$unitLabel = ['clinic' => 'Clinic', 'guidance' => 'Guidance', 'facilities' => 'BMG'][$unit];
?>
<div class="tws-editor">
  <div class="tws-editor-intro">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <p>For <?= esc($unitLabel) ?> operational work only. Do not include patient information or confidential counseling notes.</p>
  </div>
  <form action="<?= esc($formAction) ?>" method="post" data-modal-submit x-data="{ items: <?= esc($checklistJson, 'attr') ?> }">
    <?= csrf_field() ?>
    <?php if ($task !== null): ?><input type="hidden" name="version" value="<?= (int) $task['version'] ?>"><?php endif; ?>

    <section class="tws-editor-section" aria-labelledby="tws-task-details">
      <div class="tws-section-head">
        <span class="tws-section-icon" aria-hidden="true"><i class="bi bi-check2-square"></i></span>
        <div>
          <h2 class="tws-section-title" id="tws-task-details">Task details</h2>
          <p class="tws-section-sub">Clear operational title and concise context for <?= esc($unitLabel) ?> staff.</p>
        </div>
      </div>
      <div class="tws-field">
        <label for="teamTaskTitle" class="form-label">Title *</label>
        <input class="form-control" id="teamTaskTitle" name="title" required maxlength="160" placeholder="e.g., Audit triage crash cart expiry dates" value="<?= esc($value('title')) ?>">
      </div>
      <div class="tws-field">
        <label for="teamTaskDescription" class="form-label">Description</label>
        <textarea class="form-control" id="teamTaskDescription" name="description" rows="4" maxlength="4000" placeholder="Optional operational notes or steps…"><?= esc($value('description')) ?></textarea>
        <div class="form-text">Optional operational context, up to 4,000 characters.</div>
      </div>
    </section>

    <section class="tws-editor-section" aria-labelledby="tws-task-ownership">
      <div class="tws-section-head">
        <span class="tws-section-icon" aria-hidden="true"><i class="bi bi-sliders"></i></span>
        <div>
          <h2 class="tws-section-title" id="tws-task-ownership">Ownership &amp; timing</h2>
          <p class="tws-section-sub">Set workflow column, urgency, owner, and operational deadline.</p>
        </div>
      </div>
      <div class="tws-field-grid">
        <div>
          <label for="teamTaskStatus" class="form-label">Status</label>
          <select class="form-select" id="teamTaskStatus" name="status">
            <?php foreach ($statuses as $key => $label): ?>
              <option value="<?= esc($key) ?>" <?= $value('status', 'todo') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="teamTaskPriority" class="form-label">Priority</label>
          <select class="form-select" id="teamTaskPriority" name="priority">
            <?php foreach ($priorities as $key => $label): ?>
              <option value="<?= esc($key) ?>" <?= $value('priority', 'normal') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="teamTaskAssignee" class="form-label">Assigned to</label>
          <select class="form-select" id="teamTaskAssignee" name="assignee_user_id">
            <option value="">Unassigned</option>
            <?php if ($value('assignee_user_id') && !in_array($value('assignee_user_id'), array_column($members, 'id'), true)): ?>
              <option value="<?= (int) $value('assignee_user_id') ?>" selected>Former member — choose a current member or Unassigned</option>
            <?php endif; ?>
            <?php foreach ($members as $member): ?>
              <option value="<?= $member['id'] ?>" <?= (int) $value('assignee_user_id') === $member['id'] ? 'selected' : '' ?>><?= esc($member['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="teamTaskDueDisplay" class="form-label">Due date (Asia/Manila)</label>
          <input type="text" class="form-control" id="teamTaskDueDisplay" data-datepicker data-target="#teamTaskDue" autocomplete="off" placeholder="DD/MM/YYYY">
          <input type="hidden" id="teamTaskDue" name="due_date" value="<?= esc($value('due_date')) ?>">
          <div class="form-text">Optional. A due date does not reserve an appointment.</div>
        </div>
      </div>
    </section>

    <section class="tws-editor-section" aria-labelledby="tws-task-checklist">
      <div class="tws-checklist-card">
        <div class="tws-checklist-card-head">
          <h2 class="tws-section-title" id="tws-task-checklist">Checklist</h2>
          <span class="tws-checklist-summary" x-text="items.length + ' of 20 max'">0 of 20 max</span>
        </div>
        <fieldset class="tws-checklist-editor">
          <legend class="visually-hidden">Checklist items</legend>
          <p class="tws-field-note">Up to 20 items. Check an item when it is complete.</p>
          <input type="hidden" name="checklist" value="<?= esc($checklistJson, 'attr') ?>" :value="JSON.stringify(items)">
          <template x-for="(item, index) in items" :key="index">
            <div class="tws-checklist-row">
              <input :id="'teamChecklistDone-' + index" type="checkbox" class="form-check-input flex-shrink-0" x-model="item.done" :aria-label="'Complete checklist item ' + (index + 1)">
              <input :id="'teamChecklistText-' + index" type="text" class="form-control" x-model="item.text" required maxlength="160" :aria-label="'Checklist item ' + (index + 1)">
              <button type="button" class="btn btn-outline-danger btn-sm" @click="items.splice(index, 1)" :aria-label="'Remove checklist item ' + (index + 1)"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </div>
          </template>
          <button type="button" class="btn btn-outline-primary btn-sm tws-add-item-btn" :disabled="items.length >= 20" @click="items.push({ text: '', done: false })"><i class="bi bi-plus me-1" aria-hidden="true"></i>Add checklist item</button>
          <noscript><p class="tws-field-note">JavaScript is needed to edit the checklist. Existing items will be preserved.</p></noscript>
        </fieldset>
      </div>
    </section>

    <div class="form-actions tws-form-actions">
      <a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Cancel</a>
      <button class="btn btn-primary" type="submit"><?= $task === null ? 'Create task' : 'Save changes' ?></button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
