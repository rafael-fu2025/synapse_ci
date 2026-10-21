<?= $this->extend('layouts/modal_form') ?>

<?= $this->section('content') ?>
<div x-data="newCaseForm({ patientUrl: <?= esc(json_encode(site_url('/api/patients/search'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?> })">
  <form action="<?= site_url('/guidance/cases/store') ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="person_id" :value="selectedPerson?.id">

    <!-- 1. Client Selection (Student or Employee) -->
    <div class="mb-4">
      <label class="form-label fw-semibold">1. Client Identification (Student or Employee)</label>
      <div class="input-group mb-2">
        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
        <input type="text"
               class="form-control"
               placeholder="Search Student or Employee ID / Name..."
               x-model="searchTerm"
               @input.debounce.300ms="searchPatient()">
      </div>

      <!-- Dropdown search results -->
      <div x-show="results.length > 0 && !selectedPerson" class="list-group mb-2 shadow-sm border" style="max-height: 180px; overflow-y: auto;">
        <template x-for="p in results" :key="p.id">
          <button type="button"
                  class="list-group-item list-group-item-action p-2 d-flex justify-content-between align-items-center"
                  @click="selectPerson(p)">
            <div>
              <div class="fw-semibold" x-text="p.last_name + ', ' + p.first_name + (p.middle_name ? ' ' + p.middle_name : '')"></div>
              <small class="text-muted">
                <span class="badge me-1"
                      :class="p.kind === 'employee' ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle'"
                      x-text="p.kind.toUpperCase()"></span>
                <span x-text="'ID: ' + p.mis_id"></span>
              </small>
            </div>
            <span class="badge bg-primary">Select</span>
          </button>
        </template>
      </div>

      <!-- Selected client card -->
      <div x-show="selectedPerson" class="card border-primary-subtle bg-primary-subtle p-3 mb-2" style="display: none;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <span class="badge text-uppercase"
                  :class="selectedPerson?.kind === 'employee' ? 'bg-info text-white' : 'bg-primary text-white'"
                  x-text="selectedPerson ? (selectedPerson.kind || '') : ''"></span>
            <strong class="d-block text-dark mt-1" x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name + (selectedPerson.middle_name ? ' ' + selectedPerson.middle_name : '')) : ''"></strong>
            <small class="text-muted" x-text="selectedPerson ? ('MIS ID: ' + selectedPerson.mis_id) : ''"></small>
          </div>
          <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="selectedPerson = null">Change</button>
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
