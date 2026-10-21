<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$value = static fn(string $key, mixed $default = ''): mixed => $task[$key] ?? $default;
$checklist = $value('checklist', []);
$checklistJson = json_encode($checklist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<p class="small text-muted">For <?= esc(ucfirst($unit)) ?> operational work only. Do not include patient information or confidential counseling notes.</p>
<form action="<?= esc($formAction) ?>" method="post" data-modal-submit x-data="{ items: <?= esc($checklistJson, 'attr') ?> }">
  <?= csrf_field() ?>
  <?php if ($task !== null): ?><input type="hidden" name="version" value="<?= (int) $task['version'] ?>"><?php endif; ?>
  <div class="mb-3"><label for="teamTaskTitle" class="form-label">Title *</label><input class="form-control" id="teamTaskTitle" name="title" required maxlength="160" value="<?= esc($value('title')) ?>"></div>
  <div class="mb-3"><label for="teamTaskDescription" class="form-label">Description</label><textarea class="form-control" id="teamTaskDescription" name="description" rows="4" maxlength="4000"><?= esc($value('description')) ?></textarea></div>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><label for="teamTaskStatus" class="form-label">Status</label><select class="form-select" id="teamTaskStatus" name="status"><?php foreach ($statuses as $key => $label): ?><option value="<?= esc($key) ?>" <?= $value('status', 'todo') === $key ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label for="teamTaskPriority" class="form-label">Priority</label><select class="form-select" id="teamTaskPriority" name="priority"><?php foreach ($priorities as $key => $label): ?><option value="<?= esc($key) ?>" <?= $value('priority', 'normal') === $key ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label for="teamTaskAssignee" class="form-label">Assigned to</label><select class="form-select" id="teamTaskAssignee" name="assignee_user_id"><option value="">Unassigned</option><?php if ($value('assignee_user_id') && !in_array($value('assignee_user_id'), array_column($members, 'id'), true)): ?><option value="<?= (int) $value('assignee_user_id') ?>" selected>Former member — choose a current member or Unassigned</option><?php endif; ?><?php foreach ($members as $member): ?><option value="<?= $member['id'] ?>" <?= (int) $value('assignee_user_id') === $member['id'] ? 'selected' : '' ?>><?= esc($member['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label for="teamTaskDueDisplay" class="form-label">Due date (Asia/Manila)</label><input type="text" class="form-control" id="teamTaskDueDisplay" data-datepicker data-target="#teamTaskDue" autocomplete="off" placeholder="DD/MM/YYYY"><input type="hidden" id="teamTaskDue" name="due_date" value="<?= esc($value('due_date')) ?>"><div class="form-text">Optional. A due date does not reserve an appointment.</div></div>
  </div>
  <fieldset class="border rounded p-3">
    <legend class="float-none w-auto px-1 fs-6 fw-semibold">Checklist</legend>
    <p class="form-text mt-0">Up to 20 items. Check an item when it is complete.</p>
    <input type="hidden" name="checklist" value="<?= esc($checklistJson, 'attr') ?>" :value="JSON.stringify(items)">
    <template x-for="(item, index) in items" :key="index"><div class="d-flex align-items-center gap-2 mb-2">
      <input type="checkbox" class="form-check-input flex-shrink-0" x-model="item.done" :aria-label="'Complete checklist item ' + (index + 1)">
      <input type="text" class="form-control" x-model="item.text" required maxlength="160" :aria-label="'Checklist item ' + (index + 1)">
      <button type="button" class="btn btn-outline-danger btn-sm" @click="items.splice(index, 1)" :aria-label="'Remove checklist item ' + (index + 1)"><i class="bi bi-trash" aria-hidden="true"></i></button>
    </div></template>
    <button type="button" class="btn btn-outline-primary btn-sm" :disabled="items.length >= 20" @click="items.push({ text: '', done: false })"><i class="bi bi-plus me-1" aria-hidden="true"></i>Add checklist item</button>
    <noscript><p class="text-muted small">JavaScript is needed to edit the checklist. Existing items will be preserved.</p></noscript>
  </fieldset>
  <div class="form-actions"><a class="btn btn-outline-secondary" href="<?= esc($cancelUrl) ?>" data-app-modal-close>Cancel</a><button class="btn btn-primary" type="submit"><?= $task === null ? 'Create task' : 'Save changes' ?></button></div>
</form>
<?= $this->endSection() ?>
