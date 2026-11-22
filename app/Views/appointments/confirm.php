<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php $today = \App\Services\Scheduling\SchedulingClock::today(); ?>
<div class="appt-confirm-shell" x-data="appointmentBooking($el.dataset)" data-unit="<?= esc($unit) ?>"
     data-initial-date="<?= esc($appointment['local_date']) ?>" data-slots-url="<?= site_url('/api/appointments/slots') ?>">
  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= (int) $appointment['version'] ?>">
    <input type="hidden" name="starts_at" :value="selectedSlot?.starts_at">
    <input type="hidden" name="ends_at" :value="selectedSlot?.ends_at">

    <div class="appt-confirm-context">
      <div class="appt-confirm-context-top">
        <strong class="appt-confirm-patient"><?= esc($appointment['last_name'] . ', ' . $appointment['first_name']) ?></strong>
        <span class="appt-confirm-id">ID: <?= esc($appointment['mis_id']) ?></span>
      </div>
      <div class="appt-confirm-meta-grid">
        <div class="appt-confirm-meta-item">
          <span class="appt-confirm-meta-label">Requested</span>
          <p class="appt-confirm-meta-val appt-mono"><?= esc(date('M j, Y · h:i A', strtotime($appointment['starts_at']))) ?></p>
        </div>
        <div class="appt-confirm-meta-item">
          <span class="appt-confirm-meta-label">Brief reason</span>
          <p class="appt-confirm-meta-val"><?= esc($appointment['reason'] ?? 'No reason provided') ?></p>
        </div>
      </div>
    </div>

    <p class="appt-confirm-lead">
      <?= $unit === 'clinic'
          ? 'Assign eligible clinic staff and choose a bookable time. Confirmation reserves the selected clinician’s capacity.'
          : 'Assign an eligible counselor and choose a bookable time. For follow-ups, review the existing case and retain the counselor where appropriate.' ?>
    </p>

    <div class="appt-section-block">
      <div class="appt-section-head">
        <div class="appt-section-head-left">
          <span class="appt-section-num">01</span>
          <h6 class="appt-section-title"><?= $unit === 'clinic' ? 'Clinician &amp; confirmed date' : 'Counselor &amp; confirmed date' ?></h6>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-md-7">
          <label for="staff_assignment_id" class="form-label"><?= $unit === 'clinic' ? 'Assigned clinician' : 'Assigned counselor' ?></label>
          <select name="staff_assignment_id" id="staff_assignment_id" class="form-select" x-model="providerId" @change="fetchSlots()" required>
            <option value="">Choose <?= $unit === 'clinic' ? 'clinic staff' : 'counselor' ?></option>
            <?php foreach ($providers as $provider): ?>
              <option value="<?= (int) $provider['id'] ?>"><?= esc($provider['name']) ?><?= !empty($provider['position']) ? ' (' . esc($provider['position']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label for="bookingDate" class="form-label">Confirmed appointment date</label>
          <input type="text" id="bookingDate" class="form-control" data-datepicker data-target="#bookingDate_iso" data-min="<?= esc($today) ?>" @change="fetchSlots()" required>
          <input type="hidden" name="date" id="bookingDate_iso" x-model="date" value="<?= esc($appointment['local_date']) ?>">
        </div>
      </div>
      <?php if (empty($providers)): ?>
        <div class="appt-notice appt-notice--warn" role="note">No eligible staff are configured for this department. A department administrator must enroll an employee for scheduling and configure availability before confirming. The employee does not need to sign in first.</div>
      <?php endif; ?>
    </div>

    <fieldset class="appt-slots-fieldset">
      <legend class="appt-section-head float-none">
        <span class="appt-section-head-left">
          <span class="appt-section-num">02</span>
          <span class="appt-section-title">Available times</span>
        </span>
        <span class="appt-slot-legend" aria-hidden="true">
          <span class="appt-slot-legend-item"><span class="appt-slot-swatch"></span>Available</span>
          <span class="appt-slot-legend-item"><span class="appt-slot-swatch appt-slot-swatch--selected"></span>Selected</span>
          <span class="appt-slot-legend-item"><span class="appt-slot-swatch appt-slot-swatch--disabled"></span>Unavailable</span>
        </span>
      </legend>
      <div x-show="loadingSlots" class="text-muted small py-2" role="status"><span class="app-loading-spinner me-2" aria-hidden="true"></span>Checking staff availability…</div>
      <div x-show="slotError" x-text="slotError" class="appt-notice appt-notice--danger" role="alert"></div>
      <p x-show="!loadingSlots && !slotError && slots.length === 0" class="text-muted small mb-0">Choose staff and a date. If no times are available, ask your department administrator to configure the schedule or choose another date.</p>
      <div x-show="!loadingSlots && slots.length > 0" class="appointment-slot-grid">
        <template x-for="slot in slots" :key="slot.starts_at">
          <button type="button" class="btn appointment-slot-btn" :class="selectedSlot === slot ? 'btn-primary' : 'btn-outline-secondary'"
                  :disabled="!slot.available" :aria-pressed="selectedSlot === slot" @click="selectedSlot = slot" x-text="formatSlotTime(slot.slot_time)"></button>
        </template>
      </div>
    </fieldset>

    <div class="form-actions appt-modal-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary" :disabled="!providerId || !selectedSlot || loadingSlots">Confirm &amp; Assign</button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
