import type { QueuePriority, QueueStage, QueueUnit, StaffQueueSummary } from '../../api/endpoints/staffQueue';
import { formatDateTime } from '../../lib/format';

const businessDateFormat = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' });
export function manilaBusinessDate(now: number): string {
  const parts = businessDateFormat.formatToParts(new Date(now));
  const part = (name: string) => parts.find(value => value.type === name)?.value;
  return `${part('year')}-${part('month')}-${part('day')}`;
}

export const stages: QueueStage[] = ['called', 'in_consultation', 'waiting', 'skipped'];
export const stageLabel: Record<QueueStage, string> = {
  waiting: 'Waiting', called: 'Called', in_consultation: 'In Consultation', skipped: 'Held',
};
export const priorities: QueuePriority[] = ['routine', 'urgent', 'emergency'];
export interface QueueFilters {
  unit: QueueUnit | 'all';
  stage: QueueStage | 'all';
  priority: QueuePriority | 'all';
  code: string;
}
export const initialFilters: QueueFilters = { unit: 'all', stage: 'all', priority: 'all', code: '' };

/** Require an explicit timezone; never reinterpret a server timestamp as device-local time. */
export function timestamp(value: string | null | undefined): number | null {
  if (!value || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(value)) { return null; }
  const [year, month, day] = value.slice(0, 10).split('-').map(Number);
  const calendar = new Date(Date.UTC(year, month - 1, day));
  if (calendar.getUTCFullYear() !== year || calendar.getUTCMonth() !== month - 1 || calendar.getUTCDate() !== day
    || Number(value.slice(11, 13)) > 23 || Number(value.slice(14, 16)) > 59 || Number(value.slice(17, 19)) > 59) { return null; }
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : null;
}
export function ageSeconds(value: string | null, snapshot: string): number | null {
  const start = timestamp(value);
  const end = timestamp(snapshot);
  return start === null || end === null || start > end ? null : Math.floor((end - start) / 1000);
}
export function duration(seconds: number | null): string {
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0) { return 'Unavailable'; }
  if (seconds < 60) { return `${Math.floor(seconds)}s`; }
  const minutes = Math.floor(seconds / 60);
  return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
export function operationalTime(value: string | null, snapshot: string, deadline = false): string {
  const time = timestamp(value);
  const end = timestamp(snapshot);
  if (time === null || end === null || (!deadline && time > end)) { return 'Unavailable'; }
  return `${formatDateTime(value)} Manila`;
}
export function holdRemaining(deadline: string | null, snapshot: string): string {
  const end = timestamp(deadline);
  const at = timestamp(snapshot);
  if (end === null || at === null) { return 'Hold remaining unavailable'; }
  return end <= at ? 'Hold deadline passed · still held' : `Hold remaining ${duration((end - at) / 1000)}`;
}
export function overview(summaries: StaffQueueSummary[]) {
  const counts = { total: 0, waiting: 0, called: 0, in_consultation: 0, done: 0, skipped: 0, no_show: 0 };
  const mix = { routine: 0, urgent: 0, emergency: 0 };
  let active = 0;
  let samples = 0;
  let totalSeconds = 0;
  let oldest: number | null = null;
  for (const summary of summaries) {
    for (const key of Object.keys(counts) as (keyof typeof counts)[]) { counts[key] += summary.counts[key]; }
    for (const key of priorities) { mix[key] += summary.metrics.priority_mix[key]; }
    active += summary.metrics.active;
    const age = summary.metrics.waiting_age;
    samples += age.sample_count;
    totalSeconds += age.total_seconds;
    if (age.oldest_seconds !== null) { oldest = Math.max(oldest ?? 0, age.oldest_seconds); }
  }
  return { counts, mix, active, samples, oldest, average: samples > 0 ? totalSeconds / samples : null };
}

/** Rank before filtering, within this unit's original waiting order only. */
export function laneTickets(summary: StaffQueueSummary, stage: QueueStage, filters: QueueFilters) {
  if ((filters.unit !== 'all' && filters.unit !== summary.unit) || (filters.stage !== 'all' && filters.stage !== stage)) { return []; }
  return summary.tickets[stage].map((ticket, index) => ({ ticket, rank: stage === 'waiting' ? index + 1 : null }))
    .filter(({ ticket }) => (filters.priority === 'all' || ticket.priority === filters.priority)
      && ticket.ticket_code.toLowerCase().includes(filters.code.trim().toLowerCase()));
}
