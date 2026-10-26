import { request } from '../client';

export interface AnnouncementListItem {
  id: number;
  unit: 'clinic' | 'guidance';
  title: string;
  audience: string;
  published_at: string;
  ends_at: string | null;
  excerpt: string;
  read_at: string | null;
}

export interface AnnouncementDetail {
  id: number;
  unit: 'clinic' | 'guidance';
  title: string;
  body: string;
  audience: string;
  published_at: string;
  ends_at: string | null;
  read_at: string | null;
}

export interface AnnouncementListing {
  data: AnnouncementListItem[];
  meta: { page: number; pages: number; total: number };
  counts: { all: number; unread: number; read: number };
}

export function listing(filters: { unit?: string; status?: string; page?: number }): Promise<AnnouncementListing> {
  const params = new URLSearchParams({
    unit: filters.unit ?? 'all',
    status: filters.status ?? 'all',
    page: String(filters.page ?? 1),
  });
  return request<AnnouncementListing>(`api/v1/announcements?${params.toString()}`);
}

export function detail(id: number): Promise<{ data: AnnouncementDetail }> {
  return request<{ data: AnnouncementDetail }>(`api/v1/announcements/${id}`);
}

export function markRead(id: number): Promise<unknown> {
  return request(`api/v1/announcements/${id}/read`, { method: 'POST' });
}
