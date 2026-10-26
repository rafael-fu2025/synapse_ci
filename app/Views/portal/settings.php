<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php $health = $health ?? []; ?>
<div class="row g-3">
  <section class="col-12" aria-labelledby="profilePhotoTitle" data-profile-settings data-photo-url="<?= esc(site_url('account/photo')) ?>" data-upload-url="<?= esc(site_url('portal/settings/photo')) ?>" data-remove-url="<?= esc(site_url('portal/settings/photo/remove')) ?>" data-default-avatar="<?= esc($defaultAvatar, 'attr') ?>">
    <div class="card mb-0"><div class="card-body p-3">
      <h2 class="h5" id="profilePhotoTitle">Profile picture</h2>
      <div class="d-flex align-items-center gap-3 mb-3">
        <img src="<?= esc($photoUrl) ?>" alt="Your current profile picture" width="96" height="96" class="rounded-circle object-fit-cover border flex-shrink-0" data-profile-photo-current>
        <p class="small text-muted mb-0">For your personal account only. Your official university ID photo is unchanged.</p>
      </div>
      <form id="profilePhotoForm" action="<?= esc(site_url('portal/settings/photo')) ?>" method="POST" enctype="multipart/form-data" data-profile-photo-form>
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= \App\Services\ProfilePhotoService::MAX_UPLOAD_BYTES ?>">
        <label class="form-label" for="profilePhotoUpload">Choose a new picture</label>
        <input class="form-control" id="profilePhotoUpload" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required aria-describedby="profilePhotoHelp profilePhotoFeedback">
        <p class="form-text mb-2" id="profilePhotoHelp">JPEG, PNG, or WebP, up to <strong>10 MB</strong> before compression.</p>
        <details class="small text-muted mb-3"><summary>How pictures are processed</summary><p class="mb-0 mt-2">Maximum 20 megapixels and 10,000 pixels per side. Pictures fit within 1024 × 1024 pixels and are compressed to WebP at 300 KB or less; detailed images may be reduced further. Metadata, including location data, is removed.</p></details>
        <p class="small text-muted" id="profilePhotoFeedback" data-profile-photo-feedback role="status" aria-live="polite">Your saved picture changes only after a successful upload.</p>
        <img alt="Selected picture preview (not saved)" width="96" height="96" class="rounded-circle object-fit-cover border mb-3" data-profile-photo-preview hidden>
      </form>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <button class="btn btn-primary" type="submit" form="profilePhotoForm"><i class="bi bi-upload me-2" aria-hidden="true"></i>Upload picture</button>
        <form class="m-0" action="<?= esc(site_url('portal/settings/photo/remove')) ?>" method="POST" data-profile-photo-remove <?= !$hasPhoto ? 'hidden' : '' ?> data-confirm="Remove your profile picture and use the initials avatar? Any selected new picture will also be discarded." data-confirm-title="Remove profile picture" data-confirm-label="Remove picture" data-confirm-tone="danger">
        <?= csrf_field() ?>
          <button class="btn btn-danger" type="submit" <?= !$hasPhoto ? 'disabled' : '' ?>>Remove picture</button>
        </form>
      </div>
    </div></div>
  </section>
  <section class="col-12" aria-labelledby="profileEmergencyTitle">
    <div class="card mb-0"><div class="card-body p-3">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <h2 class="h5 mb-0" id="profileEmergencyTitle">Emergency contact</h2>
        <span class="badge bg-secondary">Clinic file</span>
      </div>
      <p class="small text-muted mb-3">In case of a medical emergency on campus, University Clinic staff will contact this person.</p>
      <form id="emergencyContactForm" action="<?= esc(site_url('portal/settings/emergency-contact')) ?>" method="POST" data-emergency-contact-form>
        <?= csrf_field() ?>
        <div class="row g-2 mb-3">
          <div class="col-12 col-md-5">
            <label for="emergencyContactName" class="form-label small fw-semibold">Contact person</label>
            <input type="text"
                   name="emergency_contact_name"
                   id="emergencyContactName"
                   class="form-control form-control-sm"
                   maxlength="150"
                   placeholder="e.g. Maria Santos"
                   value="<?= esc($health['emergency_contact_name'] ?? '') ?>"
                   autocomplete="name">
          </div>
          <div class="col-12 col-md-3">
            <label for="emergencyContactRelationship" class="form-label small fw-semibold">Relationship</label>
            <input type="text"
                   name="emergency_contact_relationship"
                   id="emergencyContactRelationship"
                   class="form-control form-control-sm"
                   maxlength="50"
                   placeholder="e.g. Mother, Spouse"
                   value="<?= esc($health['emergency_contact_relationship'] ?? '') ?>">
          </div>
          <div class="col-12 col-md-4">
            <label for="emergencyContactPhone" class="form-label small fw-semibold">Emergency phone</label>
            <input type="tel"
                   name="emergency_contact_phone"
                   id="emergencyContactPhone"
                   class="form-control form-control-sm"
                   maxlength="50"
                   placeholder="e.g. 0917 123 4567"
                   value="<?= esc($health['emergency_contact_phone'] ?? '') ?>"
                   autocomplete="tel">
          </div>
        </div>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Save emergency contact</button>
          <p class="small text-muted mb-0" data-emergency-contact-feedback role="status" aria-live="polite"></p>
        </div>
      </form>
    </div></div>
  </section>
  <section class="col-12" aria-labelledby="profileAccountTitle">
    <div class="card mb-0"><div class="card-body p-3">
      <h2 class="h5" id="profileAccountTitle">Your university profile</h2>
      <p class="small text-muted">These details are managed by MIS. Contact the university office for corrections; uploading a picture does not change your identity or access.</p>
      <dl class="row mb-0">
        <dt class="col-sm-4">Name</dt><dd class="col-sm-8 text-break"><?= esc(trim(($person['first_name'] ?? '') . ' ' . ($person['middle_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))) ?></dd>
        <dt class="col-sm-4">University ID</dt><dd class="col-sm-8 text-break"><?= esc($person['mis_id'] ?? 'Not recorded') ?></dd>
        <dt class="col-sm-4">Profile type</dt><dd class="col-sm-8"><?= esc(ucfirst($person['kind'])) ?></dd>
        <dt class="col-sm-4">University email</dt><dd class="col-sm-8 text-break"><?= esc($person['email'] ?: ($actor['email'] ?? 'Not recorded')) ?></dd>
      </dl>

    </div></div>
  </section>
</div>
<div class="form-actions"><button type="button" class="btn btn-secondary" data-app-modal-close>Close</button></div>
<?= $this->endSection() ?>
