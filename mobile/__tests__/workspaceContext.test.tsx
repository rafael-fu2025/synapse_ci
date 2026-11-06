import React from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import type { MeResponse, TeamUnit, WorkUnitEntitlement } from '../src/api/types';
import { WorkspaceProvider, useWorkspace } from '../src/features/workspace/WorkspaceContext';
import { useAuth } from '../src/store/auth';

jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));

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

function renderProvider() {
  act(() => {
    renderer = create(<WorkspaceProvider><Probe /></WorkspaceProvider>);
  });
}

function refresh(value: MeResponse | null) {
  mockProfile(value);
  act(() => renderer!.update(<WorkspaceProvider><Probe /></WorkspaceProvider>));
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
