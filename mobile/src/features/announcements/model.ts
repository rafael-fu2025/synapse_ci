import type { AnnouncementDetail, AnnouncementListing, AnnouncementListItem } from '../../api/endpoints/announcements';

// The PHP feeds return database rows, whose numeric IDs may be JSON strings.
type WireItem<T extends { id: number }> = Omit<T, 'id'> & { id: number | string };

export function announcementListing(response: Omit<AnnouncementListing, 'data'> & { data: WireItem<AnnouncementListItem>[] }): AnnouncementListing {
  return { ...response, data: response.data.map(item => ({ ...item, id: Number(item.id) })) };
}

export function announcementDetail(response: { data: WireItem<AnnouncementDetail> }): { data: AnnouncementDetail } {
  return { ...response, data: { ...response.data, id: Number(response.data.id) } };
}
