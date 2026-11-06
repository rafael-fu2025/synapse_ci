import { ApiError, NetworkError } from '../src/api/client';
import { teamTaskErrorKind, teamTaskErrorMessage } from '../src/features/team-tasks/errors';
import { groupTasks, validDateOnly } from '../src/features/team-tasks/model';
import type { TeamTaskSummary } from '../src/api/endpoints/teamTasks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));

const task = (id: number, status: TeamTaskSummary['status']): TeamTaskSummary => ({
  id,
  title: `Task ${id}`,
  status,
  priority: 'normal',
  assignee: null,
  due_date: null,
  checklist_progress: { done: 0, total: 0 },
  version: 1,
  can_edit: true,
  created_at: '2026-11-06T08:00:00+08:00',
  updated_at: '2026-11-06T08:00:00+08:00',
});

describe('Team task presentation helpers', () => {
  it('groups tasks in workflow order without inventing empty sections', () => {
    const sections = groupTasks([task(1, 'done'), task(2, 'todo'), task(3, 'blocked')]);
    expect(sections.map(section => [section.status, section.data.map(item => item.id)])).toEqual([
      ['todo', [2]],
      ['blocked', [3]],
      ['done', [1]],
    ]);
  });

  it.each([
    ['2026-02-28', true],
    ['2024-02-29', true],
    ['2026-02-29', false],
    ['2026-13-01', false],
    ['11/06/2026', false],
    ['', false],
  ])('validates Manila date-only input %s', (value, expected) => {
    expect(validDateOnly(value)).toBe(expected);
  });
});

describe('Team task errors', () => {
  it.each([
    [new NetworkError(), 'offline'],
    [new ApiError(403, { code: 'team_membership_required', message: 'No access' }), 'forbidden'],
    [new ApiError(409, { code: 'stale_version', message: 'Changed' }), 'stale'],
    [new ApiError(409, { code: 'idempotency_conflict', message: 'Nonce differs' }), 'idempotency'],
    [new ApiError(404, { code: 'not_found', message: 'Missing' }), 'missing'],
    [new ApiError(422, { code: 'validation_failed', message: 'Invalid' }), 'validation'],
  ])('classifies %s as %s', (error, expected) => {
    expect(teamTaskErrorKind(error)).toBe(expected);
  });

  it('keeps idempotency and stale guidance distinct', () => {
    expect(teamTaskErrorMessage(new ApiError(409, { code: 'stale_version', message: 'Changed' }), 'fallback')).toContain('changed');
    expect(teamTaskErrorMessage(new ApiError(409, { code: 'idempotency_conflict', message: 'Conflict' }), 'fallback')).toContain('retry');
  });
});
