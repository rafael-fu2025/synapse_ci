<?php
$initialTime = $value ?? '';
$initialDisplay = '';
if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $initialTime, $parts)) {
    $hour = (int) $parts[1];
    $initialDisplay = sprintf('%02d:%s %s', $hour % 12 ?: 12, $parts[2], $hour >= 12 ? 'PM' : 'AM');
}
?>
<input type="text" id="<?= esc($id, 'attr') ?>" class="form-control" data-timepicker
       data-target="#<?= esc($id . 'Value', 'attr') ?>" value="<?= esc($initialDisplay, 'attr') ?>" placeholder="hh:mm AM/PM"
       aria-label="<?= esc($label, 'attr') ?>"<?= !empty($describedBy) ? ' aria-describedby="' . esc($describedBy, 'attr') . '"' : '' ?> autocomplete="off" required>
<input type="hidden" id="<?= esc($id . 'Value', 'attr') ?>" name="<?= esc($name, 'attr') ?>" value="<?= esc($initialTime, 'attr') ?>">
