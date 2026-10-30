import { useQuery } from '@tanstack/react-query';
import { profilePhoto } from '../../api/endpoints/health';
import { queryKeys } from '../../api/queryKeys';
import { useAuth } from '../../store/auth';

/** Shared in-memory photo; upload/removal invalidate the `me` prefix and sign-out clears it. */
export function useProfilePhoto(): string | null {
  const { user } = useAuth();
  const query = useQuery({
    queryKey: queryKeys.profilePhoto(user?.tenant_id ?? 0, user?.id ?? 0),
    queryFn: profilePhoto,
    enabled: user !== null && user.id > 0 && user.tenant_id > 0,
    staleTime: 60_000,
  });
  return typeof query.data === 'string' ? query.data : null;
}
