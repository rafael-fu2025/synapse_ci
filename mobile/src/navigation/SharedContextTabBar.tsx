import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { ArrowLeftRight } from 'lucide-react-native';
import { useWorkspace } from '../features/workspace/WorkspaceContext';
import { Theme, font, memoizeStyles } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';

/** Four real routes plus an optional standalone context action in the center. */
export function SharedContextTabBar({ state, descriptors, navigation, insets }: BottomTabBarProps) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const workspace = useWorkspace();
  const showContextButton = workspace.canSwitchContext;
  const contextLabel = workspace.mode === 'personal' ? 'Work' : 'Personal';
  const contextAccessibilityLabel = workspace.mode === 'personal'
    ? 'Switch to Team Workspace'
    : 'Switch to Personal Portal';

  const routeButtons = state.routes.map((route, index) => {
    const focused = state.index === index;
    const options = descriptors[route.key].options;
    const rawLabel = options.tabBarLabel ?? options.title ?? route.name;
    const label = typeof rawLabel === 'string' ? rawLabel : route.name;
    const color = focused ? theme.brandIcon : theme.ink400;
    const icon = options.tabBarIcon?.({ focused, color, size: 21 });

    return (
      <Pressable
        key={route.key}
        accessibilityRole="tab"
        accessibilityLabel={options.tabBarAccessibilityLabel ?? label}
        accessibilityState={{ selected: focused }}
        onPress={() => {
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
          if (!focused && !event.defaultPrevented) {
            navigation.navigate(route.name, route.params);
          }
        }}
        onLongPress={() => navigation.emit({ type: 'tabLongPress', target: route.key })}
        style={({ pressed }) => [styles.route, pressed && styles.pressed]}>
        {icon}
        <Text numberOfLines={2} style={[styles.label, { color }]}>{label}</Text>
      </Pressable>
    );
  });

  if (showContextButton) {
    routeButtons.splice(2, 0,
      <Pressable
        key="context-action"
        accessibilityRole="button"
        accessibilityLabel={contextAccessibilityLabel}
        onPress={() => workspace.requestDiscard(
          workspace.mode === 'personal' ? workspace.enterWorkspace : workspace.enterPersonal,
        )}
        style={({ pressed }) => [styles.context, pressed && styles.pressed]}>
        <View style={styles.contextIcon}><ArrowLeftRight size={21} color="#ffffff" strokeWidth={2.4} /></View>
        <Text style={styles.contextLabel}>{contextLabel}</Text>
      </Pressable>,
    );
  }

  return (
    <View
      accessibilityRole="tablist"
      style={[styles.bar, { minHeight: 56 + insets.bottom, paddingBottom: Math.max(6, insets.bottom) }]}>
      {routeButtons}
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    bar: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      paddingTop: 5,
      paddingHorizontal: 6,
      backgroundColor: theme.surface,
      borderTopColor: theme.line,
      borderTopWidth: 1,
    },
    route: { flex: 1, minWidth: 44, minHeight: 48, alignItems: 'center', justifyContent: 'center', gap: 2 },
    context: { flex: 1, minWidth: 52, minHeight: 48, alignItems: 'center', justifyContent: 'center', gap: 1 },
    contextIcon: { width: 40, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center', backgroundColor: theme.brand },
    contextLabel: { color: theme.brandText, fontFamily: font.body, fontWeight: '800', fontSize: 9.5 },
    label: { fontFamily: font.body, fontWeight: '600', fontSize: 9.5, textAlign: 'center' },
    pressed: { opacity: 0.68 },
  });
}

const makeStyles = memoizeStyles(createStyles);
