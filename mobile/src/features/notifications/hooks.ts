import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { poll } from '../../api/poll';
import * as notificationsApi from '../../api/endpoints/notifications';
import { queryKeys } from '../../api/queryKeys';
import { useFocusPoll } from '../../lib/useFocusPoll';

export function useNotificationInbox() {
  const refetchInterval = useFocusPoll(poll.notificationsMs);
  return useQuery({
    queryKey: queryKeys.notifications,
    queryFn: () => notificationsApi.inbox(),
    enabled: refetchInterval !== false,
    refetchInterval,
  });
}

export function useNotificationHistory(page: number, filter: 'all' | 'unread') {
  const refetchInterval = useFocusPoll(poll.notificationsMs);
  return useQuery({
    queryKey: queryKeys.notificationHistory(page, filter),
    queryFn: () => notificationsApi.history(page, filter),
    enabled: refetchInterval !== false,
    refetchInterval,
    // Enabling on navigation focus refreshes even a recently cached page.
    staleTime: 0,
  });
}

export function useMarkNotificationRead() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => notificationsApi.markRead(id),
    onSuccess: () => client.invalidateQueries({ queryKey: ['notifications'] }),
  });
}

export function useMarkAllNotificationsRead() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => notificationsApi.markAllRead(),
    onSuccess: () => client.invalidateQueries({ queryKey: ['notifications'] }),
  });
}
