import { StatusTone } from '../../theme/tokens';
import { Appointment } from '../../api/endpoints/appointments';

const STATUS_PRESENTATION: Record<Appointment['status'], { label: string; tone: StatusTone }> = {
  requested: { label: 'Awaiting confirmation', tone: 'warning' },
  scheduled: { label: 'Confirmed', tone: 'success' },
  confirmed: { label: 'Confirmed', tone: 'success' },
  checked_in: { label: 'Checked in', tone: 'info' },
  completed: { label: 'Completed', tone: 'neutral' },
  cancelled: { label: 'Cancelled', tone: 'danger' },
  no_show: { label: 'Missed', tone: 'danger' },
};

export function statusPresentation(status: Appointment['status']) {
  return STATUS_PRESENTATION[status] ?? { label: status, tone: 'neutral' as StatusTone };
}

export const UNIT_PRESENTATION = {
  clinic: { label: 'University Clinic', short: 'Clinic' },
  guidance: { label: 'Guidance Center', short: 'Guidance' },
} as const;

export type UnitKey = keyof typeof UNIT_PRESENTATION;

export function unitLabel(unit: string): string {
  return UNIT_PRESENTATION[unit as UnitKey]?.short ?? unit;
}

/** Detail-progress step model: requested → confirmed → checked in → completed. */
export function progressStep(status: Appointment['status']): number {
  switch (status) {
    case 'requested':
      return 1;
    case 'scheduled':
    case 'confirmed':
      return 2;
    case 'checked_in':
      return 3;
    case 'completed':
      return 4;
    default:
      return 0;
  }
}
