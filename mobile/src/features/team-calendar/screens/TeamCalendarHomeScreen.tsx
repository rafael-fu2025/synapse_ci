import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
  useWindowDimensions,
} from 'react-native';
import { useNetInfo } from '@react-native-community/netinfo';
import { useIsFocused, useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import {
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Plus,
  RefreshCw,
  SlidersHorizontal,
} from 'lucide-react-native';
import type {
  TeamCalendarItem,
  TeamCalendarScope,
  TeamCalendarStatusFilter,
  TeamCalendarView,
} from '../../../api/endpoints/teamCalendar';
import { Action } from '../../../components/Action';
import { BottomSheet } from '../../../components/BottomSheet';
import { EmptyState } from '../../../components/EmptyState';
import { IconAction } from '../../../components/IconAction';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { useAuth } from '../../../store/auth';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { WorkspaceHeaderActions } from '../../workspace/WorkspaceHeaderActions';
import { CalendarItemRow } from '../components/CalendarItemRow';
import { teamCalendarErrorKind, teamCalendarErrorMessage } from '../errors';
import { useCalendarManualRefresh, useManilaToday, useTeamCalendar, useTeamCalendarAccess } from '../hooks';
import {
  addManilaDays,
  addManilaMonths,
  formatCompactDate,
  formatManilaDayLabel,
  formatManilaMonthLabel,
  monthGridDates,
  startOfManilaWeek,
  todayInManila,
} from '../manila';
import { agendaSections, countItemsByDate, itemsForDate } from '../model';
import { useOpenTaskDeadline, type TeamCalendarStackParamList } from '../navigation';

type Navigation = NativeStackNavigationProp<TeamCalendarStackParamList>;
const VIEWS: readonly TeamCalendarView[] = ['agenda', 'week', 'month'];
const SCOPES: readonly TeamCalendarScope[] = ['team', 'mine'];
const STATUSES: readonly TeamCalendarStatusFilter[] = ['scheduled', 'all', 'cancelled'];
const WEEKDAY_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

export function TeamCalendarHomeScreen() {
  const navigation = useNavigation<Navigation>();
  const focused = useIsFocused();
  const { user } = useAuth();
  const { fontScale, width } = useWindowDimensions();
  const openTask = useOpenTaskDeadline();
  const { activeUnit, openUnitPicker } = useWorkspace();
  const network = useNetInfo();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const today = useManilaToday();
  const previousToday = useRef(today);
  const [view, setView] = useState<TeamCalendarView>('agenda');
  const [scope, setScope] = useState<TeamCalendarScope>('team');
  const [status, setStatus] = useState<TeamCalendarStatusFilter>('scheduled');
  const [date, setDate] = useState(today);
  const [selectedDate, setSelectedDate] = useState(today);
  const [filterSession, setFilterSession] = useState<object | null>(null);
  const filterLease = useRef<object | null>(null);
  const unit = activeUnit?.features.team_calendar === true ? activeUnit.unit : null;
  const filters = useMemo(() => ({ date, view, scope, status }), [date, scope, status, view]);
  const { accessDenied, refreshingAccess, recoverAccess, retryAccess } = useTeamCalendarAccess(unit);
  const query = useTeamCalendar(unit, filters, !accessDenied);
  const { refreshing, refresh } = useCalendarManualRefresh(query);
  const errorKind = query.isError ? teamCalendarErrorKind(query.error) : null;
  const forbidden = accessDenied || errorKind === 'forbidden';
  const items = useMemo(() => forbidden ? [] : query.data?.data ?? [], [forbidden, query.data]);
  const meta = forbidden ? undefined : query.data?.meta;
  const offline = network.isConnected === false || query.fetchStatus === 'paused';
  const filterCount = Number(scope !== 'team') + Number(status !== 'scheduled');
  const filterSummary = `${scope === 'mine' ? 'Mine' : 'Team'} · ${status === 'all' ? 'All events' : status === 'cancelled' ? 'Cancelled events' : 'Scheduled events'}`;
  const scopeKey = `${user?.tenant_id ?? 0}:${user?.id ?? 0}:${unit ?? 'none'}`;
  const lifetime = useRef({ key: scopeKey, mounted: true, focused, forbidden });
  lifetime.current.key = scopeKey;
  lifetime.current.focused = focused;
  lifetime.current.forbidden = forbidden;

  useEffect(() => {
    const screen = lifetime.current;
    screen.mounted = true;
    return () => { screen.mounted = false; filterLease.current = null; };
  }, []);

  useEffect(() => {
    filterLease.current = null;
    setFilterSession(null);
  }, [scopeKey, focused, forbidden]);

  function isCurrentFilterScope() {
    return lifetime.current.mounted && lifetime.current.key === scopeKey && lifetime.current.focused && !lifetime.current.forbidden && unit !== null;
  }
  function isCurrentFilterSession() {
    return isCurrentFilterScope() && filterSession !== null && filterLease.current === filterSession;
  }
  function openFilters() {
    if (!isCurrentFilterScope()) { return; }
    const lease = {};
    filterLease.current = lease;
    setFilterSession(lease);
  }
  function closeFilters() {
    if (!isCurrentFilterSession()) { return; }
    filterLease.current = null;
    setFilterSession(null);
  }
  function resetFilters() {
    if (!isCurrentFilterSession()) { return; }
    setScope('team');
    setStatus('scheduled');
  }

  useEffect(() => {
    if (errorKind === 'forbidden') { recoverAccess().catch(() => undefined); }
  }, [errorKind, recoverAccess]);

  useEffect(() => {
    const previous = previousToday.current;
    previousToday.current = today;
    // Follow today only when the user is still viewing it. Browsed dates survive
    // midnight, foregrounding, task navigation, and background refreshes.
    if (date === previous && selectedDate === previous) {
      setDate(today);
      setSelectedDate(today);
    }
  }, [date, selectedDate, today]);

  const header = (
    <PageHeader
      edgeToEdge
      title="Team Calendar"
      subtitle={activeUnit?.label ?? 'Choose a Team Workspace'}
      action={(
        <WorkspaceHeaderActions root>
          {unit && !forbidden ? (
            <IconAction
              label="Create event"
              icon={<Plus size={19} color={theme.brandIcon} />}
              onPress={() => navigation.navigate('TeamCalendarEventForm', { initialDate: selectedDate })}
            />
          ) : null}
        </WorkspaceHeaderActions>
      )}
    />
  );

  if (!unit) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<CalendarDays size={30} color={theme.brandIcon} />}
          title="Team Calendar unavailable"
          body={activeUnit
            ? 'Calendar access is not enabled for this Team Workspace unit.'
            : 'Choose a Team Workspace unit to view its calendar.'}
          action={!activeUnit ? <Action full onPress={openUnitPicker}>Choose unit</Action> : undefined}
        />
      </Screen>
    );
  }

  if (forbidden) {
    return (
      <Screen header={header}>
        <EmptyState
          icon={<CalendarDays size={30} color={theme.brandIcon} />}
          title="Calendar access changed"
          body="Your calendar read access is no longer available. Refresh workspace access to continue."
          action={<Action full loading={refreshingAccess} disabled={offline} onPress={() => { retryAccess().catch(() => undefined); }}>Refresh access</Action>}
        />
      </Screen>
    );
  }

  const move = (direction: -1 | 1) => {
    const next = view === 'month'
      ? addManilaMonths(date, direction)
      : addManilaDays(date, direction * (view === 'week' ? 7 : 30));
    setDate(next);
    setSelectedDate(next);
  };

  const selectView = (next: TeamCalendarView) => {
    setView(next);
    setDate(selectedDate);
  };

  const openItem = (item: TeamCalendarItem) => {
    if (item.type === 'event') {
      navigation.navigate('TeamCalendarEventDetail', { id: item.id });
    } else {
      openTask(item.id);
    }
  };

  return (
    <Screen scroll={false} bodyStyle={styles.screen} header={header}>
      <ScrollView
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refreshing} enabled={query.eligible} tintColor={theme.brandIcon} colors={[theme.brandIcon]} onRefresh={() => { refresh().catch(() => undefined); }} />}>
        <View testID="calendar-toolbar" style={styles.toolbar}>
          <View testID="calendar-view-control" style={[styles.viewControl, { minWidth: Math.min(Math.max(0, width - 36), Math.ceil(210 * fontScale)) }]}>
            <SegmentedControl
              label="Calendar view"
              values={VIEWS}
              selected={view}
              labelFor={value => value[0].toUpperCase() + value.slice(1)}
              onChange={selectView}
            />
          </View>
          <Pressable accessibilityRole="button" accessibilityLabel="Open calendar filters" accessibilityHint={filterSummary}
            accessibilityState={{ expanded: filterSession !== null }} onPress={openFilters}
            style={({ pressed }) => [styles.filterButton, filterCount > 0 && styles.filterButtonActive, pressed && styles.pressed]}>
            <SlidersHorizontal size={20} color={filterCount > 0 ? theme.brandText : theme.ink700} />
            {filterCount > 0 ? <View style={styles.filterBadge}><Text style={styles.filterBadgeText}>{filterCount}</Text></View> : null}
          </Pressable>
        </View>
        <Text testID="calendar-filter-summary" accessibilityLiveRegion="polite" style={styles.filterSummary}>{filterSummary}</Text>

        <View style={styles.periodBar}>
          <Pressable accessibilityRole="button" accessibilityLabel="Previous calendar period" onPress={() => move(-1)} style={styles.periodButton}>
            <ChevronLeft size={19} color={theme.ink700} />
          </Pressable>
          <Pressable accessibilityRole="button" accessibilityLabel="Go to today" onPress={() => { const currentToday = todayInManila(); setDate(currentToday); setSelectedDate(currentToday); }} style={styles.periodLabelButton}>
            <Text style={styles.periodLabel}>{periodLabel(view, date, meta?.start_date, meta?.end_date)}</Text>
            <Text style={styles.todayHint}>Tap for today</Text>
          </Pressable>
          <Pressable accessibilityRole="button" accessibilityLabel="Next calendar period" onPress={() => move(1)} style={styles.periodButton}>
            <ChevronRight size={19} color={theme.ink700} />
          </Pressable>
        </View>

        {offline ? <Notice text={items.length > 0 ? 'You are offline. Showing the last available calendar.' : 'You are offline. Reconnect to load the calendar.'} tone="warning" /> : null}
        {query.isError ? (
          <View style={styles.errorBlock}>
            <Notice
              text={query.data
                ? 'Could not refresh. Showing the last available calendar.'
                : teamCalendarErrorMessage(query.error, 'Could not load Team Calendar.')}
              tone="danger"
            />
            {errorKind !== 'unauthorized' ? <Action variant="secondary" loading={refreshing} disabled={!query.eligible} onPress={() => { refresh().catch(() => undefined); }}>Retry</Action> : null}
          </View>
        ) : null}
        {meta?.truncated ? (
          <View style={styles.errorBlock}>
            <Notice text={`Results are truncated (${meta.total_events} events, ${meta.total_tasks} task deadlines). Narrow the range or scope to review more.`} tone="warning" />
            {view !== 'week' ? <Action variant="secondary" onPress={() => selectView('week')}>Show selected week</Action> : null}
            {scope !== 'mine' ? <Action variant="secondary" onPress={() => setScope('mine')}>Show only mine</Action> : null}
            {view === 'week' && scope === 'mine' ? <Text style={styles.capHint}>This is the narrowest available range. Use the period arrows to review another week.</Text> : null}
          </View>
        ) : null}
        {query.isLoading ? <ActivityIndicator accessibilityLabel="Loading calendar" style={styles.loader} color={theme.brandIcon} /> : null}

        {!query.isLoading && !query.isError && items.length === 0 && view === 'agenda' ? (
          <EmptyState
            icon={<CalendarDays size={30} color={theme.brandIcon} />}
            title="Nothing scheduled"
            body={scope === 'mine' ? 'You have no events or task deadlines in this period.' : 'This team has no events or task deadlines in this period.'}
            action={<Action full icon={<Plus size={17} color="#ffffff" />} onPress={() => navigation.navigate('TeamCalendarEventForm', { initialDate: selectedDate })}>Create event</Action>}
          />
        ) : null}

        {!query.isLoading && items.length > 0 && view === 'agenda' ? (
          <AgendaView items={items} rangeStart={meta?.start_date ?? date} onOpen={openItem} />
        ) : null}
        {!query.isLoading && view === 'week' ? (
          <WeekView items={items} anchor={date} selected={selectedDate} onSelect={setSelectedDate} onOpen={openItem} />
        ) : null}
        {!query.isLoading && view === 'month' ? (
          <MonthView items={items} anchor={date} selected={selectedDate} onSelect={setSelectedDate} onOpen={openItem} />
        ) : null}
      </ScrollView>
      <BottomSheet visible={filterSession !== null && focused} title="Calendar filters" description="Choose whose schedule to see and which events to include. Selections are kept when you close."
        closeLabel="Close calendar filters" onClose={closeFilters} footer={<View style={styles.filterFooter}>
          <Action variant="ghost" accessibilityLabel="Reset calendar filters" onPress={resetFilters}>Reset filters</Action>
          <Action style={styles.filterDone} accessibilityLabel="Done filtering calendar" onPress={closeFilters}>Done</Action>
        </View>}>
        <View style={styles.filterGroup}>
          <Text accessibilityRole="header" style={styles.filterHeading}>Whose schedule</Text>
          <SegmentedControl label="Calendar scope" values={SCOPES} selected={scope}
            labelFor={value => value === 'team' ? 'Team' : 'Mine'}
            onChange={value => { if (isCurrentFilterSession()) { setScope(value); } }} />
        </View>
        <View style={styles.filterGroup}>
          <Text accessibilityRole="header" style={styles.filterHeading}>Event status</Text>
          <SegmentedControl label="Event status" values={STATUSES} selected={status}
            labelFor={value => value === 'all' ? 'All events' : value[0].toUpperCase() + value.slice(1)}
            onChange={value => { if (isCurrentFilterSession()) { setStatus(value); } }} />
        </View>
        <Text style={styles.capHint}>Per period: up to 250 events and 250 task deadlines.</Text>
      </BottomSheet>
    </Screen>
  );
}

function SegmentedControl<T extends string>({ label, values, selected, labelFor, onChange }: {
  label: string;
  values: readonly T[];
  selected: T;
  labelFor: (value: T) => string;
  onChange: (value: T) => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={label} style={styles.segments}>
      {values.map(value => {
        const active = value === selected;
        return (
          <Pressable
            key={value}
            accessibilityRole="radio"
            accessibilityLabel={`${label}: ${labelFor(value)}`}
            accessibilityState={{ checked: active }}
            onPress={() => onChange(value)}
            style={[styles.segment, active && styles.segmentActive]}>
            <Text style={[styles.segmentText, active && styles.segmentTextActive]}>{labelFor(value)}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

function AgendaView({ items, rangeStart, onOpen }: { items: TeamCalendarItem[]; rangeStart: string; onOpen: (item: TeamCalendarItem) => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const sections = agendaSections(items, rangeStart);
  return (
    <View style={styles.listArea}>
      {sections.map(section => (
        <View key={section.date}>
          <Text accessibilityRole="header" style={styles.sectionTitle}>{formatManilaDayLabel(section.date)}</Text>
          {section.data.map(item => <CalendarItemRow key={`${item.type}:${item.id}`} item={item} onPress={() => onOpen(item)} />)}
        </View>
      ))}
    </View>
  );
}

function WeekView({ items, anchor, selected, onSelect, onOpen }: {
  items: TeamCalendarItem[];
  anchor: string;
  selected: string;
  onSelect: (date: string) => void;
  onOpen: (item: TeamCalendarItem) => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const start = startOfManilaWeek(anchor);
  const dates = Array.from({ length: 7 }, (_, index) => addManilaDays(start, index));
  const counts = countItemsByDate(items, dates);
  const dayItems = itemsForDate(items, selected);
  return (
    <View style={styles.listArea}>
      <View style={styles.weekRow}>
        {dates.map((day, index) => <DayButton key={day} date={day} weekday={WEEKDAY_LABELS[index]} count={counts.get(day) ?? 0} selected={day === selected} onPress={() => onSelect(day)} />)}
      </View>
      <SelectedDayList date={selected} items={dayItems} onOpen={onOpen} />
    </View>
  );
}

function MonthView({ items, anchor, selected, onSelect, onOpen }: {
  items: TeamCalendarItem[];
  anchor: string;
  selected: string;
  onSelect: (date: string) => void;
  onOpen: (item: TeamCalendarItem) => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const dates = monthGridDates(anchor);
  const counts = countItemsByDate(items, dates);
  const month = anchor.slice(0, 7);
  return (
    <View style={styles.listArea}>
      <View style={styles.weekdayRow}>{WEEKDAY_LABELS.map((label, index) => <Text key={`${label}:${index}`} style={styles.weekday}>{label}</Text>)}</View>
      <View style={styles.monthGrid}>
        {dates.map(day => {
          const selectedDay = day === selected;
          const outside = day.slice(0, 7) !== month;
          const count = counts.get(day) ?? 0;
          return (
            <Pressable
              key={day}
              accessibilityRole="button"
              accessibilityLabel={`${formatManilaDayLabel(day)}, ${count} item${count === 1 ? '' : 's'}`}
              accessibilityState={{ selected: selectedDay }}
              onPress={() => onSelect(day)}
              style={[styles.monthDay, selectedDay && styles.monthDaySelected]}>
              <Text style={[styles.monthDayText, outside && styles.outsideMonth, selectedDay && styles.monthDayTextSelected]}>{Number(day.slice(8, 10))}</Text>
              {count > 0 ? <View style={[styles.dot, selectedDay && styles.dotSelected]} /> : null}
            </Pressable>
          );
        })}
      </View>
      <SelectedDayList date={selected} items={itemsForDate(items, selected)} onOpen={onOpen} />
    </View>
  );
}

function DayButton({ date, weekday, count, selected, onPress }: { date: string; weekday: string; count: number; selected: boolean; onPress: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${formatManilaDayLabel(date)}, ${count} item${count === 1 ? '' : 's'}`}
      accessibilityState={{ selected }}
      onPress={onPress}
      style={[styles.dayButton, selected && styles.dayButtonSelected]}>
      <Text style={[styles.dayWeekday, selected && styles.dayTextSelected]}>{weekday}</Text>
      <Text style={[styles.dayNumber, selected && styles.dayTextSelected]}>{Number(date.slice(8, 10))}</Text>
      <Text style={[styles.dayCount, selected && styles.dayTextSelected]}>{count || '·'}</Text>
    </Pressable>
  );
}

function SelectedDayList({ date, items, onOpen }: { date: string; items: TeamCalendarItem[]; onOpen: (item: TeamCalendarItem) => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.selectedList}>
      <Text accessibilityRole="header" style={styles.sectionTitle}>{formatManilaDayLabel(date)}</Text>
      {items.length === 0
        ? <Text style={styles.noItems}>No events or task deadlines on this day.</Text>
        : items.map(item => <CalendarItemRow key={`${item.type}:${item.id}`} item={item} onPress={() => onOpen(item)} />)}
    </View>
  );
}

function Notice({ text, tone }: { text: string; tone: 'warning' | 'danger' }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View accessibilityRole="alert" style={[styles.notice, tone === 'danger' ? styles.noticeDanger : styles.noticeWarning]}>
      {tone === 'danger' ? <RefreshCw size={16} color={theme.mode === 'dark' ? '#ffb4ae' : '#a33b3b'} /> : null}
      <Text style={[styles.noticeText, tone === 'danger' && styles.noticeDangerText]}>{text}</Text>
    </View>
  );
}

function periodLabel(view: TeamCalendarView, date: string, start?: string, end?: string): string {
  if (view === 'month') { return formatManilaMonthLabel(date); }
  if (view === 'week') {
    const first = start ?? startOfManilaWeek(date);
    return `${formatCompactDate(first)} – ${formatCompactDate(addManilaDays((end ?? addManilaDays(first, 7)), -1))}`;
  }
  const first = start ?? date;
  return `${formatCompactDate(first)} – ${formatCompactDate(addManilaDays((end ?? addManilaDays(first, 30)), -1))}`;
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { paddingHorizontal: 0 },
    content: { paddingHorizontal: 18, paddingTop: 12, paddingBottom: 30 },
    capHint: { ...type.caption, color: theme.ink500, marginTop: 8 },
    segments: { minHeight: 44, flexDirection: 'row', padding: 3, borderRadius: 13, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    segment: { minHeight: 44, flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6, paddingVertical: 8, borderRadius: 10 },
    segmentActive: { backgroundColor: theme.brand },
    segmentText: { ...type.caption, textAlign: 'center', color: theme.ink500, textTransform: 'capitalize' },
    segmentTextActive: { color: '#ffffff' },
    toolbar: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 8 },
    viewControl: { flex: 1 },
    filterButton: { minWidth: 50, minHeight: 52, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 4, paddingHorizontal: 12, paddingVertical: 10, borderRadius: 13, backgroundColor: theme.surface },
    filterButtonActive: { backgroundColor: theme.brand50 },
    filterBadge: { minWidth: 18, minHeight: 18, paddingHorizontal: 4, alignItems: 'center', justifyContent: 'center', borderRadius: 9, backgroundColor: theme.brand },
    filterBadgeText: { fontFamily: font.body, fontSize: 10, fontWeight: '800', color: '#ffffff' },
    filterSummary: { ...type.caption, color: theme.ink500, marginTop: 8, marginBottom: 2 },
    filterGroup: { gap: 10, marginBottom: 22 }, filterHeading: { ...type.action, color: theme.ink900 },
    filterFooter: { flexDirection: 'row', alignItems: 'center', gap: 12 }, filterDone: { flex: 1 }, pressed: { opacity: 0.72 },
    periodBar: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 6 },
    periodButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    periodLabelButton: { minHeight: 44, flex: 1, alignItems: 'center', justifyContent: 'center' },
    periodLabel: { ...type.cardTitle, color: theme.ink900 },
    todayHint: { ...type.micro, marginTop: 2, color: theme.ink500 },
    notice: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 8, padding: 10, marginTop: 8, borderRadius: 11, borderWidth: 1 },
    noticeWarning: { borderColor: theme.mode === 'dark' ? '#685536' : '#ead39f', backgroundColor: theme.mode === 'dark' ? '#30291c' : '#f9efd9' },
    noticeDanger: { borderColor: theme.mode === 'dark' ? '#774542' : '#ebc5c2', backgroundColor: theme.mode === 'dark' ? '#3a2323' : '#fae8e6' },
    noticeText: { ...type.bodyStrong, flex: 1, color: theme.mode === 'dark' ? '#f5d697' : '#79591f' },
    noticeDangerText: { color: theme.mode === 'dark' ? '#ffb4ae' : '#8c3030' },
    errorBlock: { gap: 8 },
    loader: { marginTop: 30 },
    listArea: { marginTop: 8 },
    sectionTitle: { ...type.cardTitle, paddingVertical: 9, color: theme.ink900 },
    weekRow: { flexDirection: 'row', gap: 4 },
    dayButton: { minHeight: 64, flex: 1, alignItems: 'center', justifyContent: 'center', borderRadius: 11, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    dayButtonSelected: { borderColor: theme.brand, backgroundColor: theme.brand },
    dayWeekday: { fontFamily: font.body, fontSize: 9, fontWeight: '800', color: theme.ink500 },
    dayNumber: { fontFamily: font.display, fontSize: 14, fontWeight: '700', color: theme.ink900 },
    dayCount: { fontFamily: font.body, fontSize: 9, color: theme.ink500 },
    dayTextSelected: { color: '#ffffff' },
    weekdayRow: { flexDirection: 'row', marginBottom: 3 },
    weekday: { width: `${100 / 7}%`, textAlign: 'center', fontFamily: font.body, fontSize: 9, fontWeight: '800', color: theme.ink500 },
    monthGrid: { flexDirection: 'row', flexWrap: 'wrap', padding: 4, borderRadius: 14, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    monthDay: { minHeight: 44, width: `${100 / 7}%`, alignItems: 'center', justifyContent: 'center', borderRadius: 10 },
    monthDaySelected: { backgroundColor: theme.brand },
    monthDayText: { ...type.bodyStrong, color: theme.ink900 },
    monthDayTextSelected: { color: '#ffffff' },
    outsideMonth: { color: theme.ink400 },
    dot: { width: 4, height: 4, marginTop: 2, borderRadius: 2, backgroundColor: theme.brand },
    dotSelected: { backgroundColor: '#ffffff' },
    selectedList: { marginTop: 8 },
    noItems: { ...type.body, minHeight: 44, paddingVertical: 12, color: theme.ink500 },
  });
}

const makeStyles = memoizeStyles(createStyles);
