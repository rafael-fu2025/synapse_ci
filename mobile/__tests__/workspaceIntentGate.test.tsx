import React from 'react';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { useAuth } from '../src/store/auth';
import type { MeResponse } from '../src/api/types';
import { NavigationIntentGate, navigateAuthorizedIntent } from '../src/features/workspace/NavigationIntentGate';
import { useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { __resetNavigationIntentForTests, queueNavigationIntent, takePendingNavigationIntent } from '../src/features/workspace/navigationIntent';
import { navigationRef, navigateToAppointmentDetail, navigateToWorkspaceIntent } from '../src/navigation/navigationRef';

jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));
jest.mock('../src/features/workspace/WorkspaceContext', () => ({ useWorkspace: jest.fn() }));
jest.mock('../src/navigation/navigationRef', () => ({
  navigationRef: { isReady: jest.fn(), navigate: jest.fn() },
  navigateToAppointmentDetail: jest.fn(), navigateToWorkspaceIntent: jest.fn(),
}));

const profile: MeResponse = {
  user: { id: 7, tenant_id: 3, person_id: 9, username: 'member', identifier: 'member', email: null, name: 'Team Member', avatar_initials: 'TM', role: 'staff', is_teaching: false, permissions: [] },
  entitlements: { portal: true, staff_queue_units: [], personal: { enabled: true }, work: { enabled: true, units: [
    { unit: 'clinic', label: 'Clinic', can_manage: false, features: { team_board: true, my_tasks: true, team_chat: true, team_calendar: true } },
  ] } },
  photo_url: '/api/v1/me/photo',
};
let renderer: ReactTestRenderer | undefined;
const showNotice = jest.fn();
const openUnit = jest.fn((_unit: string, _afterOpen?: () => void) => true);
const discard = jest.fn((_action: () => void) => true);
const enterPersonal = jest.fn();

beforeEach(() => {
  jest.clearAllMocks();
  __resetNavigationIntentForTests();
  jest.mocked(navigationRef.isReady).mockReturnValue(true);
  openUnit.mockReturnValue(true);
  discard.mockReturnValue(true);
  jest.mocked(useAuth).mockReturnValue({ status: 'signedIn', profile } as ReturnType<typeof useAuth>);
  jest.mocked(useWorkspace).mockReturnValue({ openAuthorizedUnit: openUnit, requestDiscard: discard, enterPersonal, showNotice } as unknown as ReturnType<typeof useWorkspace>);
});
afterEach(() => { act(() => renderer?.unmount()); renderer = undefined; __resetNavigationIntentForTests(); });

it('waits for authorized unit selection before opening the actual Team destination', () => {
  const intent = { kind: 'team_task' as const, unit: 'clinic' as const, id: 11 };
  expect(navigateAuthorizedIntent(intent, profile, openUnit)).toBe(true);
  expect(openUnit).toHaveBeenCalledWith('clinic', expect.any(Function));
  expect(navigateToWorkspaceIntent).not.toHaveBeenCalled();
  openUnit.mock.calls[0][1]!();
  expect(navigateToWorkspaceIntent).toHaveBeenCalledWith(intent);
});

it('routes a task notice to My Tasks when the board feature is disabled', () => {
  const taskOnly = { ...profile, entitlements: { ...profile.entitlements, work: { ...profile.entitlements.work, units: [{ ...profile.entitlements.work.units[0], features: { ...profile.entitlements.work.units[0].features, team_board: false } }] } } };
  const intent = { kind: 'team_task' as const, unit: 'clinic' as const, id: 11 };
  expect(navigateAuthorizedIntent(intent, taskOnly, openUnit)).toBe(true);
  openUnit.mock.calls[0][1]!();
  expect(navigateToWorkspaceIntent).toHaveBeenCalledWith(intent, 'MyTasks');
});

it('does not navigate to revoked units, disabled features, or a blocked unit transition', () => {
  expect(navigateAuthorizedIntent({ kind: 'team_event', unit: 'guidance', id: 4 }, profile, openUnit)).toBe(false);
  const disabled = { ...profile, entitlements: { ...profile.entitlements, work: { ...profile.entitlements.work, units: [{ ...profile.entitlements.work.units[0], features: { ...profile.entitlements.work.units[0].features, team_calendar: false } }] } } };
  expect(navigateAuthorizedIntent({ kind: 'team_event', unit: 'clinic', id: 4 }, disabled, openUnit)).toBe(false);
  expect(openUnit).not.toHaveBeenCalled();
  openUnit.mockReturnValue(false);
  expect(navigateAuthorizedIntent({ kind: 'team_task', unit: 'clinic', id: 4 }, profile, openUnit)).toBe(false);
  expect(navigateToWorkspaceIntent).not.toHaveBeenCalled();
});

it('protects a Personal target and rejects Personal-only content for work-only accounts', () => {
  const intent = { kind: 'appointment' as const, id: 4 };
  expect(navigateAuthorizedIntent(intent, profile, openUnit, discard, enterPersonal)).toBe(true);
  expect(navigateToAppointmentDetail).not.toHaveBeenCalled();
  discard.mock.calls[0][0]();
  expect(enterPersonal).toHaveBeenCalledTimes(1);
  expect(navigateToAppointmentDetail).toHaveBeenCalledWith(4);
  const workOnly = { ...profile, entitlements: { ...profile.entitlements, personal: { enabled: false } } };
  expect(navigateAuthorizedIntent(intent, workOnly, openUnit, discard)).toBe(false);
  expect(discard).toHaveBeenCalledTimes(1);
});

it('returns false when a pending action prevents a Personal navigation request', () => {
  discard.mockReturnValue(false);
  expect(navigateAuthorizedIntent({ kind: 'survey', id: 4 }, profile, openUnit, discard)).toBe(false);
  expect(navigationRef.navigate).not.toHaveBeenCalled();
});

it.each(['announcement', 'survey', 'appointment'] as const)('rechecks Personal access before a deferred %s push opens', kind => {
  act(() => { renderer = create(<NavigationIntentGate />); });
  act(() => queueNavigationIntent({ kind, id: 4 }));
  expect(discard).toHaveBeenCalledTimes(1);
  const revoked = { ...profile, entitlements: { ...profile.entitlements, personal: { enabled: false } } };
  jest.mocked(useAuth).mockReturnValue({ status: 'signedIn', profile: revoked } as ReturnType<typeof useAuth>);
  act(() => renderer!.update(<NavigationIntentGate />));
  act(() => discard.mock.calls[0][0]());
  expect(navigationRef.navigate).not.toHaveBeenCalled();
  expect(navigateToAppointmentDetail).not.toHaveBeenCalled();
  expect(enterPersonal).not.toHaveBeenCalled();
  expect(showNotice).toHaveBeenCalledTimes(1);
});

it('rejects an old identity deferred target after a same-tree account change', () => {
  act(() => { renderer = create(<NavigationIntentGate />); });
  act(() => queueNavigationIntent({ kind: 'survey', id: 4 }));
  const next = { ...profile, user: { ...profile.user, id: 8 } };
  jest.mocked(useAuth).mockReturnValue({ status: 'signedIn', profile: next } as ReturnType<typeof useAuth>);
  act(() => renderer!.update(<NavigationIntentGate />));
  act(() => discard.mock.calls[0][0]());
  expect(navigationRef.navigate).not.toHaveBeenCalled();
  expect(showNotice).toHaveBeenCalledTimes(1);
});

it('does not open a deferred target after its signed-in gate unmounts', () => {
  act(() => { renderer = create(<NavigationIntentGate />); });
  act(() => queueNavigationIntent({ kind: 'announcement', id: 4 }));
  act(() => renderer!.unmount());
  renderer = undefined;
  act(() => discard.mock.calls[0][0]());
  expect(navigationRef.navigate).not.toHaveBeenCalled();
  expect(showNotice).not.toHaveBeenCalled();
});

it('rechecks named Work features and identity after an authorized unit transition', () => {
  act(() => { renderer = create(<NavigationIntentGate />); });
  act(() => queueNavigationIntent({ kind: 'team_event', unit: 'clinic', id: 4 }));
  const disabled = { ...profile, entitlements: { ...profile.entitlements, work: { ...profile.entitlements.work, units: [{
    ...profile.entitlements.work.units[0], features: { ...profile.entitlements.work.units[0].features, team_calendar: false },
  }] } } };
  jest.mocked(useAuth).mockReturnValue({ status: 'signedIn', profile: disabled } as ReturnType<typeof useAuth>);
  act(() => renderer!.update(<NavigationIntentGate />));
  act(() => openUnit.mock.calls[0][1]!());
  expect(navigateToWorkspaceIntent).not.toHaveBeenCalled();
  expect(showNotice).toHaveBeenCalledTimes(1);
});

it('uses current task-tab availability when completing delayed unit entry', () => {
  const intent = { kind: 'team_task' as const, unit: 'clinic' as const, id: 4 };
  act(() => { renderer = create(<NavigationIntentGate />); });
  act(() => queueNavigationIntent(intent));
  const next = { ...profile, entitlements: { ...profile.entitlements, work: { ...profile.entitlements.work, units: [{
    ...profile.entitlements.work.units[0], features: { ...profile.entitlements.work.units[0].features, team_board: false },
  }] } } };
  jest.mocked(useAuth).mockReturnValue({ status: 'signedIn', profile: next } as ReturnType<typeof useAuth>);
  act(() => renderer!.update(<NavigationIntentGate />));
  act(() => openUnit.mock.calls[0][1]!());
  expect(navigateToWorkspaceIntent).toHaveBeenCalledWith(intent, 'MyTasks');
  expect(showNotice).not.toHaveBeenCalled();
});

it('consumes a rejected signed-in push once and shows feedback rather than replaying it', () => {
  act(() => { renderer = create(<NavigationIntentGate />); });
  act(() => queueNavigationIntent({ kind: 'team_event', unit: 'guidance', id: 4 }));
  expect(showNotice).toHaveBeenCalledTimes(1);
  expect(takePendingNavigationIntent()).toBeNull();
  act(() => renderer!.update(<NavigationIntentGate />));
  expect(showNotice).toHaveBeenCalledTimes(1);
  expect(navigateToWorkspaceIntent).not.toHaveBeenCalled();
});

it('retains a cold-start target until both auth and navigation are ready', () => {
  const intent = { kind: 'team_task' as const, unit: 'clinic' as const, id: 4 };
  jest.mocked(useAuth).mockReturnValue({ status: 'restoring', profile: null } as ReturnType<typeof useAuth>);
  queueNavigationIntent(intent);
  act(() => { renderer = create(<NavigationIntentGate />); });
  expect(openUnit).not.toHaveBeenCalled();
  jest.mocked(useAuth).mockReturnValue({ status: 'signedIn', profile } as ReturnType<typeof useAuth>);
  act(() => renderer!.update(<NavigationIntentGate />));
  expect(openUnit).toHaveBeenCalledTimes(1);
  expect(takePendingNavigationIntent()).toBeNull();
});
