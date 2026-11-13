import React, { useEffect, useRef, useState } from 'react';
import { AppState, StyleSheet, Text, View } from 'react-native';
import { Action } from './Action';
import { useApiClient } from '../api/runtime';
import { useAuth } from '../store/auth';
import { useTheme } from '../theme/ThemeProvider';
import { font, memoizeStyles, type Theme } from '../theme/tokens';
import { openPushSettings } from '../lib/push/fcm';
import { enableNotificationPermission, refreshNotificationPermission, useNotificationPermission } from '../lib/push/permission';
import { retryPushSetup } from '../lib/push/registration';
import { usePushSetupStatus } from '../lib/push/setupStatus';

/** Permission and server registration are separate: neither state claims delivery to this phone. */
export function PhoneNotificationControl() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const { status, user } = useAuth();
  const client = useApiClient();
  const permission = useNotificationPermission();
  const setup = usePushSetupStatus();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const working = useRef(false);
  const mounted = useRef(true);
  const current = useRef({ status, user, client });
  current.current = { status, user, client };

  useEffect(() => {
    mounted.current = true;
    if (AppState.currentState === 'active') { refreshNotificationPermission().catch(() => undefined); }
    return () => { mounted.current = false; };
  }, []);

  const server = setup.runtimeId === client.runtime.id ? setup : null;
  const systemBlocked = permission.permission === 'blocked' ||
    (permission.permission === 'granted' && permission.systemEnabled === false);
  const needsPermission = permission.permission !== 'granted';
  const waiting = busy || permission.requesting || server?.state === 'checking';
  const title = 'Phone notifications';
  const caption = systemBlocked
    ? 'Allow SYNAPSE notifications and its notification channel in Android settings.'
    : needsPermission
      ? 'Allow notifications to receive updates in your phone’s status bar.'
      : server?.state === 'ready'
        ? 'This phone is registered for supported updates. Delivery also depends on the server and connection.'
        : server?.warning ?? 'Permission is allowed. Finish setup to receive supported updates on this phone.';
  const label = systemBlocked || server?.state === 'ready' ? 'Notification settings'
    : needsPermission ? 'Enable notifications' : 'Retry notification setup';

  async function activate() {
    if (working.current || waiting || AppState.currentState !== 'active') { return; }
    working.current = true;
    setBusy(true);
    setError(null);
    const captured = current.current;
    const valid = () => mounted.current && current.current.status === 'signedIn' && captured.status === 'signedIn' &&
      current.current.user?.id === captured.user?.id && current.current.user?.tenant_id === captured.user?.tenant_id &&
      current.current.client === captured.client && captured.client.isCurrent();
    const active = () => valid() && AppState.currentState === 'active';
    try {
      if (!active()) { return; }
      if (systemBlocked || server?.state === 'ready') {
        const opened = await openPushSettings();
        if (valid() && !opened) { setError('Notification settings could not be opened. Use this app’s notification settings on your phone.'); }
        return;
      }
      const allowed = needsPermission ? await enableNotificationPermission(active) : await refreshNotificationPermission();
      if (!active()) { return; }
      if (allowed !== 'granted') {
        const opened = await openPushSettings();
        if (valid() && !opened) { setError('Notifications are not enabled. You can allow them in your phone’s app settings.'); }
        return;
      }
      await retryPushSetup(captured.client.runtime, captured.user, valid);
    } catch {
      if (valid()) { setError('Phone notification setup could not finish. Please try again.'); }
    } finally {
      working.current = false;
      if (mounted.current) { setBusy(false); }
    }
  }

  if (status !== 'signedIn') { return null; }
  return (
    <View testID="phone-notification-control" style={styles.group}>
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.caption}>{caption}</Text>
      {server?.warning && needsPermission && !systemBlocked ? <Text style={styles.caption}>{server.warning}</Text> : null}
      {error ? <Text style={styles.error} accessibilityRole="alert" accessibilityLiveRegion="polite">{error}</Text> : null}
      <Action variant="secondary" accessibilityLabel={label} onPress={() => { activate().catch(() => undefined); }} loading={waiting}>{label}</Action>
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    group: { gap: 8, padding: 12, marginBottom: 17, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    title: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    caption: { fontSize: 10, lineHeight: 15, fontFamily: font.body, color: theme.ink500 },
    error: { fontSize: 10, lineHeight: 15, fontFamily: font.body, color: theme.mode === 'dark' ? '#f4a7a7' : '#a33b3b' },
  });
}
const makeStyles = memoizeStyles(createStyles);
