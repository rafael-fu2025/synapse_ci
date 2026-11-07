import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useIsFocused } from '@react-navigation/native';
import { poll } from '../../api/poll';
import * as notificationsApi from '../../api/endpoints/notifications';
import { queryKeys } from '../../api/queryKeys';
import { useRealtimePoll } from '../../lib/useRealtimePoll';

export function useNotificationInbox() {
  const focused = useIsFocused();
  const refetchInterval = useRealtimePoll(poll.notificationsMs);
  return useQuery({
    queryKey: queryKeys.notifications,
    queryFn: () => notificationsApi.inbox(),
    enabled: focused,
    refetchInterval,
  });
}

export function useNotificationHistory(page: number, filter: 'all' | 'unread') {
  const focused = useIsFocused();
  const refetchInterval = useRealtimePoll(poll.notificationsMs);
  return useQuery({
    queryKey: queryKeys.notificationHistory(page, filter),
    queryFn: () => notificationsApi.history(page, filter),
    enabled: focused,
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
