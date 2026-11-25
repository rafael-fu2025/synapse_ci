<?= $this->extend('layouts/app') ?>
<?= $this->section('page_actions') ?>
<a class="portal-back-btn" href="<?= esc(site_url('portal')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to My Health Portal</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$health = $health ?? [];
$fullName = trim(($person['first_name'] ?? '') . ' ' . ($person['middle_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = (string) ($actor['name'] ?? 'University Member');
}
$misId = (string) ($person['mis_id'] ?? 'Not recorded');
$kindLabel = ucfirst((string) ($person['kind'] ?? 'student'));
$email = ($person['email'] ?? '') !== '' ? (string) $person['email'] : (string) ($actor['email'] ?? 'Not recorded');
?>
<div class="ps-shell" data-profile-settings data-photo-url="<?= esc(site_url('account/photo')) ?>" data-upload-url="<?= esc(site_url('portal/settings/photo')) ?>" data-remove-url="<?= esc(site_url('portal/settings/photo/remove')) ?>" data-emergency-contact-url="<?= esc(site_url('portal/settings/emergency-contact')) ?>" data-default-avatar="<?= esc($defaultAvatar, 'attr') ?>">
  <div class="ps-sheet">

    <!-- Band 1: Profile Picture -->
    <section class="ps-band" aria-labelledby="profilePhotoTitle">
      <div class="ps-band-intro">
        <div class="ps-band-heading-row">
          <h2 class="ps-band-title" id="profilePhotoTitle">Profile picture</h2>
        </div>
        <p class="ps-band-desc">For your personal account only. Your official university ID photo is unchanged.</p>
      </div>

      <div class="ps-band-content">
        <form id="profilePhotoForm" class="ps-photo-form" action="<?= esc(site_url('portal/settings/photo')) ?>" method="POST" enctype="multipart/form-data" data-profile-photo-form>
          <?= csrf_field() ?>
          <input type="hidden" name="MAX_FILE_SIZE" value="<?= \App\Services\ProfilePhotoService::MAX_UPLOAD_BYTES ?>">

          <div class="ps-photo-row">
            <div class="ps-avatar-pair">
              <figure class="ps-avatar-figure">
                <img src="<?= esc($photoUrl) ?>" alt="Your current profile picture" width="96" height="96" class="ps-avatar-img" data-profile-photo-current>
                <figcaption class="ps-avatar-label">Current</figcaption>
              </figure>
              <figure class="ps-avatar-figure ps-avatar-preview-figure">
                <span class="ps-avatar-preview-frame">
                  <img alt="Selected picture preview (not saved)" width="96" height="96" class="ps-avatar-img ps-avatar-img-preview" data-profile-photo-preview hidden>
                </span>
                <figcaption class="ps-avatar-label ps-avatar-label-new">New preview</figcaption>
              </figure>
            </div>

            <div class="ps-photo-controls">
              <label class="ps-field-label" for="profilePhotoUpload">Choose a new picture</label>
              <input class="form-control ps-file-input" id="profilePhotoUpload" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required aria-describedby="profilePhotoHelp profilePhotoFeedback">
              <p class="ps-help-text" id="profilePhotoHelp">JPEG, PNG, or WebP, up to <strong>10 MB</strong> before compression.</p>

              <details class="ps-disclosure">
                <summary>How pictures are processed</summary>
                <p>Maximum 20 megapixels and 10,000 pixels per side. Pictures fit within 1024 &times; 1024 pixels and are compressed to WebP at 300 KB or less; detailed images may be reduced further. Metadata, including location data, is removed.</p>
              </details>

              <p class="small text-muted" id="profilePhotoFeedback" data-profile-photo-feedback role="status" aria-live="polite">Your saved picture changes only after a successful upload.</p>
            </div>
          </div>

          <div class="ps-adjuster" data-profile-photo-adjuster hidden>
            <div class="ps-adjuster-head">
              <div>
                <h3 class="ps-adjuster-title">Adjust profile photo</h3>
                <p class="ps-adjuster-hint">Drag or use arrow keys to reposition; zoom to frame your 1:1 avatar.</p>
              </div>
              <button class="btn btn-outline-secondary btn-sm ps-adjuster-reset" type="button" data-profile-photo-adjust-reset>Reset</button>
            </div>
            <div class="ps-adjuster-body">
              <div class="ps-adjuster-stage" tabindex="0" role="group" aria-label="Profile picture crop area. Drag or use arrow keys to reposition." data-profile-photo-stage>
                <img class="ps-adjuster-img" alt="" draggable="false" data-profile-photo-stage-img>
                <div class="ps-adjuster-mask" aria-hidden="true"></div>
                <div class="ps-adjuster-grid" aria-hidden="true"></div>
              </div>
              <div class="ps-adjuster-controls">
                <div class="ps-zoom-row">
                  <button class="btn btn-outline-secondary btn-sm ps-zoom-btn" type="button" data-profile-photo-zoom-out aria-label="Zoom out"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                  <label class="visually-hidden" for="profilePhotoZoom">Zoom profile picture</label>
                  <input id="profilePhotoZoom" class="ps-zoom-range" type="range" min="1" max="3" step="0.01" value="1" data-profile-photo-zoom>
                  <button class="btn btn-outline-secondary btn-sm ps-zoom-btn" type="button" data-profile-photo-zoom-in aria-label="Zoom in"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                  <span class="ps-zoom-value" data-profile-photo-zoom-value aria-live="off">100%</span>
                </div>
              </div>
            </div>
          </div>
        </form>

        <div class="ps-actions">
          <button class="btn btn-primary ps-btn" type="submit" form="profilePhotoForm">
            <i class="bi bi-upload" aria-hidden="true"></i>Upload picture
          </button>
          <button class="btn btn-outline-secondary ps-btn" type="reset" form="profilePhotoForm" data-profile-photo-clear hidden>
            Clear selection
          </button>
          <form class="m-0 ps-remove-form" action="<?= esc(site_url('portal/settings/photo/remove')) ?>" method="POST" data-profile-photo-remove <?= !$hasPhoto ? 'hidden' : '' ?> data-confirm="Remove your profile picture and use the initials avatar? Any selected new picture will also be discarded." data-confirm-title="Remove profile picture" data-confirm-label="Remove picture" data-confirm-tone="danger">
            <?= csrf_field() ?>
            <button class="btn btn-danger ps-btn" type="submit" <?= !$hasPhoto ? 'disabled' : '' ?>>Remove picture</button>
          </form>
        </div>
      </div>
    </section>

    <!-- Band 2: Official University Profile (Strictly Read-Only) -->
    <section class="ps-band" aria-labelledby="profileAccountTitle">
      <div class="ps-band-intro">
        <div class="ps-band-heading-row">
          <h2 class="ps-band-title" id="profileAccountTitle">Your university profile</h2>
          <span class="ps-band-scope">Managed by MIS</span>
        </div>
        <p class="ps-band-desc">These details are managed by MIS. Contact the university office for corrections; uploading a picture does not change your identity or access.</p>
      </div>

      <div class="ps-band-content">
        <dl class="ps-ledger">
          <div class="ps-ledger-row">
            <dt>Name</dt>
            <dd class="text-break"><?= esc($fullName) ?></dd>
          </div>
          <div class="ps-ledger-row">
            <dt>University ID</dt>
            <dd class="ps-mono text-break"><?= esc($misId) ?></dd>
          </div>
          <div class="ps-ledger-row">
            <dt>Profile type</dt>
            <dd><?= esc($kindLabel) ?></dd>
          </div>
          <div class="ps-ledger-row">
            <dt>University email</dt>
            <dd class="text-break"><?= esc($email) ?></dd>
          </div>
        </dl>
      </div>
    </section>

    <!-- Band 3: Emergency Contact -->
    <section class="ps-band" aria-labelledby="profileEmergencyTitle">
      <div class="ps-band-intro">
        <div class="ps-band-heading-row">
          <h2 class="ps-band-title" id="profileEmergencyTitle">Emergency contact</h2>
          <span class="ps-band-scope">Clinic file</span>
        </div>
        <p class="ps-band-desc">In case of a medical emergency on campus, University Clinic staff will contact this person.</p>
      </div>

      <div class="ps-band-content">
        <form id="emergencyContactForm" action="<?= esc(site_url('portal/settings/emergency-contact')) ?>" method="POST" data-emergency-contact-form>
          <?= csrf_field() ?>
          <div class="ps-form-grid ps-grid-emergency">
            <div class="ps-field">
              <label for="emergencyContactName" class="ps-field-label">Contact person</label>
              <input type="text"
                     name="emergency_contact_name"
                     id="emergencyContactName"
                     class="form-control ps-input"
                     maxlength="150"
                     placeholder="e.g. Maria Santos"
                     value="<?= esc($health['emergency_contact_name'] ?? '') ?>"
                     autocomplete="name">
            </div>

            <div class="ps-field">
              <label for="emergencyContactRelationship" class="ps-field-label">Relationship</label>
              <input type="text"
                     name="emergency_contact_relationship"
                     id="emergencyContactRelationship"
                     class="form-control ps-input"
                     maxlength="50"
                     placeholder="e.g. Mother, Spouse"
                     value="<?= esc($health['emergency_contact_relationship'] ?? '') ?>">
            </div>

            <div class="ps-field">
              <label for="emergencyContactPhone" class="ps-field-label">Emergency phone</label>
              <input type="tel"
                     name="emergency_contact_phone"
                     id="emergencyContactPhone"
                     class="form-control ps-input"
                     maxlength="50"
                     placeholder="e.g. 0917 123 4567"
                     value="<?= esc($health['emergency_contact_phone'] ?? '') ?>"
                     autocomplete="tel">
            </div>
          </div>

          <div class="ps-form-actions">
            <button class="btn btn-primary ps-btn" type="submit">
              <i class="bi bi-save" aria-hidden="true"></i>Save emergency contact
            </button>
            <p class="small text-muted mb-0" data-emergency-contact-feedback role="status" aria-live="polite" hidden></p>
          </div>
        </form>
      </div>
    </section>

    <!-- Band 4: Contact Number & Current Residence -->
    <section class="ps-band" aria-labelledby="profileCurrentContactTitle">
      <div class="ps-band-intro">
        <div class="ps-band-heading-row">
          <h2 class="ps-band-title" id="profileCurrentContactTitle">Contact number &amp; current residence</h2>
          <span class="ps-band-scope">Clinic &amp; Guidance directory</span>
        </div>
        <p class="ps-band-desc">Your official name, ID number, program, and enrollment status remain synchronized from MIS. Use this section only to keep your current contact phone and boarding house / current address up to date for Clinic and Guidance follow-ups.</p>
      </div>

      <div class="ps-band-content">
        <form action="<?= esc(site_url('portal/settings/contact-info')) ?>" method="POST" data-background-submit>
          <?= csrf_field() ?>
          <div class="ps-form-grid ps-grid-directory">
            <div class="ps-field">
              <label class="ps-field-label" for="contactPhone">Current contact number</label>
              <input class="form-control ps-input" id="contactPhone" name="phone" type="text" maxlength="30"
                     value="<?= esc((string) ($person['phone'] ?? ''), 'attr') ?>" placeholder="e.g. 0917 123 4567" autocomplete="tel">
            </div>

            <div class="ps-field">
              <label class="ps-field-label" for="contactAddress">Current / boarding house address</label>
              <input class="form-control ps-input" id="contactAddress" name="address" type="text" maxlength="500"
                     value="<?= esc((string) ($person['address'] ?? ''), 'attr') ?>" placeholder="House / Boarding House / Street / Barangay / City" autocomplete="street-address">
            </div>
          </div>

          <div class="ps-form-actions">
            <button class="btn btn-outline-primary ps-btn" type="submit">
              <i class="bi bi-check2-circle" aria-hidden="true"></i>Update current contact &amp; residence
            </button>
          </div>
        </form>
      </div>
    </section>

  </div>
</div>
<?= $this->endSection() ?>
