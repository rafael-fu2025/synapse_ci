import { ApiError, NetworkError } from '../../api/client';
import type {
  TeamChatMentionInput,
  TeamChatMessage,
  TeamChatSendInput,
} from '../../api/endpoints/teamChat';

export const CHAT_PAGE_LIMIT = 40;
export const CHAT_WINDOW_LIMIT = 400;
export const CHAT_POLL_MS = 5_000;
export const CHAT_TASK_LIMIT = 20;
export const CHAT_TASK_SEARCH_LIMIT = 40;
export const CHAT_MEMBER_SEARCH_LIMIT = 20;

export type TeamChatMode = 'latest' | 'history';
export type TeamChatMergeDirection = 'latest' | 'older' | 'newer';
export type TeamChatErrorKind =
  | 'offline'
  | 'unauthorized'
  | 'forbidden'
  | 'validation'
  | 'idempotency'
  | 'other';

export interface MentionQuery {
  start: number;
  query: string;
}

export interface ChatDayRow {
  kind: 'day';
  key: string;
  label: string;
}

export interface ChatUnreadRow {
  kind: 'unread';
  key: string;
}

export interface ChatMessageRow {
  kind: 'message';
  key: string;
  message: TeamChatMessage;
  startsGroup: boolean;
  endsGroup: boolean;
}

export interface ChatReadMarkerRow {
  kind: 'read-marker';
  key: string;
  messageId: number;
}

export type TeamChatRow = ChatDayRow | ChatUnreadRow | ChatMessageRow | ChatReadMarkerRow;

const manilaDayKey = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Manila',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
});
const manilaDayLabel = new Intl.DateTimeFormat('en-PH', {
  timeZone: 'Asia/Manila',
  dateStyle: 'long',
});
const manilaTime = new Intl.DateTimeFormat('en-PH', {
  timeZone: 'Asia/Manila',
  hour: 'numeric',
  minute: '2-digit',
});

export function parseChatTimestamp(value: string): Date | null {
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? date : null;
}

export function formatChatTime(value: string): string {
  const date = parseChatTimestamp(value);
  return date === null ? '' : manilaTime.format(date);
}

export function buildChatRows(
  messages: readonly TeamChatMessage[],
  lastReadId: number,
  actorId: number,
  includeUnread = true,
  includeReadMarkers = false,
): TeamChatRow[] {
  const rows: TeamChatRow[] = [];
  let day = '';
  let unreadInserted = false;
  let previous: ChatMessageRow | null = null;
  let previousDate: Date | null = null;
  for (const message of [...messages].sort((a, b) => a.id - b.id)) {
    const date = parseChatTimestamp(message.created_at);
    const key = date === null ? 'unknown' : manilaDayKey.format(date);
    if (key !== day) {
      rows.push({
        kind: 'day',
        key: `day:${key}:${message.id}`,
        label: date === null ? 'Date unavailable' : manilaDayLabel.format(date),
      });
      day = key;
      previous = null;
    }
    if (
      includeUnread &&
      !unreadInserted &&
      message.id > lastReadId &&
      message.sender.id !== actorId
    ) {
      rows.push({ kind: 'unread', key: `unread:${message.id}` });
      unreadInserted = true;
      previous = null;
    }
    const gap = date !== null && previousDate !== null
      ? date.getTime() - previousDate.getTime()
      : -1;
    const continuesGroup = previous !== null &&
      previous.message.sender.id === message.sender.id &&
      previous.message.reply_to === null && message.reply_to === null &&
      previous.message.tasks.length === 0 && message.tasks.length === 0 &&
      gap >= 0 && gap <= 5 * 60_000;
    const row: ChatMessageRow = {
      kind: 'message',
      key: `message:${message.id}`,
      message,
      startsGroup: !continuesGroup,
      endsGroup: true,
    };
    if (continuesGroup && previous !== null) { previous.endsGroup = false; }
    rows.push(row);
    // Markers track individual visibility, not visual group boundaries.
    previous = row;
    previousDate = date;
    if (includeReadMarkers) {
      // A bounded-height row after the bubble also works for viewport-tall text:
      // the cursor advances only once the message's actual bottom is visible.
      rows.push({ kind: 'read-marker', key: `read-marker:${message.id}`, messageId: message.id });
    }
  }
  return rows;
}

function sameMessage(left: TeamChatMessage, right: TeamChatMessage): boolean {
  return left === right || (
    left.id === right.id && left.body === right.body &&
    left.created_at === right.created_at && left.client_nonce === right.client_nonce &&
    left.sender.id === right.sender.id && left.sender.name === right.sender.name &&
    left.tasks.length === right.tasks.length && left.tasks.every((task, index) =>
      task.id === right.tasks[index].id && task.title === right.tasks[index].title) &&
    left.mentions.length === right.mentions.length && left.mentions.every((mention, index) => {
      const next = right.mentions[index];
      return mention.user_id === next.user_id && mention.name === next.name &&
        mention.start === next.start && mention.length === next.length;
    }) &&
    (left.reply_to === right.reply_to || (
      left.reply_to !== null && right.reply_to !== null &&
      left.reply_to.id === right.reply_to.id &&
      left.reply_to.sender_name === right.reply_to.sender_name &&
      left.reply_to.body === right.reply_to.body
    ))
  );
}

/** Bound the window without replacing unchanged messages (or an unchanged array). */
export function mergeChatMessages(
  current: readonly TeamChatMessage[],
  incoming: readonly TeamChatMessage[],
  direction: TeamChatMergeDirection,
  limit = CHAT_WINDOW_LIMIT,
  replace = false,
): TeamChatMessage[] {
  const existing = new Map(current.map(message => [message.id, message]));
  const byId = replace ? new Map<number, TeamChatMessage>() : new Map(existing);
  for (const message of incoming) {
    const previous = existing.get(message.id);
    byId.set(message.id, previous && sameMessage(previous, message) ? previous : message);
  }
  const ordered = [...byId.values()].sort((a, b) => a.id - b.id);
  const bounded = ordered.length <= limit ? ordered : direction === 'older'
    ? ordered.slice(0, limit)
    : ordered.slice(ordered.length - limit);
  if (current.length === bounded.length && bounded.every((message, index) => message === current[index])) {
    // Callers treat both the window and its messages as immutable.
    return current as TeamChatMessage[];
  }
  return bounded;
}

/** Locate an unfinished @name immediately before the collapsed cursor. */
export function mentionQueryAt(text: string, cursor: number): MentionQuery | null {
  if (!Number.isInteger(cursor) || cursor < 0 || cursor > text.length) { return null; }
  const prefix = text.slice(0, cursor);
  const match = /(?:^|\s)@([^@\n]{0,80})$/.exec(prefix);
  if (match === null) { return null; }
  return { start: prefix.length - match[1].length - 1, query: match[1] };
}

/**
 * Keep unaffected mention identities across one React Native text edit. JS string
 * indexes are UTF-16 code units, which is exactly the API's offset contract.
 */
export function updateMentionRanges(
  previous: string,
  next: string,
  mentions: readonly TeamChatMentionInput[],
): TeamChatMentionInput[] {
  let start = 0;
  while (
    start < previous.length &&
    start < next.length &&
    previous[start] === next[start]
  ) {
    start += 1;
  }
  let oldEnd = previous.length;
  let newEnd = next.length;
  while (
    oldEnd > start &&
    newEnd > start &&
    previous[oldEnd - 1] === next[newEnd - 1]
  ) {
    oldEnd -= 1;
    newEnd -= 1;
  }
  const delta = newEnd - oldEnd;
  return mentions.flatMap(mention => {
    const mentionEnd = mention.start + mention.length;
    if (mentionEnd <= start) { return [mention]; }
    if (mention.start >= oldEnd) {
      return [{ ...mention, start: mention.start + delta }];
    }
    // Editing any part of the bound text removes its identity binding.
    return [];
  });
}

export function insertMention(
  text: string,
  range: MentionQuery,
  cursor: number,
  member: { id: number; name: string },
): { text: string; cursor: number; mention: TeamChatMentionInput } {
  const token = `@${member.name}`;
  const suffix = cursor < text.length && /^\s/.test(text.slice(cursor)) ? '' : ' ';
  const next = text.slice(0, range.start) + token + suffix + text.slice(cursor);
  return {
    text: next,
    cursor: range.start + token.length + suffix.length,
    mention: { user_id: member.id, start: range.start, length: token.length },
  };
}

export function normalizeMentions(
  body: string,
  mentions: readonly TeamChatMentionInput[],
): TeamChatMentionInput[] {
  const ordered = [...mentions]
    .filter(item =>
      Number.isInteger(item.user_id) &&
      Number.isInteger(item.start) &&
      Number.isInteger(item.length) &&
      item.user_id > 0 &&
      item.start >= 0 &&
      item.length > 0 &&
      item.start + item.length <= body.length,
    )
    .sort((a, b) => a.start - b.start);
  return ordered.reduce<TeamChatMentionInput[]>((valid, item) => {
    const previous = valid.at(-1);
    if (previous === undefined || item.start >= previous.start + previous.length) {
      valid.push(item);
    }
    return valid;
  }, []);
}

export function messageValidation(body: string, taskCount: number): string | null {
  if (!/\S/u.test(body)) { return 'Enter a message.'; }
  if (Array.from(body).length > 2_000) {
    return 'Messages can contain at most 2,000 characters.';
  }
  // The backend rejects C0 controls except tab/newline/carriage return.
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(body)) {
    return 'Remove unsupported control characters.';
  }
  if (taskCount > 20) { return 'Choose up to 20 task references.'; }
  return null;
}

export function sendSnapshot(input: Omit<TeamChatSendInput, 'client_nonce'>): string {
  return JSON.stringify(input);
}

export function teamChatErrorKind(error: unknown): TeamChatErrorKind {
  if (error instanceof NetworkError) { return 'offline'; }
  if (error instanceof ApiError) {
    if (error.status === 401) { return 'unauthorized'; }
    if (error.status === 403) { return 'forbidden'; }
    if (error.status === 422) { return 'validation'; }
    if (error.status === 409 && error.code === 'idempotency_conflict') {
      return 'idempotency';
    }
  }
  return 'other';
}

export function teamChatErrorMessage(error: unknown, fallback: string): string {
  const kind = teamChatErrorKind(error);
  if (kind === 'offline') { return 'You appear to be offline. Reconnect and try again.'; }
  if (kind === 'unauthorized') { return 'Your session expired. Sign in again.'; }
  if (kind === 'forbidden') { return 'You no longer have access to this Team Workspace.'; }
  if (kind === 'validation') { return 'The request was not valid. Review it and try again.'; }
  if (kind === 'idempotency') { return 'This send token was already used for different contents. Review the latest messages before trying again.'; }
  return error instanceof ApiError && error.message ? error.message : fallback;
}

export function isAmbiguousSendFailure(error: unknown): boolean {
  const kind = teamChatErrorKind(error);
  return kind === 'offline' || kind === 'other';
}

export function isChatDirty(input: {
  body: string;
  taskCount: number;
  hasReply: boolean;
  hasPendingSend: boolean;
}): boolean {
  return input.body.trim() !== '' || input.taskCount > 0 || input.hasReply || input.hasPendingSend;
}
