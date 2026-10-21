<?php
/**
 * Shared carousel slide form (create + edit).
 *
 * @var array<string, mixed>|null $slide  Existing slide row for edit mode.
 */
$slide     = $slide ?? null;
$isEdit    = $slide !== null;
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

  <!-- Light image dropzone -->
  <div class="mb-3">
    <label class="form-label fw-semibold small text-uppercase text-muted">Light Mode Image <span class="text-danger">*</span></label>
    <div class="upload-dropzone" data-input="light_file" data-current="<?= esc($lightSrc) ?>">
      <input type="file" name="light_file" id="light_file" accept="image/*" class="d-none">
      <div class="dz-body">
        <i class="bi bi-cloud-arrow-up fs-1 d-block mb-2 text-primary"></i>
        <div class="fw-semibold">Drag &amp; drop or <span class="upload-browse-link">browse</span></div>
        <div class="text-muted small">PNG, JPG, WEBP, GIF &middot; no application size cap (server limits apply)</div>
      </div>
      <div class="dz-preview d-none"></div>
    </div>
    <label class="form-label small mt-2" for="light_path">Or use an existing light image path</label>
    <input type="text" name="light_path" id="light_path" class="form-control" maxlength="255" placeholder="assets/img/brand/fuel-bg.jpg" aria-describedby="lightPathHelp">
    <div class="form-text" id="lightPathHelp">Choose an upload or an existing local image in <code>assets/img/brand/</code> or <code>uploads/carousel/</code>. An upload takes precedence over the path. <?= $isEdit ? 'Leave both blank to keep the current light image.' : 'A light image is required; uploading is optional when a path is supplied.' ?></div>
  </div>

  <!-- Dark image dropzone -->
  <div class="mb-3">
    <label class="form-label fw-semibold small text-uppercase text-muted">Dark Mode Image <span class="text-muted fw-normal">(optional)</span></label>
    <div class="upload-dropzone" data-input="dark_file" data-current="<?= esc($darkSrc) ?>">
      <input type="file" name="dark_file" id="dark_file" accept="image/*" class="d-none">
      <div class="dz-body">
        <i class="bi bi-moon-stars fs-1 d-block mb-2 text-primary"></i>
        <div class="fw-semibold">Drag &amp; drop or <span class="upload-browse-link">browse</span></div>
        <div class="text-muted small">Shown when dark mode is active</div>
      </div>
      <div class="dz-preview d-none"></div>
    </div>
    <label class="form-label small mt-2" for="dark_path">Or use an existing dark image path</label>
    <input type="text" name="dark_path" id="dark_path" class="form-control" maxlength="255" placeholder="assets/img/brand/FoundationU-Dark.png" aria-describedby="darkPathHelp">
    <div class="form-text" id="darkPathHelp">The same local directories apply. An upload takes precedence. <?= $isEdit ? 'Leave both blank to keep the current dark image; use Remove dark image to clear it. Removal overrides both upload and path.' : 'Leave blank to use the light image in dark mode.' ?></div>
    <?php if ($isEdit): ?>
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
