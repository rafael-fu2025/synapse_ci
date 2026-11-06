import React, { useEffect } from 'react';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import { useNavigation } from '@react-navigation/native';
import { Text } from 'react-native';
import { Screen } from '../components/Screen';
import { WorkspaceDiscardDialog } from '../features/workspace/WorkspaceDiscardDialog';
import { useWorkspace } from '../features/workspace/WorkspaceContext';
import { WorkspaceUnitPicker } from '../features/workspace/WorkspaceUnitPicker';
import { ContextTabsParamList } from './types';
import { PersonalTabs } from './PersonalTabs';
import { WorkspaceTabs } from './WorkspaceTabs';

const Tab = createBottomTabNavigator<ContextTabsParamList>();
type ContextNavigation = BottomTabNavigationProp<ContextTabsParamList>;

function useContextModeSync() {
  const { mode, personalEnabled } = useWorkspace();
  const navigation = useNavigation<ContextNavigation>();
  useEffect(() => {
    navigation.jumpTo(mode === 'workspace' || !personalEnabled ? 'WorkspaceContext' : 'PersonalContext');
  }, [mode, navigation, personalEnabled]);
}

function PersonalContextScreen() {
  useContextModeSync();
  return <PersonalTabs />;
}

function WorkspaceContextScreen() {
  useContextModeSync();
  return <WorkspaceTabs />;
}

/** Keeps both independent navigator trees alive and hides the parent context tabs. */
export function ContextTabs() {
  const { personalEnabled, workEnabled } = useWorkspace();
  if (!personalEnabled && !workEnabled) {
    return <Screen><Text accessibilityRole="alert">No mobile portal access is available for this account.</Text></Screen>;
  }
  return (
    <>
      <Tab.Navigator
        initialRouteName={personalEnabled ? 'PersonalContext' : 'WorkspaceContext'}
        backBehavior={personalEnabled ? 'initialRoute' : 'none'}
        tabBar={() => null}
        screenOptions={{ headerShown: false, lazy: false, freezeOnBlur: true }}>
        {personalEnabled ? <Tab.Screen name="PersonalContext" component={PersonalContextScreen} /> : null}
        {workEnabled ? <Tab.Screen name="WorkspaceContext" component={WorkspaceContextScreen} /> : null}
      </Tab.Navigator>
      <WorkspaceUnitPicker />
      <WorkspaceDiscardDialog />
    </>
  );
}
