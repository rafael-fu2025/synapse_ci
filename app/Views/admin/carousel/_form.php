<?php
/**
 * Shared carousel slide form (create + edit).
 *
 * @var array<string, mixed>|null $slide  Existing slide row for edit mode.
 */
$slide     = $slide ?? null;
$isEdit    = $slide !== null;
$lockedImage = $lockedImage ?? false;
$formUrl   = $isEdit ? site_url('/admin/carousel/' . $slide['id'] . '/update') : site_url('/admin/carousel/store');
$fitMode   = $isEdit ? (string) ($slide['fit_mode'] ?? 'cover') : 'cover';
$lightSrc  = $isEdit ? base_url((string) $slide['light_image']) : '';
$darkSrc   = $isEdit && ! empty($slide['dark_image']) ? base_url((string) $slide['dark_image']) : '';
$csrfToken = csrf_hash();
?>

<form action="<?= $formUrl ?>" method="POST" enctype="multipart/form-data" id="slideForm" data-image-base-url="<?= esc(base_url('/'), 'attr') ?>">
  <?= csrf_field() ?>

  <div class="mb-3">
    <label class="form-label fw-semibold small text-uppercase text-muted">Title <span class="text-muted fw-normal">(optional)</span></label>
    <input type="text" name="title" class="form-control" maxlength="150" placeholder="e.g. University Health Services" value="<?= esc($slide['title'] ?? '') ?>">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small text-uppercase text-muted">Subtitle <span class="text-muted fw-normal">(optional)</span></label>
    <input type="text" name="subtitle" class="form-control" maxlength="255" placeholder="e.g. Accessible Campus Medical & Wellness Care" value="<?= esc($slide['subtitle'] ?? '') ?>">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small text-uppercase text-muted">Display Fit</label>
    <select name="fit_mode" class="form-select">
      <option value="cover" <?= $fitMode === 'cover' ? 'selected' : '' ?>>Full Cover (Clean, No Blur)</option>
      <option value="contain_blur" <?= $fitMode === 'contain_blur' ? 'selected' : '' ?>>Contained with Blurred Background</option>
    </select>
    <div class="form-text">Use <strong>Contained with Blur</strong> for logos or images that must not be cropped.</div>
  </div>

  <?php if ($lockedImage): ?>
    <div class="alert alert-dark d-flex align-items-start gap-2 mb-3" role="alert">
      <i class="bi bi-lock-fill mt-1" aria-hidden="true"></i>
      <div>
        <strong>Permanent default image.</strong>
        This slide carries the permanent Foundation University light and dark
        artwork. It is
        locked: it cannot be edited, replaced or removed by any role. A
        superadmin may delete the slide itself.
      </div>
    </div>
  <?php endif; ?>

  <!-- Light image dropzone -->
  <div class="mb-3">
    <label class="form-label fw-semibold small text-uppercase text-muted">
      Light Mode Image
      <?php if (! $isEdit): ?><span class="text-danger">*</span><?php endif; ?>
    </label>
    <div class="upload-dropzone" data-input="light_file" data-current="<?= esc($lightSrc) ?>">
      <input type="file" name="light_file" id="light_file" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none"<?= $lockedImage ? ' disabled' : '' ?>>
      <div class="dz-body">
        <i class="bi bi-cloud-arrow-up fs-1 d-block mb-2 text-primary"></i>
        <div class="fw-semibold">Drag &amp; drop or <span class="upload-browse-link">browse</span></div>
        <div class="text-muted small">PNG, JPG, WEBP, GIF &middot; no application size cap (server limits apply)</div>
      </div>
      <div class="dz-preview d-none"></div>
    </div>
    <div class="form-text">
      <?= $isEdit
          ? 'The current light image is kept unless you choose a replacement.'
          : 'Required. Upload the image shown when light mode is active.' ?>
    </div>
  </div>

  <!-- Dark image dropzone -->
  <div class="mb-3">
    <label class="form-label fw-semibold small text-uppercase text-muted">Dark Mode Image <span class="text-muted fw-normal">(optional)</span></label>
    <div class="upload-dropzone" data-input="dark_file" data-current="<?= esc($darkSrc) ?>">
      <input type="file" name="dark_file" id="dark_file" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none"<?= $lockedImage ? ' disabled' : '' ?>>
      <div class="dz-body">
        <i class="bi bi-moon-stars fs-1 d-block mb-2 text-primary"></i>
        <div class="fw-semibold">Drag &amp; drop or <span class="upload-browse-link">browse</span></div>
        <div class="text-muted small">PNG, JPG, WEBP, GIF</div>
      </div>
      <div class="dz-preview d-none"></div>
    </div>
    <div class="form-text">
      <?= $isEdit
          ? 'The current dark image is kept unless replaced or removed. Without one, the light image is used in dark mode.'
          : 'If omitted, the light image is also used in dark mode.' ?>
    </div>
    <?php if ($isEdit && ! $lockedImage): ?>
      <div class="form-check mt-2 <?= $darkSrc === '' ? 'd-none' : '' ?>" id="removeDarkWrap">
        <input class="form-check-input" type="checkbox" value="1" name="remove_dark" id="remove_dark">
        <label class="form-check-label small text-muted" for="remove_dark">Remove dark image</label>
      </div>
    <?php endif; ?>
  </div>

  <!-- Live upload progress -->
  <div class="carousel-upload-progress d-none mb-3">
    <div class="d-flex justify-content-between small mb-1">
      <span class="dz-status fw-semibold" role="status" aria-live="polite">Uploading&hellip;</span>
      <span class="dz-pct text-muted">0%</span>
    </div>
    <div class="progress" style="height: 6px;">
      <div class="progress-bar bg-primary" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
  </div>

  <div class="alert d-none mb-3" id="slideFormAlert" role="alert"></div>

  <div class="form-actions">
    <a href="<?= esc($cancelUrl ?? site_url('/admin/carousel')) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
    <button type="submit" class="btn btn-primary" id="slideSubmitBtn">
      <i class="bi <?= $isEdit ? 'bi-check2-circle' : 'bi-plus-circle' ?> me-1"></i>
      <?= $isEdit ? 'Save Changes' : 'Save Slide' ?>
    </button>
  </div>
</form>
