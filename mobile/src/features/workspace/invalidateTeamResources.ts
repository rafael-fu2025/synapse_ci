import type { QueryClient } from '@tanstack/react-query';
import type { TeamUnit } from '../../api/types';
import { queryKeys } from '../../api/queryKeys';

type Identity = { tenantId: number; userId: number };

/** A task's title, assignment, status and deadline also appear outside the board. */
export function teamTaskResourceKeys(identity: Identity, unit: TeamUnit) {
  return [
    queryKeys.teamUnit(identity.tenantId, identity.userId, unit),
    ['team-calendar', identity.tenantId, identity.userId, unit] as const,
    ['team-chat', identity.tenantId, identity.userId, unit, 'tasks'] as const,
  ];
}

export async function invalidateTeamTasks(client: QueryClient, identity: Identity, unit: TeamUnit): Promise<void> {
  await Promise.all(teamTaskResourceKeys(identity, unit).map(queryKey =>
    client.invalidateQueries({ queryKey, exact: false }),
  ));
}
