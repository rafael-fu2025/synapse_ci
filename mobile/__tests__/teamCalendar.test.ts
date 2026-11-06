import { ApiError, NetworkError } from '../src/api/client';
import * as calendarApi from '../src/api/endpoints/teamCalendar';
import {
  isAmbiguousCreateError,
  teamCalendarErrorKind,
  teamCalendarErrorMessage,
} from '../src/features/team-calendar/errors';
import { teamCalendarQueryKeys } from '../src/features/team-calendar/hooks';
import {
  addManilaDays,
  addManilaMonths,
  formatManilaWall,
  instantToManilaWall,
  manilaWallToPickerDate,
  mergePickerSelection,
  monthGridDates,
  parseManilaWall,
  pickerDateToManilaParts,
  startOfManilaWeek,
} from '../src/features/team-calendar/manila';
import {
  agendaSections,
  itemOccursOnDate,
  itemsForDate,
  normalizeParticipantIds,
} from '../src/features/team-calendar/model';
import { teamTaskDeadlineRoute } from '../src/features/team-calendar/navigation';
import type { TeamCalendarItem } from '../src/api/endpoints/teamCalendar';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/api/client', () => {
  const actual = jest.requireActual('../src/api/client');
  return { ...actual, request: jest.fn() };
});

const mockedRequest = jest.requireMock('../src/api/client').request as jest.Mock;

const event: TeamCalendarItem = {
  type: 'event',
  id: 4,
  title: 'Planning',
  status: 'scheduled',
  starts_at: '2026-12-10T01:00:00+00:00',
  ends_at: '2026-12-10T03:00:00+00:00',
  location: 'Room 1',
  participants: [],
  version: 1,
  can_edit: true,
  created_at: '2026-12-01T00:00:00+00:00',
  updated_at: '2026-12-01T00:00:00+00:00',
};

const overnightEvent: TeamCalendarItem = {
  ...event,
  id: 5,
  title: 'Overnight',
  starts_at: '2026-12-10T15:30:00Z', // Dec 10, 11:30 PM Manila
  ends_at: '2026-12-10T17:30:00Z', // Dec 11, 1:30 AM Manila
};

const task: TeamCalendarItem = {
  type: 'task_deadline',
  id: 8,
  title: 'Submit report',
  status: 'todo',
  priority: 'high',
  due_date: '2026-12-10',
  assignee_user_id: 3,
  version: 2,
};

describe('Team Calendar API contract', () => {
  beforeEach(() => mockedRequest.mockReset());

  it('uses exact list filters and distinguishes team from mine', async () => {
    mockedRequest.mockResolvedValue({ data: [], meta: {} });
    await calendarApi.list('clinic', {
      date: '2026-12-10',
      view: 'agenda',
      scope: 'mine',
      status: 'all',
    });
    expect(mockedRequest).toHaveBeenCalledWith(
      'api/v1/team/clinic/calendar?date=2026-12-10&view=agenda&mine=1&status=all',
    );
  });

  it('posts create, update, and cancel to the backend POST paths', async () => {
    mockedRequest.mockResolvedValue({ data: event });
    const editable = {
      title: 'Planning',
      description: '',
      location: 'Room 1',
      starts_at: '2026-12-10T09:00',
      ends_at: '2026-12-10T10:00',
      participant_ids: [1],
    };
    await calendarApi.create('clinic', {
      ...editable,
      client_nonce: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    });
    await calendarApi.update('clinic', 4, { ...editable, version: 2 });
    await calendarApi.cancel('clinic', 4, 3);
    expect(mockedRequest.mock.calls).toEqual([
      ['api/v1/team/clinic/calendar/events', { method: 'POST', body: { ...editable, client_nonce: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' } }],
      ['api/v1/team/clinic/calendar/events/4/update', { method: 'POST', body: { ...editable, version: 2 } }],
      ['api/v1/team/clinic/calendar/events/4/cancel', { method: 'POST', body: { version: 3 } }],
    ]);
  });
});

describe('Manila-safe calendar adapter', () => {
  it('validates wall values without device-local parsing', () => {
    expect(parseManilaWall('2026-02-28T23:59')).toEqual({ year: 2026, month: 2, day: 28, hour: 23, minute: 59 });
    expect(parseManilaWall('2026-02-29T12:00')).toBeNull();
    expect(parseManilaWall('2026-12-10T24:00')).toBeNull();
    expect(parseManilaWall('2026-12-10 09:00')).toBeNull();
  });

  it('maps Manila wall time to an instant and back exactly', () => {
    const value = '2026-12-10T09:05';
    const instant = manilaWallToPickerDate(value);
    expect(instant.toISOString()).toBe('2026-12-10T01:05:00.000Z');
    expect(formatManilaWall(pickerDateToManilaParts(instant))).toBe(value);
    expect(instantToManilaWall('2026-12-10T01:05:00Z')).toBe(value);
  });

  it('merges native date and time selections using Manila components', () => {
    expect(mergePickerSelection('2026-12-10T09:05', new Date('2027-01-02T15:45:00Z'), 'date')).toBe('2027-01-02T09:05');
    expect(mergePickerSelection('2026-12-10T09:05', new Date('2027-01-02T15:45:00Z'), 'time')).toBe('2026-12-10T23:45');
  });

  it('handles week and month boundaries deterministically', () => {
    expect(startOfManilaWeek('2026-11-01')).toBe('2026-10-26');
    expect(addManilaDays('2024-02-28', 1)).toBe('2024-02-29');
    expect(addManilaMonths('2026-01-31', 1)).toBe('2026-02-28');
    const dates = monthGridDates('2026-11-06');
    expect(dates).toHaveLength(42);
    expect(dates[0]).toBe('2026-10-26');
    expect(dates[41]).toBe('2026-12-06');
  });
});

describe('Team Calendar presentation and integration seams', () => {
  it('keeps event and task deadline behavior distinct', () => {
    expect(itemsForDate([event, task], '2026-12-10').map(item => item.type)).toEqual(['task_deadline', 'event']);
    expect(itemOccursOnDate(overnightEvent, '2026-12-10')).toBe(true);
    expect(itemOccursOnDate(overnightEvent, '2026-12-11')).toBe(true);
    expect(itemOccursOnDate(task, '2026-12-11')).toBe(false);
  });

  it('groups agenda items by Manila day and clamps earlier spanning starts to range start', () => {
    const spanning: TeamCalendarItem = {
      ...event,
      id: 9,
      starts_at: '2026-12-08T00:00:00Z',
      ends_at: '2026-12-11T00:00:00Z',
    };
    expect(agendaSections([event, task, spanning], '2026-12-10').map(section => [section.date, section.data.map(item => item.id)])).toEqual([
      ['2026-12-10', [9, 8, 4]],
    ]);
  });

  it('normalizes bounded participant ids', () => {
    expect(normalizeParticipantIds([5, 1, 5, -1, 0, 3])).toEqual([1, 3, 5]);
    expect(normalizeParticipantIds(Array.from({ length: 105 }, (_, index) => index + 1))).toHaveLength(100);
  });

  it('exposes tenant/user/unit-scoped cache roots and task routing seam', () => {
    expect(teamCalendarQueryKeys.unit(11, 22, 'clinic')).toEqual(['team-calendar', 11, 22, 'clinic']);
    expect(teamTaskDeadlineRoute(8)).toEqual({ tab: 'TeamBoard', screen: 'TeamTaskDetail', params: { id: 8 } });
  });
});

describe('Team Calendar error states', () => {
  it.each([
    [new NetworkError(), 'offline'],
    [new ApiError(401, { code: 'unauthenticated', message: 'Sign in' }), 'unauthorized'],
    [new ApiError(403, { code: 'team_membership_required', message: 'No access' }), 'forbidden'],
    [new ApiError(409, { code: 'stale_version', message: 'Changed' }), 'stale'],
    [new ApiError(409, { code: 'idempotency_conflict', message: 'Nonce differs' }), 'idempotency'],
    [new ApiError(409, { code: 'terminal_state', message: 'Cancelled' }), 'conflict'],
    [new ApiError(404, { code: 'not_found', message: 'Missing' }), 'missing'],
    [new ApiError(422, { code: 'validation_failed', message: 'Invalid' }), 'validation'],
    [new ApiError(503, { code: 'unavailable', message: 'Unavailable' }), 'unavailable'],
  ])('classifies %s as %s', (error, expected) => {
    expect(teamCalendarErrorKind(error)).toBe(expected);
  });

  it('marks only transport failure as an ambiguous create result', () => {
    expect(isAmbiguousCreateError(new NetworkError())).toBe(true);
    expect(isAmbiguousCreateError(new ApiError(409, { code: 'stale_version', message: 'Changed' }))).toBe(false);
    expect(teamCalendarErrorMessage(new ApiError(409, { code: 'stale_version', message: 'Changed' }), 'fallback')).toContain('changed');
    expect(teamCalendarErrorMessage(new ApiError(403, { code: 'forbidden', message: 'No' }), 'fallback')).toContain('access');
  });
});
