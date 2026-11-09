import { useQuery, useQueryClient } from '@tanstack/react-query';
import { bindApiQueryResult, useApiClient, useApiMutation } from '../../api/runtime';
import * as announcementsApi from '../../api/endpoints/announcements';
import { queryKeys } from '../../api/queryKeys';
import { announcementDetail, announcementListing } from './model';

export function useAnnouncements(filters: { unit: string; status: string; page: number }) {
  const apiClient = useApiClient();
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.announcements({ ...filters, q: '' }),
    queryFn: () => announcementsApi.listing(filters, apiClient).then(announcementListing),
  }));
}

export function useAnnouncement(id: number) {
  const apiClient = useApiClient();
  return bindApiQueryResult(apiClient, useQuery({
    queryKey: queryKeys.announcement(id),
    queryFn: () => announcementsApi.detail(id, apiClient).then(announcementDetail),
  }));
}

export function useMarkAnnouncementRead() {
  const client = useQueryClient();
  return useApiMutation({
    mutationFn: (id: number, apiClient) => announcementsApi.markRead(id, apiClient),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['announcements'] }).catch(() => undefined);
    },
  });
}
