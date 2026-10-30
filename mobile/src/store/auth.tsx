import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import * as authApi from '../api/endpoints/auth';
import * as meApi from '../api/endpoints/me';
import { refreshSession, SessionChangedError, setAccessToken, setSessionExpiredHandler } from '../api/client';
import { User } from '../api/types';
import { clearRefreshToken, getRefreshToken, setRefreshToken } from '../lib/keychain';
import { queryClient } from '../lib/queryClient';
import { queryKeys } from '../api/queryKeys';

type AuthStatus = 'restoring' | 'signedOut' | 'signedIn' | 'expired';

interface AuthContextValue {
  status: AuthStatus;
  user: User | null;
  signIn: (identifier: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  acknowledgeExpired: () => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** The app's only client-side session state; every other value comes from the server. */
export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('restoring');
  const [user, setUser] = useState<User | null>(null);
  const operation = useRef(0);

  const expire = useCallback(() => {
    operation.current += 1;
    setAccessToken(null);
    queryClient.clear();
    clearRefreshToken().catch(() => undefined);
    setUser(null);
    setStatus((current) => (current === 'signedIn' ? 'expired' : 'signedOut'));
  }, []);

  useEffect(() => {
    setSessionExpiredHandler(expire);
    return () => setSessionExpiredHandler(null);
  }, [expire]);

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
        if (!cancelled) {
          setStatus('signedOut');
        }
        return;
      }
      try {
        const renewed = await refreshSession();
        if (cancelled || version !== operation.current) {
          return;
        }
        if (!renewed) {
          await clearRefreshToken();
          setStatus('signedOut');
          return;
        }
        const profile = await meApi.me();
        if (!cancelled && version === operation.current) {
          queryClient.setQueryData(queryKeys.me, profile);
          setUser(profile.user);
          setStatus('signedIn');
        }
      } catch {
        if (!cancelled && version === operation.current) {
          setAccessToken(null);
          // Keep the encrypted refresh token on a transient outage; try restoring next launch.
          setStatus('signedOut');
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

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
    setUser(payload.user);
    setStatus('signedIn');
  }, []);

  const signOut = useCallback(async () => {
    operation.current += 1;
    // Revoke on the server with the token we still hold, but never make the user wait for it.
    const revoked = authApi.logout();
    setAccessToken(null);
    queryClient.clear();
    setUser(null);
    setStatus('signedOut');
    await clearRefreshToken();
    await revoked;
  }, []);

  const acknowledgeExpired = useCallback(() => setStatus('signedOut'), []);

  const value = useMemo(
    () => ({ status, user, signIn, signOut, acknowledgeExpired }),
    [status, user, signIn, signOut, acknowledgeExpired],
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
