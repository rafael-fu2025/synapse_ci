<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$clinic = $unit === 'clinic';
$options = $clinic ? $encounters : $cases;
$initials = strtoupper(mb_substr((string) ($patient['first_name'] ?? ''), 0, 1) . mb_substr((string) ($patient['last_name'] ?? ''), 0, 1));
?>
<div class="rw-modal-form">
  <div class="rw-modal-intro rw-handoff-banner">
    <div class="rw-selected-person-info">
      <span class="rw-avatar" aria-hidden="true"><?= esc($initials) ?></span>
      <div>
        <div class="rw-handoff-name-row">
          <strong><?= esc($patient['last_name'] . ', ' . $patient['first_name']) ?></strong>
          <span class="rw-code rw-code--pill"><?= esc($referral['referral_code']) ?></span>
        </div>
        <small class="text-muted">MIS ID: <?= esc($patient['mis_id']) ?></small>
      </div>
    </div>
    <p class="mb-0 mt-2">This handoff remains attached to the same person. No appointment or queue ticket is created automatically.</p>
  </div>

  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit x-data="{ mode: '<?= $options === [] ? 'create' : 'link' ?>' }">
    <?= csrf_field() ?>

    <div class="rw-modal-section">
      <div class="rw-section-head">
        <span class="rw-section-num" aria-hidden="true">01</span>
        <label for="referralHandoffMode" class="rw-section-title">How do you want to continue?</label>
      </div>

      <div class="rw-mode-wrap">
        <select name="mode" id="referralHandoffMode" class="form-select rw-native-select" data-native-select x-model="mode" tabindex="-1" required>
          <option value="link" <?= $options === [] ? 'disabled' : '' ?>>Link an existing <?= $clinic ? 'open clinic visit' : 'accessible active guidance case' ?></option>
          <option value="create"><?= $clinic ? 'Start a new clinic visit' : 'Open a new guidance case' ?></option>
        </select>

        <div class="rw-mode-choices" role="radiogroup" aria-label="Handoff continuation mode">
          <button
            type="button"
            role="radio"
            class="rw-mode-card"
            :class="{ 'is-selected': mode === 'link', 'is-disabled': <?= $options === [] ? 'true' : 'false' ?> }"
            :aria-checked="mode === 'link' ? 'true' : 'false'"
            <?= $options === [] ? 'disabled' : '' ?>
            @click="mode = 'link'"
          >
            <span class="rw-mode-icon" aria-hidden="true"><i class="bi bi-link-45deg"></i></span>
            <span class="rw-mode-body">
              <span class="rw-mode-title">Link existing <?= $clinic ? 'open clinic visit' : 'active guidance case' ?></span>
              <span class="rw-mode-desc"><?= $options === []
                ? ($clinic ? 'No open clinic visit found for this person.' : 'No accessible active guidance case found.')
                : ($clinic ? 'Attach this referral to an in-progress clinic encounter.' : 'Attach this referral to an open counseling case.') ?></span>
            </span>
            <span class="rw-mode-dot" aria-hidden="true"></span>
          </button>

          <button
            type="button"
            role="radio"
            class="rw-mode-card"
            :class="{ 'is-selected': mode === 'create' }"
            :aria-checked="mode === 'create' ? 'true' : 'false'"
            @click="mode = 'create'"
          >
            <span class="rw-mode-icon" aria-hidden="true"><i class="bi bi-plus-circle"></i></span>
            <span class="rw-mode-body">
              <span class="rw-mode-title"><?= $clinic ? 'Start a new clinic visit' : 'Open a new guidance case' ?></span>
              <span class="rw-mode-desc"><?= $clinic ? 'Begin a new clinical encounter directly from this handoff.' : 'Create a new counseling case linked to this referral.' ?></span>
            </span>
            <span class="rw-mode-dot" aria-hidden="true"></span>
          </button>
        </div>
      </div>
    </div>

    <div class="rw-modal-section" x-show="mode === 'link'">
      <div class="rw-section-head">
        <span class="rw-section-num" aria-hidden="true">02</span>
        <label for="referralHandoffTarget" class="rw-section-title"><?= $clinic ? 'Select open clinic visit' : 'Select guidance case' ?></label>
      </div>

      <select name="target_id" id="referralHandoffTarget" class="form-select" data-native-select :required="mode === 'link'" :disabled="mode !== 'link'">
        <option value="">Choose an existing record</option>
        <?php foreach ($options as $option): ?>
          <option value="<?= (int) $option['id'] ?>"><?= $clinic ? esc('Visit #' . $option['id'] . ' · ' . ($option['started_at'] ?? '')) : esc(($option['case_number'] ?? ('Case #' . $option['id'])) . ' · ' . ($option['title'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($clinic): ?>
      <div class="rw-modal-section" x-show="mode === 'create'">
        <div class="rw-safety-note">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          <p class="mb-0">Start a new visit only when the patient is present and you are ready to begin care. Otherwise, keep the referral under review and continue when appropriate.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="rw-modal-section" x-show="mode === 'create'">
        <div class="rw-section-head">
          <span class="rw-section-num" aria-hidden="true">02</span>
          <span class="rw-section-title">New guidance case details</span>
        </div>
        <div class="row g-3">
          <div class="col-12">
            <label for="referralCaseTitle" class="form-label">Case title</label>
            <input name="title" id="referralCaseTitle" class="form-control" maxlength="255" placeholder="Concise case title for this student" :required="mode === 'create'" :disabled="mode !== 'create'">
          </div>
          <div class="col-12">
            <label for="referralCaseCategory" class="form-label">Category</label>
            <select name="category" id="referralCaseCategory" class="form-select" data-native-select :required="mode === 'create'" :disabled="mode !== 'create'">
              <option value="">Choose a category</option>
              <?php foreach (['personal', 'academic', 'career', 'family', 'behavioral', 'crisis'] as $category): ?><option value="<?= $category ?>"><?= ucfirst($category) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <p class="form-text mt-3 mb-0">Only cases you are authorized to access are available for linking. The shared referral summary is not a copy of the source medical record.</p>
      </div>
    <?php endif; ?>

    <div class="form-actions rw-modal-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary" x-text="mode === 'link' ? 'Link existing record' : '<?= $clinic ? 'Start clinic visit' : 'Open guidance case' ?>'">Continue handoff</button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
