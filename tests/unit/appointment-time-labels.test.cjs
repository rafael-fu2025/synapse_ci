'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { window: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/care-forms.js'), 'utf8'), context);

test('appointment slot labels use AM/PM, including midnight and noon', () => {
  const booking = context.window.appointmentBooking({ initialDate: '2026-10-19' });
  for (const [time, expected] of [
    ['00:00', '12:00 AM'], ['08:00', '8:00 AM'], ['11:45', '11:45 AM'],
    ['12:00', '12:00 PM'], ['13:15', '1:15 PM'], ['16:45', '4:45 PM'],
    ['23:59', '11:59 PM'], ['17:00:00', '5:00 PM']
  ]) assert.equal(booking.formatSlotTime(time), expected);
});

test('formatting does not change the selected booking payload', () => {
  const booking = context.window.appointmentBooking({ initialDate: '2026-10-19' });
  const slot = Object.freeze({ slot_time: '13:15', starts_at: '2026-10-19 13:15:00', ends_at: '2026-10-19 13:30:00' });
  booking.selectedSlot = slot;
  assert.equal(booking.formatSlotTime(slot.slot_time), '1:15 PM');
  assert.equal(booking.selectedSlot, slot);
  assert.equal(slot.slot_time, '13:15');
  const view = fs.readFileSync(path.join(__dirname, '../../app/Views/appointments/confirm.php'), 'utf8');
  assert.ok(view.includes('x-text="formatSlotTime(slot.slot_time)"'));
});
