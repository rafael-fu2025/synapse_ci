import React, { createContext, memo, useCallback, useContext, useLayoutEffect, useState, useSyncExternalStore } from 'react';
import { AppState, StyleSheet, Text } from 'react-native';
import { NavigationContext } from '@react-navigation/native';
import { useOverlayActivity } from '../../../components/OverlayProvider';
import { useTheme } from '../../../theme/ThemeProvider';
import { font, memoizeStyles, type Theme } from '../../../theme/tokens';
import { addManilaDays, manilaWallToPickerDate, todayInManila } from '../../team-calendar/manila';
import { formatChatGroupTimestamp } from '../model';

type Navigation = NonNullable<React.ContextType<NonNullable<typeof NavigationContext>>> | undefined;
const OptionalNavigationContext = NavigationContext ?? createContext<Navigation>(undefined);
const TimestampDateContext = createContext<Date | null>(null);

/** One visible-list midnight clock updates headings, never the message window or its reads. */
export function TeamChatTimestampProvider({ children, active }: { children: React.ReactNode; active: boolean }) {
  const navigation = useContext(OptionalNavigationContext);
  const covered = useOverlayActivity();
  const [today, setToday] = useState(() => new Date());
  const subscribe = useCallback((changed: () => void) => {
    const app = AppState.addEventListener('change', changed);
    const focus = navigation?.addListener('focus', changed);
    const blur = navigation?.addListener('blur', changed);
    return () => { app.remove(); focus?.(); blur?.(); };
  }, [navigation]);
  const snapshot = useCallback(() => (AppState.currentState == null || AppState.currentState === 'active') &&
    (!navigation || navigation.isFocused()), [navigation]);
  const visible = useSyncExternalStore(subscribe, snapshot, () => false);
  useLayoutEffect(() => {
    if (!active || !visible || covered) { return; }
    let current = true;
    let timer: ReturnType<typeof setTimeout>;
    const update = () => {
      if (!current) { return; }
      const now = new Date();
      const day = todayInManila(now);
      setToday(previous => todayInManila(previous) === day ? previous : now);
      const midnight = manilaWallToPickerDate(`${addManilaDays(day, 1)}T00:00`).getTime();
      timer = setTimeout(update, Math.max(1, midnight - Date.now()));
    };
    update();
    return () => { current = false; clearTimeout(timer); };
  }, [active, covered, visible]);
  return <TimestampDateContext.Provider value={today}>{children}</TimestampDateContext.Provider>;
}

export const TeamChatTimestamp = memo(function Timestamp({ createdAt, messageId }: { createdAt: string; messageId: number }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const today = useContext(TimestampDateContext);
  return <Text testID={`team-chat-timestamp-${messageId}`} accessibilityRole="header" pointerEvents="none" style={styles.timestamp}>
    {formatChatGroupTimestamp(createdAt, today ?? new Date())}
  </Text>;
});

const makeStyles = memoizeStyles((theme: Theme) => StyleSheet.create({
  timestamp: { alignSelf: 'center', maxWidth: '100%', marginVertical: 9, paddingHorizontal: 12, paddingVertical: 5,
    textAlign: 'center', color: theme.ink500, fontFamily: font.body, fontSize: 10, lineHeight: 16 },
}));
