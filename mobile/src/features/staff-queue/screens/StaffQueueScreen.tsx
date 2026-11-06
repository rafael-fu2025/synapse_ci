import React, { useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { useNetInfo } from '@react-native-community/netinfo';
import LinearGradient from 'react-native-linear-gradient';
import { SvgXml } from 'react-native-svg';
import { CLINIC_ART, CLINIC_ART_VIEWBOX, GUIDANCE_ART, GUIDANCE_ART_VIEWBOX } from '../../../assets/brand/appointmentArt';
import {
  Activity,
  Clock3,
  Hourglass,
  ListChecks,
  ChevronDown,
  ChevronUp,
  RefreshCw,
  Search,
  Timer,
  TriangleAlert,
  Users,
} from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { SectionHeading } from '../../../components/SectionHeading';
import { IconAction } from '../../../components/IconAction';
import { StatusPill } from '../../../components/StatusPill';
import { CardOutline } from '../../../components/CardOutline';
import { ApiError } from '../../../api/client';
import type { StaffQueueSummary, StaffQueueTicket, QueueStage, QueueUnit } from '../../../api/endpoints/staffQueue';
import { useStaffQueueSummary } from '../hooks';
import { Theme, font, memoizeStyles, type, StatusTone } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { unitLabel } from '../../appointments/status';
import { formatTime } from '../../../lib/format';
import {
  ageSeconds,
  duration,
  initialFilters,
  laneTickets,
  manilaBusinessDate,
  overview,
  priorities,
  QueueFilters,
  stageLabel,
  stages,
  timestamp,
} from '../helpers';

const stageTone: Record<QueueStage, StatusTone> = {
  waiting: 'neutral',
  called: 'info',
  in_consultation: 'success',
  skipped: 'warning',
};

const priorityTone: Record<string, StatusTone> = {
  routine: 'neutral',
  urgent: 'warning',
  emergency: 'danger',
};

export function StaffQueueScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation();
  const network = useNetInfo();
  const query = useStaffQueueSummary();
  const [filters, setFilters] = useState<QueueFilters>(initialFilters);
  const [filtersExpanded, setFiltersExpanded] = useState(false);
  const now = Date.now();
  const forbidden = query.error instanceof ApiError && [401, 403].includes(query.error.status);
  const response = forbidden ? undefined : query.data;
  const summaries = response?.data ?? [];
  const totals = overview(summaries);
  const snapshot = response?.meta.generated_at ?? '';
  const generated = timestamp(snapshot);
  const offline = network.isConnected === false || network.isInternetReachable === false || query.fetchStatus === 'paused';
  const stale = generated === null || generated > now || now - generated > 45000;
  const today = response?.meta.date === manilaBusinessDate(now);
  const refresh = () => { query.refetch().catch(() => undefined); };
  const visible = summaries.reduce((sum, summary) => sum + stages.reduce((n, stage) => n + laneTickets(summary, stage, filters).length, 0), 0);
  const filtered = filters.unit !== 'all' || filters.stage !== 'all' || filters.priority !== 'all' || filters.code.trim() !== '';
  const asOf = generated !== null && generated <= now ? formatTime(snapshot) : 'Unavailable';
  const alert = forbidden
    ? 'Access Denied'
    : offline
      ? 'Offline / Saved Snapshot'
      : query.isError
        ? response ? 'Refresh Failed / Saved Snapshot' : 'Load Failed / Retry Refresh'
        : stale ? 'Outdated Snapshot' : null;

  return (
    <Screen scroll={false} bodyStyle={styles.screenBody} header={
      <PageHeader
        edgeToEdge
        title="Queue Monitor"
        subtitle="Read-Only"
        onBack={navigation.goBack}
        action={
          <IconAction
            label="Refresh queue"
            onPress={refresh}
            icon={<RefreshCw size={17} color={theme.ink700} />}
          />
        }
      />
      }>
      <ScrollView
        style={styles.scroll}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={refresh} />}
        contentContainerStyle={styles.content}>
        {alert ? (
          <View style={styles.alert}>
            <TriangleAlert size={14} color={theme.ink500} />
            <Text style={styles.alertText} accessibilityRole="alert" accessibilityLiveRegion="polite">{alert}</Text>
          </View>
        ) : null}

        {query.isLoading && !response ? (
          <View style={styles.loading} accessibilityLabel="Loading authorized queues">
            <ActivityIndicator color={theme.brandIcon} />
            <Text style={styles.muted}>Loading queues…</Text>
          </View>
        ) : null}

        {response ? (
          <>
            <View style={styles.heroShell} accessible accessibilityLabel={`Combined queue. ${totals.counts.waiting} waiting. Oldest ticket age ${duration(totals.oldest)}. Average ticket age ${duration(totals.average)}. Active ${totals.active}.`}>
              <LinearGradient colors={theme.mode === 'dark' ? ['#1c1c1c', '#0f0f0f'] : ['#520000', '#800000']} style={styles.hero}>
                <View style={styles.heroTop}>
                  <View>
                    <Text style={styles.heroEyebrow}>LIVE OPERATIONS</Text>
                    <Text style={styles.heroTitle}>{today ? 'Today’s Queue' : 'Queue Snapshot'}</Text>
                  </View>
                  <StatusPill tone="neutral" onBrand>{stale ? 'Outdated' : 'Current'}</StatusPill>
                </View>

                <View style={styles.heroMetrics}>
                  <HeroMetric icon={<Users size={13} color="#ffffff" />} value={String(totals.counts.waiting)} label="Waiting" />
                  <HeroMetric icon={<Hourglass size={13} color="#ffffff" />} value={duration(totals.oldest)} label="Oldest Ticket" />
                  <HeroMetric icon={<Timer size={13} color="#ffffff" />} value={duration(totals.average)} label="Average Age" />
                </View>

                <View style={styles.heroFooterRow}>
                  <Text style={styles.heroFootnote}>Updated {asOf}</Text>
                  <Text style={styles.heroFootnote}>Manila Time</Text>
                </View>
                <View style={styles.heroSummary}>
                  <SummaryStat value={totals.counts.total} label="Issued" />
                  <SummaryStat value={totals.counts.done} label="Completed" />
                  <SummaryStat value={totals.counts.skipped} label="Held" />
                  <SummaryStat value={totals.counts.no_show} label="No-Show" />
                </View>
                <View style={styles.prioritySummary} accessibilityLabel={`Priority mix: ${totals.mix.routine} routine, ${totals.mix.urgent} urgent, ${totals.mix.emergency} emergency. ${totals.samples} timed waiting tickets.`}>
                  <Text style={styles.heroFootnote}>Routine {totals.mix.routine}</Text>
                  <Text style={styles.heroFootnote}>Urgent {totals.mix.urgent}</Text>
                  <Text style={styles.heroFootnote}>Emergency {totals.mix.emergency}</Text>
                </View>
              </LinearGradient>
              {theme.mode === 'dark' ? <CardOutline testID="staffQueueOutline" radius={15} /> : null}
            </View>

            <View style={styles.flow} accessible accessibilityLabel={`Queue flow: ${(['waiting', 'called', 'in_consultation', 'skipped'] as QueueStage[]).map(stage => `${stageLabel[stage]} ${totals.counts[stage]}`).join(', ')}`}>
              {(['waiting', 'called', 'in_consultation', 'skipped'] as QueueStage[]).map(stage => (
                <View key={stage} style={styles.flowStep}>
                  <Text style={styles.flowNumber}>{totals.counts[stage]}</Text>
                  <Text style={styles.flowLabel}>{stageLabel[stage]}</Text>
                </View>
              ))}
            </View>

            <SectionHeading title="Unit Balance" />
            <View style={styles.balanceGrid}>
              {summaries.map(summary => <UnitBalance key={summary.unit} summary={summary} />)}
            </View>

            <SectionHeading
              title="Tickets"
              trailing={<Text style={styles.resultCount}>{visible} Results</Text>}
            />

            <View style={styles.filterCard}>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="Toggle queue filters"
                accessibilityState={{ expanded: filtersExpanded }}
                onPress={() => setFiltersExpanded(value => !value)}
                style={styles.filterToggle}>
                <Text style={styles.filterTitle}>Filters</Text>
                <Text style={styles.filterSummary}>{filtered ? 'Filters Applied' : 'All Tickets'}</Text>
                {filtersExpanded ? <ChevronUp size={16} color={theme.ink500} /> : <ChevronDown size={16} color={theme.ink500} />}
              </Pressable>
              {filtersExpanded ? <View style={styles.filterContent}>
              <View style={styles.searchRow}>
                <Search size={15} color={theme.ink500} />
                <TextInput
                  accessibilityLabel="Filter ticket code"
                  placeholder="Ticket Code"
                  placeholderTextColor={theme.ink500}
                  value={filters.code}
                  onChangeText={code => setFilters({ ...filters, code })}
                  autoCapitalize="characters"
                  autoCorrect={false}
                  style={styles.search}
                />
                {filtered ? (
                  <Pressable accessibilityRole="button" accessibilityLabel="Clear queue filters" onPress={() => setFilters(initialFilters)} style={styles.clear}>
                    <Text style={styles.clearText}>Clear</Text>
                  </Pressable>
                ) : null}
              </View>
              <FilterGroup label="Unit" values={['all', ...summaries.map(s => s.unit)]} selected={filters.unit} onSelect={value => setFilters({ ...filters, unit: value as QueueFilters['unit'] })} />
              <FilterGroup label="Stage" values={['all', ...stages]} selected={filters.stage} onSelect={value => setFilters({ ...filters, stage: value as QueueFilters['stage'] })} />
              <FilterGroup label="Priority" values={['all', ...priorities]} selected={filters.priority} onSelect={value => setFilters({ ...filters, priority: value as QueueFilters['priority'] })} />
              </View> : null}
            </View>

            {summaries.length === 0 ? (
              <View style={styles.empty}><ListChecks size={24} color={theme.brandIcon} /><Text style={styles.emptyText}>No Authorized Queues</Text></View>
            ) : visible === 0 ? (
              <View style={styles.empty}><Search size={24} color={theme.brandIcon} /><Text style={styles.emptyText}>{filtered ? 'No Matching Tickets' : 'No Active Tickets'}</Text></View>
            ) : stages.filter(stage => filters.stage === 'all' || stage === filters.stage).map(stage => {
              const rows = summaries.flatMap(summary => laneTickets(summary, stage, filters).map(row => ({ ...row, unit: summary.unit })));
              return rows.length > 0 ? (
                <View key={stage} testID={`ticket-lane-${stage}`} style={styles.lane}>
                  <View style={styles.laneHeading}>
                    <Text style={styles.laneTitle}>{stageLabel[stage]}</Text>
                    <Text style={styles.laneCount}>{rows.length}</Text>
                  </View>
                  {rows.map(row => <OperationalTicket key={`${row.unit}-${row.ticket.id}`} {...row} stage={stage} snapshot={snapshot} />)}
                </View>
              ) : null;
            })}
          </>
        ) : null}
      </ScrollView>
    </Screen>
  );
}

function HeroMetric({ icon, value, label }: { icon: React.ReactNode; value: string; label: string }) {
  const styles = makeStyles(useTheme().theme);
  return (
    <View style={styles.heroMetric}>
      <View style={styles.heroMetricIcon}>{icon}</View>
      <Text style={styles.heroValue}>{value}</Text>
      <Text style={styles.heroMetricLabel}>{label}</Text>
    </View>
  );
}

function SummaryStat({ value, label }: { value: number; label: string }) {
  const styles = makeStyles(useTheme().theme);
  return <View style={styles.summaryStat}><Text style={styles.summaryValue}>{value}</Text><Text style={styles.heroFootnote}>{label}</Text></View>;
}

function UnitBalance({ summary }: { summary: StaffQueueSummary }) {
  const styles = makeStyles(useTheme().theme);
  const { theme } = useTheme();
  const c = summary.counts;
  const guidance = summary.unit === 'guidance';
  const box = guidance ? GUIDANCE_ART_VIEWBOX : CLINIC_ART_VIEWBOX;
  return (
    <View style={styles.balance} accessible accessibilityLabel={`${unitLabel(summary.unit)}. ${summary.metrics.active} Active. Waiting ${c.waiting}, called ${c.called}, in consultation ${c.in_consultation}. Oldest waiting-ticket age ${duration(summary.metrics.waiting_age.oldest_seconds)}. ${c.total} issued, ${c.done} completed, ${c.skipped} held, ${c.no_show} no-show.`}>
      <View style={styles.balanceTop}>
        <View style={styles.balanceIcon}>
          <SvgXml
            testID={`unit-vector-${summary.unit}`}
            xml={guidance ? GUIDANCE_ART : CLINIC_ART}
            width={22 * (box.width / box.height)}
            height={22}
            color={theme.brandIcon}
          />
        </View>
        <Text style={styles.balanceTitle}>{unitLabel(summary.unit)}</Text>
        <Text style={styles.balanceActive}>{summary.metrics.active} Active</Text>
      </View>
      <View style={styles.balanceStats}>
        <MiniStat value={c.waiting} label="Waiting" />
        <MiniStat value={c.called} label="Called" />
        <MiniStat value={c.in_consultation} label="In Room" />
      </View>
      <View style={styles.balanceFooter}>
        <Clock3 size={11} color={theme.ink500} />
        <Text style={styles.balanceFooterText}>Oldest Ticket</Text>
        <Text style={styles.muted}>{duration(summary.metrics.waiting_age.oldest_seconds)}</Text>
      </View>
    </View>
  );
}

function MiniStat({ value, label }: { value: number; label: string }) {
  const styles = makeStyles(useTheme().theme);
  return <View style={styles.miniStat}><Text style={styles.miniValue}>{value}</Text><Text style={styles.miniLabel}>{label}</Text></View>;
}

function FilterGroup({ label, values, selected, onSelect }: { label: string; values: string[]; selected: string; onSelect: (value: string) => void }) {
  const styles = makeStyles(useTheme().theme);
  return (
    <View style={styles.filterGroup}>
      <Text style={styles.filterLabel}>{label}</Text>
      <View style={styles.filters}>
        {values.map(value => (
          <Pressable
            key={value}
            accessibilityRole="button"
            accessibilityLabel={`${label}: ${value}`}
            accessibilityState={{ selected: selected === value }}
            onPress={() => onSelect(value)}
            style={[styles.chip, selected === value && styles.selected]}>
            <Text style={[styles.chipText, selected === value && styles.selectedText]}>
              {value === 'all' ? 'All' : stageLabel[value as QueueStage] ?? value.charAt(0).toUpperCase() + value.slice(1)}
            </Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

function OperationalTicket({ ticket, unit, rank, stage, snapshot }: { ticket: StaffQueueTicket; unit: QueueUnit; rank: number | null; stage: QueueStage; snapshot: string }) {
  const styles = makeStyles(useTheme().theme);
  const { theme } = useTheme();
  const eventLabel = stage === 'called' ? 'Called' : stage === 'in_consultation' ? 'Started' : stage === 'skipped' ? 'Hold Until' : 'Issued';
  const eventTime = stage === 'called' ? ticket.called_at : stage === 'in_consultation' ? ticket.started_at : stage === 'skipped' ? ticket.skip_until : ticket.created_at;
  const age = duration(ageSeconds(ticket.created_at, snapshot));
  const instant = timestamp(eventTime);
  const at = timestamp(snapshot);
  const event = instant !== null && at !== null && (stage === 'skipped' || instant <= at) ? formatTime(eventTime) : 'Unavailable';
  const elapsed = stage === 'called' ? `Called ${duration(ageSeconds(ticket.called_at, snapshot))}`
    : stage === 'in_consultation' ? `In Session ${duration(ageSeconds(ticket.started_at, snapshot))}`
      : stage === 'skipped' ? instant === null || at === null ? 'Deadline Unavailable' : instant <= at ? 'Deadline Passed' : `${duration((instant - at) / 1000)} Remaining` : null;
  const label = `${unitLabel(unit)} ticket ${ticket.ticket_code}. ${stageLabel[stage]}. ${ticket.priority} priority.${rank ? ` Unit waiting rank ${rank}.` : ''} Ticket age ${age} at snapshot. ${eventLabel}: ${event}. ${elapsed ?? ''}.`;

  return (
    <View accessible accessibilityLabel={label} style={styles.ticket}>
      <View style={styles.ticketMain}>
        <View style={styles.ticketCodeWrap}>
          <Text style={styles.code}>{ticket.ticket_code}</Text>
          <Text style={styles.ticketUnit}>{unitLabel(unit)}{rank ? ` #${rank}` : ''}</Text>
        </View>
        <StatusPill tone={priorityTone[ticket.priority] ?? 'neutral'}>{ticket.priority.charAt(0).toUpperCase() + ticket.priority.slice(1)}</StatusPill>
      </View>
      <View style={styles.ticketMeta}>
        <View style={styles.metaItem}><Clock3 size={11} color={theme.ink500} /><Text style={styles.metaText}>Age {age}</Text></View>
        <View style={styles.metaItem}><Activity size={11} color={theme.ink500} /><Text style={styles.metaText}>{eventLabel} {event}</Text></View>
      </View>
      {elapsed ? (
        <View style={styles.ticketStatusRow}>
          <Text style={styles.elapsed}>{elapsed}</Text>
          <StatusPill tone={stageTone[stage]}>{stageLabel[stage]}</StatusPill>
        </View>
      ) : (
        <View style={styles.ticketStatusRow}><StatusPill tone={stageTone[stage]}>{stageLabel[stage]}</StatusPill></View>
      )}
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    screenBody: { paddingHorizontal: 0, paddingBottom: 0 },
    scroll: { flex: 1 },
    content: { paddingHorizontal: 18, paddingTop: 0, paddingBottom: 16 },
    alert: {
      flexDirection: 'row', alignItems: 'center', gap: 7, marginBottom: 12,
      paddingHorizontal: 12, paddingVertical: 9, borderRadius: 11,
      backgroundColor: theme.surfaceWarm, borderWidth: 1, borderColor: theme.line,
    },
    alertText: { flex: 1, fontSize: 10.5, fontFamily: font.body, color: theme.ink700 },
    loading: { paddingVertical: 32, gap: 9, alignItems: 'center' },
    muted: { fontSize: 9.5, fontFamily: font.body, color: theme.ink500 },

    heroShell: { borderRadius: 15, overflow: 'hidden', marginTop: 10, marginBottom: 7 },
    hero: { padding: 15, gap: 13, overflow: 'hidden' },
    heroTop: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10 },
    heroEyebrow: { color: '#ffffff', fontFamily: font.body, fontSize: 8.5, fontWeight: '800', letterSpacing: 1.1 },
    heroTitle: { marginTop: 2, ...type.cardTitle, color: '#ffffff', fontSize: 18 },
    heroMetrics: { flexDirection: 'row', gap: 8 },
    heroMetric: {
      flex: 1, minWidth: 0, paddingHorizontal: 9, paddingVertical: 9, borderRadius: 11,
      backgroundColor: 'rgba(255,255,255,0.11)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.13)',
    },
    heroMetricIcon: { marginBottom: 5 },
    heroValue: { color: '#ffffff', fontFamily: font.display, fontSize: 19, fontWeight: '800' },
    heroMetricLabel: { marginTop: 1, color: 'rgba(255,255,255,0.78)', fontSize: 8.5, fontWeight: '700', fontFamily: font.body },
    heroSummary: { flexDirection: 'row', gap: 8 },
    summaryStat: { flex: 1, gap: 2 },
    summaryValue: { color: '#ffffff', fontSize: 13, fontWeight: '800', fontFamily: font.body },
    prioritySummary: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', gap: 8 },
    heroFooterRow: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
    heroFootnote: { color: 'rgba(255,255,255,0.78)', fontSize: 9, fontFamily: font.body },

    flow: {
      flexDirection: 'row', alignItems: 'center', paddingVertical: 10, paddingHorizontal: 12,
      borderRadius: 13, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface,
    },
    flowStep: { flex: 1, alignItems: 'center', gap: 1 },
    flowNumber: { color: theme.ink900, fontFamily: font.display, fontSize: 16, fontWeight: '800' },
    flowLabel: { color: theme.ink500, fontSize: 8.5, fontFamily: font.body, textAlign: 'center' },

    balanceGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 9 },
    balance: {
      flexGrow: 1, flexBasis: '47%', minWidth: 150, padding: 12, borderRadius: 13,
      borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface, gap: 9,
    },
    balanceTop: { flexDirection: 'row', alignItems: 'center', gap: 7 },
    balanceIcon: { width: 22, height: 22, alignItems: 'center', justifyContent: 'center' },
    balanceTitle: { flex: 1, ...type.bodyStrong, fontSize: 11, color: theme.ink900 },
    balanceActive: { fontSize: 8.5, fontWeight: '700', fontFamily: font.body, color: theme.brandText },
    balanceStats: { flexDirection: 'row', gap: 6 },
    miniStat: { flex: 1, alignItems: 'center', paddingVertical: 6, borderRadius: 8, backgroundColor: theme.surfaceWarm },
    miniValue: { color: theme.ink900, fontFamily: font.display, fontSize: 15, fontWeight: '800' },
    miniLabel: { marginTop: 1, color: theme.ink500, fontSize: 8, fontFamily: font.body },
    balanceFooter: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 5 },
    balanceFooterText: { flex: 1, fontSize: 9.5, fontFamily: font.body, color: theme.ink500 },

    resultCount: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    filterCard: {
      paddingHorizontal: 11, borderRadius: 13, borderWidth: 1, borderColor: theme.line,
      backgroundColor: theme.surface, marginBottom: 10,
    },
    filterToggle: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 8 },
    filterTitle: { ...type.bodyStrong, color: theme.ink900 },
    filterSummary: { flex: 1, textAlign: 'right', fontSize: 9.5, fontFamily: font.body, color: theme.ink500 },
    filterContent: { gap: 9, paddingBottom: 11 },
    searchRow: {
      flexDirection: 'row', alignItems: 'center', gap: 7, minHeight: 36,
      paddingHorizontal: 10, borderRadius: 10, backgroundColor: theme.surfaceWarm,
    },
    search: { flex: 1, minHeight: 36, fontSize: 11.5, color: theme.ink900, fontFamily: font.body },
    clear: { minHeight: 32, justifyContent: 'center', paddingHorizontal: 7 },
    clearText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.brandText },
    filterGroup: { gap: 5 },
    filterLabel: { color: theme.ink500, fontSize: 8.5, fontWeight: '800', letterSpacing: 0.8, fontFamily: font.body },
    filters: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
    chip: {
      minHeight: 44, minWidth: 44, justifyContent: 'center', alignItems: 'center',
      paddingHorizontal: 10, borderRadius: 10, borderWidth: 1,
      borderColor: theme.line, backgroundColor: theme.surface,
    },
    selected: { backgroundColor: theme.brand, borderColor: theme.brand },
    chipText: { color: theme.ink700, fontSize: 9.5, fontWeight: '700', fontFamily: font.body },
    selectedText: { color: '#ffffff' },

    lane: { marginBottom: 10, gap: 6 },
    laneHeading: { flexDirection: 'row', alignItems: 'center', gap: 7, marginBottom: 1 },
    laneTitle: { ...type.bodyStrong, fontSize: 11, color: theme.ink900 },
    laneCount: { fontSize: 9, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },

    ticket: {
      padding: 11, borderRadius: 12, borderWidth: 1, borderColor: theme.line,
      backgroundColor: theme.surface, gap: 7,
    },
    ticketMain: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
    ticketCodeWrap: { flex: 1, minWidth: 0 },
    code: { color: theme.ink900, fontFamily: font.display, fontSize: 15, fontWeight: '800' },
    ticketUnit: { marginTop: 1, color: theme.ink500, fontSize: 9, fontFamily: font.body, textTransform: 'capitalize' },
    ticketMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 11 },
    metaItem: { flexDirection: 'row', alignItems: 'center', gap: 4 },
    metaText: { color: theme.ink700, fontSize: 9.5, fontFamily: font.body },
    ticketStatusRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 7 },
    elapsed: { flex: 1, color: theme.ink500, fontSize: 9, fontFamily: font.body },

    empty: {
      alignItems: 'center', gap: 8, paddingVertical: 24, borderRadius: 13,
      borderWidth: 1, borderStyle: 'dashed', borderColor: theme.line, backgroundColor: theme.surface,
    },
    emptyText: { color: theme.ink500, fontSize: 10.5, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
