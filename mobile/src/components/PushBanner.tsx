import React, { useEffect, useState, useSyncExternalStore } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { BellRing } from 'lucide-react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { font, memoizeStyles, Theme } from '../theme/tokens';
import { useTheme } from '../theme/ThemeProvider';
import { onPushMessage } from '../lib/push/fcm';
import { acceptsPushMessage, currentPushOwner, pushScopeRevision, subscribePushScope } from '../lib/push/scope';

interface PushNotice {
  title: string;
  body: string;
}

const NOTICE_MS = 5000;

/**
 * Subscribes to foreground pushes and holds the latest notice for NOTICE_MS.
 *
 * The operating system raises its own banner only for backgrounded or killed
 * apps; while the user is looking at the app, this is what makes the notice
 * visible. Tapping it is not needed — the inbox refetch has already updated
 * the content underneath.
 */
export function usePushNotice(): PushNotice | null {
  const scopeRevision = useSyncExternalStore(subscribePushScope, pushScopeRevision, pushScopeRevision);
  const [notice, setNotice] = useState<{ value: PushNotice; revision: number } | null>(null);
  useEffect(() => onPushMessage(message => {
    if (!acceptsPushMessage(message)) { return; }
    const lanTest = currentPushOwner()?.lanTest === true;
    setNotice({
      revision: pushScopeRevision(),
      value: {
        // Defense in depth: do not display a server-supplied personal title/body in a LAN build.
        title: lanTest ? 'Synapse LAN Test' : message.notification?.title ?? 'Portal notification',
        body: lanTest ? 'Open the app to check for updates' : message.notification?.body ?? '',
      },
    });
  }), []);
  useEffect(() => {
    if (notice === null) {
      return undefined;
    }
    const timer = setTimeout(() => setNotice(null), NOTICE_MS);
    return () => clearTimeout(timer);
  }, [notice]);
  // Scope retirement changes the external snapshot synchronously; an old account's
  // notice is hidden during the next render, without waiting for an effect to clear it.
  return notice?.revision === scopeRevision && currentPushOwner()?.isActive() !== false ? notice.value : null;
}

/** The in-app stand-in for the system banner while the app is in the foreground. */
export function PushBanner(): React.JSX.Element | null {
  const { theme } = useTheme();
  const notice = usePushNotice();
  const styles = makeStyles(theme);
  if (notice === null) {
    return null;
  }
  return (
    <SafeAreaView edges={['top']} style={styles.safe}>
      <View style={styles.wrap} accessibilityRole="alert" accessibilityLabel={`${notice.title}. ${notice.body}`}>
        <BellRing size={13} color="#ffffff" />
        <View style={styles.copy}>
          <Text style={styles.title} numberOfLines={1}>{notice.title}</Text>
          <Text style={styles.body} numberOfLines={2}>{notice.body}</Text>
        </View>
      </View>
    </SafeAreaView>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    // The banner sits above the navigator, so it must clear the status bar itself.
    safe: { backgroundColor: theme.mode === 'dark' ? '#2b2b2b' : '#520000' },
    wrap: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      marginHorizontal: 10,
      marginBottom: 8,
      padding: 10,
      borderRadius: 12,
      backgroundColor: theme.mode === 'dark' ? '#2b2b2b' : '#520000',
      borderWidth: 1,
      borderColor: theme.mode === 'dark' ? '#3a3a3a' : 'rgba(255,255,255,0.25)',
    },
    copy: { flex: 1 },
    title: { color: '#ffffff', fontSize: 11, fontWeight: '800', fontFamily: font.body },
    body: { color: 'rgba(255,255,255,0.87)', fontSize: 10.5, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
