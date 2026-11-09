import { bindApiClient, request, type ApiBinding } from '../client';
import type { TeamUnit } from '../types';

export interface TeamChatMember {
  id: number;
  name: string;
  role: string;
}

export interface TeamChatTaskReference {
  id: number;
  title: string;
}

/** JavaScript/React Native string offsets are UTF-16 code units, matching the API. */
export interface TeamChatMentionInput {
  user_id: number;
  start: number;
  length: number;
}

export interface TeamChatMention extends TeamChatMentionInput {
  name: string;
}

export interface TeamChatReply {
  id: number;
  sender_name: string;
  body: string;
}

export interface TeamChatMessage {
  id: number;
  body: string;
  sender: Pick<TeamChatMember, 'id' | 'name'>;
  /** RFC3339 with an explicit offset. */
  created_at: string;
  /** Returned only for the current actor's messages. */
  client_nonce: string | null;
  tasks: TeamChatTaskReference[];
  mentions: TeamChatMention[];
  reply_to: TeamChatReply | null;
}

export interface TeamChatHistory {
  messages: TeamChatMessage[];
  has_more: boolean;
  next_before: number | null;
  last_read_id: number;
  unread_count: number;
  latest_id: number;
}

export type TeamChatHistoryCursor =
  | { before: number; after?: never; around?: never }
  | { before?: never; after: number; around?: never }
  | { before?: never; after?: never; around: number }
  | { before?: never; after?: never; around?: never };

export interface TeamChatSendInput {
  body: string;
  /** UUID v4 generated once and reused only for an identical ambiguous retry. */
  client_nonce: string;
  task_ids: number[];
  mentions: TeamChatMentionInput[];
  reply_to_id: number | null;
}

export interface TeamChatReadSummary {
  last_read_id: number;
  unread_count: number;
  latest_id: number;
}

function base(unit: TeamUnit): string {
  return `api/v1/team/${unit}`;
}

function query(values: Record<string, string | number | undefined>): string {
  const value = Object.entries(values)
    .filter(([, item]) => item !== undefined && item !== '')
    .map(([key, item]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(item))}`)
    .join('&');
  return value === '' ? '' : `?${value}`;
}

/** Latest, older, newer, or around-target history. The server fixes pages at 40. */
export function history(
  unit: TeamUnit,
  cursor: TeamChatHistoryCursor = {},
  binding?: ApiBinding,
): Promise<{ data: TeamChatHistory }> {
  return (binding ? bindApiClient(binding).request : request)(`${base(unit)}/chat/messages${query(cursor)}`);
}

/** Active, authorized task references only; the server fixes results at 40. */
export function tasks(
  unit: TeamUnit,
  q = '',
  binding?: ApiBinding,
): Promise<{
  data: TeamChatTaskReference[];
  meta: { unit: TeamUnit; total: number; limit: number };
}> {
  return (binding ? bindApiClient(binding).request : request)(`${base(unit)}/chat/tasks${query({ q: q.trim() || undefined })}`);
}

/** Current authorized unit members, explicitly bounded for mention suggestions. */
export function members(
  unit: TeamUnit,
  q = '',
  binding?: ApiBinding,
): Promise<{
  data: TeamChatMember[];
  meta: {
    unit: TeamUnit;
    q: string;
    total: number;
    page: number;
    pages: number;
    limit: number;
  };
}> {
  return (binding ? bindApiClient(binding).request : request)(`${base(unit)}/members${query({
    q: q.trim() || undefined,
    page: 1,
    limit: 20,
  })}`);
}

export function send(
  unit: TeamUnit,
  input: TeamChatSendInput,
  binding?: ApiBinding,
): Promise<{ data: TeamChatMessage }> {
  return (binding ? bindApiClient(binding).request : request)(`${base(unit)}/chat/messages`, { method: 'POST', body: input });
}

export function markRead(
  unit: TeamUnit,
  lastReadId: number,
  binding?: ApiBinding,
): Promise<{ data: TeamChatReadSummary }> {
  return (binding ? bindApiClient(binding).request : request)(`${base(unit)}/chat/read`, {
    method: 'POST',
    body: { last_read_id: lastReadId },
  });
}
