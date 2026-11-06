/** Wire types for the /api/v1 contract (docs/MOBILE_API.md). */

export interface User {
  id: number;
  person_id: number | null;
  tenant_id: number;
  username: string;
  email: string | null;
  name: string;
  avatar_initials: string;
  identifier: string;
  role: string;
  is_teaching: boolean;
  permissions: string[];
}

export interface TokenPayload {
  token_type: 'Bearer';
  access_token: string;
  refresh_token: string;
  access_expires_at: string;
}

export interface LoginResponse {
  user: User;
  token: TokenPayload;
}

export type TeamUnit = 'clinic' | 'guidance' | 'facilities';

export interface WorkFeatures {
  team_board: boolean;
  my_tasks: boolean;
  team_chat: boolean;
  team_calendar: boolean;
}

export interface WorkUnitEntitlement {
  unit: TeamUnit;
  label: string;
  can_manage: boolean;
  features: WorkFeatures;
}

export interface MeEntitlements {
  portal: boolean;
  staff_queue_units: string[];
  personal: {
    enabled: boolean;
  };
  work: {
    enabled: boolean;
    units: WorkUnitEntitlement[];
  };
}

export interface MeResponse {
  user: User;
  entitlements: MeEntitlements;
  photo_url: string;
}

export interface ErrorBody {
  code: string;
  message: string;
  fields?: Record<string, string[]>;
}
