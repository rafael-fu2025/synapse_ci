import React, { useCallback, useEffect } from 'react';
import { BackHandler, Keyboard, Text } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import type { BottomTabBarProps, BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import { TabActions, useFocusEffect, useNavigation } from '@react-navigation/native';
import type { NavigationState, PartialState } from '@react-navigation/native';
import { Screen } from '../components/Screen';
import { TabOwnsBottomInsetContext } from '../components/ScreenInsets';
import { useWorkspace } from '../features/workspace/WorkspaceContext';
import { ContextTabsParamList } from './types';
import { PersonalTabs } from './PersonalTabs';
import { WorkspaceTabs } from './WorkspaceTabs';
import { useTabMotionOptions } from './useTabMotionOptions';
import { ContextTabBarHost, ContextTabBarProvider } from './ContextTabBarBinding';

const Tab = createBottomTabNavigator<ContextTabsParamList>();

function hasPoppableStack(state: NavigationState | PartialState<NavigationState> | undefined): boolean {
  if (!state) { return false; }
  if (state.type === 'stack' && (state.index ?? 0) > 0) { return true; }
  return hasPoppableStack(state.routes[state.index ?? 0]?.state);
}

function ContextModeSync({ navigation, state }: BottomTabBarProps) {
  const { mode, personalEnabled } = useWorkspace();
  const route = mode === 'workspace' || !personalEnabled ? 'WorkspaceContext' : 'PersonalContext';
  useEffect(() => {
    if (state.routes[state.index]?.name !== route) { navigation.dispatch(TabActions.jumpTo(route)); }
  }, [navigation, route, state.index, state.routes]);
  return <ContextTabBarHost mode={mode === 'workspace' || !personalEnabled ? 'workspace' : 'personal'} />;
}

function WorkspaceContextScreen() {
  const { personalEnabled, enterPersonal } = useWorkspace();
  const navigation = useNavigation<BottomTabNavigationProp<ContextTabsParamList>>();
  useFocusEffect(useCallback(() => {
    if (!personalEnabled) { return undefined; }
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (Keyboard.isVisible()) {
        Keyboard.dismiss();
        return true;
      }
      const state = navigation.getState();
      if (hasPoppableStack(state.routes[state.index]?.state)) { return false; }
      enterPersonal();
      return true;
    });
    return () => subscription.remove();
  }, [enterPersonal, navigation, personalEnabled]));
  return <WorkspaceTabs />;
}

/** Both contexts retain their same-unit history; only accepted transitions change focus. */
export function ContextTabs() {
  const { mode, personalEnabled, workEnabled, activeUnit } = useWorkspace();
  const motion = useTabMotionOptions();
  const route = mode === 'workspace' || !personalEnabled ? 'WorkspaceContext' : 'PersonalContext';
  if (!personalEnabled && !workEnabled) {
    return <Screen><Text accessibilityRole="alert">No mobile portal access is available for this account.</Text></Screen>;
  }
  return (
    <ContextTabBarProvider>
      <TabOwnsBottomInsetContext.Provider value>
        <Tab.Navigator
          initialRouteName={route}
          backBehavior="none"
          tabBar={(props) => <ContextModeSync {...props} />}
          screenOptions={motion}>
          {personalEnabled ? <Tab.Screen name="PersonalContext" component={PersonalContextScreen} /> : null}
          {workEnabled ? (
            // Reset parent-owned Work state across units without replacing Personal or the shared host.
            <Tab.Screen name="WorkspaceContext" navigationKey={activeUnit?.unit ?? 'no-unit'} component={WorkspaceContextScreen} />
          ) : null}
        </Tab.Navigator>
      </TabOwnsBottomInsetContext.Provider>
    </ContextTabBarProvider>
  );
}

function PersonalContextScreen() {
  return <PersonalTabs />;
}
