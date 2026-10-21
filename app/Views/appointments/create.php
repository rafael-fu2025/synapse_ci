<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php $today = \App\Services\Scheduling\SchedulingClock::today(); ?>
<div x-data="appointmentRequest($el.dataset)" data-unit="<?= esc($unit) ?>"
     data-initial-date="<?= esc($today) ?>" data-patient-url="<?= site_url('/api/patients/search') ?>">
  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <div class="alert alert-info" role="note">
      <strong><?= $unit === 'clinic' ? 'Request a clinic visit' : 'Request a guidance session' ?></strong>
      <p class="mb-0 small">Your preferred time is not a reservation. <?= $unit === 'clinic' ? 'Clinic staff will review the request, confirm an available time, and assign a clinician.' : 'Guidance staff will review the request, confirm an available time, and assign a counselor.' ?></p>
    </div>
    <?php if ($bookingMode === 'personal'): ?>
      <div class="mb-4 border rounded p-3">
        <span class="text-muted small">Requesting for yourself</span>
        <strong class="d-block"><?= esc(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))) ?></strong>
        <span class="text-muted small">ID: <?= esc($person['mis_id'] ?? '') ?></span>
      </div>
    <?php else: ?>
      <input type="hidden" name="person_id" :value="selectedPerson?.id">
      <div class="mb-4">
        <label for="requestPersonSearch" class="form-label fw-semibold"><?= $unit === 'clinic' ? 'Patient' : 'Student / employee' ?></label>
        <div class="input-group mb-2">
          <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
          <input id="requestPersonSearch" type="text" class="form-control" placeholder="Search Student ID, Employee ID, or Name..."
                 x-model="searchTerm" @input.debounce.300ms="searchPatient()" autocomplete="off">
        </div>
        <div x-show="results.length > 0 && !selectedPerson" style="display: none;" class="list-group mb-2 border" style="max-height:180px;overflow-y:auto;">
          <template x-for="p in results" :key="p.id">
            <button type="button" class="list-group-item list-group-item-action p-2 d-flex justify-content-between align-items-center" @click="selectPerson(p)">
              <span><strong class="d-block" x-text="p.last_name + ', ' + p.first_name"></strong>
                <small class="text-muted" x-text="'ID: ' + p.mis_id + (p.kind ? ' (' + p.kind.toUpperCase() + ')' : '')"></small></span>
              <span class="badge bg-primary-subtle text-primary">Select</span>
            </button>
          </template>
        </div>
        <div x-show="selectedPerson" style="display: none;" class="border rounded p-3 d-flex justify-content-between align-items-center">
          <span><strong class="d-block" x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name) : ''"></strong>
            <small class="text-muted" x-text="selectedPerson ? ('ID: ' + selectedPerson.mis_id + (selectedPerson.kind ? ' (' + selectedPerson.kind.toUpperCase() + ')' : '')) : ''"></small></span>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="selectedPerson = null">Change</button>
        </div>
      </div>
    <?php endif; ?>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label for="bookingDate" class="form-label fw-semibold">Preferred date</label>
        <input type="text" id="bookingDate" class="form-control" data-datepicker data-target="#bookingDate_iso" data-min="<?= esc($today) ?>" required>
        <input type="hidden" name="date" id="bookingDate_iso" x-model="date" value="<?= esc($today) ?>">
      </div>
      <div class="col-md-6">
        <label for="preferredTime" class="form-label fw-semibold">Preferred time</label>
        <?= view('partials/schedule_time', ['id' => 'preferredTime', 'name' => 'preferred_time', 'label' => 'Preferred time', 'value' => '', 'describedBy' => 'preferredTimeHelp']) ?>
        <small id="preferredTimeHelp" class="text-muted">Staff may propose another time based on availability.</small>
      </div>
    </div>
    <div class="mb-3">
      <label for="type" class="form-label fw-semibold"><?= $unit === 'clinic' ? 'Visit type' : 'Session type' ?></label>
      <select name="type" id="type" class="form-select" required>
        <?php if ($unit === 'clinic'): ?>
          <option value="routine">General clinic visit</option>
          <option value="follow_up">Follow-up visit</option>
          <option value="referral_based">Referred clinic visit</option>
        <?php else: ?>
          <option value="initial">First guidance session</option>
          <option value="follow_up">Follow-up session</option>
          <option value="referral_based">Referred guidance session</option>
        <?php endif; ?>
      </select>
    </div>
    <div class="mb-4">
      <label for="reason" class="form-label fw-semibold"><?= $unit === 'clinic' ? 'Brief reason for visit (optional)' : 'Brief concern (optional)' ?></label>
      <textarea name="reason" id="reason" class="form-control" rows="3" maxlength="255" aria-describedby="requestReasonHelp"
                placeholder="<?= $unit === 'clinic' ? 'For example: check-up or follow-up for a previous visit' : 'For example: academic concerns or a follow-up session' ?>"></textarea>
      <small id="requestReasonHelp" class="text-muted"><?= $unit === 'clinic' ? 'Keep this brief. Detailed symptoms can be discussed during the visit.' : 'Avoid sensitive details here. Discuss them privately with your counselor. For a follow-up, staff will review counselor continuity.' ?></small>
    </div>
    <p class="small text-muted">For urgent concerns, contact the department directly rather than waiting for an appointment request.</p>
    <div class="form-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary" <?= $bookingMode === 'staff' ? ':disabled="!selectedPerson"' : '' ?>>Submit Request</button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
