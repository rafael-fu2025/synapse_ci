import React from 'react';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, Text, TextInput } from 'react-native';
import { PageHeader } from '../src/components/PageHeader';
import { useTheme } from '../src/theme/ThemeProvider';
import { lightTheme, darkTheme } from '../src/theme/tokens';
import { useStaffQueueSummary } from '../src/features/staff-queue/hooks';
import { StaffQueueScreen } from '../src/features/staff-queue/screens/StaffQueueScreen';
import { ApiError } from '../src/api/client';
import { useNetInfo } from '@react-native-community/netinfo';
jest.mock('../src/theme/ThemeProvider', () => ({ useTheme: jest.fn() }));
jest.mock('../src/features/staff-queue/hooks', () => ({ useStaffQueueSummary: jest.fn() }));
jest.mock('../src/api/client', () => ({ ApiError: class extends Error { status: number; constructor(code: number) { super('error'); this.status = code; } } }));
jest.mock('@react-navigation/native', () => ({ useNavigation: () => ({ goBack: jest.fn() }) }));
jest.mock('@react-native-community/netinfo', () => ({ useNetInfo: jest.fn(() => ({ isConnected: true, isInternetReachable: true })) }));
jest.mock('../src/components/Screen', () => ({ Screen: ({ children, header }: { children: React.ReactNode; header?: React.ReactNode }) => <>{header}{children}</> }));
jest.mock('../src/components/PageHeader', () => ({ PageHeader: ({ action }: { action: React.ReactNode }) => <>{action}</> }));
jest.mock('react-native-linear-gradient', () => jest.requireActual('react-native').View);
jest.mock('lucide-react-native', () => {
  const { View } = jest.requireActual('react-native');
  const Icon = () => <View />;
  return {
    Activity: Icon, Clock3: Icon, Hourglass: Icon, ListChecks: Icon,
    MessageSquareText: Icon, RefreshCw: Icon, Search: Icon, Stethoscope: Icon,
    Timer: Icon, TriangleAlert: Icon, Users: Icon, ChevronDown: Icon, ChevronUp: Icon,
  };
});
jest.mock('../src/components/IconAction', () => {
  const ReactModule = require('react');
  const RN = require('react-native');
  return { IconAction: ({ label, onPress }: { label: string; onPress?: () => void }) => ReactModule.createElement(RN.Pressable, { accessibilityRole: 'button', accessibilityLabel: label, onPress }) };
});
jest.mock('../src/components/SectionHeading', () => {
  const ReactModule = require('react');
  const RN = require('react-native');
  return { SectionHeading: ({ title, caption, trailing }: { title: string; caption?: string; trailing?: React.ReactNode }) => ReactModule.createElement(ReactModule.Fragment, null, ReactModule.createElement(RN.Text, null, title), caption ? ReactModule.createElement(RN.Text, null, caption) : null, trailing) };
});
jest.mock('../src/components/StatusPill', () => {
  const ReactModule = require('react');
  const RN = require('react-native');
  return { StatusPill: ({ children, tone }: { children: React.ReactNode; tone: string }) => ReactModule.createElement(RN.Text, { testID: `status-pill-${tone}` }, children) };
});
jest.mock('react-native-svg', () => {
  const { View } = jest.requireActual('react-native');
  return { __esModule: true, default: View, Defs: View, LinearGradient: View, Rect: View, Stop: View, SvgXml: View };
});
const ticket = { id: 1, ticket_code: 'C-001', status: 'waiting', priority: 'routine', created_at: '2026-11-05T09:50:00Z', called_at: null, started_at: null, skip_until: null, patient_name: 'PRIVATE PATIENT', patient_kind: 'PRIVATE KIND' };
const data = { data: [{ unit: 'clinic', counts: { total: 7, waiting: 2, called: 0, in_consultation: 0, skipped: 1, done: 3, no_show: 1 }, tickets: { waiting: [ticket, { ...ticket, id: 2, ticket_code: 'C-002', priority: 'urgent' }], called: [], in_consultation: [], skipped: [{ ...ticket, id: 3, ticket_code: 'C-003', status: 'skipped', skip_until: '2026-11-05T10:05:00Z' }] }, metrics: { active: 3, priority_mix: { routine: 2, urgent: 1, emergency: 0 }, waiting_age: { sample_count: 2, total_seconds: 900, oldest_seconds: 600, average_seconds: 450 } } }], meta: { date: '2026-11-05', generated_at: '2026-11-05T10:00:00Z' } };
let renderer: ReactTestRenderer;
let state: any;
const refetch = jest.fn(() => Promise.resolve());
function render() { act(() => { renderer = create(<StaffQueueScreen />); }); }
it('preserves fixed-header side gutters without a gap above scrolling content', () => {
  render();
  expect(renderer.root.findByType(PageHeader).props.edgeToEdge).toBe(true);
  const content = StyleSheet.flatten(renderer.root.findByType(ScrollView).props.contentContainerStyle);
  expect(content.paddingHorizontal).toBe(18);
  expect(content.paddingTop).toBe(0);
  const hero = renderer.root.findAll(n => typeof n.type === 'string' && n.props.accessibilityLabel?.startsWith('Combined queue.'))[0];
  expect(StyleSheet.flatten(hero.props.style).marginTop).toBe(10);
  expect(hero.findAll(n => typeof n.props.xml === 'string')).toHaveLength(0);
});
function text() { return renderer.root.findAllByType(Text).map(n => React.Children.toArray(n.props.children).join('')).join('\n'); }
function press(label: string) { act(() => { renderer.root.findAll(n => n.props.accessibilityLabel === label && typeof n.props.onPress === 'function')[0].props.onPress(); }); }
beforeEach(() => {
  jest.useFakeTimers().setSystemTime(new Date('2026-11-05T10:00:10Z'));
  jest.mocked(useTheme).mockReturnValue({ theme: lightTheme, mode: 'light', preference: 'system', setPreference: jest.fn() });
  state = { data, isLoading: false, isError: false, isRefetching: false, isFetching: false, refetch, fetchStatus: 'idle' };
  jest.mocked(useStaffQueueSummary).mockImplementation(() => state);
  jest.mocked(useNetInfo).mockReturnValue({ isConnected: true, isInternetReachable: true } as any);
  refetch.mockClear();
});
afterEach(() => { act(() => { renderer?.unmount(); }); jest.useRealTimers(); });
it.each([lightTheme, darkTheme])('renders privacy-first board in $mode theme', theme => {
  jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference: jest.fn() });
  render();
  expect(text()).toContain('10m');
  expect(text()).toContain('7m');
  expect(text()).toContain('C-003');
  expect(text()).not.toMatch(/PRIVATE|ETA|SLA|capacity/);
  expect(renderer.root.findAll(n => n.props.accessible && n.props.accessibilityLabel?.includes('Unit waiting rank 2')).length).toBeGreaterThan(0);
  const gradient = renderer.root.findAll(n => Array.isArray(n.props.colors))[0];
  expect(gradient.props.colors).toEqual(theme.mode === 'dark' ? ['#1c1c1c', '#0f0f0f'] : ['#520000', '#800000']);
  expect(renderer.root.findAll(n => typeof n.type === 'string' && n.props.testID === 'staffQueueOutline')).toHaveLength(theme.mode === 'dark' ? 1 : 0);
});
it('filters locally, retains original unit rank and combined metrics, and clears filters', () => {
  render(); press('Toggle queue filters'); press('Priority: urgent');
  expect(text()).not.toContain('C-001');
  expect(text()).toContain('C-002');
  expect(text()).toContain('Clinic #2');
  expect(text()).toContain('10m');
  expect(useStaffQueueSummary).toHaveBeenLastCalledWith();
  act(() => renderer.root.findByType(TextInput).props.onChangeText('missing'));
  expect(text()).toContain('No Matching Tickets');
  press('Clear queue filters'); expect(text()).toContain('C-001');
  press('Stage: skipped'); expect(text()).toContain('Hold Until'); expect(text()).not.toContain('C-002');
});
it('provides 44px selectable controls and manual and pull refresh', () => {
  render();
  press('Toggle queue filters');
  renderer.root.findAll(n => n.props.accessibilityState && n.props.onPress).forEach(n => expect(StyleSheet.flatten(n.props.style).minHeight).toBeGreaterThanOrEqual(44));
  press('Refresh queue');
  act(() => renderer.root.findByType(RefreshControl).props.onRefresh());
  expect(refetch).toHaveBeenCalledTimes(2);
});
it.each([401, 403])('hides all cached protected rows and metrics on %s', status => {
  state = { ...state, isError: true, error: new ApiError(status, { code: 'error', message: 'error' }) };
  render(); expect(text()).toContain('Access Denied'); expect(text()).not.toContain('C-001'); expect(text()).not.toContain('ALL AUTHORIZED');
});
it('shows retained snapshot for transient error, offline and stale conditions', () => {
  state = { ...state, isError: true, error: new Error('network') };
  jest.mocked(useNetInfo).mockReturnValue({ isConnected: false } as any);
  render(); expect(text()).toContain('Offline'); expect(text()).toContain('Saved Snapshot'); expect(text()).toContain('C-001');
  act(() => { jest.advanceTimersByTime(60000); state = { ...state, isFetching: true }; renderer.update(<StaffQueueScreen />); }); expect(text()).toContain('Offline'); expect(text()).toContain('Saved Snapshot'); expect(text()).toContain('Outdated');
});
it('does not present an old business date as today', () => {
  state = { ...state, data: { ...data, meta: { ...data.meta, date: '2026-11-04' } } }; render();
  expect(text()).toContain('Queue Snapshot'); expect(text()).not.toContain('Today');
});
it('distinguishes loading, failed initial fetch and empty queues', () => {
  state = { ...state, data: undefined, isLoading: true }; render(); expect(renderer.root.findAllByType(ActivityIndicator)).toHaveLength(1);
  act(() => { state = { ...state, isLoading: false, isError: true }; renderer.update(<StaffQueueScreen />); });
  expect(text()).toContain('Retry Refresh'); expect(text()).not.toContain('No active');
  act(() => { state = { ...state, isError: false, data: { ...data, data: [] } }; renderer.update(<StaffQueueScreen />); });
  expect(text()).toContain('No Authorized Queues');
});

it('keeps totals and timing without the distribution bar or ticket lane lines', () => {
  render();
  expect(text()).toContain('Issued'); expect(text()).toContain('Completed'); expect(text()).toContain('No-Show');
  expect(text()).toContain('Oldest Ticket'); expect(text()).toContain('10m');
  expect(renderer.root.findAllByProps({ testID: 'stageDistribution' })).toHaveLength(0);
  const lanes = renderer.root.findAll(n => typeof n.type === 'string' && n.props.testID?.startsWith('ticket-lane-'));
  expect(lanes.length).toBeGreaterThan(0);
  lanes.forEach(lane => expect(StyleSheet.flatten(lane.props.style).borderLeftWidth ?? 0).toBe(0));
  expect(text()).toContain('5m Remaining');
});

it('collapses filters by default and preserves selected filters when closed', () => {
  render();
  const toggle = () => renderer.root.findAll(n => n.props.accessibilityLabel === 'Toggle queue filters' && n.props.onPress)[0];
  expect(toggle().props.accessibilityState.expanded).toBe(false);
  expect(renderer.root.findAllByType(TextInput)).toHaveLength(0);
  press('Toggle queue filters');
  expect(toggle().props.accessibilityState.expanded).toBe(true);
  press('Priority: urgent');
  press('Toggle queue filters');
  expect(text()).toContain('Filters Applied');
  expect(text()).toContain('C-002');
  expect(text()).not.toContain('C-001');
  expect(renderer.root.findAllByType(TextInput)).toHaveLength(0);
  press('Toggle queue filters');
  press('Clear queue filters');
  expect(text()).toContain('C-001');
});

it.each([lightTheme, darkTheme])('uses tinted unit vectors in $mode mode', theme => {
  jest.mocked(useTheme).mockReturnValue({ theme, mode: theme.mode, preference: 'system', setPreference: jest.fn() });
  const clinic = data.data[0];
  state = { ...state, data: { ...data, data: [clinic, { ...clinic, unit: 'guidance' }] } };
  render();
  const clinicArt = renderer.root.findAll(n => n.props.testID === 'unit-vector-clinic')[0];
  const guidanceArt = renderer.root.findAll(n => n.props.testID === 'unit-vector-guidance')[0];
  expect(clinicArt.props.color).toBe(theme.mode === 'dark' ? '#ffffff' : '#800000');
  expect(guidanceArt.props.color).toBe(clinicArt.props.color);
  expect(clinicArt.props.xml).not.toBe(guidanceArt.props.xml);
  expect(clinicArt.props.xml).toContain('currentColor');
  expect(guidanceArt.props.xml).toContain('currentColor');
});
it('uses Manila today across the UTC/device date boundary and human as-of times', () => {
  jest.setSystemTime(new Date('2026-11-04T16:05:00Z'));
  state = { ...state, data: { ...data, meta: { date: '2026-11-05', generated_at: '2026-11-04T16:00:00Z' } } };
  render();
  expect(text()).toContain('Today’s Queue');
  expect(text()).toMatch(/Updated 12:00\s?am/i);
  expect(text()).not.toContain('2026-11-04T16:00:00Z');
  expect(jest.getTimerCount()).toBe(0);
});
it('shows stage badges, elapsed called/consultation time and passed holds without automatic no-show', () => {
  const unit = data.data[0];
  state = { ...state, data: { ...data, data: [{ ...unit, tickets: { ...unit.tickets,
    called: [{ ...ticket, id: 4, ticket_code: 'C-004', status: 'called', priority: 'emergency', called_at: '2026-11-05T09:58:00Z' }],
    in_consultation: [{ ...ticket, id: 5, ticket_code: 'C-005', status: 'in_consultation', started_at: '2026-11-05T09:57:00Z' }],
    skipped: [{ ...ticket, id: 6, ticket_code: 'C-006', status: 'skipped', skip_until: '2026-11-05T09:59:00Z' }],
  } }] } };
  render();
  expect(text()).toContain('Called 2m');
  expect(text()).toContain('In Session 3m');
  expect(text()).toContain('Deadline Passed');
  expect(text()).toContain('No-Show');
  const pills = renderer.root.findAllByType(require('../src/components/StatusPill').StatusPill);
  expect(pills.some(n => n.props.tone === 'danger' && n.props.children === 'Emergency')).toBe(true);
  expect(pills.some(n => n.props.tone === 'info' && n.props.children === 'Called')).toBe(true);
  expect(pills.some(n => n.props.tone === 'success' && n.props.children === 'In Consultation')).toBe(true);
});

it('uses concise labels without dot-separated visual sentences', () => {
  render();
  expect(text()).not.toContain('·');
  expect(text()).not.toContain('at snapshot');
  expect(text()).not.toContain('Unit ranks follow');
  expect(text()).toContain('Routine 2');
});
