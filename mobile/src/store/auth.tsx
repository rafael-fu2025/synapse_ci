import React, { createContext, useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient, type QueryClient } from '@tanstack/react-query';
import * as authApi from '../api/endpoints/auth';
import * as meApi from '../api/endpoints/me';
import {
  bindApiClient, captureRuntimeCleanup, getAccessToken, getApiRuntime, refreshSession,
  SessionChangedError, setAccessToken, setSessionExpiredHandler, type ApiRuntime,
} from '../api/client';
import { MeResponse, User } from '../api/types';
import { useConnectionSession } from '../features/connection/ConnectionContext';
import { clearRefreshToken, getRefreshToken, setRefreshToken } from '../lib/keychain';
import { queryClient } from '../lib/queryClient';
import { queryKeys } from '../api/queryKeys';
import { usePushSession } from '../lib/push/session';
import { capturePushCleanup } from '../lib/push/registration';

export type AuthStatus = 'restoring' | 'signedOut' | 'signedIn' | 'bootstrapError' | 'expired';

export interface AuthContextValue {
  status: AuthStatus;
  user: User | null;
  /** The authoritative /me snapshot for this session. Never sourced from login. */
  profile: MeResponse | null;
  signIn: (identifier: string, password: string) => Promise<void>;
  retryBootstrap: () => Promise<void>;
  /** Refresh entitlements without unmounting the signed-in navigation trees. */
  refreshProfile: () => Promise<void>;
  signOut: () => Promise<void>;
  acknowledgeExpired: () => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** The app's only account state belongs to its enclosing server runtime. */
export function AuthProvider({ children, client: providedClient, runtime: providedRuntime }: {
  children: React.ReactNode; client?: QueryClient; runtime?: ApiRuntime;
}) {
  const connection = useConnectionSession();
  const runtime = providedRuntime ?? connection?.runtime ?? getApiRuntime();
  const cache = useQueryClient(providedClient ?? (connection ? undefined : queryClient));
  const binding = runtime.credentialBinding;
  const [status, setStatus] = useState<AuthStatus>('restoring');
  const [profile, setProfile] = useState<MeResponse | null>(null);
  const operation = useRef(0);
  const alive = useRef(true);
  const current = useRef({ status, profile });
  current.current = { status, profile };
  const profileRefresh = useRef<{ version: number; promise: Promise<void> } | null>(null);

  const isCurrent = useCallback((version: number) => alive.current && runtime.active && version === operation.current, [runtime]);
  const assertCurrent = useCallback((version: number) => {
    if (!isCurrent(version)) { throw new SessionChangedError(); }
  }, [isCurrent]);
  const readCredential = useCallback(() => binding === null ? getRefreshToken() : getRefreshToken(binding), [binding]);
  const saveCredential = useCallback((token: string) => binding === null ? setRefreshToken(token) : setRefreshToken(token, binding), [binding]);
  const deleteCredential = useCallback(() => binding === null ? clearRefreshToken() : clearRefreshToken(binding), [binding]);
  const rememberSession = connection?.setSessionRestorable;
  const registerRetirement = connection?.registerRetirement;

  const retire = useCallback(() => {
    alive.current = false;
    operation.current += 1;
    profileRefresh.current = null;
    current.current = { status: 'signedOut', profile: null };
  }, []);
  useLayoutEffect(() => {
    alive.current = true;
    const unregister = registerRetirement?.(retire);
    return () => { unregister?.(); retire(); };
  }, [registerRetirement, retire]);

  const user = profile?.user ?? null;
  const authVersion = operation.current;
  const pushOptions = useMemo(() => ({
    runtime, user, queryClient: cache, isCurrent: () => isCurrent(authVersion),
  }), [authVersion, cache, isCurrent, runtime, user]);
  usePushSession(status, pushOptions);

  const clearLocal = useCallback(() => {
    operation.current += 1;
    profileRefresh.current = null;
    setAccessToken(null, runtime);
    cache.clear();
    current.current = { status: 'signedOut', profile: null };
    setProfile(null);
    setStatus('signedOut');
  }, [cache, runtime]);

  const expire = useCallback(() => {
    if (!alive.current || !runtime.active) { return; }
    const previousStatus = current.current.status;
    const cleanup = capturePushCleanup(runtime);
    cleanup().catch(() => undefined);
    // The deletion is queued before any await, so a login starting during this
    // expiry writes after the deletion instead of being erased by it.
    deleteCredential().catch(() => undefined);
    clearLocal();
    rememberSession?.(false).catch(() => undefined);
    cleanup.clearScope.catch(() => undefined);
    if (previousStatus === 'signedIn') { setStatus('expired'); }
  }, [clearLocal, deleteCredential, rememberSession, runtime]);

  useEffect(() => {
    setSessionExpiredHandler(expire, runtime);
    return () => setSessionExpiredHandler(null, runtime);
  }, [expire, runtime]);

  const loadAuthoritativeProfile = useCallback(async (version: number): Promise<boolean> => {
    try {
      assertCurrent(version);
      const nextProfile = await meApi.me(bindApiClient(runtime));
      assertCurrent(version);
      cache.setQueryData(queryKeys.me, nextProfile);
      current.current = { status: 'signedIn', profile: nextProfile };
      setProfile(nextProfile);
      setStatus('signedIn');
      return true;
    } catch {
      if (isCurrent(version)) {
        current.current = { status: 'bootstrapError', profile: null };
        setProfile(null);
        setStatus('bootstrapError');
      }
      return false;
    }
  }, [assertCurrent, cache, isCurrent, runtime]);

  const bootstrapSession = useCallback(async (version: number): Promise<void> => {
    try {
      assertCurrent(version);
      if (getAccessToken(runtime) === null) {
        const renewed = await refreshSession(runtime);
        assertCurrent(version);
        if (!renewed) {
          const deleted = deleteCredential();
          clearLocal();
          await Promise.all([rememberSession?.(false), deleted]);
          return;
        }
      }
      await loadAuthoritativeProfile(version);
    } catch {
      if (isCurrent(version)) {
        current.current = { status: 'bootstrapError', profile: null };
        setProfile(null);
        setStatus('bootstrapError');
      }
    }
  }, [assertCurrent, clearLocal, deleteCredential, isCurrent, loadAuthoritativeProfile, rememberSession, runtime]);

  const restoreSession = connection?.restoreSession ?? true;
  useEffect(() => {
    let cancelled = false;
    const version = operation.current;
    (async () => {
      if (!restoreSession) {
        if (isCurrent(version)) { current.current = { status: 'signedOut', profile: null }; setStatus('signedOut'); }
        return;
      }
      const stored = await readCredential();
      if (cancelled || !isCurrent(version)) { return; }
      if (stored === null) {
        current.current = { status: 'signedOut', profile: null };
        setStatus('signedOut');
        return;
      }
      await bootstrapSession(version);
    })().catch(() => {
      if (!cancelled && isCurrent(version)) {
        current.current = { status: 'bootstrapError', profile: null };
        setProfile(null);
        setStatus('bootstrapError');
      }
    });
    return () => { cancelled = true; };
  }, [bootstrapSession, isCurrent, readCredential, restoreSession]);

  const signIn = useCallback(async (identifier: string, password: string) => {
    if (!alive.current || !runtime.active) { throw new SessionChangedError(); }
    const version = ++operation.current;
    const client = bindApiClient(runtime);
    const payload = await authApi.login(identifier, password, client);
    assertCurrent(version);
    client.assertCurrent();
    await saveCredential(payload.token.refresh_token);
    assertCurrent(version);
    client.assertCurrent();
    await rememberSession?.(true);
    assertCurrent(version);
    client.assertCurrent();
    cache.clear();
    setAccessToken(payload.token.access_token, runtime);
    current.current = { status: 'restoring', profile: null };
    setProfile(null);
    setStatus('restoring');
    await loadAuthoritativeProfile(version);
  }, [assertCurrent, cache, loadAuthoritativeProfile, rememberSession, runtime, saveCredential]);

  const retryBootstrap = useCallback(async () => {
    if (!alive.current || !runtime.active) { throw new SessionChangedError(); }
    const version = ++operation.current;
    current.current = { status: 'restoring', profile: null };
    setProfile(null);
    setStatus('restoring');
    await bootstrapSession(version);
  }, [bootstrapSession, runtime]);

  const refreshProfile = useCallback((): Promise<void> => {
    const previous = current.current.profile;
    if (!alive.current || !runtime.active || current.current.status !== 'signedIn' || previous === null) { return Promise.resolve(); }
    const version = operation.current;
    if (profileRefresh.current?.version === version) { return profileRefresh.current.promise; }
    const client = bindApiClient(runtime);
    const promise = (async () => {
      const next = await meApi.me(client);
      assertCurrent(version);
      if (current.current.status !== 'signedIn' || next.user.id !== previous.user.id || next.user.tenant_id !== previous.user.tenant_id) {
        throw new SessionChangedError();
      }
      cache.setQueryData(queryKeys.me, next);
      current.current = { status: 'signedIn', profile: next };
      setProfile(next);
    })();
    profileRefresh.current = { version, promise };
    const clear = () => { if (profileRefresh.current?.promise === promise) { profileRefresh.current = null; } };
    promise.then(clear, clear);
    return promise;
  }, [assertCurrent, cache, runtime]);

  useEffect(() => {
    if (status !== 'signedIn' || profile === null) { return undefined; }
    const version = operation.current;
    return cache.getQueryCache?.()?.subscribe(event => {
      if (!isCurrent(version) || current.current.status !== 'signedIn') { return; }
      if (event.type !== 'updated' || event.query.queryKey.length !== 1 || event.query.queryKey[0] !== 'me') { return; }
      const next = event.query.state.data as MeResponse | undefined;
      if (next?.user.id !== profile.user.id || next.user.tenant_id !== profile.user.tenant_id || !next.entitlements) { return; }
      current.current = { status: 'signedIn', profile: next };
      setProfile(next);
    });
  }, [cache, isCurrent, profile, status]);

  const signOut = useCallback(async () => {
    if (!alive.current || !runtime.active) { return; }
    const pushCleanup = capturePushCleanup(runtime);
    const cleanup = captureRuntimeCleanup(runtime);
    const pushRevoked = pushCleanup();
    const revoked = cleanup.revokeSession();
    // Deletion is enqueued before any await: a login starting during this sign-out
    // queues its write after the deletion instead of being erased by it, and a
    // failed settings write can no longer skip credential removal.
    const deleted = deleteCredential();
    clearLocal();
    if (rememberSession) { await rememberSession(false).catch(() => undefined); }
    await pushCleanup.clearScope;
    await Promise.all([revoked, pushRevoked, deleted]);
  }, [clearLocal, deleteCredential, rememberSession, runtime]);

  const acknowledgeExpired = useCallback(() => {
    if (alive.current && runtime.active) {
      current.current = { status: 'signedOut', profile: null };
      setStatus('signedOut');
    }
  }, [runtime]);
  const value = useMemo<AuthContextValue>(
    () => ({ status, user, profile, signIn, retryBootstrap, refreshProfile, signOut, acknowledgeExpired }),
    [status, user, profile, signIn, retryBootstrap, refreshProfile, signOut, acknowledgeExpired],
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (context === null) { throw new Error('useAuth must be used inside AuthProvider'); }
  return context;
}
