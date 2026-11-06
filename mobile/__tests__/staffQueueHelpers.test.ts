import { ageSeconds, duration, holdRemaining, initialFilters, laneTickets, manilaBusinessDate, operationalTime, overview, timestamp } from '../src/features/staff-queue/helpers';
import type { StaffQueueSummary, StaffQueueTicket } from '../src/api/endpoints/staffQueue';

export const snapshot = '2026-11-05T10:00:00Z';
export const ticket: StaffQueueTicket = { id: 1, ticket_code: 'C-001', priority: 'routine', status: 'waiting', created_at: '2026-11-05T09:50:00Z', called_at: null, started_at: null, skip_until: null };
export function summary(unit: 'clinic' | 'guidance' = 'clinic'): StaffQueueSummary {
  return { unit, counts: { total: 8, waiting: 2, called: 1, in_consultation: 1, done: 2, skipped: 1, no_show: 1 },
    tickets: { waiting: [ticket, { ...ticket, id: 2, ticket_code: 'C-002', priority: 'urgent' }], called: [{ ...ticket, id: 3, status: 'called', called_at: '2026-11-05T09:59:00Z' }], in_consultation: [], skipped: [{ ...ticket, id: 4, status: 'skipped', skip_until: '2026-11-05T10:05:00Z' }] },
    metrics: { active: 5, priority_mix: { routine: 3, urgent: 1, emergency: 1 }, waiting_age: { sample_count: 2, total_seconds: 900, oldest_seconds: 600, average_seconds: 450 } } };
}

describe('staff queue snapshot helpers', () => {
  it('uses Manila business dates at midnight independently of device-local date getters', () => {
    const spy = jest.spyOn(Date.prototype, 'getDate').mockReturnValue(4);
    expect(manilaBusinessDate(Date.parse('2026-11-04T15:59:59Z'))).toBe('2026-11-04');
    expect(manilaBusinessDate(Date.parse('2026-11-04T16:00:00Z'))).toBe('2026-11-05');
    spy.mockRestore();
  });
  it('computes durations from the server snapshot, not the device clock', () => {
    expect(ageSeconds(ticket.created_at, snapshot)).toBe(600);
    expect(duration(600)).toBe('10m');
    expect(duration(3660)).toBe('1h 1m');
    expect(duration(0)).toBe('0s');
  });
  it.each([null, '', 'bad', '2026-11-05T09:00:00', '2026-11-05T11:00:00Z'])('rejects malformed or future age %s', value => {
    expect(ageSeconds(value, snapshot)).toBeNull();
    expect(operationalTime(value, snapshot)).toBe('Unavailable');
  });
  it('permits future explicit hold deadlines but not future event timestamps', () => {
    expect(operationalTime('2026-11-05T10:05:00Z', snapshot, true)).toMatch(/6:05\s?pm Manila/i);
    expect(holdRemaining('2026-11-05T10:05:00Z', snapshot)).toBe('Hold remaining 5m');
    expect(holdRemaining(snapshot, snapshot)).toBe('Hold deadline passed · still held');
    expect(holdRemaining(null, snapshot)).toBe('Hold remaining unavailable');
    expect(timestamp('2026-11-05T10:00:00+08:00')).not.toBeNull();
    expect(duration(-1)).toBe('Unavailable');
  });
  it('aggregates weighted waiting age rather than averaging unit averages', () => {
    const second = summary('guidance');
    second.metrics.waiting_age = { sample_count: 1, total_seconds: 3000, oldest_seconds: 3000, average_seconds: 3000 };
    expect(overview([summary(), second])).toEqual(expect.objectContaining({ average: 1300, oldest: 3000, samples: 3, active: 10, mix: { routine: 6, urgent: 2, emergency: 2 } }));
    expect(overview([]).average).toBeNull();
    expect(overview([]).oldest).toBeNull();
  });
  it('retains unit-local API ranks through all local filters', () => {
    const filters = { ...initialFilters, code: ' c-002 ', priority: 'urgent' as const };
    expect(laneTickets(summary(), 'waiting', filters).map(row => row.rank)).toEqual([2]);
    expect(laneTickets(summary('guidance'), 'waiting', filters).map(row => row.rank)).toEqual([2]);
    expect(laneTickets(summary(), 'waiting', { ...filters, unit: 'guidance' })).toEqual([]);
    expect(laneTickets(summary(), 'waiting', { ...filters, stage: 'called' })).toEqual([]);
    expect(laneTickets(summary(), 'called', initialFilters)[0].rank).toBeNull();
    expect(summary().tickets.waiting[0].id).toBe(1);
  });
});
