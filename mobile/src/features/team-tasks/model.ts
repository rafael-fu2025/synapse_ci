import type { TeamTaskPriority, TeamTaskStatus, TeamTaskSummary } from '../../api/endpoints/teamTasks';
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
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) { return false; }
  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}
