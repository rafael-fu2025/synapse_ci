import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Keyboard } from 'react-native';
import type { TeamUnit, WorkUnitEntitlement } from '../../api/types';
import { useAuth } from '../../store/auth';

export type AppContextMode = 'personal' | 'workspace';
export type DraftScope = { context: 'personal' } | { context: 'workspace'; unit: TeamUnit | null };
type RegistrationOptions = { scope?: DraftScope };
type Registration = { key: string; identity: string | null; scope: DraftScope };
type DraftOptions = RegistrationOptions & { onDiscard?: () => void; warning?: string };
type Draft = Registration & DraftOptions;
type PendingDiscard = { action: () => void; key?: string; identity: string | null };

export interface WorkspaceContextValue {
  mode: AppContextMode;
  personalEnabled: boolean;
  workEnabled: boolean;
  units: WorkUnitEntitlement[];
  activeUnit: WorkUnitEntitlement | null;
  unitPickerVisible: boolean;
  canSwitchContext: boolean;
  hasDirtyDrafts: boolean;
  hasPendingSubmission: boolean;
  /** Registrations capture their session and originating context, with idempotent cleanup. */
  registerDirtyDraft: (key: string, options?: DraftOptions) => () => void;
  registerSubmission: (key: string, options?: RegistrationOptions) => () => void;
  /** Without a key, the transition protects all retained drafts. */
  requestDiscard: (action: () => void, key?: string) => boolean;
  pendingDiscard: boolean;
  pendingDiscardWarning: string | undefined;
  confirmDiscard: () => void;
  cancelDiscard: () => void;
  completeDiscard: () => void;
  enterWorkspace: () => void;
  enterPersonal: () => void;
  openUnitPicker: () => void;
  closeUnitPicker: () => void;
  /** The callback runs after the authorized unit's navigation tree has committed. */
  openAuthorizedUnit: (unit: TeamUnit, afterOpen?: () => void) => boolean;
  selectUnit: (unit: TeamUnit) => void;
  notice: string | null;
  showNotice: (message: string) => void;
  clearNotice: () => void;
}

const WorkspaceContext = createContext<WorkspaceContextValue | null>(null);

/** No context selection, protected content, or draft is written to storage. */
export function WorkspaceProvider({ children }: { children: React.ReactNode }) {
  const { profile } = useAuth();
  const [mode, setMode] = useState<AppContextMode>('personal');
  const [activeUnitName, setActiveUnitName] = useState<TeamUnit | null>(null);
  const [unitPickerVisible, setUnitPickerVisible] = useState(false);
  const [, setRevision] = useState(0);
  const [pendingDiscard, setPendingDiscard] = useState(false);
  const [pendingDiscardWarning, setPendingDiscardWarning] = useState<string>();
  const [notice, setNotice] = useState<string | null>(null);
  const [destination, setDestination] = useState<{ identity: string | null; unit: TeamUnit; action: () => void } | null>(null);
  const drafts = useRef(new Map<symbol, Draft>());
  const submissions = useRef(new Map<symbol, Registration>());
  const pending = useRef<PendingDiscard | null>(null);
  const accepted = useRef<PendingDiscard | null>(null);
  const discardClosing = useRef(false);
  const identityRef = useRef<string | null>(null);
  const previousUnits = useRef(new Set<TeamUnit>());
  const previousPersonalEnabled = useRef(false);

  const personalEnabled = profile?.entitlements.personal.enabled === true;
  const units = useMemo(
    () => profile?.entitlements.work.enabled === true
      ? profile.entitlements.work.units.filter(item => Object.values(item.features).some(Boolean))
      : [],
    [profile],
  );
  const workEnabled = units.length > 0;
  const identityKey = profile ? `${profile.user.tenant_id}:${profile.user.id}` : null;
  const activeUnit = units.find(item => item.unit === activeUnitName) ?? null;
  const current = useRef({ identityKey, personalEnabled, units, activeUnitName, mode });
  current.current = { identityKey, personalEnabled, units, activeUnitName, mode };
  const notify = useCallback(() => setRevision(value => value + 1), []);

  const disposeDrafts = useCallback((matches: (draft: Draft) => boolean = () => true) => {
    const selected = [...drafts.current].filter(([, draft]) => matches(draft));
    for (const [token] of selected) { drafts.current.delete(token); }
    for (const [, draft] of selected) { draft.onDiscard?.(); }
    notify();
  }, [notify]);

  useEffect(() => {
    if (identityRef.current !== identityKey) {
      identityRef.current = identityKey;
      // Child effects may already have registered the newly mounted identity.
      for (const [token, submission] of submissions.current) {
        if (submission.identity !== identityKey) { submissions.current.delete(token); }
      }
      disposeDrafts(draft => draft.identity !== identityKey);
      pending.current = null;
      accepted.current = null;
      discardClosing.current = false;
      setPendingDiscard(false);
      setPendingDiscardWarning(undefined);
      setNotice(null);
      setDestination(null);
      setActiveUnitName(units.length === 1 ? units[0].unit : null);
      setMode(!personalEnabled && workEnabled ? 'workspace' : 'personal');
      setUnitPickerVisible(!personalEnabled && units.length > 1);
      previousUnits.current = new Set(units.map(item => item.unit));
      previousPersonalEnabled.current = personalEnabled;
      return;
    }
    const available = new Set(units.map(item => item.unit));
    const revoked = new Set([...previousUnits.current].filter(unit => !available.has(unit)));
    const personalRevoked = previousPersonalEnabled.current && !personalEnabled;
    previousUnits.current = available;
    previousPersonalEnabled.current = personalEnabled;
    if (revoked.size > 0 || personalRevoked || (!personalEnabled && !workEnabled)) {
      const isRevoked = (registration: Registration) => registration.identity === identityKey
        && (registration.scope.context === 'personal'
          ? !personalEnabled
          : registration.scope.unit === null ? !workEnabled : !available.has(registration.scope.unit));
      // Still-authorized contexts retain both their drafts and submission locks.
      for (const [token, submission] of submissions.current) {
        if (isRevoked(submission)) { submissions.current.delete(token); }
      }
      disposeDrafts(isRevoked);
      pending.current = null;
      accepted.current = null;
      discardClosing.current = false;
      setPendingDiscard(false);
      setPendingDiscardWarning(undefined);
      setDestination(null);
    }
    if (activeUnitName !== null && !available.has(activeUnitName)) {
      setActiveUnitName(units.length === 1 ? units[0].unit : null);
      setUnitPickerVisible(mode === 'workspace' && units.length > 1);
    }
    if (!workEnabled && mode === 'workspace') {
      setMode('personal');
      setUnitPickerVisible(false);
    } else if (!personalEnabled && workEnabled && mode === 'personal') {
      setMode('workspace');
      setUnitPickerVisible(units.length > 1 && activeUnitName === null);
    }
  }, [activeUnitName, disposeDrafts, identityKey, mode, personalEnabled, units, workEnabled]);

  useEffect(() => {
    if (!destination) { return; }
    if (destination.identity !== identityKey || !units.some(unit => unit.unit === destination.unit)) {
      setDestination(null);
      return;
    }
    if (mode === 'workspace' && activeUnitName === destination.unit) {
      setDestination(null);
      destination.action();
    }
  }, [activeUnitName, destination, identityKey, mode, units]);

  const registrationFor = useCallback((key: string, scope?: DraftScope): Registration => {
    const state = current.current;
    return {
      key,
      identity: state.identityKey,
      scope: scope ?? (state.mode === 'workspace'
        ? { context: 'workspace', unit: state.activeUnitName }
        : { context: 'personal' }),
    };
  }, []);

  const registerDirtyDraft = useCallback((key: string, options: DraftOptions = {}) => {
    const token = Symbol(key);
    drafts.current.set(token, { ...options, ...registrationFor(key, options.scope) });
    notify();
    return () => { if (drafts.current.delete(token)) { notify(); } };
  }, [notify, registrationFor]);

  const registerSubmission = useCallback((key: string, options: RegistrationOptions = {}) => {
    const token = Symbol(key);
    submissions.current.set(token, registrationFor(key, options.scope));
    notify();
    return () => { if (submissions.current.delete(token)) { notify(); } };
  }, [notify, registrationFor]);

  const requestDiscard = useCallback((action: () => void, key?: string) => {
    if (pending.current !== null || accepted.current !== null) { return false; }
    const locked = [...submissions.current.values()].some(submission => key === undefined || submission.key === key);
    if (locked) {
      setNotice('Please wait for the current action to finish before leaving.');
      return false;
    }
    const selected = [...drafts.current.values()].filter(draft => key === undefined || draft.key === key);
    if (selected.length === 0) {
      Keyboard.dismiss();
      action();
      return true;
    }
    pending.current = { action, key, identity: current.current.identityKey };
    discardClosing.current = false;
    setPendingDiscardWarning(selected.map(draft => draft.warning).find(Boolean));
    setPendingDiscard(true);
    return true;
  }, []);

  const cancelDiscard = useCallback(() => {
    if (accepted.current !== null) { return; }
    // Keep the pending token until native dismissal so another tap cannot reopen it.
    discardClosing.current = true;
    setPendingDiscard(false);
  }, []);

  const confirmDiscard = useCallback(() => {
    const next = pending.current;
    if (!next || discardClosing.current || [...submissions.current.values()].some(submission => next.key === undefined || submission.key === next.key)) { return; }
    pending.current = null;
    if (next.identity !== current.current.identityKey) {
      setPendingDiscard(false);
      return;
    }
    disposeDrafts(draft => next.key === undefined || draft.key === next.key);
    accepted.current = next;
    discardClosing.current = true;
    setPendingDiscard(false);
  }, [disposeDrafts]);

  // Continue only after the dialog exits: no navigation beneath a native modal.
  const completeDiscard = useCallback(() => {
    const next = accepted.current;
    accepted.current = null;
    pending.current = null;
    discardClosing.current = false;
    setPendingDiscardWarning(undefined);
    if (next && next.identity === current.current.identityKey) {
      Keyboard.dismiss();
      next.action();
    }
  }, []);

  const commitUnit = useCallback((unit: TeamUnit, afterOpen?: () => void) => {
    if (!current.current.units.some(item => item.unit === unit)) {
      setNotice('This Team Workspace is no longer available to your account.');
      return;
    }
    setActiveUnitName(unit);
    setUnitPickerVisible(false);
    setMode('workspace');
    if (afterOpen) { setDestination({ identity: current.current.identityKey, unit, action: afterOpen }); }
  }, []);

  const enterWorkspace = useCallback(() => {
    const state = current.current;
    if (state.units.length === 0) { return; }
    const unit = state.units.length === 1 ? state.units[0].unit : state.activeUnitName;
    if (unit === null) {
      setUnitPickerVisible(true);
    } else {
      requestDiscard(() => commitUnit(unit));
    }
  }, [commitUnit, requestDiscard]);

  const enterPersonal = useCallback(() => {
    if (!current.current.personalEnabled || current.current.mode === 'personal') { return; }
    requestDiscard(() => {
      if (!current.current.personalEnabled) { return; }
      setUnitPickerVisible(false);
      setMode('personal');
    });
  }, [requestDiscard]);

  const openUnitPicker = useCallback(() => {
    if (current.current.units.length > 1 && submissions.current.size === 0) { setUnitPickerVisible(true); }
  }, []);

  const closeUnitPicker = useCallback(() => {
    if (!current.current.personalEnabled && current.current.activeUnitName === null) { return; }
    setUnitPickerVisible(false);
  }, []);

  const openAuthorizedUnit = useCallback((unit: TeamUnit, afterOpen?: () => void): boolean => {
    if (!current.current.units.some(item => item.unit === unit)) { return false; }
    if (submissions.current.size > 0) {
      setNotice('Please wait for the current action to finish, then open this item again.');
      return false;
    }
    if (pending.current !== null || accepted.current !== null) { return false; }
    if (afterOpen && current.current.activeUnitName === unit && current.current.mode === 'workspace') {
      // A safe record push within the same unit does not discard a retained composer.
      Keyboard.dismiss();
      afterOpen();
      return true;
    }
    setUnitPickerVisible(false);
    return requestDiscard(() => commitUnit(unit, afterOpen));
  }, [commitUnit, requestDiscard]);

  const selectUnit = useCallback((unit: TeamUnit) => {
    if (unit === current.current.activeUnitName && current.current.mode === 'workspace') {
      closeUnitPicker();
      return;
    }
    openAuthorizedUnit(unit);
  }, [closeUnitPicker, openAuthorizedUnit]);

  const clearNotice = useCallback(() => setNotice(null), []);
  const hasDirtyDrafts = drafts.current.size > 0;
  const hasPendingSubmission = submissions.current.size > 0;
  const value = useMemo<WorkspaceContextValue>(() => ({
    mode, personalEnabled, workEnabled, units, activeUnit, unitPickerVisible,
    canSwitchContext: personalEnabled && workEnabled,
    hasDirtyDrafts,
    hasPendingSubmission,
    registerDirtyDraft, registerSubmission, requestDiscard,
    pendingDiscard, pendingDiscardWarning, confirmDiscard, cancelDiscard, completeDiscard,
    enterWorkspace, enterPersonal, openUnitPicker, closeUnitPicker, openAuthorizedUnit, selectUnit,
    notice, showNotice: setNotice, clearNotice,
  }), [
    activeUnit, cancelDiscard, clearNotice, closeUnitPicker, completeDiscard, confirmDiscard,
    enterPersonal, enterWorkspace, mode, notice, openAuthorizedUnit, openUnitPicker,
    pendingDiscard, pendingDiscardWarning, personalEnabled, registerDirtyDraft, registerSubmission,
    requestDiscard, hasDirtyDrafts, hasPendingSubmission, selectUnit, unitPickerVisible, units, workEnabled,
  ]);

  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>;
}

export function useOptionalWorkspace(): WorkspaceContextValue | null {
  return useContext(WorkspaceContext);
}

export function useWorkspace(): WorkspaceContextValue {
  const context = useOptionalWorkspace();
  if (context === null) { throw new Error('useWorkspace must be used inside WorkspaceProvider'); }
  return context;
}
