<?php
$selectedTargets = static function (string $field) use ($targetRecord): array {
    $value = $targetRecord[$field] ?? null;
    $decoded = is_string($value) ? json_decode($value, true) : $value;
    return is_array($decoded) ? array_map('strval', $decoded) : [];
};
$targetGroups = [
    'target_year_levels' => ['Year levels', array_combine(range(1, 6), array_map(static fn ($year) => 'Year ' . $year, range(1, 6)))],
    'target_student_categories' => ['Student categories', ['new' => 'New', 'continuing' => 'Continuing', 'transferee' => 'Transferee', 'graduating' => 'Graduating']],
];
?>
<div class="border rounded p-3 mb-3" role="group" aria-label="Student targeting">
  <h6>Student targeting (optional)</h6>
  <p class="form-text mb-2">Available only for Students. Leave both groups empty for unrestricted student access. Selections use OR within each group and AND between groups. An empty group adds no restriction. Students with unknown year or status do not match a selected filter. Graduating is independent of enrollment category and may overlap New, Continuing or Transferee.</p>
  <?php foreach ($targetGroups as $field => [$heading, $choices]): $selected = $selectedTargets($field); ?>
    <div class="mb-2" role="group" aria-label="<?= esc($heading) ?>">
      <span class="d-block small fw-semibold mb-1"><?= esc($heading) ?></span>
      <div class="d-flex flex-wrap gap-3">
      <?php foreach ($choices as $value => $label): $id = $targetPrefix . '_' . $field . '_' . $value; ?>
        <div class="form-check"><input type="checkbox" class="form-check-input" id="<?= esc($id) ?>" name="<?= esc($field) ?>[]" value="<?= esc((string) $value) ?>" <?= in_array((string) $value, $selected, true) ? 'checked' : '' ?> <?= ($targetRecord['audience'] ?? 'all') !== 'students' ? 'disabled' : '' ?> :disabled="audience !== 'students'"><label class="form-check-label" for="<?= esc($id) ?>"><?= esc($label) ?></label></div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
