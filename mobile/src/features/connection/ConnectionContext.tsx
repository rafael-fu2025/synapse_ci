import { createContext, useContext } from 'react';
import type { ApiRuntime } from '../../api/client';
import type { ConnectionProfile, ConnectionProfileId } from './profiles';

export type ConnectionContextValue = {
  enabled: boolean;
  profile: ConnectionProfile | null;
  owner: number;
  switching: boolean;
  error: string | null;
  selectProfile: (id: ConnectionProfileId) => Promise<void>;
};

export const ConnectionContext = createContext<ConnectionContextValue | null>(null);

export function useOptionalConnection(): ConnectionContextValue | null {
  return useContext(ConnectionContext);
}

export type ConnectionSessionValue = {
  runtime: ApiRuntime;
  restoreSession: boolean;
  setSessionRestorable: (active: boolean) => Promise<void>;
  registerRetirement: (retire: () => void) => () => void;
};

export const ConnectionSessionContext = createContext<ConnectionSessionValue | null>(null);

export function useConnectionSession(): ConnectionSessionValue | null {
  return useContext(ConnectionSessionContext);
}
