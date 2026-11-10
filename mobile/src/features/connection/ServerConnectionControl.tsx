import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { Server } from 'lucide-react-native';
import { Action } from '../../components/Action';
import { BottomSheet } from '../../components/BottomSheet';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useOverlayActivity } from '../../components/OverlayProvider';
import { usePushSetupStatus } from '../../lib/push/setupStatus';
import { useTheme } from '../../theme/ThemeProvider';
import { font, memoizeStyles, Theme, type } from '../../theme/tokens';
import { useOptionalWorkspace } from '../workspace/WorkspaceContext';
import { ConnectionContextValue, useOptionalConnection } from './ConnectionContext';
import { CONNECTION_PROFILES, ConnectionProfileId } from './profiles';
import { ServerProfileChoices } from './ServerProfileChoices';

type Props = {
  presentation?: 'compact' | 'settings';
  /** Restoring and signed-in sessions are also retired when changing servers. */
  requiresSignIn?: boolean;
  renderTrigger?: (trigger: { open: () => void; available: boolean; busy: boolean }) => React.ReactNode;
};
type Phase = 'choosing' | 'closingChooser' | 'confirming' | 'closingConfirmation' | 'requestDiscard' | 'awaitingDiscard' | 'ready' | 'selecting';
type Selection = {
  token: number;
  owner: number;
  sourceId: ConnectionProfileId;
  sourceUrl: string;
  targetId: ConnectionProfileId;
  selectProfile: ConnectionContextValue['selectProfile'];
  requiresSignIn: boolean;
  phase: Phase;
  accepted: boolean;
};

/** Legacy fixtures and non-LAN builds need neither a provider nor extra UI. */
export function ServerConnectionControl({ presentation = 'compact', requiresSignIn = false, renderTrigger }: Props) {
  const connection = useOptionalConnection();
  if (!connection?.enabled || !connection.profile) {
    return renderTrigger?.({ open: () => undefined, available: false, busy: false }) ?? null;
  }
  return <EnabledConnectionControl key={`${connection.owner}:${connection.profile.id}`} connection={connection} presentation={presentation} requiresSignIn={requiresSignIn} renderTrigger={renderTrigger} />;
}

function EnabledConnectionControl({ connection, presentation, requiresSignIn, renderTrigger }: Required<Omit<Props, 'renderTrigger'>> & Pick<Props, 'renderTrigger'> & { connection: ConnectionContextValue }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const { width, fontScale } = useWindowDimensions();
  const workspace = useOptionalWorkspace();
  const overlayActive = useOverlayActivity();
  const [selection, setSelection] = useState<Selection | null>(null);
  const [localError, setLocalError] = useState<string | null>(null);
  const active = useRef<Selection | null>(null);
  const sequence = useRef(0);
  const alive = useRef(false);
  const latest = useRef({ connection, workspace, overlayActive, requiresSignIn });
  latest.current = { connection, workspace, overlayActive, requiresSignIn };
  const profile = connection.profile!;
  const target = CONNECTION_PROFILES.find(item => item.id === selection?.targetId) ?? profile;
  const locked = workspace?.hasPendingSubmission === true;
  const busy = connection.switching || selection !== null || overlayActive || locked || workspace?.pendingDiscard === true;
  const stacked = width < 340 || fontScale >= 1.4;

  useLayoutEffect(() => {
    alive.current = true;
    return () => { alive.current = false; active.current = null; sequence.current += 1; };
  }, []);

  const update = useCallback((next: Selection | null) => {
    if (!alive.current) { return; }
    active.current = next;
    setSelection(next);
  }, []);
  const current = useCallback((token: number | undefined, phase?: Phase): Selection | null => {
    const next = active.current;
    return alive.current && next !== null && next.token === token && (!phase || next.phase === phase) ? next : null;
  }, []);
  const owns = useCallback((next: Selection): boolean => {
    const state = latest.current;
    return alive.current && state.connection.enabled && state.connection.owner === next.owner
      && state.connection.profile?.id === next.sourceId && state.connection.profile.apiBaseUrl === next.sourceUrl
      && state.connection.selectProfile === next.selectProfile && state.requiresSignIn === next.requiresSignIn;
  }, []);
  const canAct = useCallback((next: Selection): boolean => {
    return owns(next) && !latest.current.connection.switching && latest.current.workspace?.hasPendingSubmission !== true;
  }, [owns]);
  function open() {
    const state = latest.current;
    const source = state.connection.profile;
    if (!alive.current || active.current || !source || !state.connection.enabled || state.connection.switching
      || state.overlayActive || state.workspace?.pendingDiscard || state.workspace?.hasPendingSubmission) { return; }
    setLocalError(null);
    update({ token: ++sequence.current, owner: state.connection.owner, sourceId: source.id, sourceUrl: source.apiBaseUrl,
      targetId: source.id, selectProfile: state.connection.selectProfile, requiresSignIn: state.requiresSignIn, phase: 'choosing', accepted: false });
  }
  function choose(token: number | undefined, id: ConnectionProfileId) {
    const next = current(token, 'choosing');
    if (!next || !canAct(next) || !CONNECTION_PROFILES.some(item => item.id === id)) { return; }
    update({ ...next, targetId: id });
  }
  function closeChooser(token: number | undefined, accept: boolean) {
    const next = current(token, 'choosing');
    if (!next || (accept && (!canAct(next) || next.targetId === next.sourceId))) { return; }
    update({ ...next, phase: 'closingChooser', accepted: accept });
  }
  function chooserDismissed(token: number | undefined) {
    const next = current(token, 'closingChooser');
    if (!next) { return; }
    update(next.accepted && canAct(next) ? { ...next, phase: 'confirming', accepted: false } : null);
  }
  function closeConfirmation(token: number | undefined, accept: boolean) {
    const next = current(token, 'confirming');
    if (!next || (accept && !canAct(next))) { return; }
    update({ ...next, phase: 'closingConfirmation', accepted: accept });
  }
  function confirmationDismissed(token: number | undefined) {
    const next = current(token, 'closingConfirmation');
    if (!next) { return; }
    update(next.accepted && canAct(next) ? { ...next, phase: 'requestDiscard' } : null);
  }

  useEffect(() => {
    const next = active.current;
    if (!next || overlayActive) { return; }
    if (next.phase === 'awaitingDiscard' && workspace && !workspace.pendingDiscard) {
      // Keep editing never invokes the continuation; release only after its modal exits.
      update(null);
      return;
    }
    if (next.phase !== 'requestDiscard' && next.phase !== 'ready') { return; }
    if (!canAct(next)) { update(null); return; }
    if (next.phase === 'requestDiscard') {
      update({ ...next, phase: 'awaitingDiscard' });
      const continueSwitch = () => {
        const waiting = current(next.token, 'awaitingDiscard');
        if (!waiting) { return; }
        update(canAct(waiting) ? { ...waiting, phase: 'ready' } : null);
      };
      // No key: drafts and submission locks from every retained context are protected.
      if (workspace) {
        if (!workspace.requestDiscard(continueSwitch) && current(next.token, 'awaitingDiscard')) {
          setLocalError('Please finish the current action before changing servers.');
          update(null);
        }
      } else { continueSwitch(); }
      return;
    }
    if (workspace?.pendingDiscard) { return; }
    // A discard continuation can run inside onDismiss before overlay activity rerenders.
    // This effect waits for the native host to be released, including any other overlay.
    update({ ...next, phase: 'selecting' });
    const finished = (failed: boolean) => {
      const working = current(next.token, 'selecting');
      if (!working || !owns(working)) { return; }
      if (failed) { setLocalError('Could not change the server. Please try again.'); }
      update(null);
    };
    try { Promise.resolve(next.selectProfile(next.targetId)).then(() => finished(false), () => finished(true)); }
    catch { finished(true); }
  }, [canAct, connection, current, overlayActive, owns, requiresSignIn, selection, update, workspace]);

  const error = connection.error ?? localError;
  const token = selection?.token;
  const feedback = <>
    {error ? <Text style={styles.error} accessibilityRole="alert" accessibilityLiveRegion="polite">{error}</Text> : null}
    <ConnectionSetupWarning owner={connection.owner} />
  </>;
  return (
    <>
      {renderTrigger ? <>{renderTrigger({ open, available: true, busy })}{feedback}</> : (
        <View style={presentation === 'settings' ? styles.settingsGroup : styles.compact} testID="server-connection-control">
          {presentation === 'settings' ? <Text style={styles.groupLabel}>CONNECTION</Text> : null}
          <View style={[styles.row, stacked && styles.stacked]}>
            {presentation === 'settings' && !stacked ? <View style={styles.icon}><Server size={16} color={theme.brandIcon} /></View> : null}
            <View style={[styles.copy, stacked && styles.stackedCopy]}>
              <Text style={styles.label}>{`Server · ${profile.label}`}</Text>
              <Text style={styles.address}>{profile.apiBaseUrl}</Text>
            </View>
            <Action
              variant="ghost"
              onPress={open}
              disabled={busy}
              loading={connection.switching}
              accessibilityLabel="Change server connection"
              accessibilityHint="Choose University or Home without testing a connection first"
              testID="change-server-connection">
              Change
            </Action>
          </View>
          {feedback}
        </View>
      )}
      <BottomSheet
        visible={selection?.phase === 'choosing'}
        title="Choose a server"
        description="Choose the network you are using. The server changes only after confirmation."
        closeLabel="Cancel server choice"
        onClose={() => closeChooser(token, false)}
        onDismiss={() => chooserDismissed(token)}
        testID="server-connection-chooser"
        footer={
          <View style={styles.footer}>
            <Action full onPress={() => closeChooser(token, true)} disabled={selection?.phase !== 'choosing' || target.id === profile.id || connection.switching || locked}>Continue</Action>
            <Action full variant="secondary" onPress={() => closeChooser(token, false)} disabled={selection?.phase !== 'choosing'}>Cancel</Action>
          </View>
        }>
        <ServerProfileChoices selectedId={selection?.targetId ?? profile.id} currentId={profile.id} onSelect={id => choose(token, id)} disabled={selection?.phase !== 'choosing' || connection.switching || locked} />
      </BottomSheet>
      <ConfirmDialog
        visible={selection?.phase === 'confirming'}
        title={`Switch to ${target.label}?`}
        message={`The new server is ${target.apiBaseUrl}.${requiresSignIn ? ' Your current session will end. You will need to sign in again on this server.' : ' Your sign-in details will not be sent until you sign in.'}`}
        confirmLabel="Switch server"
        cancelLabel="Keep current server"
        onConfirm={() => closeConfirmation(token, true)}
        onCancel={() => closeConfirmation(token, false)}
        onDismiss={() => confirmationDismissed(token)}
      />
    </>
  );
}

function ConnectionSetupWarning({ owner }: { owner: number }) {
  const status = usePushSetupStatus();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  if (status.state !== 'warning' || !status.warning || status.runtimeId !== owner) { return null; }
  return <Text style={styles.warning} accessibilityRole="alert" accessibilityLiveRegion="polite">{status.warning}</Text>;
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    compact: { alignSelf: 'stretch', marginTop: 12, marginBottom: 12, borderWidth: 1, borderColor: theme.line, borderRadius: 12, backgroundColor: theme.surface },
    settingsGroup: { marginBottom: 17, borderWidth: 1, borderColor: theme.line, borderRadius: 14, backgroundColor: theme.surface, overflow: 'hidden' },
    groupLabel: { padding: 10, paddingBottom: 7, paddingHorizontal: 12, backgroundColor: theme.surfaceWarm, color: theme.ink400, fontSize: 8.5, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    row: { minHeight: 61, flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12 },
    stacked: { flexDirection: 'column', alignItems: 'stretch', gap: 8 },
    icon: { width: 26, height: 26, alignItems: 'center', justifyContent: 'center' },
    copy: { flex: 1, minWidth: 0 },
    stackedCopy: { flex: 0 },
    label: { ...type.caption, color: theme.ink900 },
    address: { ...type.body, color: theme.ink500, marginTop: 3, flexShrink: 1 },
    error: { ...type.body, color: theme.mode === 'dark' ? '#f4a7a7' : '#a33b3b', paddingHorizontal: 12, paddingBottom: 12 },
    warning: { ...type.body, color: theme.ink500, paddingHorizontal: 12, paddingBottom: 12 },
    footer: { gap: 8 },
  });
}
const makeStyles = memoizeStyles(createStyles);
