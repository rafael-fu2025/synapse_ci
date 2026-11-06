import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useNetInfo } from '@react-native-community/netinfo';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useQueryClient } from '@tanstack/react-query';
import {
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Plus,
  RefreshCw,
  Users,
} from 'lucide-react-native';
import type {
  TeamCalendarItem,
  TeamCalendarScope,
  TeamCalendarStatusFilter,
  TeamCalendarView,
} from '../../../api/endpoints/teamCalendar';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { useAuth } from '../../../store/auth';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, font, memoizeStyles, type } from '../../../theme/tokens';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { CalendarItemRow } from '../components/CalendarItemRow';
import { teamCalendarErrorKind, teamCalendarErrorMessage } from '../errors';
import { teamCalendarQueryKeys, useTeamCalendar } from '../hooks';
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
  const openTask = useOpenTaskDeadline();
  const { activeUnit, enterPersonal, openUnitPicker } = useWorkspace();
  const { retryBootstrap, user } = useAuth();
  const queryClient = useQueryClient();
  const network = useNetInfo();
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const today = useMemo(() => todayInManila(), []);
  const [view, setView] = useState<TeamCalendarView>('agenda');
  const [scope, setScope] = useState<TeamCalendarScope>('team');
  const [status, setStatus] = useState<TeamCalendarStatusFilter>('scheduled');
  const [date, setDate] = useState(today);
  const [selectedDate, setSelectedDate] = useState(today);
  const unit = activeUnit?.features.team_calendar === true ? activeUnit.unit : null;
  const filters = useMemo(() => ({ date, view, scope, status }), [date, scope, status, view]);
  const query = useTeamCalendar(unit, filters);
  const accessRecoveryStarted = useRef(false);
  const errorKind = query.isError ? teamCalendarErrorKind(query.error) : null;
  const forbidden = errorKind === 'forbidden';
  const items = useMemo(() => forbidden ? [] : query.data?.data ?? [], [forbidden, query.data]);
  const meta = query.data?.meta;
  const offline = network.isConnected === false
    || network.isInternetReachable === false
    || query.fetchStatus === 'paused';

  const recoverAccess = useCallback(async () => {
    if (accessRecoveryStarted.current || !activeUnit || !user) { return; }
    accessRecoveryStarted.current = true;
    const key = teamCalendarQueryKeys.unit(user.tenant_id, user.id, activeUnit.unit);
    await queryClient.cancelQueries({ queryKey: key, exact: false });
    queryClient.removeQueries({ queryKey: key, exact: false });
    enterPersonal();
    await retryBootstrap().catch(() => undefined);
  }, [activeUnit, enterPersonal, queryClient, retryBootstrap, user]);

  useEffect(() => {
    accessRecoveryStarted.current = false;
  }, [unit]);

  useEffect(() => {
    if (forbidden) { recoverAccess().catch(() => undefined); }
  }, [forbidden, recoverAccess]);

  useEffect(() => {
    setSelectedDate(date);
  }, [date, view]);

  const header = (
    <PageHeader
      edgeToEdge
      title="Team Calendar"
      subtitle={activeUnit?.label ?? 'Choose a Team Workspace'}
      action={(
        <View style={styles.headerActions}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Switch Team Workspace unit"
            onPress={openUnitPicker}
            style={styles.headerButton}>
            <Users size={19} color={theme.brandIcon} />
          </Pressable>
          {unit && activeUnit?.can_manage ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Create event"
              onPress={() => navigation.navigate('TeamCalendarEventForm', { initialDate: selectedDate })}
              style={styles.createButton}>
              <Plus size={20} color="#ffffff" />
            </Pressable>
          ) : null}
        </View>
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
        refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => { query.refetch().catch(() => undefined); }} />}>
        <SegmentedControl
          label="Calendar view"
          values={VIEWS}
          selected={view}
          labelFor={value => value[0].toUpperCase() + value.slice(1)}
          onChange={selectView}
        />
        <View style={styles.secondaryFilters}>
          <SegmentedControl
            compact
            label="Calendar scope"
            values={SCOPES}
            selected={scope}
            labelFor={value => value === 'team' ? 'Team' : 'Mine'}
            onChange={setScope}
          />
          <SegmentedControl
            compact
            label="Event status"
            values={STATUSES}
            selected={status}
            labelFor={value => value === 'all' ? 'All events' : value[0].toUpperCase() + value.slice(1)}
            onChange={setStatus}
          />
        </View>

        <View style={styles.periodBar}>
          <Pressable accessibilityRole="button" accessibilityLabel="Previous calendar period" onPress={() => move(-1)} style={styles.periodButton}>
            <ChevronLeft size={19} color={theme.ink700} />
          </Pressable>
          <Pressable accessibilityRole="button" accessibilityLabel="Go to today" onPress={() => { setDate(today); setSelectedDate(today); }} style={styles.periodLabelButton}>
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
              text={teamCalendarErrorMessage(query.error, items.length > 0
                ? 'Could not refresh. Showing the last available calendar.'
                : 'Could not load Team Calendar.')}
              tone="danger"
            />
            {!forbidden && errorKind !== 'unauthorized' ? <Action variant="secondary" onPress={() => { query.refetch().catch(() => undefined); }}>Retry</Action> : null}
          </View>
        ) : null}
        {meta?.truncated ? (
          <Notice text={`Results are truncated. Showing up to 250 events and 250 task deadlines for this period (${meta.total_events} events, ${meta.total_tasks} tasks). Narrow the period or scope to review more.`} tone="warning" />
        ) : null}
        {query.isLoading ? <ActivityIndicator accessibilityLabel="Loading calendar" style={styles.loader} color={theme.brandIcon} /> : null}

        {!query.isLoading && !query.isError && items.length === 0 && view === 'agenda' ? (
          <EmptyState
            icon={<CalendarDays size={30} color={theme.brandIcon} />}
            title="Nothing scheduled"
            body={scope === 'mine' ? 'You have no events or task deadlines in this period.' : 'This team has no events or task deadlines in this period.'}
            action={activeUnit?.can_manage ? <Action full onPress={() => navigation.navigate('TeamCalendarEventForm', { initialDate: selectedDate })}>Create event</Action> : undefined}
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
    </Screen>
  );
}

function SegmentedControl<T extends string>({ label, values, selected, labelFor, onChange, compact = false }: {
  label: string;
  values: readonly T[];
  selected: T;
  labelFor: (value: T) => string;
  onChange: (value: T) => void;
  compact?: boolean;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={label} style={[styles.segments, compact && styles.segmentsCompact]}>
      {values.map(value => {
        const active = value === selected;
        return (
          <Pressable
            key={value}
            accessibilityRole="radio"
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
      {tone === 'danger' ? <RefreshCw size={16} color="#a33b3b" /> : null}
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
    headerActions: { flexDirection: 'row', gap: 8 },
    headerButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    createButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, backgroundColor: theme.brand },
    segments: { minHeight: 44, flexDirection: 'row', padding: 3, borderRadius: 13, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    segmentsCompact: { minWidth: 0 },
    segment: { minHeight: 44, flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6, paddingVertical: 8, borderRadius: 10 },
    segmentActive: { backgroundColor: theme.brand },
    segmentText: { ...type.caption, textAlign: 'center', color: theme.ink500, textTransform: 'capitalize' },
    segmentTextActive: { color: '#ffffff' },
    secondaryFilters: { gap: 8, marginTop: 8 },
    periodBar: { minHeight: 52, flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 11 },
    periodButton: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 12, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    periodLabelButton: { minHeight: 44, flex: 1, alignItems: 'center', justifyContent: 'center' },
    periodLabel: { ...type.cardTitle, color: theme.ink900 },
    todayHint: { ...type.micro, marginTop: 2, color: theme.ink500 },
    notice: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 8, padding: 10, marginTop: 8, borderRadius: 11, borderWidth: 1 },
    noticeWarning: { borderColor: '#ead39f', backgroundColor: '#f9efd9' },
    noticeDanger: { borderColor: '#ebc5c2', backgroundColor: '#fae8e6' },
    noticeText: { ...type.bodyStrong, flex: 1, color: '#79591f' },
    noticeDangerText: { color: '#8c3030' },
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
