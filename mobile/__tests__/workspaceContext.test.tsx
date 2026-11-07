import React from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import type { MeResponse, TeamUnit, WorkUnitEntitlement } from '../src/api/types';
import { WorkspaceProvider, useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { useContextDraftRegistration } from '../src/features/workspace/useContextDraftRegistration';
import { useWorkspaceDraft } from '../src/features/workspace/useWorkspaceDraft';
import { useAuth } from '../src/store/auth';

jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({ dispatch: jest.fn() }),
  useRoute: () => ({ key: 'retained-work-form' }),
  usePreventRemove: jest.fn(),
}));

const features = {
  team_board: true,
  my_tasks: true,
  team_chat: true,
  team_calendar: true,
};

function unit(name: TeamUnit): WorkUnitEntitlement {
  return {
    unit: name,
    label: name === 'facilities' ? 'BMG Facilities' : `${name[0].toUpperCase()}${name.slice(1)}`,
    can_manage: name === 'clinic',
    features,
  };
}

function profile(options: {
  user?: number;
  personal?: boolean;
  units?: TeamUnit[];
} = {}): MeResponse {
  const units = (options.units ?? []).map(unit);
  return {
    user: {
      id: options.user ?? 7,
      person_id: (options.personal ?? true) ? 17 : null,
      tenant_id: 3,
      username: 'member',
      email: null,
      name: 'Team Member',
      avatar_initials: 'TM',
      identifier: 'member',
      role: 'staff',
      is_teaching: false,
      permissions: [],
    },
    entitlements: {
      portal: true,
      staff_queue_units: [],
      personal: { enabled: options.personal ?? true },
      work: { enabled: units.length > 0, units },
    },
    photo_url: '/api/v1/me/photo',
  };
}

let current: ReturnType<typeof useWorkspace>;
let renderer: ReactTestRenderer | undefined;

function Probe() {
  current = useWorkspace();
  return null;
}

function mockProfile(value: MeResponse | null) {
  jest.mocked(useAuth).mockReturnValue({ profile: value } as ReturnType<typeof useAuth>);
}

function renderProvider(children?: React.ReactNode) {
  act(() => {
    renderer = create(<WorkspaceProvider><Probe />{children}</WorkspaceProvider>);
  });
}

function refresh(value: MeResponse | null, children?: React.ReactNode) {
  mockProfile(value);
  act(() => renderer!.update(<WorkspaceProvider><Probe />{children}</WorkspaceProvider>));
}

beforeEach(() => {
  mockProfile(null);
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  jest.clearAllMocks();
});

it('defaults an authorized account to Personal and enters its only unit directly', () => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();

  expect(current.mode).toBe('personal');
  expect(current.activeUnit?.unit).toBe('clinic');
  expect(current.canSwitchContext).toBe(true);

  act(() => current.enterWorkspace());
  expect(current.mode).toBe('workspace');
  expect(current.unitPickerVisible).toBe(false);

  act(() => current.enterPersonal());
  expect(current.mode).toBe('personal');
});

it('opens the accessible selection step for multiple units without switching early', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();

  act(() => current.enterWorkspace());
  expect(current.mode).toBe('personal');
  expect(current.activeUnit).toBeNull();
  expect(current.unitPickerVisible).toBe(true);

  act(() => current.selectUnit('guidance'));
  expect(current.mode).toBe('workspace');
  expect(current.activeUnit?.unit).toBe('guidance');
  expect(current.unitPickerVisible).toBe(false);

  act(() => current.selectUnit('facilities'));
  expect(current.activeUnit?.unit).toBe('guidance');
});

it('forces a work-only account into Workspace and does not allow dismissing its required picker', () => {
  mockProfile(profile({ personal: false, units: ['clinic', 'facilities'] }));
  renderProvider();

  expect(current.mode).toBe('workspace');
  expect(current.unitPickerVisible).toBe(true);
  expect(current.canSwitchContext).toBe(false);

  act(() => current.closeUnitPicker());
  expect(current.unitPickerVisible).toBe(true);
  act(() => current.enterPersonal());
  expect(current.mode).toBe('workspace');
});

it('reconciles refreshed entitlements for the same identity and never retains a revoked unit', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();
  act(() => {
    current.enterWorkspace();
    current.selectUnit('clinic');
  });

  refresh(profile({ units: ['guidance', 'facilities'] }));
  expect(current.mode).toBe('workspace');
  expect(current.activeUnit).toBeNull();
  expect(current.unitPickerVisible).toBe(true);

  act(() => current.selectUnit('facilities'));
  refresh(profile({ units: ['guidance'] }));
  expect(current.activeUnit?.unit).toBe('guidance');
});

it.each(['inactive unit', 'rollout'] as const)('preserves a mounted Personal draft and submission after Work %s loss', loss => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  const discarded = jest.fn();
  let setSubmitting!: React.Dispatch<React.SetStateAction<boolean>>;
  function PersonalForm() {
    const [draft, setDraft] = React.useState('Retained booking details');
    const [submitting, updateSubmitting] = React.useState(true);
    setSubmitting = updateSubmitting;
    useContextDraftRegistration({
      dirty: draft !== '', submitting,
      onDiscard: () => { discarded(); setDraft(''); },
    });
    return <>{draft}</>;
  }
  const form = <PersonalForm />;
  renderProvider(form);
  const next = profile({ units: loss === 'inactive unit' ? ['clinic'] : ['clinic', 'guidance'] });
  if (loss === 'rollout') { next.entitlements.work.enabled = false; }
  refresh(next, form);

  expect(discarded).not.toHaveBeenCalled();
  expect(renderer!.toJSON()).toBe('Retained booking details');
  expect(current.hasDirtyDrafts).toBe(true);
  expect(current.hasPendingSubmission).toBe(true);
  const leave = jest.fn();
  act(() => { expect(current.requestDiscard(leave)).toBe(false); });
  expect(leave).not.toHaveBeenCalled();
  act(() => setSubmitting(false));
  expect(current.hasPendingSubmission).toBe(false);
  expect(current.hasDirtyDrafts).toBe(true);
});

it('preserves the active authorized Work hook draft and submission when an inactive unit is revoked', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();
  act(() => current.selectUnit('clinic'));
  const discarded = jest.fn();
  function WorkForm() {
    useWorkspaceDraft({ key: 'form', dirty: true, submitting: true, onDiscard: discarded });
    return null;
  }
  const form = <WorkForm />;
  refresh(profile({ units: ['clinic', 'guidance'] }), form);
  refresh(profile({ units: ['clinic'] }), form);

  expect(current.activeUnit?.unit).toBe('clinic');
  expect(discarded).not.toHaveBeenCalled();
  expect(current.hasDirtyDrafts).toBe(true);
  expect(current.hasPendingSubmission).toBe(true);
  const leave = jest.fn();
  act(() => { expect(current.requestDiscard(leave)).toBe(false); });
  expect(leave).not.toHaveBeenCalled();
});

it('disposes only the revoked active Work scope while preserving Personal and other valid-unit locks', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();
  act(() => current.selectUnit('clinic'));
  const revokedDiscard = jest.fn();
  const personalDiscard = jest.fn();
  const guidanceDiscard = jest.fn();
  const cleanups: Array<() => void> = [];
  act(() => {
    // Key text deliberately disagrees with scope: ownership is never inferred from strings.
    cleanups.push(current.registerDirtyDraft('personal-looking-work', { scope: { context: 'workspace', unit: 'clinic' }, onDiscard: revokedDiscard }));
    cleanups.push(current.registerSubmission('personal-looking-work', { scope: { context: 'workspace', unit: 'clinic' } }));
    cleanups.push(current.registerDirtyDraft('work-looking-personal', { scope: { context: 'personal' }, onDiscard: personalDiscard }));
    cleanups.push(current.registerSubmission('work-looking-personal', { scope: { context: 'personal' } }));
    cleanups.push(current.registerDirtyDraft('guidance', { scope: { context: 'workspace', unit: 'guidance' }, onDiscard: guidanceDiscard }));
    cleanups.push(current.registerSubmission('guidance', { scope: { context: 'workspace', unit: 'guidance' } }));
  });
  refresh(profile({ units: ['guidance'] }));

  expect(revokedDiscard).toHaveBeenCalledTimes(1);
  expect(personalDiscard).not.toHaveBeenCalled();
  expect(guidanceDiscard).not.toHaveBeenCalled();
  expect(current.activeUnit?.unit).toBe('guidance');
  expect(current.hasDirtyDrafts).toBe(true);
  expect(current.hasPendingSubmission).toBe(true);
  const leaveRevoked = jest.fn();
  act(() => { expect(current.requestDiscard(leaveRevoked, 'personal-looking-work')).toBe(true); });
  expect(leaveRevoked).toHaveBeenCalledTimes(1);
  act(() => {
    expect(current.requestDiscard(jest.fn(), 'work-looking-personal')).toBe(false);
    expect(current.requestDiscard(jest.fn(), 'guidance')).toBe(false);
    cleanups.forEach(cleanup => { cleanup(); cleanup(); });
  });
  expect(current.hasDirtyDrafts).toBe(false);
  expect(current.hasPendingSubmission).toBe(false);
  expect(revokedDiscard).toHaveBeenCalledTimes(1);
});

it('disposes a retained Work hook using its originating unit after active selection is revoked', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();
  act(() => current.selectUnit('clinic'));
  const discarded = jest.fn();
  function WorkForm() {
    const [draft, setDraft] = React.useState('Unsaved clinic draft');
    useWorkspaceDraft({ key: 'form', dirty: draft !== '', submitting: true, onDiscard: () => { discarded(); setDraft(''); } });
    return <>{draft}</>;
  }
  const form = <WorkForm />;
  refresh(profile({ units: ['clinic', 'guidance'] }), form);
  refresh(profile({ units: ['guidance'] }), form);

  expect(discarded).toHaveBeenCalledTimes(1);
  expect(current.activeUnit?.unit).toBe('guidance');
  expect(current.hasDirtyDrafts).toBe(false);
  expect(current.hasPendingSubmission).toBe(false);
  refresh(profile({ units: ['guidance'] }), form);
  expect(discarded).toHaveBeenCalledTimes(1);
  expect(current.hasPendingSubmission).toBe(false);
});

it('disposes Personal scopes on Personal access loss while retaining authorized Work scopes', () => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();
  const personalDiscard = jest.fn();
  const workDiscard = jest.fn();
  act(() => {
    current.registerDirtyDraft('personal', { scope: { context: 'personal' }, onDiscard: personalDiscard });
    current.registerSubmission('personal', { scope: { context: 'personal' } });
    current.registerDirtyDraft('work', { scope: { context: 'workspace', unit: 'clinic' }, onDiscard: workDiscard });
    current.registerSubmission('work', { scope: { context: 'workspace', unit: 'clinic' } });
  });
  refresh(profile({ personal: false, units: ['clinic'] }));

  expect(personalDiscard).toHaveBeenCalledTimes(1);
  expect(workDiscard).not.toHaveBeenCalled();
  expect(current.mode).toBe('workspace');
  expect(current.hasDirtyDrafts).toBe(true);
  expect(current.hasPendingSubmission).toBe(true);
  const leave = jest.fn();
  act(() => {
    expect(current.requestDiscard(leave, 'personal')).toBe(true);
    expect(current.requestDiscard(jest.fn(), 'work')).toBe(false);
  });
  expect(leave).toHaveBeenCalledTimes(1);
});

it.each(['no mobile access', 'identity', 'sign out'] as const)('clears all scoped drafts and submissions on %s loss', loss => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();
  const personalDiscard = jest.fn();
  const workDiscard = jest.fn();
  act(() => {
    current.registerDirtyDraft('personal', { scope: { context: 'personal' }, onDiscard: personalDiscard });
    current.registerSubmission('personal', { scope: { context: 'personal' } });
    current.registerDirtyDraft('work', { scope: { context: 'workspace', unit: 'clinic' }, onDiscard: workDiscard });
    current.registerSubmission('work', { scope: { context: 'workspace', unit: 'clinic' } });
  });
  refresh(loss === 'sign out' ? null : loss === 'identity' ? profile({ user: 99, units: ['clinic'] }) : profile({ personal: false }));

  expect(personalDiscard).toHaveBeenCalledTimes(1);
  expect(workDiscard).toHaveBeenCalledTimes(1);
  expect(current.hasDirtyDrafts).toBe(false);
  expect(current.hasPendingSubmission).toBe(false);
});

it('does not discard while choosing or cancelling a unit picker', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();
  const discard = jest.fn();
  act(() => { current.registerDirtyDraft('task', { onDiscard: discard }); });
  act(() => current.enterWorkspace());
  expect(current.unitPickerVisible).toBe(true);
  expect(current.pendingDiscard).toBe(false);
  act(() => current.closeUnitPicker());
  expect(discard).not.toHaveBeenCalled();
  expect(current.hasDirtyDrafts).toBe(true);
});

it('disposes drafts once and continues only after the confirmation has closed', () => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();
  const discard = jest.fn();
  const navigate = jest.fn();
  act(() => { current.registerDirtyDraft('task', { onDiscard: discard }); });
  act(() => { current.requestDiscard(navigate); current.requestDiscard(jest.fn()); });
  expect(current.pendingDiscard).toBe(true);
  act(() => current.cancelDiscard());
  expect(discard).not.toHaveBeenCalled();
  expect(navigate).not.toHaveBeenCalled();
  act(() => current.completeDiscard());
  act(() => { expect(current.requestDiscard(navigate)).toBe(true); });
  act(() => current.confirmDiscard());
  expect(discard).toHaveBeenCalledTimes(1);
  expect(current.hasDirtyDrafts).toBe(false);
  expect(navigate).not.toHaveBeenCalled();
  act(() => { current.completeDiscard(); current.completeDiscard(); });
  expect(navigate).toHaveBeenCalledTimes(1);
});

it('locks transitions during a submission and scopes ordinary leave to its own draft', () => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();
  const first = jest.fn();
  const second = jest.fn();
  const navigate = jest.fn();
  let release!: () => void;
  act(() => {
    current.registerDirtyDraft('first', { onDiscard: first });
    current.registerDirtyDraft('second', { onDiscard: second });
    release = current.registerSubmission('first');
  });
  act(() => { current.requestDiscard(navigate); });
  expect(current.pendingDiscard).toBe(false);
  expect(current.notice).toMatch(/wait/i);
  act(() => release());
  act(() => { current.requestDiscard(navigate, 'first'); });
  act(() => current.confirmDiscard());
  act(() => current.completeDiscard());
  expect(first).toHaveBeenCalledTimes(1);
  expect(second).not.toHaveBeenCalled();
  expect(current.hasDirtyDrafts).toBe(true);
  expect(navigate).toHaveBeenCalledTimes(1);
});

it('guards external targets and waits for the selected unit to commit', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();
  const discard = jest.fn();
  const destination = jest.fn();
  act(() => { current.registerDirtyDraft('chat', { onDiscard: discard, warning: 'Delivery may have succeeded.' }); });
  act(() => expect(current.openAuthorizedUnit('guidance', destination)).toBe(true));
  expect(current.activeUnit).toBeNull();
  expect(current.pendingDiscardWarning).toMatch(/Delivery/);
  expect(destination).not.toHaveBeenCalled();
  act(() => current.confirmDiscard());
  act(() => current.completeDiscard());
  expect(current.activeUnit?.unit).toBe('guidance');
  expect(discard).toHaveBeenCalledTimes(1);
  expect(destination).toHaveBeenCalledTimes(1);
});

it('keeps safe same-unit record pushes from discarding a retained draft', () => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();
  act(() => current.enterWorkspace());
  const discard = jest.fn();
  const destination = jest.fn();
  act(() => { current.registerDirtyDraft('chat', { onDiscard: discard }); });
  act(() => { expect(current.openAuthorizedUnit('clinic', destination)).toBe(true); });
  expect(current.pendingDiscard).toBe(false);
  expect(current.hasDirtyDrafts).toBe(true);
  expect(discard).not.toHaveBeenCalled();
  expect(destination).toHaveBeenCalledTimes(1);
});

it('rejects another transition while a cancelled confirmation is exiting', () => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();
  const discarded = jest.fn();
  const first = jest.fn();
  const second = jest.fn();
  act(() => { current.registerDirtyDraft('task', { onDiscard: discarded }); });
  act(() => { expect(current.requestDiscard(first)).toBe(true); });
  act(() => current.cancelDiscard());
  act(() => {
    expect(current.requestDiscard(second)).toBe(false);
    current.confirmDiscard();
  });
  expect(current.pendingDiscard).toBe(false);
  expect(discarded).not.toHaveBeenCalled();
  act(() => current.completeDiscard());
  act(() => { expect(current.requestDiscard(second)).toBe(true); });
  act(() => current.confirmDiscard());
  act(() => current.completeDiscard());
  expect(first).not.toHaveBeenCalled();
  expect(second).toHaveBeenCalledTimes(1);
});

it('does not continue an accepted prompt after identity changes', () => {
  mockProfile(profile({ units: ['clinic'] }));
  renderProvider();
  const navigate = jest.fn();
  act(() => { current.registerDirtyDraft('task'); });
  act(() => { current.requestDiscard(navigate); });
  act(() => current.confirmDiscard());
  refresh(profile({ user: 99, units: ['guidance'] }));
  act(() => current.completeDiscard());
  expect(navigate).not.toHaveBeenCalled();
});

it('wipes session-only selection on sign-out and account change', () => {
  mockProfile(profile({ units: ['clinic', 'guidance'] }));
  renderProvider();
  act(() => {
    current.enterWorkspace();
    current.selectUnit('guidance');
  });

  refresh(null);
  expect(current.mode).toBe('personal');
  expect(current.activeUnit).toBeNull();
  expect(current.units).toEqual([]);

  refresh(profile({ user: 99, units: ['facilities'] }));
  expect(current.mode).toBe('personal');
  expect(current.activeUnit?.unit).toBe('facilities');
});
