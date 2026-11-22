<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php
$today = \App\Services\Scheduling\SchedulingClock::today();
$isClinic = $unit === 'clinic';
$unitName = $isClinic ? 'University Clinic' : 'Guidance & Counseling';
?>
<div class="pa-req"
     x-data="appointmentRequest($el.dataset)"
     data-unit="<?= esc($unit) ?>"
     data-initial-date="<?= esc($today) ?>"
     data-patient-url="<?= site_url('/api/patients/search') ?>">
  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>

    <div class="pa-req-intro" role="note">
      <div class="pa-req-unit">
        <?= view('partials/unit_vector_icon', ['unit' => $isClinic ? 'clinic' : 'guidance', 'class' => 'pa-unit-icon']) ?>
        <span><?= esc($unitName) ?></span>
      </div>
      <strong class="pa-req-heading"><?= $isClinic ? 'Request a clinic visit' : 'Request a guidance session' ?></strong>
      <p class="pa-req-note">
        Your preferred time is not a reservation.
        <?= $isClinic
            ? 'Clinic staff will review the request, confirm an available time, and assign a clinician.'
            : 'Guidance staff will review the request, confirm an available time, and assign a counselor.' ?>
      </p>
    </div>

    <?php if ($bookingMode === 'personal'): ?>
      <div class="pa-req-identity">
        <div class="pa-req-identity-copy">
          <span class="pa-req-identity-eyebrow">Requesting for yourself</span>
          <strong class="pa-req-identity-name"><?= esc(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))) ?></strong>
        </div>
        <span class="pa-req-identity-id">ID: <?= esc($person['mis_id'] ?? '') ?></span>
      </div>
    <?php else: ?>
      <input type="hidden" name="person_id" :value="selectedPerson?.id">
      <div class="pa-req-section">
        <label for="requestPersonSearch" class="pa-req-label"><?= $isClinic ? 'Patient' : 'Student / employee' ?></label>
        <div class="syn-person-lookup" data-person-lookup x-show="!selectedPerson">
          <div class="pa-req-search-wrap" data-person-lookup-anchor>
            <i class="bi bi-search pa-req-search-icon" aria-hidden="true"></i>
            <input id="requestPersonSearch"
                   type="text"
                   class="form-control pa-req-input pa-req-search-input"
                   placeholder="Search Student ID, Employee ID, or Name..."
                   x-model="searchTerm"
                   @input="patientInput()"
                   @input.debounce.300ms="searchPatient()"
                   autocomplete="off"
                   maxlength="150">
          </div>
          <div class="syn-person-lookup-menu"
               data-person-lookup-menu
               x-show="searching || searchError || (searchComplete && !searching && results.length === 0) || results.length > 0"
               style="display:none;">
            <div role="status" aria-live="polite" class="syn-person-lookup-status pa-req-search-status m-0" x-show="searching || searchError || (searchComplete && !searching && !searchError && results.length === 0)" x-cloak>
              <span x-show="searching">Searching local directory...</span>
              <span x-show="searchComplete && !searching && !searchError && results.length === 0">No matching local records.</span>
              <span x-show="searchError" class="text-danger">
                <span x-text="searchError"></span>
                <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="searchPatient()">Retry</button>
              </span>
            </div>
            <div x-show="results.length > 0" class="list-group syn-person-lookup-list pa-req-results">
              <template x-for="p in results" :key="p.id">
                <button type="button" class="pa-req-result-row" @click="selectPerson(p)">
                  <span class="pa-req-result-copy">
                    <strong x-text="p.last_name + ', ' + p.first_name"></strong>
                    <small x-text="'ID: ' + p.mis_id + (p.kind ? ' (' + p.kind.toUpperCase() + ')' : '')"></small>
                  </span>
                  <span class="pa-req-result-action">Select</span>
                </button>
              </template>
            </div>
          </div>
        </div>
        <div x-show.important="selectedPerson" style="display: none;" class="pa-req-identity">
          <div class="pa-req-identity-copy">
            <strong class="pa-req-identity-name" x-text="selectedPerson ? (selectedPerson.last_name + ', ' + selectedPerson.first_name) : ''"></strong>
            <span class="pa-req-identity-id" x-text="selectedPerson ? ('ID: ' + selectedPerson.mis_id + (selectedPerson.kind ? ' (' + selectedPerson.kind.toUpperCase() + ')' : '')) : ''"></span>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary pa-req-btn-sm" @click="changePerson()">Change</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="pa-req-grid">
      <div class="pa-req-field">
        <label for="bookingDate" class="pa-req-label">Preferred date</label>
        <input type="text" id="bookingDate" class="form-control pa-req-input" data-datepicker data-target="#bookingDate_iso" data-min="<?= esc($today) ?>" required>
        <input type="hidden" name="date" id="bookingDate_iso" x-model="date" value="<?= esc($today) ?>">
      </div>
      <div class="pa-req-field">
        <label for="preferredTime" class="pa-req-label">Preferred time</label>
        <?= view('partials/schedule_time', ['id' => 'preferredTime', 'name' => 'preferred_time', 'label' => 'Preferred time', 'value' => '', 'describedBy' => 'preferredTimeHelp']) ?>
        <small id="preferredTimeHelp" class="pa-req-help">Staff may propose another time based on availability.</small>
      </div>
    </div>

    <div class="pa-req-section">
      <label for="type" class="pa-req-label"><?= $isClinic ? 'Visit type' : 'Session type' ?></label>
      <select name="type" id="type" class="form-select" required>
        <?php if ($isClinic): ?>
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

    <div class="pa-req-section">
      <label for="reason" class="pa-req-label">
        <?= $isClinic ? 'Brief reason for visit' : 'Brief concern' ?>
        <span class="pa-req-optional">(optional)</span>
      </label>
      <textarea name="reason"
                id="reason"
                class="form-control pa-req-textarea"
                rows="3"
                maxlength="255"
                aria-describedby="requestReasonHelp"
                placeholder="<?= $isClinic ? 'For example: check-up or follow-up for a previous visit' : 'For example: academic concerns or a follow-up session' ?>"></textarea>
      <small id="requestReasonHelp" class="pa-req-help">
        <?= $isClinic
            ? 'Keep this brief. Detailed symptoms can be discussed during the visit.'
            : 'Avoid sensitive details here. Discuss them privately with your counselor. For a follow-up, staff will review counselor continuity.' ?>
      </small>
    </div>

    <p class="pa-req-urgent">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>For urgent concerns, contact the department directly rather than waiting for an appointment request.</span>
    </p>

    <div class="form-actions pa-req-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary pa-req-btn" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary pa-req-btn" <?= $bookingMode === 'staff' ? ':disabled="!selectedPerson"' : '' ?>>Submit Request</button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
