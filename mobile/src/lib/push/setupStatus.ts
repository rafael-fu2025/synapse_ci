import { useSyncExternalStore } from 'react';

export type PushSetupState = 'idle' | 'checking' | 'ready' | 'warning';
export interface PushSetupStatus {
  readonly state: PushSetupState;
  readonly warning: string | null;
  readonly code: string | null;
  readonly runtimeId: number | null;
}

let status: PushSetupStatus = Object.freeze({ state: 'idle', warning: null, code: null, runtimeId: null });
const listeners = new Set<() => void>();

export function getPushSetupStatus(): PushSetupStatus { return status; }
export function subscribePushSetupStatus(listener: () => void): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

/** Lightweight UI subscription; no Firebase, keychain or transport is loaded by a settings screen. */
export function usePushSetupStatus(): PushSetupStatus {
  return useSyncExternalStore(subscribePushSetupStatus, getPushSetupStatus, getPushSetupStatus);
}

/** The push lifecycle must validate its captured owner before publishing a new status. */
export function publishPushSetupStatus(next: PushSetupStatus): void {
  status = Object.freeze({ ...next });
  for (const listener of listeners) { listener(); }
}

export function __resetPushSetupStatusForTests(): void {
  status = Object.freeze({ state: 'idle', warning: null, code: null, runtimeId: null });
  listeners.clear();
}
