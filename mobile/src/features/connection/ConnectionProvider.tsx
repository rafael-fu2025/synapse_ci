import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { activateApiRuntime, captureRuntimeCleanup, createApiRuntime, retireApiRuntime, SessionChangedError, type ApiRuntime } from '../../api/client';
import { ApiRuntimeProvider } from '../../api/runtime';
import { Action } from '../../components/Action';
import { Screen } from '../../components/Screen';
import { bindRuntimeApiBaseUrl, config } from '../../lib/config';
import { bindCredentialStorage, clearLanRefreshToken } from '../../lib/keychain';
import { capturePushCleanup } from '../../lib/push/registration';
import { clearUnownedPushScope } from '../../lib/push/scope';
import { createQueryClient } from '../../lib/queryClient';
import { useTheme } from '../../theme/ThemeProvider';
import { memoizeStyles, type, type Theme } from '../../theme/tokens';
import { clearNavigationIntents, setNavigationIntentOwner } from '../workspace/navigationIntent';
import { ConnectionContext, ConnectionSessionContext, type ConnectionSessionValue } from './ConnectionContext';
import { CONNECTION_PROFILES, findConnectionProfile, type ConnectionProfileId } from './profiles';
import { credentialBindingFor, newSavedConnection, readSavedConnection, writeSavedConnection, type SavedConnection } from './storage';

type ReadyConnection = {
  runtime: ApiRuntime;
  cache: QueryClient;
  record: SavedConnection | null;
  restoreSession: boolean;
  release: () => void;
};

type ConnectionState = {
  phase: 'loading' | 'selection' | 'switching' | 'ready';
  ready: ReadyConnection | null;
  error: string | null;
};

/** Account/data consumers do not mount until one validated server owns the runtime. */
export function ConnectionProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<ConnectionState>({ phase: 'loading', ready: null, error: null });
  const current = useRef(state);
  current.current = state;
  const alive = useRef(false);
  const operation = useRef(0);
  const selection = useRef<Promise<void> | null>(null);
  const retirements = useRef(new Set<() => void>());
  const readyRef = useRef<ReadyConnection | null>(null);

  const publish = useCallback((next: ConnectionState) => {
    if (!alive.current) { return; }
    current.current = next;
    setState(next);
  }, []);

  const release = useCallback((ready: ReadyConnection) => {
    for (const retire of retirements.current) { retire(); }
    retirements.current.clear();
    retireApiRuntime(ready.runtime);
    ready.cache.clear();
    ready.release();
    if (readyRef.current === ready) { readyRef.current = null; }
    clearNavigationIntents();
  }, []);

  const start = useCallback((record: SavedConnection | null): ReadyConnection => {
    const profile = record ? findConnectionProfile(record.profileId)! : null;
    const runtime = createApiRuntime({
      apiBaseUrl: profile?.apiBaseUrl ?? config.apiBaseUrl,
      profileId: profile?.id ?? null,
      credentialBinding: record ? credentialBindingFor(record) : null,
      lanTest: config.lanTest,
    });
    const releaseConfig = bindRuntimeApiBaseUrl(runtime.id, runtime.apiBaseUrl);
    const releaseCredential = runtime.credentialBinding ? bindCredentialStorage(runtime.credentialBinding) : () => undefined;
    activateApiRuntime(runtime);
    setNavigationIntentOwner(runtime.id);
    const ready: ReadyConnection = {
      runtime, cache: createQueryClient(), record,
      restoreSession: record === null || record.session === 'active',
      release: () => { releaseCredential(); releaseConfig(); },
    };
    readyRef.current = ready;
    return ready;
  }, []);

  useEffect(() => {
    alive.current = true;
    const version = ++operation.current;
    (async () => {
      let record: SavedConnection | null = null;
      if (config.lanTest) {
        record = await readSavedConnection();
        if (!alive.current || version !== operation.current) { return; }
        if (record === null) { publish({ phase: 'selection', ready: null, error: null }); return; }
        if (record.phase === 'pending' || record.session === 'signed-out') {
          await clearLanRefreshToken();
          await clearUnownedPushScope();
          record = { ...record, phase: 'ready', session: 'signed-out' };
          await writeSavedConnection(record);
        }
      }
      if (!alive.current || version !== operation.current) { return; }
      publish({ phase: 'ready', ready: start(record), error: null });
    })().catch(() => {
      if (alive.current && version === operation.current) {
        publish({ phase: 'selection', ready: null, error: config.lanTest
          ? 'Saved connection settings could not be loaded. Choose a server and try again.'
          : 'The app connection is not configured. Configure an HTTPS server and rebuild the app.' });
      }
    });
    return () => {
      alive.current = false;
      operation.current += 1;
      if (readyRef.current) { release(readyRef.current); }
    };
  }, [publish, release, start]);

  const owner = state.ready?.runtime.id ?? 0;
  const selectProfile = useCallback((id: ConnectionProfileId): Promise<void> => {
    if (!config.lanTest || !findConnectionProfile(id) || !alive.current) { return Promise.reject(new SessionChangedError()); }
    if (selection.current) { return selection.current; }
    const previous = readyRef.current;
    if ((previous?.runtime.id ?? 0) !== owner) { return Promise.reject(new SessionChangedError()); }
    if (previous?.record?.profileId === id && previous.runtime.active) { return Promise.resolve(); }
    let pending: SavedConnection;
    try { pending = newSavedConnection(id); }
    catch { return Promise.reject(new Error('Secure connection settings are unavailable. Please try again.')); }
    const version = ++operation.current;
    const revoke = previous ? captureRuntimeCleanup(previous.runtime) : null;
    const pushCleanup = previous ? capturePushCleanup(previous.runtime) : null;
    if (previous) { release(previous); }
    publish({ phase: 'switching', ready: null, error: null });
    const job = (async () => {
      // The pending signed-out record is durable before replacement credentials can exist.
      const cleanup = [
        writeSavedConnection(pending), clearLanRefreshToken(),
        pushCleanup ? pushCleanup.clearScope : clearUnownedPushScope(),
        revoke?.revokeSession() ?? Promise.resolve(), pushCleanup?.() ?? Promise.resolve(),
      ];
      const results = await Promise.allSettled(cleanup);
      const failure = results.find(result => result.status === 'rejected');
      if (failure?.status === 'rejected') { throw failure.reason; }
      // A retired switch must not overwrite the record a newer instance finalized.
      if (!alive.current || version !== operation.current) { throw new SessionChangedError(); }
      const record: SavedConnection = { ...pending, phase: 'ready' };
      await writeSavedConnection(record);
      if (!alive.current || version !== operation.current) { throw new SessionChangedError(); }
      publish({ phase: 'ready', ready: start(record), error: null });
    })().catch(error => {
      if (alive.current && version === operation.current) {
        publish({ phase: 'selection', ready: null, error: 'The server change could not be completed. Your account is closed locally. Choose a server to retry.' });
      }
      throw error;
    });
    selection.current = job;
    const done = () => { if (selection.current === job) { selection.current = null; } };
    job.then(done, done);
    return job;
  }, [owner, publish, release, start]);

  const setSessionRestorable = useCallback(async (active: boolean) => {
    const ready = readyRef.current;
    if (!ready || ready.runtime.id !== owner || !ready.runtime.active) { throw new SessionChangedError(); }
    if (!ready.record) { return; }
    const version = ready.runtime.sessionVersion;
    const record: SavedConnection = { ...ready.record, session: active ? 'active' : 'signed-out' };
    await writeSavedConnection(record);
    if (readyRef.current !== ready || !ready.runtime.active || version !== ready.runtime.sessionVersion) { throw new SessionChangedError(); }
    ready.record = record;
  }, [owner]);

  const registerRetirement = useCallback((retire: () => void) => {
    const ready = readyRef.current;
    if (!ready || ready.runtime.id !== owner || !ready.runtime.active) { retire(); return () => undefined; }
    retirements.current.add(retire);
    return () => { retirements.current.delete(retire); };
  }, [owner]);

  const value = useMemo(() => ({
    enabled: config.lanTest, profile: state.ready?.record ? findConnectionProfile(state.ready.record.profileId) : null,
    owner, switching: state.phase === 'switching', error: state.error, selectProfile,
  }), [owner, selectProfile, state]);
  const session = useMemo<ConnectionSessionValue | null>(() => state.ready ? ({
    runtime: state.ready.runtime, restoreSession: state.ready.restoreSession, setSessionRestorable, registerRetirement,
  }) : null, [registerRetirement, setSessionRestorable, state.ready]);

  return (
    <ConnectionContext.Provider value={value}>
      {state.phase === 'ready' && state.ready && session ? (
        <ApiRuntimeProvider key={state.ready.runtime.id} value={state.ready.runtime}>
          <QueryClientProvider client={state.ready.cache}>
            <ConnectionSessionContext.Provider value={session}>{children}</ConnectionSessionContext.Provider>
          </QueryClientProvider>
        </ApiRuntimeProvider>
      ) : (
        <ConnectionStartScreen loading={state.phase === 'loading'} switching={state.phase === 'switching'}
          enabled={config.lanTest} error={state.error} onSelect={selectProfile} />
      )}
    </ConnectionContext.Provider>
  );
}

function ConnectionStartScreen({ loading, switching, enabled, error, onSelect }: {
  loading: boolean; switching: boolean; enabled: boolean; error: string | null;
  onSelect: (id: ConnectionProfileId) => Promise<void>;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const [selected, setSelected] = useState<ConnectionProfileId | null>(null);
  return (
    <Screen bottomSafeArea>
      <View style={styles.content}>
        <Text accessibilityRole="header" style={styles.title}>Synapse LAN Test</Text>
        <Text style={styles.description}>{switching ? 'Closing the previous connection…' : enabled
          ? 'Choose where the app connects. You can change this later on the sign-in screen or in Settings.'
          : 'Connection setup required'}</Text>
        {error ? <Text accessibilityRole="alert" style={styles.warning}>{error}</Text> : null}
        {loading || switching ? <ActivityIndicator accessibilityLabel={switching ? 'Changing server' : 'Loading saved server choice'} color={theme.brandIcon} /> : null}
        {enabled && !loading && !switching ? (
          <>
            <View accessibilityRole="radiogroup" accessibilityLabel="Server" style={styles.options}>
              {CONNECTION_PROFILES.map(profile => (
                <Pressable key={profile.id} accessibilityRole="radio"
                  accessibilityLabel={`${profile.label}, ${profile.apiBaseUrl}`}
                  accessibilityState={{ checked: selected === profile.id }}
                  onPress={() => setSelected(profile.id)} style={[styles.option, selected === profile.id && styles.selected]}>
                  <Text style={styles.label}>{profile.label}</Text>
                  <Text style={styles.address}>{profile.apiBaseUrl}</Text>
                </Pressable>
              ))}
            </View>
            <Text style={styles.warning}>For controlled testing only. These HTTP connections are not encrypted.</Text>
            <Action full disabled={selected === null} onPress={() => { if (selected) { onSelect(selected).catch(() => undefined); } }}>Use selected server</Action>
          </>
        ) : null}
      </View>
    </Screen>
  );
}

const makeStyles = memoizeStyles((theme: Theme) => StyleSheet.create({
  content: { flex: 1, justifyContent: 'center', paddingVertical: 32, gap: 18 },
  title: { ...type.pageTitle, color: theme.ink900 },
  description: { ...type.body, fontSize: 14, lineHeight: 21, color: theme.ink700 },
  options: { gap: 12 },
  option: { minHeight: 64, padding: 16, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface, gap: 6 },
  selected: { borderColor: theme.brandText, backgroundColor: theme.brand50 },
  label: { ...type.bodyStrong, fontSize: 15, color: theme.ink900 },
  address: { ...type.body, fontSize: 13, color: theme.ink500 },
  warning: { ...type.body, fontSize: 12, lineHeight: 18, color: theme.ink700 },
}));
