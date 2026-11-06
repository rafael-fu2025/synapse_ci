import React, { createContext, useCallback, useContext } from 'react';
import { useNavigation } from '@react-navigation/native';

export type { TeamCalendarStackParamList } from '../../navigation/types';

export interface TeamTaskDeadlineRoute {
  tab: 'TeamBoard';
  screen: 'TeamTaskDetail';
  params: { id: number };
}

export function teamTaskDeadlineRoute(id: number): TeamTaskDeadlineRoute {
  return { tab: 'TeamBoard', screen: 'TeamTaskDetail', params: { id } };
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
  const open = useCallback<OpenTaskDeadline>((id) => {
    if (onOpenTaskDeadline) {
      onOpenTaskDeadline(id);
      return;
    }
    const route = teamTaskDeadlineRoute(id);
    // Default integration seam: this provider is mounted directly as the
    // WorkspaceTabs TeamCalendar screen, so its navigation belongs to those tabs.
    const tabs = navigation as unknown as { navigate: (name: string, params: unknown) => void };
    tabs.navigate(route.tab, { screen: route.screen, params: route.params });
  }, [navigation, onOpenTaskDeadline]);

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
