import { useQuery, useQueryClient } from '@tanstack/react-query';
import { bindApiQueryResult, useApiClient, useApiMutation } from '../../api/runtime';
import { useIsFocused } from '@react-navigation/native';
import { poll } from '../../api/poll';
import * as notificationsApi from '../../api/endpoints/notifications';
import { queryKeys } from '../../api/queryKeys';
import { useRealtimePoll } from '../../lib/useRealtimePoll';

export function useNotificationInbox() {
  const apiClient = useApiClient();
  const focused = useIsFocused();
  const refetchInterval = useRealtimePoll(poll.notificationsMs);
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.notifications,
    queryFn: () => notificationsApi.inbox(apiClient),
    enabled: focused,
    refetchInterval,
  }));
}

export function useNotificationHistory(page: number, filter: 'all' | 'unread') {
  const apiClient = useApiClient();
  const focused = useIsFocused();
  const refetchInterval = useRealtimePoll(poll.notificationsMs);
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.notificationHistory(page, filter),
    queryFn: () => notificationsApi.history(page, filter, apiClient),
    enabled: focused,
    refetchInterval,
    // Enabling on navigation focus refreshes even a recently cached page.
    staleTime: 0,
  }));
}

export function useMarkNotificationRead() {
  const client = useQueryClient();
  return useApiMutation({
    mutationFn: (id: number, apiClient) => notificationsApi.markRead(id, apiClient),
    onSuccess: () => client.invalidateQueries({ queryKey: ['notifications'] }),
  });
}

export function useMarkAllNotificationsRead() {
  const client = useQueryClient();
  return useApiMutation({
    mutationFn: (_input: void, apiClient) => notificationsApi.markAllRead(apiClient),
    onSuccess: () => client.invalidateQueries({ queryKey: ['notifications'] }),
  });
}
