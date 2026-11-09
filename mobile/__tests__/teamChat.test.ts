import { ApiError, NetworkError, __resetClientForTests, setAccessToken } from '../src/api/client';
import * as teamChatApi from '../src/api/endpoints/teamChat';
import type { TeamChatMessage } from '../src/api/endpoints/teamChat';
import {
  buildChatRows,
  type ChatMessageRow,
  insertMention,
  isAmbiguousSendFailure,
  mentionQueryAt,
  mergeChatMessages,
  messageValidation,
  teamChatErrorKind,
  updateMentionRanges,
} from '../src/features/team-chat/model';
import { teamChatKeys } from '../src/features/team-chat/hooks';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({
  getRefreshToken: async () => null,
  setRefreshToken: async () => undefined,
  clearRefreshToken: async () => undefined,
}));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('@react-navigation/native', () => ({ useIsFocused: () => true }));
jest.mock('../src/lib/useActiveScreen', () => ({ useActiveScreen: () => true, useForegroundOnline: () => true }));

function response(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function message(id: number, changes: Partial<TeamChatMessage> = {}): TeamChatMessage {
  return {
    id,
    body: `Message ${id}`,
    sender: { id: 2, name: 'Person 2' },
    created_at: `2026-11-${id < 3 ? '06' : '07'}T00:00:00+00:00`,
    client_nonce: null,
    tasks: [],
    mentions: [],
    reply_to: null,
    ...changes,
  };
}

describe('Team Chat API contract', () => {
  beforeEach(() => {
    __resetClientForTests();
    setAccessToken('a'.repeat(64));
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('uses the corrected messages route and strict cursor query', async () => {
    const fetchMock = jest.fn().mockResolvedValue(response(200, { data: {
      messages: [], has_more: false, next_before: null,
      last_read_id: 0, unread_count: 0, latest_id: 0,
    } }));
    globalThis.fetch = fetchMock as unknown as typeof fetch;

    await teamChatApi.history('clinic', { around: 27 });

    expect(String(fetchMock.mock.calls[0][0])).toBe('http://api.test/api/v1/team/clinic/chat/messages?around=27');
  });

  it('sends immutable retry fields and reads through the current backend routes', async () => {
    const fetchMock = jest.fn()
      .mockResolvedValueOnce(response(201, { data: message(8) }))
      .mockResolvedValueOnce(response(200, { data: { last_read_id: 8, unread_count: 0, latest_id: 8 } }));
    globalThis.fetch = fetchMock as unknown as typeof fetch;
    const input: teamChatApi.TeamChatSendInput = {
      body: '😀 @Person 2',
      client_nonce: '12345678-1234-4234-8234-123456789abc',
      task_ids: [4, 5],
      mentions: [{ user_id: 2, start: 3, length: 9 }],
      reply_to_id: 3,
    };

    await teamChatApi.send('guidance', input);
    await teamChatApi.markRead('guidance', 8);

    expect(String(fetchMock.mock.calls[0][0])).toBe('http://api.test/api/v1/team/guidance/chat/messages');
    expect(JSON.parse((fetchMock.mock.calls[0][1] as RequestInit).body as string)).toEqual(input);
    expect(String(fetchMock.mock.calls[1][0])).toBe('http://api.test/api/v1/team/guidance/chat/read');
  });

  it('bounds member suggestions at 20 and task search server-side', async () => {
    globalThis.fetch = jest.fn()
      .mockResolvedValueOnce(response(200, { data: [], meta: {} }))
      .mockResolvedValueOnce(response(200, { data: [], meta: {} })) as unknown as typeof fetch;

    await teamChatApi.members('facilities', 'ann');
    await teamChatApi.tasks('facilities', 'active');

    expect(String(jest.mocked(globalThis.fetch).mock.calls[0][0])).toContain('/members?q=ann&page=1&limit=20');
    expect(String(jest.mocked(globalThis.fetch).mock.calls[1][0])).toContain('/chat/tasks?q=active');
  });
});

describe('Team Chat cursor and display helpers', () => {
  it('merges immutable ids in ascending order and caps the correct side', () => {
    const current = [message(3), message(4), message(5)];
    expect(mergeChatMessages(current, [message(1), message(2)], 'older', 4).map(item => item.id)).toEqual([1, 2, 3, 4]);
    expect(mergeChatMessages(current, [message(6), message(7)], 'newer', 4).map(item => item.id)).toEqual([4, 5, 6, 7]);
  });

  it('adds Manila day separators and one unread divider before the first other-user message', () => {
    const rows = buildChatRows([
      message(1, { sender: { id: 1, name: 'Me' } }),
      message(2),
      message(3),
    ], 1, 1);
    expect(rows.map(row => row.kind)).toEqual(['day', 'message', 'unread', 'message', 'day', 'message']);
  });

  it('keeps references for unchanged pages while replacing changed reply/task/mention fields', () => {
    const current = [message(1), message(2)];
    expect(mergeChatMessages(current, [message(1), message(2)], 'newer')).toBe(current);
    const next = mergeChatMessages(current, [message(2, { tasks: [{ id: 1, title: 'Renamed' }] })], 'newer');
    expect(next[0]).toBe(current[0]);
    expect(next[1]).not.toBe(current[1]);
    expect(mergeChatMessages(next, [], 'newer')).toBe(next);
  });

  it('adds bounded-height read markers after actual messages when requested', () => {
    const rows = buildChatRows([message(1)], 0, 1, true, true);
    expect(rows.at(-1)).toEqual({ kind: 'read-marker', key: 'read-marker:1', messageId: 1 });
    expect(rows.at(-2)?.kind).toBe('message');
  });

  it('uses a distinct task-picker prefix for task-only invalidation', () => {
    const identity = { tenantId: 9, userId: 12 };
    expect(teamChatKeys.taskPicker(identity, 'clinic')).toEqual(['team-chat', 9, 12, 'clinic', 'tasks']);
    expect(teamChatKeys.tasks(identity, 'clinic', '  active  ')).toEqual(['team-chat', 9, 12, 'clinic', 'tasks', 'active']);
  });

  it('uses the exact local realtime invalidation root', () => {
    expect(teamChatKeys.unit({ tenantId: 9, userId: 12 }, 'clinic')).toEqual(['team-chat', 9, 12, 'clinic']);
  });
});

describe('Team Chat message grouping', () => {
  function messageRows(
    messages: readonly TeamChatMessage[],
    lastReadId = 0,
    actorId = 1,
    includeUnread = true,
    includeReadMarkers = false,
  ): ChatMessageRow[] {
    return buildChatRows(messages, lastReadId, actorId, includeUnread, includeReadMarkers)
      .filter((row): row is ChatMessageRow => row.kind === 'message');
  }

  function grouping(rows: readonly ChatMessageRow[]) {
    return rows.map(row => ({
      id: row.message.id,
      startsGroup: row.startsGroup,
      endsGroup: row.endsGroup,
    }));
  }

  it('handles empty and single-message windows as standalone without changing row identities', () => {
    expect(buildChatRows([], 0, 1)).toEqual([]);
    const value = message(1);
    const rows = messageRows([value]);
    expect(rows).toEqual([{
      kind: 'message', key: 'message:1', message: value, startsGroup: true, endsGroup: true,
    }]);
    expect(rows[0].message).toBe(value);
  });

  it('groups adjacent sender ids across name changes and mentions through exactly five minutes', () => {
    const rows = messageRows([
      message(1, { created_at: '2026-11-06T00:00:00.000Z' }),
      message(2, { created_at: '2026-11-06T00:05:00.000Z', sender: { id: 2, name: 'Renamed' } }),
      message(3, {
        created_at: '2026-11-06T00:05:00.000Z',
        body: '@Person 2',
        mentions: [{ user_id: 2, name: 'Person 2', start: 0, length: 9 }],
      }),
      message(4, { created_at: '2026-11-06T00:10:00.001Z' }),
    ]);
    expect(grouping(rows)).toEqual([
      { id: 1, startsGroup: true, endsGroup: false },
      { id: 2, startsGroup: false, endsGroup: false },
      { id: 3, startsGroup: false, endsGroup: true },
      { id: 4, startsGroup: true, endsGroup: true },
    ]);
  });

  it('breaks both connections at a sender change even when names match', () => {
    const rows = messageRows([
      message(1),
      message(2, { sender: { id: 3, name: 'Person 2' } }),
      message(3, { created_at: message(1).created_at }),
    ]);
    expect(grouping(rows)).toEqual([
      { id: 1, startsGroup: true, endsGroup: true },
      { id: 2, startsGroup: true, endsGroup: true },
      { id: 3, startsGroup: true, endsGroup: true },
    ]);
  });

  it('breaks at Manila midnight even within the same UTC day and five-minute interval', () => {
    const rows = buildChatRows([
      message(1, { created_at: '2026-11-06T15:59:00Z' }),
      message(2, { created_at: '2026-11-06T16:00:00Z' }),
    ], 0, 1);
    expect(rows.map(row => row.key)).toEqual([
      'day:2026-11-06:1', 'unread:1', 'message:1', 'day:2026-11-07:2', 'message:2',
    ]);
    expect(grouping(rows.filter((row): row is ChatMessageRow => row.kind === 'message'))).toEqual([
      { id: 1, startsGroup: true, endsGroup: true },
      { id: 2, startsGroup: true, endsGroup: true },
    ]);
  });

  it('groups equivalent timestamps across offsets and UTC days when the Manila day matches', () => {
    const rows = messageRows([
      message(1, { created_at: '2026-11-06T23:59:00Z' }),
      message(2, { created_at: '2026-11-07T08:00:00+08:00' }),
    ]);
    expect(grouping(rows)).toEqual([
      { id: 1, startsGroup: true, endsGroup: false },
      { id: 2, startsGroup: false, endsGroup: true },
    ]);
  });

  it('breaks at the unread divider and regroups when that divider is hidden', () => {
    const values = [message(1), message(2), message(3, { created_at: message(1).created_at })];
    const rows = buildChatRows(values, 1, 1);
    expect(rows.map(row => row.key)).toEqual([
      'day:2026-11-06:1', 'message:1', 'unread:2', 'message:2', 'message:3',
    ]);
    expect(grouping(messageRows(values, 1))).toEqual([
      { id: 1, startsGroup: true, endsGroup: true },
      { id: 2, startsGroup: true, endsGroup: false },
      { id: 3, startsGroup: false, endsGroup: true },
    ]);
    const connected = [
      { id: 1, startsGroup: true, endsGroup: false },
      { id: 2, startsGroup: false, endsGroup: false },
      { id: 3, startsGroup: false, endsGroup: true },
    ];
    expect(grouping(messageRows(values, 1, 1, false))).toEqual(connected);
    expect(grouping(messageRows(values, 3))).toEqual(connected);
    expect(grouping(messageRows(values, 1, 2))).toEqual(connected);
  });

  it('does not reorder backwards timestamps and starts a new forward group after the break', () => {
    const rows = messageRows([
      message(1, { created_at: '2026-11-06T00:02:00Z' }),
      message(2, { created_at: '2026-11-06T00:01:00Z' }),
      message(3, { created_at: '2026-11-06T00:02:00Z' }),
    ]);
    expect(grouping(rows)).toEqual([
      { id: 1, startsGroup: true, endsGroup: true },
      { id: 2, startsGroup: true, endsGroup: false },
      { id: 3, startsGroup: false, endsGroup: true },
    ]);
  });

  it.each(['not-a-date', '', '2026-13-06T00:00:00Z'])(
    'makes invalid timestamps standalone and breaks either connection (%p)',
    created_at => {
      const rows = messageRows([
        message(1),
        message(2, { created_at }),
        message(3, { created_at }),
        message(4, { created_at: message(1).created_at }),
      ]);
      expect(grouping(rows)).toEqual([
        { id: 1, startsGroup: true, endsGroup: true },
        { id: 2, startsGroup: true, endsGroup: true },
        { id: 3, startsGroup: true, endsGroup: true },
        { id: 4, startsGroup: true, endsGroup: true },
      ]);
    },
  );

  it.each<{ label: string; changes: Partial<TeamChatMessage> }>([
    { label: 'reply', changes: { reply_to: { id: 9, sender_name: 'Person 9', body: 'Original' } } },
    { label: 'task reference', changes: { tasks: [{ id: 7, title: 'Task 7' }] } },
    {
      label: 'reply with task reference',
      changes: {
        reply_to: { id: 9, sender_name: 'Person 9', body: 'Original' },
        tasks: [{ id: 7, title: 'Task 7' }],
      },
    },
  ])('keeps a $label standalone while grouping its unaffected neighbors', ({ changes }) => {
    const created_at = message(1).created_at;
    const values = [
      message(1), message(2), message(3, { ...changes, created_at }),
      message(4, { created_at }), message(5, { created_at }),
    ];
    expect(grouping(messageRows(values))).toEqual([
      { id: 1, startsGroup: true, endsGroup: false },
      { id: 2, startsGroup: false, endsGroup: true },
      { id: 3, startsGroup: true, endsGroup: true },
      { id: 4, startsGroup: true, endsGroup: false },
      { id: 5, startsGroup: false, endsGroup: true },
    ]);
    expect(messageRows(values)[2].message).toBe(values[2]);
  });

  it('keeps ordered ids, keys, objects and structural rows stable with or without read markers', () => {
    const first = Object.freeze(message(1));
    const second = Object.freeze(message(2));
    const third = Object.freeze(message(3));
    const values = Object.freeze([third, first, second]);
    const withoutMarkers = buildChatRows(values, 1, 1);
    const withMarkers = buildChatRows(values, 1, 1, true, true);
    expect(withoutMarkers.map(row => row.key)).toEqual([
      'day:2026-11-06:1', 'message:1', 'unread:2', 'message:2', 'day:2026-11-07:3', 'message:3',
    ]);
    expect(withMarkers.map(row => row.key)).toEqual([
      'day:2026-11-06:1', 'message:1', 'read-marker:1', 'unread:2',
      'message:2', 'read-marker:2', 'day:2026-11-07:3', 'message:3', 'read-marker:3',
    ]);
    expect(withMarkers.filter(row => row.kind !== 'read-marker')).toEqual(withoutMarkers);
    const rows = withMarkers.filter((row): row is ChatMessageRow => row.kind === 'message');
    expect(rows.map(row => row.message.id)).toEqual([1, 2, 3]);
    [first, second, third].forEach((value, index) => {
      expect(rows[index].message).toBe(value);
      expect(value).not.toHaveProperty('startsGroup');
      expect(value).not.toHaveProperty('endsGroup');
    });
    expect(values[0]).toBe(third);
    expect(values[1]).toBe(first);
    expect(values[2]).toBe(second);
    withMarkers.forEach((row, index) => {
      if (row.kind !== 'message') { return; }
      expect(withMarkers[index + 1]).toEqual({
        kind: 'read-marker', key: `read-marker:${row.message.id}`, messageId: row.message.id,
      });
    });
    expect(new Set(withMarkers.map(row => row.key)).size).toBe(withMarkers.length);
  });

  it.each([true, false])('ignores read markers inside groups with includeUnread=%p', includeUnread => {
    const values = [message(1), message(2), message(3, { created_at: message(1).created_at })];
    const withMarkers = buildChatRows(values, 0, 1, includeUnread, true);
    expect(withMarkers.filter(row => row.kind !== 'read-marker'))
      .toEqual(buildChatRows(values, 0, 1, includeUnread));
    expect(grouping(messageRows(values, 0, 1, includeUnread, true))).toEqual([
      { id: 1, startsGroup: true, endsGroup: false },
      { id: 2, startsGroup: false, endsGroup: false },
      { id: 3, startsGroup: false, endsGroup: true },
    ]);
    expect(withMarkers.filter(row => row.kind === 'read-marker')).toEqual([
      { kind: 'read-marker', key: 'read-marker:1', messageId: 1 },
      { kind: 'read-marker', key: 'read-marker:2', messageId: 2 },
      { kind: 'read-marker', key: 'read-marker:3', messageId: 3 },
    ]);
  });

  it.each([true, false])('regroups an appended message without mutating previous rows or objects (markers=%p)', includeReadMarkers => {
    const first = Object.freeze(message(1));
    const second = Object.freeze(message(2));
    const current = [first];
    const previous = buildChatRows(current, 0, 1, true, includeReadMarkers);
    previous.forEach(row => Object.freeze(row));
    const next = mergeChatMessages(current, [second], 'newer');
    const appended = buildChatRows(next, 0, 1, true, includeReadMarkers);
    const appendedMessages = appended.filter((row): row is ChatMessageRow => row.kind === 'message');
    expect(grouping(appendedMessages)).toEqual([
      { id: 1, startsGroup: true, endsGroup: false },
      { id: 2, startsGroup: false, endsGroup: true },
    ]);
    expect(grouping(previous.filter((row): row is ChatMessageRow => row.kind === 'message'))).toEqual([
      { id: 1, startsGroup: true, endsGroup: true },
    ]);
    expect(appendedMessages[0].key).toBe('message:1');
    expect(appendedMessages[0].message).toBe(first);
    expect(appendedMessages[1].message).toBe(second);
    expect(appended.filter(row => row.kind !== 'message' && row.key !== 'read-marker:2'))
      .toEqual(previous.filter(row => row.kind !== 'message'));
    const later = mergeChatMessages(next, [message(3, { created_at: '2026-11-06T00:05:00.001Z' })], 'newer');
    expect(grouping(messageRows(later, 0, 1, true, includeReadMarkers))).toEqual([
      { id: 1, startsGroup: true, endsGroup: false },
      { id: 2, startsGroup: false, endsGroup: true },
      { id: 3, startsGroup: true, endsGroup: true },
    ]);
  });
});

describe('Team Chat UTF-16 mentions and send retries', () => {
  it('finds and inserts a member after an emoji using JavaScript UTF-16 offsets', () => {
    const body = '😀 hello @Per';
    const match = mentionQueryAt(body, body.length)!;
    const inserted = insertMention(body, match, body.length, { id: 2, name: 'Person 2' });

    expect(match).toEqual({ start: 9, query: 'Per' });
    expect(inserted.text).toBe('😀 hello @Person 2 ');
    expect(inserted.mention).toEqual({ user_id: 2, start: 9, length: 9 });
  });

  it('shifts later mentions and removes a binding edited through', () => {
    const ranges = [
      { user_id: 2, start: 3, length: 9 },
      { user_id: 3, start: 13, length: 9 },
    ];
    expect(updateMentionRanges('😀 @Person 2 @Person 3', 'x😀 @Person 2 @Person 3', ranges)).toEqual([
      { user_id: 2, start: 4, length: 9 },
      { user_id: 3, start: 14, length: 9 },
    ]);
    expect(updateMentionRanges('😀 @Person 2 @Person 3', '😀 @X 2 @Person 3', ranges)).toEqual([
      { user_id: 3, start: 8, length: 9 },
    ]);
  });

  it('keeps ambiguous failures retryable but unlocks validation/conflict edits', () => {
    expect(isAmbiguousSendFailure(new NetworkError())).toBe(true);
    expect(isAmbiguousSendFailure(new ApiError(503, { code: 'service_unavailable', message: 'retry' }))).toBe(true);
    expect(isAmbiguousSendFailure(new ApiError(422, { code: 'validation_failed', message: 'fix' }))).toBe(false);
    expect(teamChatErrorKind(new ApiError(409, { code: 'idempotency_conflict', message: 'different' }))).toBe('idempotency');
  });

  it('validates visible composer limits without trimming immutable content', () => {
    expect(messageValidation('   ', 0)).toBe('Enter a message.');
    expect(messageValidation('hello', 21)).toContain('20');
    expect(messageValidation('  hello\n', 2)).toBeNull();
  });
});
