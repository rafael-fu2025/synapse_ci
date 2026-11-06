import React from 'react';
import { useRoute } from '@react-navigation/native';
import type { RouteProp } from '@react-navigation/native';
import type { WorkspaceTabsParamList } from '../../navigation/types';
import { TeamChatScreen } from './screens/TeamChatScreen';

/** Production root for the Workspace Team Chat tab. */
export function TeamChatRoot() {
  const route = useRoute<RouteProp<WorkspaceTabsParamList, 'TeamChat'>>();
  return <TeamChatScreen targetMessageId={route.params?.targetMessageId} />;
}

export { TeamChatScreen } from './screens/TeamChatScreen';
export { teamChatKeys } from './hooks';
export type { TeamChatIdentity } from './hooks';
