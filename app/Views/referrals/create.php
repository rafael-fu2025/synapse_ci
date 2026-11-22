<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<div class="rw-modal-form" x-data="referralForm($el.dataset)" data-patient-url="<?= site_url('/api/patients/search') ?>"
     data-initial-person="<?= esc(json_encode($patient ?? null), 'attr') ?>">
  <div class="rw-modal-intro">
    <p class="rw-modal-route-heading mb-1"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Referral route: <?= $unit === 'clinic' ? 'Clinic → Guidance' : 'Guidance → Clinic' ?></p>
    <p class="mb-0">Determined by this workstation. Only your brief handoff summary is shared—not the person's full medical or counseling record.</p>
  </div>

  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="request_key" value="<?= esc($requestKey) ?>">
    <input type="hidden" name="patient_person_id" :value="selectedPerson?.id">
    <?php if (!empty($sourceEncounterId)): ?><input type="hidden" name="source_encounter_id" value="<?= (int) $sourceEncounterId ?>"><?php endif; ?>

    <div class="rw-modal-section">
      <div class="rw-section-head">
        <span class="rw-section-num" aria-hidden="true">01</span>
        <label class="rw-section-title" for="referralPatientSearch">Student / employee</label>
      </div>

      <div class="syn-person-lookup" data-person-lookup x-show="!selectedPerson">
        <div class="rw-search-input" data-person-lookup-anchor>
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="referralPatientSearch" class="form-control" placeholder="Search Student ID or Name..." x-model="searchTerm" @input="patientInput()" @input.debounce.300ms="searchPatient()" autocomplete="off" maxlength="150">
        </div>

        <div class="syn-person-lookup-menu"
             data-person-lookup-menu
             x-show="searching || searchError || (searchComplete && !searching && results.length === 0) || results.length > 0"
             style="display:none">
          <div role="status" aria-live="polite" class="syn-person-lookup-status rw-search-status m-0" x-show="searching || searchError || (searchComplete && !searching && !searchError && results.length === 0)" x-cloak>
            <span x-show="searching">Searching local directory...</span>
            <span x-show="searchComplete && !searching && !searchError && results.length === 0">No matching local records.</span>
            <span x-show="searchError" class="text-danger"><span x-text="searchError"></span> <button type="button" class="btn btn-link btn-sm p-0" @click="searchPatient()">Retry</button></span>
          </div>

          <div x-show="results.length > 0" class="list-group syn-person-lookup-list rw-search-results">
            <template x-for="p in results" :key="p.id">
              <button type="button" class="list-group-item list-group-item-action rw-search-option" @click="selectPerson(p)">
                <span>
                  <strong class="d-block" x-text="p.last_name + ', ' + p.first_name"></strong>
                  <small x-text="'ID: ' + p.mis_id + ' (' + (p.kind || '').toUpperCase() + ')'"></small>
                </span>
                <span class="rw-select-chip">Select</span>
              </button>
            </template>
          </div>
        </div>
      </div>

      <div x-show.important="selectedPerson" style="display: none;" class="rw-selected-person">
        <div class="rw-selected-person-info">
          <span class="rw-avatar" aria-hidden="true" x-text="selectedPerson ? ((selectedPerson.first_name || '')[0] || '') + ((selectedPerson.last_name || '')[0] || '') : ''"></span>
          <div>
            <strong class="d-block" x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name) : ''"></strong>
            <small class="text-muted" x-text="selectedPerson ? ('MIS ID: ' + selectedPerson.mis_id) : ''"></small>
          </div>
        </div>
        <?php if (empty($sourceEncounterId)): ?><button type="button" class="btn btn-outline-secondary btn-sm" @click="changePerson()">Change</button><?php endif; ?>
      </div>
      <?php if (!empty($sourceEncounterId)): ?><p class="form-text mt-2 mb-0">Person and source visit are linked from the clinic chart.</p><?php endif; ?>
    </div>

    <div class="rw-modal-section">
      <div class="rw-section-head">
        <span class="rw-section-num" aria-hidden="true">02</span>
        <span class="rw-section-title">Reason &amp; priority</span>
      </div>

      <div class="row g-3">
        <div class="col-md-7">
          <label for="reason_code" class="form-label">Reason for <?= $targetModule === 'guidance' ? 'guidance support' : 'clinic assessment' ?></label>
          <select name="reason_code" id="reason_code" class="form-select" required>
            <option value="">Choose a reason</option>
            <?php foreach ($reasonOptions as $value => $label): ?><option value="<?= esc($value) ?>"><?= esc($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label for="priority" class="form-label">Priority</label>
          <select name="priority" id="priority" class="form-select" required>
            <option value="routine">Routine</option>
            <option value="urgent">Urgent</option>
            <option value="crisis">Crisis</option>
          </select>
        </div>
      </div>

      <div class="rw-safety-note">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <p class="mb-0">For emergencies or immediate safety concerns, follow university emergency procedures and contact the receiving team directly. This form does not guarantee an immediate response.</p>
      </div>
    </div>

    <div class="rw-modal-section" x-data="{ summaryLength: 0 }">
      <div class="rw-section-head rw-section-head--split">
        <div class="rw-section-head-left">
          <span class="rw-section-num" aria-hidden="true">03</span>
          <label for="reason_description" class="rw-section-title">Brief handoff summary</label>
        </div>
        <span class="rw-char-counter" x-text="summaryLength + ' / 2,000'">0 / 2,000</span>
      </div>

      <textarea name="reason_description" id="reason_description" rows="4" class="form-control" maxlength="2000" required @input="summaryLength = $event.target.value.length" placeholder="State the concern and what support you are requesting. Share only what the receiving team needs."></textarea>
      <div class="form-text mt-2">Maximum 2,000 characters. Do not paste full consultation records or confidential counseling notes.</div>
    </div>

    <div class="form-actions rw-modal-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary" :disabled="!selectedPerson"><i class="bi bi-send-check" aria-hidden="true"></i> Send to <?= ucfirst($targetModule) ?></button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
