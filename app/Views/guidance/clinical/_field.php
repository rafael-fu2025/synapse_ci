<?php
$schemaMeta = is_array($schemaFields[$fieldName] ?? null) ? $schemaFields[$fieldName] : [];
$type = (string) ($meta['type'] ?? $schemaMeta['type'] ?? 'string');
$label = (string) ($meta['label'] ?? $schemaMeta['label'] ?? ucwords(str_replace('_', ' ', (string) $fieldName)));
$required = (bool) ($meta['required'] ?? $schemaMeta['required'] ?? false);
$encrypted = (bool) ($meta['encrypted'] ?? $schemaMeta['encrypted'] ?? false);
$colClass = (string) ($meta['col'] ?? 'col-12');
$val = $formData[$fieldName] ?? ($schemaMeta['default'] ?? '');
$disabledAttr = $isEditable ? '' : 'disabled';
$reqAttr = $required ? 'required' : '';
$isInterview = (bool) ($isInterview ?? false);
?>

<?php if ($type === 'object'): ?>
  <?php
  $subProps = $meta['properties'] ?? $schemaMeta['properties'] ?? [];
  $objVal = is_array($val) ? $val : [];
  ?>
  <div class="col-12">
    <div class="<?= $isInterview ? 'giw-object' : 'border rounded p-3 bg-light-subtle' ?>">
      <div class="<?= $isInterview ? 'giw-object-title' : 'fw-semibold small text-uppercase text-muted mb-2' ?>">
        <?= esc($label) ?>
        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
      </div>
      <div class="row g-2">
        <?php foreach ($subProps as $subKey => $subMeta): ?>
          <?php
          $subLabel = (string) ($subMeta['label'] ?? ucwords(str_replace('_', ' ', (string) $subKey)));
          $subType = (string) ($subMeta['type'] ?? 'string');
          $subVal = $objVal[$subKey] ?? '';
          $inputId = 'field_' . $fieldName . '_' . $subKey;
          ?>
          <div class="col-md-4">
            <label for="<?= esc($inputId) ?>" class="form-label small fw-semibold"><?= esc($subLabel) ?></label>
            <?php if ($subType === 'enum'): ?>
              <select id="<?= esc($inputId) ?>" name="fields[<?= esc($fieldName) ?>][<?= esc($subKey) ?>]" class="form-select form-select-sm" <?= $disabledAttr ?>>
                <?php foreach (($subMeta['options'] ?? []) as $opt): ?>
                  <option value="<?= esc((string) $opt) ?>" <?= ((string) $subVal === (string) $opt) ? 'selected' : '' ?>>
                    <?= $opt === '' ? '-- Not Specified --' : esc(ucwords(str_replace('_', ' ', (string) $opt))) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($subType === 'integer'): ?>
              <input type="number" id="<?= esc($inputId) ?>" name="fields[<?= esc($fieldName) ?>][<?= esc($subKey) ?>]" class="form-control form-control-sm" value="<?= esc((string) $subVal) ?>" <?= $disabledAttr ?>>
            <?php else: ?>
              <input type="text" id="<?= esc($inputId) ?>" name="fields[<?= esc($fieldName) ?>][<?= esc($subKey) ?>]" class="form-control form-control-sm" value="<?= esc((string) $subVal) ?>" <?= $disabledAttr ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

<?php elseif ($type === 'boolean'): ?>
  <div class="col-12">
    <div class="form-check <?= $isInterview ? 'giw-consent' : '' ?>">
      <input type="hidden" name="fields[<?= esc($fieldName) ?>]" value="0">
      <input class="form-check-input" type="checkbox" id="field_<?= esc($fieldName) ?>"
             name="fields[<?= esc($fieldName) ?>]" value="1"
             <?= ! empty($val) ? 'checked' : '' ?> <?= $disabledAttr ?> <?= $reqAttr ?>>
      <label class="form-check-label fw-semibold" for="field_<?= esc($fieldName) ?>">
        <?= esc($label) ?>
        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
      </label>
    </div>
  </div>

<?php elseif ($type === 'textarea'): ?>
  <div class="col-12">
    <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold <?= $isInterview ? 'giw-narrative-label' : '' ?>">
      <span><?= esc($label) ?><?php if ($required): ?> <span class="text-danger">*</span><?php endif; ?></span>
      <?php if ($encrypted): ?>
        <span class="<?= $isInterview ? 'ce-tag giw-encrypted' : 'badge bg-light text-success border ms-1' ?>"><i class="bi bi-lock-fill" aria-hidden="true"></i> Encrypted</span>
      <?php endif; ?>
    </label>
    <textarea id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]" rows="<?= $isInterview ? 4 : 3 ?>"
              class="form-control" <?= $disabledAttr ?> <?= $reqAttr ?>><?= esc((string) $val) ?></textarea>
  </div>

<?php elseif ($type === 'enum'): ?>
  <?php $options = $meta['options'] ?? $schemaMeta['options'] ?? []; ?>
  <div class="<?= esc($colClass) ?>">
    <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold">
      <?= esc($label) ?><?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
    </label>
    <select id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]" class="form-select" <?= $disabledAttr ?> <?= $reqAttr ?>>
      <?php foreach ($options as $opt): ?>
        <option value="<?= esc((string) $opt) ?>" <?= ((string) $val === (string) $opt) ? 'selected' : '' ?>><?= esc(ucwords(str_replace('_', ' ', (string) $opt))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

<?php elseif ($type === 'integer'): ?>
  <div class="<?= esc($colClass) ?>">
    <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold">
      <?= esc($label) ?><?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
    </label>
    <input type="number" min="1" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]"
           class="form-control" value="<?= esc((string) $val) ?>" <?= $disabledAttr ?> <?= $reqAttr ?>>
  </div>

<?php elseif (in_array($type, ['date', 'time', 'datetime'], true)): ?>
  <?php
  $picker = match ($type) { 'date' => 'datepicker', 'time' => 'timepicker', default => 'datetimepicker' };
  $placeholder = match ($type) { 'date' => 'DD/MM/YYYY', 'time' => 'hh:mm A', default => 'DD/MM/YYYY hh:mm A' };
  $pickerVal = match ($type) { 'date' => substr((string) $val, 0, 10), 'time' => substr((string) $val, 0, 5), default => (string) $val };
  if ($type === 'datetime' && $pickerVal !== '' && strpos($pickerVal, ' ') !== false && strpos($pickerVal, 'T') === false) {
      $pickerVal = str_replace(' ', 'T', substr($pickerVal, 0, 16));
  }
  ?>
  <div class="<?= esc($colClass) ?>">
    <label for="field_<?= esc($fieldName) ?>_display" class="form-label fw-semibold">
      <?= esc($label) ?><?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
    </label>
    <input type="text" id="field_<?= esc($fieldName) ?>_display"
           class="form-control" data-<?= $picker ?> data-target="#field_<?= esc($fieldName) ?>"
           placeholder="<?= esc($placeholder) ?>" autocomplete="off" <?= $disabledAttr ?> <?= $reqAttr ?>>
    <input type="hidden" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]" value="<?= esc($pickerVal) ?>">
  </div>

<?php else: ?>
  <div class="<?= esc($colClass) ?>">
    <label for="field_<?= esc($fieldName) ?>" class="form-label fw-semibold">
      <?= esc($label) ?><?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
    </label>
    <input type="text" id="field_<?= esc($fieldName) ?>" name="fields[<?= esc($fieldName) ?>]"
           class="form-control" value="<?= esc((string) $val) ?>" <?= $disabledAttr ?> <?= $reqAttr ?>>
  </div>
<?php endif; ?>
