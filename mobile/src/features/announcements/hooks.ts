import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as announcementsApi from '../../api/endpoints/announcements';
import { queryKeys } from '../../api/queryKeys';

export function useAnnouncements(filters: { unit: string; status: string; page: number }) {
  return useQuery({
    queryKey: queryKeys.announcements({ ...filters, q: '' }),
    queryFn: () => announcementsApi.listing(filters),
  });
}

export function useAnnouncement(id: number) {
  return useQuery({
    queryKey: queryKeys.announcement(id),
    queryFn: () => announcementsApi.detail(id),
  });
}

export function useMarkAnnouncementRead() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => announcementsApi.markRead(id),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['announcements'] }).catch(() => undefined);
    },
  });
}
