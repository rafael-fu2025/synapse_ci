import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import * as authApi from '../api/endpoints/auth';
import * as meApi from '../api/endpoints/me';
import {
  getAccessToken,
  refreshSession,
  SessionChangedError,
  setAccessToken,
  setSessionExpiredHandler,
} from '../api/client';
import { MeResponse, User } from '../api/types';
import { clearRefreshToken, getRefreshToken, setRefreshToken } from '../lib/keychain';
import { queryClient } from '../lib/queryClient';
import { queryKeys } from '../api/queryKeys';
import { usePushSession } from '../lib/push/session';
import { revokeDevice } from '../lib/push/registration';

export type AuthStatus = 'restoring' | 'signedOut' | 'signedIn' | 'bootstrapError' | 'expired';

export interface AuthContextValue {
  status: AuthStatus;
  user: User | null;
  /** The authoritative /me snapshot for this session. Never sourced from login. */
  profile: MeResponse | null;
  signIn: (identifier: string, password: string) => Promise<void>;
  retryBootstrap: () => Promise<void>;
  signOut: () => Promise<void>;
  acknowledgeExpired: () => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** The app's only client-side session state; every other value comes from the server. */
export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('restoring');
  const [profile, setProfile] = useState<MeResponse | null>(null);
  const operation = useRef(0);

  const user = profile?.user ?? null;

  // The device is registered only after the authoritative session bootstrap succeeds.
  usePushSession(status);

  const expire = useCallback(() => {
    operation.current += 1;
    // Withdraw the device token while the credential is still held.
    revokeDevice().catch(() => undefined);
    setAccessToken(null);
    queryClient.clear();
    clearRefreshToken().catch(() => undefined);
    setProfile(null);
    setStatus((current) => (current === 'signedIn' ? 'expired' : 'signedOut'));
  }, []);

  useEffect(() => {
    setSessionExpiredHandler(expire);
    return () => setSessionExpiredHandler(null);
  }, [expire]);

  const loadAuthoritativeProfile = useCallback(async (version: number): Promise<boolean> => {
    try {
      const nextProfile = await meApi.me();
      if (version !== operation.current) {
        return false;
      }
      queryClient.setQueryData(queryKeys.me, nextProfile);
      setProfile(nextProfile);
      setStatus('signedIn');
      return true;
    } catch {
      if (version === operation.current) {
        // Keep valid credentials for an explicit retry. No protected UI is mounted
        // until /me has authoritatively established identity and entitlements.
        setProfile(null);
        setStatus('bootstrapError');
      }
      return false;
    }
  }, []);

  const bootstrapSession = useCallback(async (version: number): Promise<void> => {
    try {
      if (getAccessToken() === null) {
        const renewed = await refreshSession();
        if (version !== operation.current) {
          return;
        }
        if (!renewed) {
          setAccessToken(null);
          await clearRefreshToken();
          if (version === operation.current) {
            setProfile(null);
            setStatus('signedOut');
          }
          return;
        }
      }
      await loadAuthoritativeProfile(version);
    } catch {
      if (version === operation.current) {
        // A transport or 5xx failure must not destroy a potentially valid refresh
        // credential. The retry screen can safely resume the same bootstrap.
        setProfile(null);
        setStatus('bootstrapError');
      }
    }
  }, [loadAuthoritativeProfile]);

  // Cold start: a stored refresh token restores the session without asking for credentials.
  useEffect(() => {
    let cancelled = false;
    const version = operation.current;
    (async () => {
      const stored = await getRefreshToken();
      if (cancelled || version !== operation.current) {
        return;
      }
      if (stored === null) {
        setStatus('signedOut');
        return;
      }
      await bootstrapSession(version);
    })().catch(() => {
      if (!cancelled && version === operation.current) {
        setProfile(null);
        setStatus('bootstrapError');
      }
    });
    return () => {
      cancelled = true;
    };
  }, [bootstrapSession]);

  const signIn = useCallback(async (identifier: string, password: string) => {
    const version = ++operation.current;
    const payload = await authApi.login(identifier, password);
    if (version !== operation.current) {
      throw new SessionChangedError();
    }
    await setRefreshToken(payload.token.refresh_token);
    if (version !== operation.current) {
      throw new SessionChangedError();
    }
    queryClient.clear();
    setAccessToken(payload.token.access_token);
    setProfile(null);
    setStatus('restoring');
    // The login projection is deliberately ignored. Fresh login and cold restore
    // both become signed in only after the same authoritative /me request.
    await loadAuthoritativeProfile(version);
  }, [loadAuthoritativeProfile]);

  const retryBootstrap = useCallback(async () => {
    const version = ++operation.current;
    setProfile(null);
    setStatus('restoring');
    await bootstrapSession(version);
  }, [bootstrapSession]);

  const signOut = useCallback(async () => {
    operation.current += 1;
    // The device token is withdrawn while the session is still valid; the request
    // leaves before the logout revokes the credential.
    const pushRevoked = revokeDevice();
    const revoked = authApi.logout();
    setAccessToken(null);
    queryClient.clear();
    setProfile(null);
    setStatus('signedOut');
    await clearRefreshToken();
    await Promise.all([revoked, pushRevoked]);
  }, []);

  const acknowledgeExpired = useCallback(() => setStatus('signedOut'), []);

  const value = useMemo<AuthContextValue>(
    () => ({ status, user, profile, signIn, retryBootstrap, signOut, acknowledgeExpired }),
    [status, user, profile, signIn, retryBootstrap, signOut, acknowledgeExpired],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (context === null) {
    throw new Error('useAuth must be used inside AuthProvider');
  }
  return context;
}
