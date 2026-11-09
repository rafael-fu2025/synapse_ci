import { bindApiClient, request, type ApiBinding } from '../client';

export interface SurveyQuestion {
  label: string;
  type: 'text' | 'rating' | 'single' | 'multiple';
  options: string[];
  required: boolean;
}

export interface SurveyListItem {
  id: number;
  title: string;
  description: string | null;
  starts_at: string | null;
  ends_at: string | null;
  published_at: string | null;
  status: 'published' | 'closed';
  submitted: boolean;
  closed: boolean;
}

export interface SurveyForm {
  id: number;
  title: string;
  description: string | null;
  ends_at: string | null;
  questions: SurveyQuestion[];
  submitted: boolean;
}

export interface SurveyListing {
  data: SurveyListItem[];
  meta: { page: number; pages: number; total: number };
  counts: { all: number; available: number; submitted: number; closed: number };
  has_profile: boolean;
}

export function listing(filters: { status?: string }, binding?: ApiBinding): Promise<SurveyListing> {
  const params = new URLSearchParams({ status: filters.status ?? 'all' });
  return (binding ? bindApiClient(binding).request : request)<SurveyListing>(`api/v1/surveys?${params.toString()}`);
}

export function form(id: number, binding?: ApiBinding): Promise<{ data: SurveyForm }> {
  return (binding ? bindApiClient(binding).request : request)<{ data: SurveyForm }>(`api/v1/surveys/${id}`);
}

/** Answers are indexed by question position: string | 1-5 | option index | option indexes. */
export function submit(id: number, answers: unknown[], binding?: ApiBinding): Promise<{ data: { response_id: number } }> {
  return (binding ? bindApiClient(binding).request : request)<{ data: { response_id: number } }>(`api/v1/surveys/${id}/submit`, {
    method: 'POST',
    body: { answers },
  });
}
