export const queryKeys = {
  me: ['me'] as const,
  appointments: (filters: { service: string; status: string; sort: string }) => ['appointments', filters] as const,
  appointment: (id: number) => ['appointments', 'detail', id] as const,
  queue: ['queue'] as const,
  announcements: (filters: { unit: string; status: string; q: string; page: number }) =>
    ['announcements', filters] as const,
  announcement: (id: number) => ['announcements', 'detail', id] as const,
  surveys: (filters: { status: string; q: string }) => ['surveys', filters] as const,
  survey: (id: number) => ['surveys', 'detail', id] as const,
  notifications: ['notifications'] as const,
  notificationHistory: (page: number, filter: string) => ['notifications', 'history', page, filter] as const,
  staffQueue: (unit: string) => ['staff-queue', unit] as const,
} as const;
