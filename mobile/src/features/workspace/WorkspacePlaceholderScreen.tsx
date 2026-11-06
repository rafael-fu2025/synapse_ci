import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Screen } from '../../components/Screen';
import { useWorkspace } from './WorkspaceContext';
import { Theme, memoizeStyles, type } from '../../theme/tokens';
import { useTheme } from '../../theme/ThemeProvider';

export type WorkspacePlaceholderKind = 'Team Board' | 'My Tasks' | 'Team Chat' | 'Team Calendar';

/** Temporary production-safe root until each collaboration surface lands. */
export function WorkspacePlaceholderScreen({ kind }: { kind: WorkspacePlaceholderKind }) {
  const { activeUnit, openUnitPicker, units } = useWorkspace();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Screen>
      <View style={styles.center}>
        <Text accessibilityRole="header" style={styles.title}>{kind}</Text>
        <Text style={styles.unit}>{activeUnit?.label ?? 'Choose a team'}</Text>
        {activeUnit && units.length > 1 ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Switch Team Workspace unit"
            onPress={openUnitPicker}
            style={styles.switcher}>
            <Text style={styles.switcherText}>Switch unit</Text>
          </Pressable>
        ) : null}
        <Text style={styles.body}>This workspace surface is not available in this foundation build.</Text>
      </View>
    </Screen>
  );
}

export function TeamBoardRoot() { return <WorkspacePlaceholderScreen kind="Team Board" />; }
export function MyTasksRoot() { return <WorkspacePlaceholderScreen kind="My Tasks" />; }
export function TeamChatRoot() { return <WorkspacePlaceholderScreen kind="Team Chat" />; }
export function TeamCalendarRoot() { return <WorkspacePlaceholderScreen kind="Team Calendar" />; }

function createStyles(theme: Theme) {
  return StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 8 },
    title: { ...type.pageTitle, color: theme.ink900, textAlign: 'center' },
    unit: { ...type.bodyStrong, color: theme.brandText },
    switcher: { minHeight: 44, paddingHorizontal: 16, justifyContent: 'center' },
    switcherText: { ...type.action, color: theme.brandText },
    body: { ...type.body, color: theme.ink500, textAlign: 'center' },
  });
}

const makeStyles = memoizeStyles(createStyles);
