<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<div x-data="referralForm($el.dataset)" data-patient-url="<?= site_url('/api/patients/search') ?>"
     data-initial-person="<?= esc(json_encode($patient ?? null), 'attr') ?>">
  <div class="alert alert-info small mb-3">
    <strong>Referral route: <?= $unit === 'clinic' ? 'Clinic → Guidance' : 'Guidance → Clinic' ?></strong>
    <span class="d-block">Determined by this workstation. Only your brief handoff summary is shared—not the person's full medical or counseling record.</span>
  </div>
  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="request_key" value="<?= esc($requestKey) ?>">
    <input type="hidden" name="patient_person_id" :value="selectedPerson?.id">
    <?php if (!empty($sourceEncounterId)): ?><input type="hidden" name="source_encounter_id" value="<?= (int) $sourceEncounterId ?>"><?php endif; ?>
    <div class="mb-3">
      <label class="form-label fw-semibold" for="referralPatientSearch">Student / employee</label>
      <div class="input-group mb-2" x-show="!selectedPerson">
        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
        <input type="search" id="referralPatientSearch" class="form-control" placeholder="Search Student ID or Name..." x-model="searchTerm" @input.debounce.300ms="searchPatient()" maxlength="150">
      </div>
      <div x-show="results.length > 0 && !selectedPerson" style="display: none;" class="list-group mb-2 border" style="max-height:180px;overflow-y:auto">
        <template x-for="p in results" :key="p.id">
          <button type="button" class="list-group-item list-group-item-action p-2 d-flex justify-content-between align-items-center" @click="selectPerson(p)">
            <span><strong class="d-block" x-text="p.last_name + ', ' + p.first_name"></strong><small x-text="'ID: ' + p.mis_id + ' (' + (p.kind || '').toUpperCase() + ')'"></small></span>
            <span class="badge bg-primary-subtle text-primary">Select</span>
          </button>
        </template>
      </div>
      <div x-show="selectedPerson" style="display: none;" class="border rounded p-3 d-flex justify-content-between align-items-center">
        <span><strong class="d-block" x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name) : ''"></strong><small class="text-muted" x-text="selectedPerson ? ('MIS ID: ' + selectedPerson.mis_id) : ''"></small></span>
        <?php if (empty($sourceEncounterId)): ?><button type="button" class="btn btn-outline-secondary btn-sm" @click="selectedPerson = null">Change</button><?php endif; ?>
      </div>
      <?php if (!empty($sourceEncounterId)): ?><p class="form-text">Person and source visit are linked from the clinic chart.</p><?php endif; ?>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-md-7">
        <label for="reason_code" class="form-label fw-semibold">Reason for <?= $targetModule === 'guidance' ? 'guidance support' : 'clinic assessment' ?></label>
        <select name="reason_code" id="reason_code" class="form-select" required>
          <option value="">Choose a reason</option>
          <?php foreach ($reasonOptions as $value => $label): ?><option value="<?= esc($value) ?>"><?= esc($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label for="priority" class="form-label fw-semibold">Priority</label>
        <select name="priority" id="priority" class="form-select" required>
          <option value="routine">Routine</option><option value="urgent">Urgent</option><option value="crisis">Crisis</option>
        </select>
      </div>
    </div>
    <p class="small text-muted">For emergencies or immediate safety concerns, follow university emergency procedures and contact the receiving team directly. This form does not guarantee an immediate response.</p>
    <div class="mb-4">
      <label for="reason_description" class="form-label fw-semibold">Brief handoff summary</label>
      <textarea name="reason_description" id="reason_description" rows="4" class="form-control" maxlength="2000" required placeholder="State the concern and what support you are requesting. Share only what the receiving team needs."></textarea>
      <div class="form-text">Maximum 2,000 characters. Do not paste full consultation records or confidential counseling notes.</div>
    </div>
    <div class="form-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary" :disabled="!selectedPerson"><i class="bi bi-send-check me-1" aria-hidden="true"></i> Send to <?= ucfirst($targetModule) ?></button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
