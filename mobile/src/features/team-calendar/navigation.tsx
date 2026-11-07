import React, { createContext, useCallback, useContext } from 'react';
import { useNavigation } from '@react-navigation/native';
import { useWorkspace } from '../workspace/WorkspaceContext';

export type { TeamCalendarStackParamList } from '../../navigation/types';

export interface TeamTaskDeadlineRoute {
  tab: 'TeamBoard' | 'MyTasks';
  screen: 'TeamTaskDetail';
  params: { id: number };
}

export function teamTaskDeadlineRoute(id: number, tab: TeamTaskDeadlineRoute['tab'] = 'TeamBoard'): TeamTaskDeadlineRoute {
  return { tab, screen: 'TeamTaskDetail', params: { id } };
}

export type OpenTaskDeadline = (id: number) => void;

const TaskDeadlineNavigationContext = createContext<OpenTaskDeadline | null>(null);

export function TaskDeadlineNavigationProvider({
  onOpenTaskDeadline,
  children,
}: {
  onOpenTaskDeadline?: OpenTaskDeadline;
  children: React.ReactNode;
}) {
  const navigation = useNavigation();
  const { activeUnit, showNotice } = useWorkspace();
  const open = useCallback<OpenTaskDeadline>((id) => {
    if (onOpenTaskDeadline) {
      onOpenTaskDeadline(id);
      return;
    }
    const tab = activeUnit?.features.team_board ? 'TeamBoard' : activeUnit?.features.my_tasks ? 'MyTasks' : null;
    if (!tab) {
      showNotice('Task details are not enabled for this Team Workspace unit.');
      return;
    }
    const route = teamTaskDeadlineRoute(id, tab);
    // Default integration seam: this provider is mounted directly as the
    // WorkspaceTabs TeamCalendar screen, so its navigation belongs to those tabs.
    const tabs = navigation as unknown as { navigate: (name: string, params: unknown) => void };
    tabs.navigate(route.tab, { screen: route.screen, params: route.params });
  }, [activeUnit, navigation, onOpenTaskDeadline, showNotice]);

  return (
    <TaskDeadlineNavigationContext.Provider value={open}>
      {children}
    </TaskDeadlineNavigationContext.Provider>
  );
}

export function useOpenTaskDeadline(): OpenTaskDeadline {
  const callback = useContext(TaskDeadlineNavigationContext);
  if (!callback) { throw new Error('Task deadline navigation is unavailable.'); }
  return callback;
}
