<?= $this->extend('layouts/modal_form') ?>

<?= $this->section('content') ?>
<div class="guidance-ui gc-modal-scope" x-data="newCaseForm({ patientUrl: <?= esc(json_encode(site_url('/api/patients/search'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?> })">
  <form action="<?= site_url('/guidance/cases/store') ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="person_id" :value="selectedPerson?.id">

    <!-- 1. Client Selection (Student or Employee) -->
    <div class="mb-4">
      <label class="form-label fw-semibold">1. Client Identification (Student or Employee)</label>
      <div class="syn-person-lookup" data-person-lookup x-show="!selectedPerson">
        <div class="input-group" data-person-lookup-anchor>
          <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
          <input type="text"
                 class="form-control"
                 placeholder="Search Student or Employee ID / Name..." maxlength="150" autocomplete="off"
                 x-model="searchTerm"
                 @input="patientInput()" @input.debounce.300ms="searchPatient()">
        </div>

        <!-- Floating search results popover -->
        <div class="syn-person-lookup-menu"
             data-person-lookup-menu
             x-show="searching || searchError || (searchComplete && !searching && results.length === 0) || results.length > 0"
             style="display:none;">
          <div role="status" aria-live="polite" class="syn-person-lookup-status" x-show="searching || searchError || (searchComplete && !searching && !searchError && results.length === 0)" x-cloak>
            <span x-show="searching">Searching local directory...</span>
            <span x-show="searchComplete && !searching && !searchError && results.length === 0">No matching local records.</span>
            <span x-show="searchError" class="text-danger"><span x-text="searchError"></span> <button type="button" class="btn btn-link btn-sm p-0" @click="searchPatient()">Retry</button></span>
          </div>
          <div x-show="results.length > 0" class="list-group syn-person-lookup-list">
            <template x-for="p in results" :key="p.id">
              <button type="button"
                      class="list-group-item list-group-item-action p-2 d-flex flex-wrap justify-content-between align-items-center gap-2"
                      @click="selectPerson(p)">
                <div>
                  <div class="fw-semibold" x-text="p.last_name + ', ' + p.first_name + (p.middle_name ? ' ' + p.middle_name : '')"></div>
                  <small class="text-muted" x-text="(p.kind ? p.kind.toUpperCase() + ' · ' : '') + 'ID: ' + p.mis_id"></small>
                </div>
                <span class="syn-person-lookup-select">Select</span>
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Selected client card -->
      <div x-show.important="selectedPerson" class="card gc-selected-person-card p-3 mb-2" style="display: none;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="ce-tag ce-tag-upper"
                  x-text="selectedPerson ? (selectedPerson.kind || '') : ''"></span>
            <strong class="d-block mt-1" x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name + (selectedPerson.middle_name ? ' ' + selectedPerson.middle_name : '')) : ''"></strong>
            <small class="text-muted" x-text="selectedPerson ? ('MIS ID: ' + selectedPerson.mis_id) : ''"></small>
          </div>
          <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="changePerson()">Change</button>
        </div>
      </div>
      <div class="form-text" x-show="!selectedPerson">Counseling is available to both Foundation University students and employees (faculty & staff).</div>
    </div>

    <!-- 2. Case Details -->
    <div class="row g-3 mb-4">
      <div class="col-md-8">
        <label for="caseTitle" class="form-label fw-semibold">Case Title / Focus</label>
        <input type="text"
               name="title"
               id="caseTitle"
               class="form-control"
               placeholder="e.g. Academic Anxiety, Workplace Wellness, Transition Adjustment"
               maxlength="200"
               required>
      </div>

      <div class="col-md-4">
        <label for="category" class="form-label fw-semibold">Category</label>
        <select name="category" id="category" class="form-select" required>
          <option value="academic">Academic Concerns</option>
          <option value="personal">Personal / Mental Health</option>
          <option value="career">Career Guidance</option>
          <option value="crisis">Crisis Intervention</option>
          <option value="behavioral">Behavioral Concerns</option>
          <option value="family">Family Concerns</option>
        </select>
      </div>

      <div class="col-12">
        <label for="summary_notes" class="form-label fw-semibold">Intake Summary / Referral Context (Optional)</label>
        <textarea name="summary_notes"
                  id="summary_notes"
                  rows="3"
                  class="form-control"
                  maxlength="2000"
                  placeholder="Initial observations, client motivation, referral background..."></textarea>
      </div>
    </div>

    <div class="form-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary" :disabled="!selectedPerson">
        <i class="bi bi-folder-check me-1"></i> Open Case Workspace
      </button>
    </div>
  </form>
</div>

<?= $this->endSection() ?>
