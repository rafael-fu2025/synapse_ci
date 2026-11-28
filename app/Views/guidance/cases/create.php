<?= $this->extend('layouts/modal_form') ?>

<?= $this->section('content') ?>
<div class="guidance-ui gc-modal-scope gc-case-modal"
     x-data="newCaseForm({ patientUrl: <?= esc(json_encode(site_url('/api/patients/search'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?> })">
  <div class="gc-case-intro">
    <p class="gc-case-intro-heading mb-1">
      <i class="bi bi-shield-lock" aria-hidden="true"></i>
      <span>Confidential Counseling Record</span>
    </p>
    <p class="mb-0">Creates an audited Guidance case workspace for a Foundation University student or employee. Detailed session notes are encrypted inside the workspace.</p>
  </div>

  <form action="<?= site_url('/guidance/cases/store') ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="person_id" :value="selectedPerson?.id">

    <!-- 01. Client Identification -->
    <div class="gc-case-section">
      <div class="gc-section-head gc-section-head--split">
        <div class="gc-section-head-left">
          <span class="gc-section-num" aria-hidden="true">01</span>
          <label for="newCaseClientSearch" class="gc-section-title">Client identification</label>
        </div>
        <span class="gc-section-badge">Student or Employee</span>
      </div>

      <div class="syn-person-lookup" data-person-lookup x-show="!selectedPerson">
        <div class="gc-search-input" data-person-lookup-anchor>
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search"
                 id="newCaseClientSearch"
                 class="form-control"
                 placeholder="Search Student or Employee ID / Name..."
                 maxlength="150"
                 autocomplete="off"
                 x-model="searchTerm"
                 @input="patientInput()"
                 @input.debounce.300ms="searchPatient()">
        </div>

        <!-- Floating search results popover -->
        <div class="syn-person-lookup-menu"
             data-person-lookup-menu
             x-show="searching || searchError || (searchComplete && !searching && results.length === 0) || results.length > 0"
             style="display:none;">
          <div role="status"
               aria-live="polite"
               class="syn-person-lookup-status m-0"
               x-show="searching || searchError || (searchComplete && !searching && !searchError && results.length === 0)"
               x-cloak>
            <span x-show="searching">Searching local directory...</span>
            <span x-show="searchComplete && !searching && !searchError && results.length === 0">No matching local records.</span>
            <span x-show="searchError" class="text-danger">
              <span x-text="searchError"></span>
              <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="searchPatient()">Retry</button>
            </span>
          </div>
          <div x-show="results.length > 0" class="list-group syn-person-lookup-list gc-search-results">
            <template x-for="p in results" :key="p.id">
              <button type="button"
                      class="list-group-item list-group-item-action gc-search-option"
                      @click="selectPerson(p)">
                <span class="gc-search-option-identity">
                  <span class="gc-option-avatar" aria-hidden="true">
                    <template x-if="p.photo_url"><img class="syn-avatar-img" :src="p.photo_url" alt=""></template>
                    <span x-show="!p.photo_url" x-text="clientInitials(p)"></span>
                  </span>
                  <span class="gc-search-option-copy">
                    <strong class="d-block" x-text="p.last_name + ', ' + p.first_name + (p.middle_name ? ' ' + p.middle_name : '')"></strong>
                    <small x-text="(p.kind ? p.kind.toUpperCase() + ' · ' : '') + 'ID: ' + p.mis_id"></small>
                  </span>
                </span>
                <span class="syn-person-lookup-select gc-select-chip">Select</span>
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Selected client card -->
      <div x-show.important="selectedPerson" class="card gc-selected-person-card" style="display: none;">
        <div class="gc-selected-person-row">
          <div class="gc-selected-person-info">
            <span class="gc-client-avatar" aria-hidden="true">
              <template x-if="selectedPerson && selectedPerson.photo_url"><img class="syn-avatar-img" :src="selectedPerson.photo_url" alt=""></template>
              <span x-show="!selectedPerson || !selectedPerson.photo_url" x-text="clientInitials(selectedPerson)"></span>
            </span>
            <div class="gc-selected-person-meta">
              <div class="gc-selected-name-row">
                <strong x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name + (selectedPerson.middle_name ? ' ' + selectedPerson.middle_name : '')) : ''"></strong>
                <span class="ce-tag ce-tag-upper" x-show="selectedPerson && selectedPerson.kind" x-text="selectedPerson ? (selectedPerson.kind || '') : ''"></span>
              </div>
              <small class="gc-client-id" x-text="selectedPerson ? ('MIS ID: ' + selectedPerson.mis_id) : ''"></small>
            </div>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm gc-change-btn" @click="changePerson()">Change</button>
        </div>
      </div>

      <div class="form-text mt-2 mb-0" x-show="!selectedPerson">Type at least 2 characters (name or university ID). Counseling is available to students, faculty, and staff.</div>
    </div>

    <!-- 02. Case Details -->
    <div class="gc-case-section">
      <div class="gc-section-head">
        <span class="gc-section-num" aria-hidden="true">02</span>
        <span class="gc-section-title">Case details</span>
      </div>

      <div class="row g-3">
        <div class="col-md-7">
          <label for="caseTitle" class="form-label">Case title / focus</label>
          <input type="text"
                 name="title"
                 id="caseTitle"
                 class="form-control"
                 placeholder="e.g. Academic Anxiety, Workplace Wellness"
                 maxlength="200"
                 required>
        </div>

        <div class="col-md-5">
          <label for="category" class="form-label">Category</label>
          <select name="category" id="category" class="form-select" required @change="selectedCategory = $event.target.value">
            <option value="academic">Academic Concerns</option>
            <option value="personal">Personal / Mental Health</option>
            <option value="career">Career Guidance</option>
            <option value="crisis">Crisis Intervention</option>
            <option value="behavioral">Behavioral Concerns</option>
            <option value="family">Family Concerns</option>
          </select>
        </div>
      </div>

      <div class="gc-case-callout" :class="{ 'gc-case-callout--crisis': selectedCategory === 'crisis' }" role="note">
        <i class="bi" :class="selectedCategory === 'crisis' ? 'bi-exclamation-triangle' : 'bi-info-circle'" aria-hidden="true"></i>
        <p class="mb-0" x-show="selectedCategory !== 'crisis'">Case metadata is visible to authorized Guidance staff. Keep the case title concise and free of sensitive third-party details.</p>
        <p class="mb-0" x-show="selectedCategory === 'crisis'" x-cloak>Crisis Intervention selected — follow immediate campus safety and escalation protocols in addition to opening this case.</p>
      </div>
    </div>

    <!-- 03. Intake Summary -->
    <div class="gc-case-section">
      <div class="gc-section-head gc-section-head--split">
        <div class="gc-section-head-left">
          <span class="gc-section-num" aria-hidden="true">03</span>
          <label for="summary_notes" class="gc-section-title">Intake summary &amp; referral context</label>
          <span class="gc-optional-tag">Optional</span>
        </div>
        <span class="gc-char-counter" x-text="summaryLength.toLocaleString() + ' / 2,000'">0 / 2,000</span>
      </div>

      <textarea name="summary_notes"
                id="summary_notes"
                rows="3"
                class="form-control gc-summary-textarea"
                maxlength="2000"
                @input="summaryLength = $event.target.value.length"
                placeholder="Initial observations, client motivation, referral background..."></textarea>
      <div class="form-text mt-2 mb-0">Keep sensitive clinical narratives inside encrypted counseling session notes after opening the workspace.</div>
    </div>

    <div class="form-actions gc-case-modal-actions">
      <span class="gc-footer-hint" x-show="!selectedPerson">
        <i class="bi bi-person-exclamation" aria-hidden="true"></i>
        Select a client to open a case
      </span>
      <div class="gc-footer-buttons">
        <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
        <button type="submit" class="btn btn-primary" :disabled="!selectedPerson">
          <i class="bi bi-folder-check" aria-hidden="true"></i> Open Case Workspace
        </button>
      </div>
    </div>
  </form>
</div>

<?= $this->endSection() ?>
