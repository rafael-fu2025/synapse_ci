import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as notificationsApi from '../../api/endpoints/notifications';
import { queryKeys } from '../../api/queryKeys';

export function useNotificationInbox() {
  return useQuery({
    queryKey: queryKeys.notifications,
    queryFn: () => notificationsApi.inbox(),
    refetchInterval: poll.notificationsMs,
  });
}

export function useNotificationHistory(page: number, filter: 'all' | 'unread') {
  return useQuery({
    queryKey: queryKeys.notificationHistory(page, filter),
    queryFn: () => notificationsApi.history(page, filter),
  });
}

export function useMarkNotificationRead() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => notificationsApi.markRead(id),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['notifications'] }).catch(() => undefined);
    },
  });
}

export function useMarkAllNotificationsRead() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => notificationsApi.markAllRead(),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['notifications'] }).catch(() => undefined);
    },
  });
}
