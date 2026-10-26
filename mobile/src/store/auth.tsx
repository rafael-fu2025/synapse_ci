import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import * as authApi from '../api/endpoints/auth';
import * as meApi from '../api/endpoints/me';
import { refreshSession, setAccessToken, setSessionExpiredHandler } from '../api/client';
import { User } from '../api/types';
import { clearRefreshToken, getRefreshToken, setRefreshToken } from '../lib/keychain';

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

  const expire = useCallback(() => {
    setAccessToken(null);
    setUser(null);
    setStatus((current) => (current === 'signedIn' ? 'expired' : current));
  }, []);

  useEffect(() => {
    setSessionExpiredHandler(expire);
    return () => setSessionExpiredHandler(null);
  }, [expire]);

  // Cold start: a stored refresh token restores the session without asking for credentials.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const stored = await getRefreshToken();
      if (stored === null) {
        if (!cancelled) {
          setStatus('signedOut');
        }
        return;
      }
      const renewed = await refreshSession();
      if (!renewed) {
        await clearRefreshToken();
        if (!cancelled) {
          setStatus('signedOut');
        }
        return;
      }
      try {
        const profile = await meApi.me();
        if (!cancelled) {
          setUser(profile.user);
          setStatus('signedIn');
        }
      } catch {
        await clearRefreshToken();
        if (!cancelled) {
          setStatus('signedOut');
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const signIn = useCallback(async (identifier: string, password: string) => {
    const payload = await authApi.login(identifier, password);
    setAccessToken(payload.token.access_token);
    await setRefreshToken(payload.token.refresh_token);
    setUser(payload.user);
    setStatus('signedIn');
  }, []);

  const signOut = useCallback(async () => {
    try {
      await authApi.logout();
    } catch {
      // Server-side revocation is best-effort; the local credential is cleared regardless.
    }
    setAccessToken(null);
    await clearRefreshToken();
    setUser(null);
    setStatus('signedOut');
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
