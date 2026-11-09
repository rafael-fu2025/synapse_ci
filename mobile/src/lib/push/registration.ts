import { captureRuntimeCleanup, type ApiRuntime } from '../../api/client';
import { createPushApi, type PushCapabilities, type PushRegistrationScope } from '../../api/endpoints/push';
import { getOrCreateInstallationId } from '../keychain';
import { secureClientNonce } from '../secureNonce';
import { currentDeviceToken, currentPushPlatform, onPushTokenRefresh, requestPushPermission } from './fcm';
import {
  activatePushOwner, canBindPushScope, clearPushScope, clearUnownedPushScope, currentPushOwner,
  isPushOwnerCurrent, makePushScope, publishPushScope, readPushScope, retirePushOwner, savePushScope,
  suspendPushScope, validDeploymentId, validRegistrationId,
  type PushOwner, type PushOwnerOptions, type PushScope,
} from './scope';

import { publishPushSetupStatus, __resetPushSetupStatusForTests, type PushSetupState } from './setupStatus';
export { getPushSetupStatus, subscribePushSetupStatus } from './setupStatus';

function updateStatus(captured: PushOwner | null, state: PushSetupState, code: string | null = null, warning: string | null = null): void {
  if (captured && !isPushOwnerCurrent(captured)) { return; }
  publishPushSetupStatus({ state, code, warning, runtimeId: captured?.runtimeId ?? null });
}

function warn(captured: PushOwner, code: string, warning: string): void {
  updateStatus(captured, 'warning', code, warning);
}

export interface PushRegistrationOptions extends PushOwnerOptions {
  runtime?: ApiRuntime | null;
}

interface Registration {
  owner: PushOwner;
  runtime?: ApiRuntime;
  api: ReturnType<typeof createPushApi>;
  cleanup: ReturnType<typeof captureRuntimeCleanup>;
  isCurrent?: () => boolean;
  enabled: boolean;
  retired: boolean;
  token: string | null;
  binding: PushScope | null;
  priorWithdrawal: { token: string; binding: PushScope | null } | null;
  readyForTokens: boolean;
  suspended: boolean;
  pending: Promise<void> | null;
  generation: number;
  stopTokenWatch: (() => void) | null;
}

let registration: Registration | null = null;

function current(captured: Registration): boolean {
  try {
    return registration === captured && captured.enabled && isPushOwnerCurrent(captured.owner) &&
      captured.api.client.isCurrent() && (captured.isCurrent?.() ?? true);
  } catch { return false; }
}

function scopedBody(binding: PushScope): PushRegistrationScope {
  return { audience: 'lan-test', registration_id: binding.registration_id, deployment_id: binding.deployment_id };
}

/** One short no-refresh old-origin request; token/registration id are captured before retirement. */
function withdrawal(captured: Registration, token: string, binding: PushScope | null): () => Promise<void> {
  const body = { token, ...(binding ? { audience: 'lan-test', registration_id: binding.registration_id } : {}) };
  let cleanup = captured.cleanup;
  try {
    if (captured.api.client.isCurrent()) {
      cleanup = captureRuntimeCleanup(captured.runtime);
      captured.cleanup = cleanup;
    }
  } catch { /* Keep the last immutable old-origin snapshot if already retired. */ }
  return async () => {
    try { await cleanup.request('api/v1/push/token', { method: 'DELETE', body }); } catch { /* Best effort only. */ }
  };
}

/** Callable cleanup owns its separate native clear promise, so connection UI never waits on the network. */
export type PushCleanup = (() => Promise<void>) & { readonly clearScope: Promise<void> };

export function capturePushCleanup(runtime?: ApiRuntime | null): PushCleanup {
  const captured = registration;
  const selectedOwner = currentPushOwner();
  const matches = runtime == null || (captured ? captured.owner.runtimeId === runtime.id : selectedOwner?.runtimeId === runtime.id);
  const owns = captured !== null && matches;
  let clearScope: Promise<void> = Promise.resolve();
  let revoke: (() => Promise<void>) | null = null;
  if (owns) {
    captured.enabled = false;
    captured.retired = true;
    captured.generation += 1;
    captured.stopTokenWatch?.();
    captured.stopTokenWatch = null;
    const withdrawals: Array<() => Promise<void>> = [];
    if (captured.token) { withdrawals.push(withdrawal(captured, captured.token, captured.binding)); }
    if (captured.priorWithdrawal) { withdrawals.push(withdrawal(captured, captured.priorWithdrawal.token, captured.priorWithdrawal.binding)); }
    if (withdrawals.length) { revoke = async () => { await Promise.all(withdrawals.map(run => run())); }; }
    captured.token = null;
    captured.priorWithdrawal = null;
    registration = null;
    clearScope = retirePushOwner(captured.owner);
    updateStatus(null, 'idle');
  } else if (selectedOwner && matches) {
    clearScope = retirePushOwner(selectedOwner);
    updateStatus(null, 'idle');
  } else if (!selectedOwner && runtime?.active && runtime.lanTest) {
    // A switch/signout during cold bootstrap has no loaded registration yet.
    clearScope = clearUnownedPushScope();
    updateStatus(null, 'idle');
  }
  // Observe failure without swallowing it: the provider must report an unverified native wipe.
  clearScope.catch(() => undefined);
  let done: Promise<void> | null = null;
  const run = (() => {
    if (!done) { done = revoke ? revoke() : Promise.resolve(); }
    return done;
  }) as PushCleanup;
  Object.defineProperty(run, 'clearScope', { value: clearScope });
  return run;
}

function ensureRegistration(options: PushRegistrationOptions = {}): Registration | null {
  const existing = registration;
  const runtime = options.runtime ?? undefined;
  const user = options.user;
  const same = existing && (runtime === undefined || existing.owner.runtimeId === runtime.id) &&
    existing.owner.account?.tenant_id === user?.tenant_id && existing.owner.account?.id === user?.id;
  const carriedToken = same && existing.suspended ? existing.token : null;
  const carriedBinding = same && existing.suspended ? existing.binding : null;
  if (same && existing.api.client.isCurrent()) {
    if (existing.suspended) {
      // Detached native work may never settle. A new hook activation gets its own
      // callback owner while the persisted registration id remains unchanged.
      existing.enabled = false;
      existing.stopTokenWatch?.();
      existing.stopTokenWatch = null;
      retirePushOwner(existing.owner, false).catch(() => undefined);
      registration = null;
    } else {
      existing.enabled = true;
      existing.isCurrent = options.isCurrent;
      if (isPushOwnerCurrent(existing.owner)) { return existing; }
    }
  }
  if (existing && registration === existing) {
    const cleanup = capturePushCleanup();
    cleanup().catch(() => undefined);
  }
  if (runtime && !runtime.active) { return null; }
  const api = createPushApi(runtime);
  if (!api.client.isCurrent()) { return null; }
  const selected: PushOwner = activatePushOwner({
    ...options,
    runtime: api.client.runtime,
    isCurrent: () => api.client.isCurrent() && registration?.owner === selected && registration.enabled &&
      (registration.isCurrent?.() ?? true),
  });
  const captured: Registration = {
    owner: selected, runtime: api.client.runtime, api, cleanup: captureRuntimeCleanup(api.client.runtime), isCurrent: options.isCurrent,
    enabled: true, retired: false, token: carriedToken, binding: carriedBinding,
    priorWithdrawal: same && existing.suspended ? existing.priorWithdrawal : null,
    readyForTokens: false, suspended: false,
    pending: null, generation: 0, stopTokenWatch: null,
  };
  registration = captured;
  return captured;
}

function supportsLanPush(value: PushCapabilities | null | undefined): value is PushCapabilities {
  return value?.scope_version === 1 && validDeploymentId(value.deployment_id) &&
    Array.isArray(value.audiences) && value.audiences.includes('lan-test');
}

async function prepareScope(captured: Registration): Promise<PushScope | null> {
  if (!canBindPushScope(captured.owner)) {
    warn(captured.owner, 'binding_unavailable', 'LAN push setup is unavailable for this session. Sign in to the selected server again.');
    return null;
  }
  let capabilities: PushCapabilities;
  try {
    const response = await captured.api.capabilities();
    if (!current(captured)) { return null; }
    if (!supportsLanPush(response?.data)) {
      suspendPushScope(captured.owner);
      warn(captured.owner, 'unsupported', 'This server does not support scoped LAN push. Update the server before enabling phone notifications.');
      return null;
    }
    capabilities = response.data;
  } catch {
    if (current(captured)) {
      suspendPushScope(captured.owner);
      warn(captured.owner, 'capability_check_failed', 'LAN push setup could not verify this server. Check the connection and try again.');
    }
    return null;
  }
  let restored: PushScope | null;
  try {
    restored = await readPushScope(captured.owner);
    if (!current(captured)) { return null; }
  } catch {
    if (current(captured)) { warn(captured.owner, 'storage_unavailable', 'Secure LAN push setup could not be restored. Try signing in again.'); }
    return null;
  }
  if (restored?.deployment_id === capabilities.deployment_id) {
    // A same-server cold-start tap is valid before a token refresh/re-register finishes.
    captured.binding = restored;
    publishPushScope(captured.owner, restored);
    return restored;
  }
  try {
    const cleared = clearPushScope(captured.owner);
    if (captured.token && captured.binding) {
      const prior = { token: captured.token, binding: captured.binding };
      const removePrior = withdrawal(captured, prior.token, prior.binding);
      captured.priorWithdrawal = prior;
      captured.token = null;
      removePrior().then(() => {
        if (captured.priorWithdrawal === prior) { captured.priorWithdrawal = null; }
      });
    }
    captured.binding = null;
    await cleared;
    if (!current(captured)) { return null; }
    const binding = makePushScope(captured.owner, capabilities.deployment_id, secureClientNonce());
    const saved = await savePushScope(captured.owner, binding, false);
    if (!current(captured) || !saved) { return null; }
    captured.binding = binding;
    // New scope is not accepted until the backend acknowledges this exact registration.
    return binding;
  } catch {
    if (current(captured)) { warn(captured.owner, 'storage_unavailable', 'Secure LAN push setup could not be saved. Try signing in again.'); }
    return null;
  }
}

async function submit(captured: Registration, token: string, binding: PushScope | null): Promise<void> {
  if (!current(captured) || !token) { return; }
  const generation = ++captured.generation;
  let installationId: string;
  try {
    installationId = await getOrCreateInstallationId(secureClientNonce);
    if (!current(captured) || generation !== captured.generation) { return; }
  } catch {
    if (current(captured) && generation === captured.generation) {
      warn(captured.owner, 'installation_unavailable', 'The secure device identifier is unavailable. Phone push was not enabled.');
    }
    return;
  }
  if (!validRegistrationId(installationId)) {
    warn(captured.owner, 'installation_unavailable', 'The secure device identifier is unavailable. Phone push was not enabled.');
    return;
  }
  // Record only after the final owner check and immediately before the API submits.
  captured.token = token;
  const removeLateRegistration = withdrawal(captured, token, binding);
  try {
    const response = await captured.api.register(token, currentPushPlatform(), installationId, ['team_workspace'], binding ? scopedBody(binding) : undefined);
    if (!current(captured)) {
      if (captured.retired || !captured.api.client.isCurrent()) { removeLateRegistration().catch(() => undefined); }
      return;
    }
    if (generation !== captured.generation) { return; }
    if (binding && (response?.data?.registered !== true || response.data.scope_version !== 1 ||
        response.data.deployment_id !== binding.deployment_id || response.data.registration_id !== binding.registration_id)) {
      captured.readyForTokens = false;
      suspendPushScope(captured.owner);
      warn(captured.owner, 'registration_mismatch', 'This server returned an incompatible LAN push registration. Phone push was not enabled.');
      removeLateRegistration().catch(() => undefined);
      await savePushScope(captured.owner, binding, false);
      return;
    }
    if (binding) {
      const saved = await savePushScope(captured.owner, binding, true);
      if (!current(captured) || generation !== captured.generation || !saved) { return; }
      publishPushScope(captured.owner, binding);
    }
    captured.cleanup = captureRuntimeCleanup(captured.runtime);
    updateStatus(captured.owner, 'ready');
  } catch {
    if (!current(captured)) {
      if (captured.retired || !captured.api.client.isCurrent()) { removeLateRegistration().catch(() => undefined); }
      return;
    }
    if (generation === captured.generation) {
      warn(captured.owner, 'registration_failed', 'Phone push setup could not finish. The in-app inbox remains available.');
    }
  }
}

async function setup(captured: Registration): Promise<void> {
  if (!current(captured)) { return; }
  captured.readyForTokens = false;
  updateStatus(captured.owner, 'checking');
  const binding = captured.owner.lanTest ? await prepareScope(captured) : null;
  if (!current(captured) || (captured.owner.lanTest && !binding)) { return; }
  const permission = await requestPushPermission(() => current(captured));
  if (!current(captured)) { return; }
  if (permission !== 'granted') {
    warn(captured.owner, `permission_${permission}`, 'Phone notification permission is not enabled. The in-app inbox remains available.');
    return;
  }
  captured.readyForTokens = true;
  const token = await currentDeviceToken();
  if (!current(captured)) { return; }
  if (!token) {
    warn(captured.owner, 'token_unavailable', 'Phone push is unavailable in this build or on this device. The in-app inbox remains available.');
    return;
  }
  await submit(captured, token, binding);
}

/** Runtime and auth-bound registration; no unsafe legacy fallback is permitted in a LAN build. */
export function registerDevice(options: PushRegistrationOptions = {}): Promise<void> {
  const captured = ensureRegistration(options);
  if (!captured) { return Promise.resolve(); }
  if (captured.pending) { return captured.pending; }
  const pending = setup(captured).catch(() => {
    if (current(captured)) { warn(captured.owner, 'setup_failed', 'Phone push setup could not finish. The in-app inbox remains available.'); }
  }).finally(() => {
    if (captured.pending === pending) { captured.pending = null; }
  });
  captured.pending = pending;
  return pending;
}

/** Signout invalidates scope before returning its bounded best-effort network withdrawal. */
export function revokeDevice(options?: PushRegistrationOptions): Promise<void> {
  const cleanup = capturePushCleanup(options?.runtime);
  return cleanup();
}

/** Token callbacks are tied to the signed-in owner; never subscribe globally while signed out. */
export function watchPushToken(options: PushRegistrationOptions = {}): () => void {
  const captured = ensureRegistration(options);
  if (!captured) { return () => undefined; }
  captured.stopTokenWatch?.();
  const stop = onPushTokenRefresh(token => {
    if (!current(captured) || !token) { return; }
    if (captured.pending) {
      const pending = captured.pending;
      pending.then(() => {
        if (!current(captured) || !captured.readyForTokens || (captured.owner.lanTest && !captured.binding)) { return; }
        return submit(captured, token, captured.binding);
      }).catch(() => undefined);
      return;
    }
    if (!captured.readyForTokens || (captured.owner.lanTest && !captured.binding)) { registerDevice(options).catch(() => undefined); return; }
    submit(captured, token, captured.binding).catch(() => undefined);
  });
  captured.stopTokenWatch = stop;
  return () => {
    stop();
    if (captured.stopTokenWatch === stop) { captured.stopTokenWatch = null; }
  };
}

/** Hook detachment cancels delayed callbacks but is not signout: keep secure scope for cold restore. */
export function pausePushSession(runtime?: ApiRuntime | null): void {
  const captured = registration;
  if (!captured || (runtime != null && captured.owner.runtimeId !== runtime.id)) { return; }
  captured.enabled = false;
  captured.suspended = true;
  captured.readyForTokens = false;
  captured.generation += 1;
  captured.stopTokenWatch?.();
  captured.stopTokenWatch = null;
  suspendPushScope(captured.owner);
}

export function __resetPushRegistrationForTests(): void {
  registration?.stopTokenWatch?.();
  registration = null;
  __resetPushSetupStatusForTests();
}
