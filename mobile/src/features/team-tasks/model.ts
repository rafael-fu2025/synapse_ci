import type { TeamTaskChecklistItem, TeamTaskDetail, TeamTaskEditableInput, TeamTaskPriority, TeamTaskStatus, TeamTaskSummary } from '../../api/endpoints/teamTasks';
import { parseManilaDate } from '../team-calendar/manila';
import type { StatusTone } from '../../theme/tokens';

export const TASK_STATUSES: readonly TeamTaskStatus[] = ['todo', 'in_progress', 'blocked', 'done'];
export const TASK_PRIORITIES: readonly TeamTaskPriority[] = ['low', 'normal', 'high'];

export const STATUS_PRESENTATION: Record<TeamTaskStatus, { label: string; tone: StatusTone }> = {
  todo: { label: 'To do', tone: 'neutral' },
  in_progress: { label: 'In progress', tone: 'info' },
  blocked: { label: 'Blocked', tone: 'danger' },
  done: { label: 'Done', tone: 'success' },
};

export const PRIORITY_LABEL: Record<TeamTaskPriority, string> = {
  low: 'Low', normal: 'Normal', high: 'High',
};

export function groupTasks(tasks: TeamTaskSummary[]) {
  return TASK_STATUSES.map(status => ({
    status,
    title: STATUS_PRESENTATION[status].label,
    data: tasks.filter(task => task.status === status),
  })).filter(section => section.data.length > 0);
}

export function validDateOnly(value: string): boolean {
  return parseManilaDate(value) !== null;
}

/** Local row identity must not change when text is edited or another row is removed. */
export interface ChecklistDraftItem extends TeamTaskChecklistItem {
  clientId: string;
}

export interface TeamTaskDraft extends Omit<TeamTaskEditableInput, 'checklist'> {
  checklist: ChecklistDraftItem[];
}

let nextChecklistId = 0;
export function checklistDraftItem(item: TeamTaskChecklistItem): ChecklistDraftItem {
  nextChecklistId += 1;
  return { ...item, clientId: `checklist-${nextChecklistId}` };
}

export function emptyTaskDraft(): TeamTaskDraft {
  return { title: '', description: '', status: 'todo', priority: 'normal', assignee_user_id: null, due_date: null, checklist: [] };
}

export function taskEditableInput(task: TeamTaskDetail): TeamTaskEditableInput {
  return {
    title: task.title,
    description: task.description,
    status: task.status,
    priority: task.priority,
    assignee_user_id: task.assignee?.id ?? null,
    due_date: task.due_date,
    checklist: task.checklist.map(({ text, done }) => ({ text, done })),
  };
}

export function taskDraft(task: TeamTaskDetail): TeamTaskDraft {
  return { ...taskEditableInput(task), checklist: task.checklist.map(checklistDraftItem) };
}

/** An explicit wire projection prevents client-only checklist IDs escaping into strict API payloads. */
export function taskDraftInput(draft: TeamTaskDraft): TeamTaskEditableInput {
  return {
    title: draft.title,
    description: draft.description,
    status: draft.status,
    priority: draft.priority,
    assignee_user_id: draft.assignee_user_id,
    due_date: draft.due_date,
    checklist: draft.checklist.map(({ text, done }) => ({ text, done })),
  };
}

export function taskDraftSnapshot(draft: TeamTaskDraft): string {
  return JSON.stringify(taskDraftInput(draft));
}
