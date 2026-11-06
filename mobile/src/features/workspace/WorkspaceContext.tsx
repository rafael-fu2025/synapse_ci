import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { TeamUnit, WorkUnitEntitlement } from '../../api/types';
import { useAuth } from '../../store/auth';

export type AppContextMode = 'personal' | 'workspace';

export interface WorkspaceContextValue {
  mode: AppContextMode;
  personalEnabled: boolean;
  workEnabled: boolean;
  units: WorkUnitEntitlement[];
  activeUnit: WorkUnitEntitlement | null;
  unitPickerVisible: boolean;
  canSwitchContext: boolean;
  hasDirtyDrafts: boolean;
  /** Registers an in-memory dirty source and returns its cleanup function. */
  registerDirtyDraft: (key: string) => () => void;
  /** Runs now when clean; otherwise asks the context host to confirm first. */
  requestDiscard: (action: () => void) => void;
  pendingDiscard: boolean;
  confirmDiscard: () => void;
  cancelDiscard: () => void;
  enterWorkspace: () => void;
  enterPersonal: () => void;
  openUnitPicker: () => void;
  closeUnitPicker: () => void;
  /** Selects one currently authorized unit for an external navigation intent. */
  openAuthorizedUnit: (unit: TeamUnit) => boolean;
  selectUnit: (unit: TeamUnit) => void;
}

const WorkspaceContext = createContext<WorkspaceContextValue | null>(null);

/**
 * Session-only context selection. Identity and entitlements always come from /me;
 * no unit, mode, content, or draft is written to persistent storage.
 */
export function WorkspaceProvider({ children }: { children: React.ReactNode }) {
  const { profile } = useAuth();
  const [mode, setMode] = useState<AppContextMode>('personal');
  const [activeUnitName, setActiveUnitName] = useState<TeamUnit | null>(null);
  const [unitPickerVisible, setUnitPickerVisible] = useState(false);
  const [dirtyDraftCounts, setDirtyDraftCounts] = useState<ReadonlyMap<string, number>>(() => new Map());
  const [pendingDiscardAction, setPendingDiscardAction] = useState<(() => void) | null>(null);
  const identityRef = useRef<string | null>(null);
  const unitAccessRef = useRef<{ identity: string | null; units: ReadonlySet<TeamUnit> }>({
    identity: null,
    units: new Set(),
  });

  const personalEnabled = profile?.entitlements.personal.enabled === true;
  const units = useMemo(
    () => profile?.entitlements.work.enabled === true ? profile.entitlements.work.units : [],
    [profile],
  );
  const workEnabled = units.length > 0;
  const identityKey = profile ? `${profile.user.tenant_id}:${profile.user.id}` : null;
  const activeUnit = units.find(item => item.unit === activeUnitName) ?? null;
  const canSwitchContext = personalEnabled && workEnabled;

  useEffect(() => {
    if (identityRef.current === identityKey) {
      return;
    }
    identityRef.current = identityKey;
    setActiveUnitName(units.length === 1 ? units[0].unit : null);
    setDirtyDraftCounts(new Map());
    setPendingDiscardAction(null);
    unitAccessRef.current = { identity: identityKey, units: new Set(units.map(item => item.unit)) };
    if (identityKey !== null && !personalEnabled && workEnabled) {
      setMode('workspace');
      setUnitPickerVisible(units.length > 1);
    } else {
      // Personal is the default whenever the entitlement exists.
      setMode('personal');
      setUnitPickerVisible(false);
    }
  }, [identityKey, personalEnabled, units, workEnabled]);

  useEffect(() => {
    if (identityKey === null) {
      return;
    }
    const nextUnitNames = new Set(units.map(item => item.unit));
    const previousAccess = unitAccessRef.current;
    const accessRevoked = previousAccess.identity === identityKey
      && [...previousAccess.units].some(unit => !nextUnitNames.has(unit));
    if (accessRevoked) {
      // Drafts belong to the old authorization scope and must disappear without
      // offering to retain or submit them after a unit is revoked.
      setDirtyDraftCounts(new Map());
      setPendingDiscardAction(null);
    }
    unitAccessRef.current = { identity: identityKey, units: nextUnitNames };
    // Live /me changes may remove a selected unit or the entire workspace.
    if (activeUnitName !== null && !units.some(item => item.unit === activeUnitName)) {
      setActiveUnitName(units.length === 1 ? units[0].unit : null);
      if (mode === 'workspace' && units.length > 1) {
        setUnitPickerVisible(true);
      }
    }
    if (!workEnabled && mode === 'workspace') {
      setMode('personal');
      setUnitPickerVisible(false);
    } else if (!personalEnabled && workEnabled && mode === 'personal') {
      setMode('workspace');
      setUnitPickerVisible(units.length > 1 && activeUnitName === null);
    }
  }, [activeUnitName, identityKey, mode, personalEnabled, units, workEnabled]);

  const enterWorkspace = useCallback(() => {
    if (!workEnabled) {
      return;
    }
    if (units.length === 1) {
      setActiveUnitName(units[0].unit);
      setUnitPickerVisible(false);
      setMode('workspace');
      return;
    }
    if (activeUnit !== null) {
      setMode('workspace');
      return;
    }
    setUnitPickerVisible(true);
  }, [activeUnit, units, workEnabled]);

  const registerDirtyDraft = useCallback((key: string) => {
    setDirtyDraftCounts(current => {
      const next = new Map(current);
      next.set(key, (next.get(key) ?? 0) + 1);
      return next;
    });
    let registered = true;
    return () => {
      if (!registered) {
        return;
      }
      registered = false;
      setDirtyDraftCounts(current => {
        const next = new Map(current);
        const count = next.get(key) ?? 0;
        if (count <= 1) {
          next.delete(key);
        } else {
          next.set(key, count - 1);
        }
        return next;
      });
    };
  }, []);

  const requestDiscard = useCallback((action: () => void) => {
    if (dirtyDraftCounts.size === 0) {
      action();
      return;
    }
    setPendingDiscardAction(() => action);
  }, [dirtyDraftCounts.size]);

  const cancelDiscard = useCallback(() => setPendingDiscardAction(null), []);

  const confirmDiscard = useCallback(() => {
    if (pendingDiscardAction === null) {
      return;
    }
    const action = pendingDiscardAction;
    setPendingDiscardAction(null);
    setDirtyDraftCounts(new Map());
    action();
  }, [pendingDiscardAction]);

  const enterPersonal = useCallback(() => {
    if (!personalEnabled) {
      return;
    }
    setUnitPickerVisible(false);
    setMode('personal');
  }, [personalEnabled]);

  const openUnitPicker = useCallback(() => {
    if (workEnabled && units.length > 1) {
      setUnitPickerVisible(true);
    }
  }, [units.length, workEnabled]);

  const closeUnitPicker = useCallback(() => {
    // A work-only account needs a unit before it has a usable destination.
    if (!personalEnabled && activeUnit === null) {
      return;
    }
    setUnitPickerVisible(false);
  }, [activeUnit, personalEnabled]);

  const openAuthorizedUnit = useCallback((unit: TeamUnit): boolean => {
    if (!units.some(item => item.unit === unit)) { return false; }
    setDirtyDraftCounts(new Map());
    setPendingDiscardAction(null);
    setActiveUnitName(unit);
    setUnitPickerVisible(false);
    setMode('workspace');
    return true;
  }, [units]);

  const selectUnit = useCallback((unit: TeamUnit) => {
    openAuthorizedUnit(unit);
  }, [openAuthorizedUnit]);

  const value = useMemo<WorkspaceContextValue>(() => ({
    mode,
    personalEnabled,
    workEnabled,
    units,
    activeUnit,
    unitPickerVisible,
    canSwitchContext,
    hasDirtyDrafts: dirtyDraftCounts.size > 0,
    registerDirtyDraft,
    requestDiscard,
    pendingDiscard: pendingDiscardAction !== null,
    confirmDiscard,
    cancelDiscard,
    enterWorkspace,
    enterPersonal,
    openUnitPicker,
    closeUnitPicker,
    openAuthorizedUnit,
    selectUnit,
  }), [
    activeUnit,
    cancelDiscard,
    canSwitchContext,
    closeUnitPicker,
    confirmDiscard,
    dirtyDraftCounts.size,
    enterPersonal,
    enterWorkspace,
    mode,
    openUnitPicker,
    openAuthorizedUnit,
    pendingDiscardAction,
    personalEnabled,
    registerDirtyDraft,
    requestDiscard,
    selectUnit,
    unitPickerVisible,
    units,
    workEnabled,
  ]);

  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>;
}

export function useWorkspace(): WorkspaceContextValue {
  const context = useContext(WorkspaceContext);
  if (context === null) {
    throw new Error('useWorkspace must be used inside WorkspaceProvider');
  }
  return context;
}
