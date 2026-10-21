<?php
$targetSummary = [];
foreach (['target_year_levels' => 'Years', 'target_student_categories' => 'Categories'] as $field => $heading) {
    $value = $targetRecord[$field] ?? null;
    if ($value === null) { continue; }
    $values = is_string($value) ? json_decode($value, true) : $value;
    $labels = [];
    foreach (is_array($values) ? $values : [] as $item) {
        if ($field === 'target_year_levels' && in_array($item, [1, 2, 3, 4, 5, 6, '1', '2', '3', '4', '5', '6'], true)) {
            $labels[] = (string) $item;
        } elseif ($field === 'target_student_categories' && in_array($item, ['new', 'continuing', 'transferee', 'graduating'], true)) {
            $labels[] = ucfirst($item);
        }
    }
    if ($labels !== []) { $targetSummary[] = $heading . ': ' . implode(' or ', $labels); }
}
?>
<?php if (($targetRecord['audience'] ?? '') === 'students'): ?>
<small class="d-block text-muted"><?= esc($targetSummary === [] ? 'All students (unrestricted)' : implode(' AND ', $targetSummary)) ?></small>
<?php endif; ?>
