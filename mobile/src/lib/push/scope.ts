import * as Keychain from 'react-native-keychain';
import { config } from '../config';
import type { PushMessage } from './fcm';
import { clearSystemNotifications } from './systemNotifications';

const PUSH_SERVICE = 'com.humus.synapse.lantest.push';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export interface PushAccount {
  tenant_id: number;
  id: number;
}

/** Only immutable identity is copied here; the runtime itself owns its transport. */
export interface PushRuntimeIdentity {
  readonly id: number;
  readonly apiBaseUrl: string;
  readonly profileId: string | null;
  readonly credentialBinding: string | null;
  readonly lanTest: boolean;
  readonly active: boolean;
  readonly sessionVersion?: number;
}

export interface PushOwnerOptions {
  runtime?: PushRuntimeIdentity | null;
  user?: PushAccount | null;
  /** Also guards auth activations in callers that do not expose a session version. */
  isCurrent?: () => boolean;
}

export interface PushOwner {
  readonly epoch: number;
  readonly runtimeId: number | null;
  readonly lanTest: boolean;
  readonly apiBaseUrl: string | null;
  readonly profileId: string | null;
  readonly credentialBinding: string | null;
  readonly account: Readonly<PushAccount> | null;
  readonly isActive: () => boolean;
}

/** Runtime ids are deliberately absent: this binding survives a same-session cold start. */
export interface PushScope {
  scope_version: 1;
  deployment_id: string;
  registration_id: string;
  api_base_url: string;
  profile_id: string;
  credential_binding: string;
  tenant_id: number;
  user_id: number;
}

let epoch = 0;
let owner: PushOwner | null = null;
let scope: Readonly<PushScope> | null = null;
let scopedMode = config.lanTest === true;
let revision = 0;
const listeners = new Set<() => void>();
let writes: Promise<void> = Promise.resolve();
const retiredBindings = new Set<string>();

function bindingKey(captured: PushOwner): string {
  return JSON.stringify([captured.apiBaseUrl, captured.profileId, captured.credentialBinding,
    captured.account?.tenant_id, captured.account?.id]);
}

function changed(): void {
  revision += 1;
  for (const listener of listeners) { listener(); }
}

function enqueue(operation: () => Promise<void>): Promise<void> {
  const result = writes.then(operation);
  writes = result.catch(() => undefined);
  return result;
}

async function deleteSavedScope(): Promise<void> {
  const stored = await Keychain.getGenericPassword({ service: PUSH_SERVICE });
  if (!stored) { return; }
  const cleared = await Keychain.resetGenericPassword({ service: PUSH_SERVICE });
  if (cleared === false) { throw new Error('Secure push binding could not be removed.'); }
}

export function activatePushOwner(options: PushOwnerOptions = {}): PushOwner {
  const runtime = options.runtime ?? null;
  const version = runtime?.sessionVersion;
  const account = options.user ? Object.freeze({ tenant_id: options.user.tenant_id, id: options.user.id }) : null;
  const captured: PushOwner = Object.freeze({
    epoch: ++epoch,
    runtimeId: runtime?.id ?? null,
    lanTest: config.lanTest === true || runtime?.lanTest === true,
    apiBaseUrl: runtime?.apiBaseUrl ?? null,
    profileId: runtime?.profileId ?? null,
    credentialBinding: runtime?.credentialBinding ?? null,
    account,
    isActive: () => (runtime === null || (runtime.active && runtime.sessionVersion === version)) &&
      (options.isCurrent?.() ?? true),
  });
  // A new activation must never inherit an in-memory registration or foreground notice.
  if (owner) { clearSystemNotifications(owner); }
  owner = captured;
  scope = null;
  scopedMode = captured.lanTest;
  changed();
  return captured;
}

export function currentPushOwner(): PushOwner | null { return owner; }

export function isPushOwnerCurrent(captured: PushOwner): boolean {
  try { return owner === captured && captured.isActive(); } catch { return false; }
}

export function canBindPushScope(captured: PushOwner): boolean {
  return isPushOwnerCurrent(captured) && captured.lanTest &&
    typeof captured.apiBaseUrl === 'string' && captured.apiBaseUrl.length > 0 &&
    typeof captured.profileId === 'string' && captured.profileId.length > 0 &&
    typeof captured.credentialBinding === 'string' && captured.credentialBinding.length > 0 &&
    Number.isSafeInteger(captured.account?.tenant_id) && Number(captured.account?.tenant_id) > 0 &&
    Number.isSafeInteger(captured.account?.id) && Number(captured.account?.id) > 0;
}

export function validDeploymentId(value: unknown): value is string {
  return typeof value === 'string' && /^[a-zA-Z0-9][a-zA-Z0-9._-]{7,127}$/.test(value);
}

export function validRegistrationId(value: unknown): value is string {
  return typeof value === 'string' && UUID.test(value);
}

function belongsToOwner(value: PushScope, captured: PushOwner): boolean {
  return value.api_base_url === captured.apiBaseUrl && value.profile_id === captured.profileId &&
    value.credential_binding === captured.credentialBinding && value.tenant_id === captured.account?.tenant_id &&
    value.user_id === captured.account?.id;
}

function parseScope(text: string): PushScope | null {
  try {
    const value: unknown = JSON.parse(text);
    if (!value || typeof value !== 'object') { return null; }
    const record = value as Partial<PushScope> & { acknowledged?: unknown };
    if (record.acknowledged !== true || record.scope_version !== 1 || !validDeploymentId(record.deployment_id) ||
        !validRegistrationId(record.registration_id) || typeof record.api_base_url !== 'string' ||
        typeof record.profile_id !== 'string' || typeof record.credential_binding !== 'string' ||
        !Number.isSafeInteger(record.tenant_id) || !Number.isSafeInteger(record.user_id)) { return null; }
    // Copy only the binding, never an arbitrary saved token or credential field.
    return {
      scope_version: 1,
      deployment_id: record.deployment_id,
      registration_id: record.registration_id,
      api_base_url: record.api_base_url,
      profile_id: record.profile_id,
      credential_binding: record.credential_binding,
      tenant_id: record.tenant_id as number,
      user_id: record.user_id as number,
    };
  } catch { return null; }
}

export function makePushScope(captured: PushOwner, deploymentId: string, registrationId: string): PushScope {
  if (!canBindPushScope(captured) || !validDeploymentId(deploymentId) || !validRegistrationId(registrationId)) {
    throw new Error('A scoped push registration is unavailable.');
  }
  return {
    scope_version: 1,
    deployment_id: deploymentId,
    registration_id: registrationId,
    api_base_url: captured.apiBaseUrl as string,
    profile_id: captured.profileId as string,
    credential_binding: captured.credentialBinding as string,
    tenant_id: captured.account!.tenant_id,
    user_id: captured.account!.id,
  };
}

/** Restore only for this selected profile, credential activation and authenticated account. */
export async function readPushScope(captured: PushOwner): Promise<PushScope | null> {
  if (!canBindPushScope(captured) || retiredBindings.has(bindingKey(captured))) { return null; }
  await writes;
  if (!isPushOwnerCurrent(captured)) { return null; }
  const stored = await Keychain.getGenericPassword({ service: PUSH_SERVICE });
  if (!isPushOwnerCurrent(captured) || !stored) { return null; }
  const restored = parseScope(stored.password);
  return restored && belongsToOwner(restored, captured) ? restored : null;
}

/** Publish restored scope only after the selected backend confirms its deployment capability. */
export function publishPushScope(captured: PushOwner, binding: PushScope): boolean {
  if (!isPushOwnerCurrent(captured) || !belongsToOwner(binding, captured)) { return false; }
  scope = Object.freeze({ ...binding });
  changed();
  return true;
}

export function currentPushScope(captured: PushOwner): Readonly<PushScope> | null {
  return isPushOwnerCurrent(captured) ? scope : null;
}

/** Hide accepted hints while a hook is detached, without deleting cold-start persistence. */
export function suspendPushScope(captured: PushOwner): void {
  if (owner !== captured) { return; }
  scope = null;
  changed();
}

/** Call only at a current runtime boundary before activating a new push owner. */
export function clearUnownedPushScope(): Promise<void> {
  if (owner !== null) { return Promise.resolve(); }
  scope = null;
  changed();
  return enqueue(deleteSavedScope);
}

/** Capture ownership and acknowledgment before queuing native persistence. */
export function savePushScope(captured: PushOwner, binding: PushScope, acknowledged: boolean): Promise<boolean> {
  const copy = Object.freeze({ ...binding });
  if (!isPushOwnerCurrent(captured) || !belongsToOwner(copy, captured)) { return Promise.resolve(false); }
  if (!acknowledged) { retiredBindings.add(bindingKey(captured)); }
  let savedForOwner = false;
  return enqueue(async () => {
    if (!isPushOwnerCurrent(captured)) { return; }
    const saved = await Keychain.setGenericPassword('synapse-lan-push', JSON.stringify({ ...copy, acknowledged }), {
      service: PUSH_SERVICE,
      accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
    if (saved === false) { throw new Error('Secure push binding storage is unavailable.'); }
    savedForOwner = isPushOwnerCurrent(captured);
    if (savedForOwner && acknowledged) { retiredBindings.delete(bindingKey(captured)); }
  }).then(() => savedForOwner);
}

/** Clear memory immediately. Native deletion is queued now, before any new owner's write. */
export function clearPushScope(captured: PushOwner): Promise<void> {
  if (owner !== captured) { return Promise.resolve(); }
  scope = null;
  changed();
  return enqueue(deleteSavedScope);
}

/** Retiring a stale owner cannot erase a newer owner's native binding. */
export function retirePushOwner(captured: PushOwner, clearSaved = true): Promise<void> {
  clearSystemNotifications(captured);
  if (owner !== captured) { return Promise.resolve(); }
  if (clearSaved && captured.lanTest) { retiredBindings.add(bindingKey(captured)); }
  const cleared = clearSaved && captured.lanTest ? clearPushScope(captured) : Promise.resolve();
  owner = null;
  scope = null;
  changed();
  return cleared;
}

/** LAN notifications are hints only; no banner, invalidation or route precedes these checks. */
export function acceptsPushMessage(message: PushMessage | null, captured?: PushOwner | null): boolean {
  if (!message) { return false; }
  const selected = captured === undefined ? owner : captured;
  if (!selected) { return !scopedMode; }
  if (!isPushOwnerCurrent(selected)) { return false; }
  if (!selected.lanTest) { return true; }
  const binding = currentPushScope(selected);
  return binding !== null && message.data?.scope_version === '1' &&
    message.data.deployment_id === binding.deployment_id &&
    message.data.registration_id === binding.registration_id;
}

export function subscribePushScope(listener: () => void): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

export function pushScopeRevision(): number { return revision; }

/** Focused unit tests reset only module memory; native persistence is intentionally external. */
export function __resetPushScopeForTests(): void {
  owner = null;
  scope = null;
  epoch = 0;
  scopedMode = config.lanTest === true;
  revision = 0;
  listeners.clear();
  retiredBindings.clear();
  writes = Promise.resolve();
}
