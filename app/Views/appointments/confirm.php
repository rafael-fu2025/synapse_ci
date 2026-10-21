<?= $this->extend('layouts/modal_form') ?>
<?= $this->section('content') ?>
<?php $today = \App\Services\Scheduling\SchedulingClock::today(); ?>
<div x-data="appointmentBooking($el.dataset)" data-unit="<?= esc($unit) ?>"
     data-initial-date="<?= esc($appointment['local_date']) ?>" data-slots-url="<?= site_url('/api/appointments/slots') ?>">
  <form action="<?= esc($formAction) ?>" method="POST" data-modal-submit>
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= (int) $appointment['version'] ?>">
    <input type="hidden" name="starts_at" :value="selectedSlot?.starts_at">
    <input type="hidden" name="ends_at" :value="selectedSlot?.ends_at">
    <div class="border rounded p-3 mb-3">
      <strong class="d-block"><?= esc($appointment['last_name'] . ', ' . $appointment['first_name']) ?></strong>
      <span class="text-muted small">ID: <?= esc($appointment['mis_id']) ?></span>
      <p class="mb-0 mt-2">Requested: <?= esc(date('M j, Y · h:i A', strtotime($appointment['starts_at']))) ?></p>
      <p class="mb-0 small text-muted"><?= esc($appointment['reason'] ?? 'No reason provided') ?></p>
    </div>
    <p class="small text-muted"><?= $unit === 'clinic' ? 'Assign eligible clinic staff and choose a bookable time. Confirmation reserves the selected clinician’s capacity.' : 'Assign an eligible counselor and choose a bookable time. For follow-ups, review the existing case and retain the counselor where appropriate.' ?></p>
    <div class="mb-3">
      <label for="staff_assignment_id" class="form-label fw-semibold"><?= $unit === 'clinic' ? 'Assigned clinician' : 'Assigned counselor' ?></label>
      <select name="staff_assignment_id" id="staff_assignment_id" class="form-select" x-model="providerId" @change="fetchSlots()" required>
        <option value="">Choose <?= $unit === 'clinic' ? 'clinic staff' : 'counselor' ?></option>
        <?php foreach ($providers as $provider): ?>
          <option value="<?= (int) $provider['id'] ?>"><?= esc($provider['name']) ?><?= !empty($provider['position']) ? ' (' . esc($provider['position']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (empty($providers)): ?>
        <p class="text-warning small mt-2">No eligible staff are configured for this department. A department administrator must enroll an employee for scheduling and configure availability before confirming. The employee does not need to sign in first.</p>
      <?php endif; ?>
    </div>
    <div class="mb-3">
      <label for="bookingDate" class="form-label fw-semibold">Confirmed appointment date</label>
      <input type="text" id="bookingDate" class="form-control" data-datepicker data-target="#bookingDate_iso" data-min="<?= esc($today) ?>" @change="fetchSlots()" required>
      <input type="hidden" name="date" id="bookingDate_iso" x-model="date" value="<?= esc($appointment['local_date']) ?>">
    </div>
    <fieldset class="mb-4">
      <legend class="form-label fw-semibold fs-6 float-none">Available times</legend>
      <div x-show="loadingSlots" class="text-muted small" role="status"><span class="app-loading-spinner me-2" aria-hidden="true"></span>Checking staff availability…</div>
      <p x-show="slotError" x-text="slotError" class="text-danger small" role="alert"></p>
      <p x-show="!loadingSlots && !slotError && slots.length === 0" class="text-muted small">Choose staff and a date. If no times are available, ask your department administrator to configure the schedule or choose another date.</p>
      <div x-show="!loadingSlots && slots.length > 0" class="d-flex flex-wrap gap-2">
        <template x-for="slot in slots" :key="slot.starts_at">
          <button type="button" class="btn btn-sm" :class="selectedSlot === slot ? 'btn-primary' : 'btn-outline-secondary'"
                  :disabled="!slot.available" :aria-pressed="selectedSlot === slot" @click="selectedSlot = slot" x-text="formatSlotTime(slot.slot_time)"></button>
        </template>
      </div>
    </fieldset>
    <div class="form-actions">
      <a href="<?= esc($cancelUrl) ?>" class="btn btn-outline-secondary" data-app-modal-close>Cancel</a>
      <button type="submit" class="btn btn-primary" :disabled="!providerId || !selectedSlot || loadingSlots">Confirm &amp; Assign</button>
    </div>
  </form>
</div>
<?= $this->endSection() ?>
