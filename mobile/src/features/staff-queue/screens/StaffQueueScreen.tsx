import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Eye } from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { StatusPill } from '../../../components/StatusPill';
import { StaffQueueSummary, StaffQueueTicket } from '../../../api/endpoints/staffQueue';
import { useStaffQueueSummary } from '../hooks';
import { Theme, font, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatTime } from '../../../lib/format';
import { unitLabel } from '../../appointments/status';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'StaffQueue'>;

export function StaffQueueScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const [unit, setUnit] = useState<'all' | 'clinic' | 'guidance'>('all');
  const query = useStaffQueueSummary(unit);
  const summaries = query.data?.data ?? [];

  return (
    <Screen scroll={false}>
      <PageHeader title="Staff queue monitor" subtitle="Read-only · actions on the workstation" onBack={navigation.goBack} />

      <View style={styles.chips}>
        {(['all', 'clinic', 'guidance'] as const).map((key) => (
          <Pressable key={key} onPress={() => setUnit(key)} style={[styles.chip, unit === key && styles.chipActive]}>
            <Text style={[styles.chipText, unit === key && styles.chipTextActive]}>
              {key === 'all' ? 'All units' : unitLabel(key)}
            </Text>
          </Pressable>
        ))}
      </View>

      {query.isLoading ? (
        <ActivityIndicator style={styles.loader} color={theme.brand} />
      ) : (
        <FlatList
          data={summaries}
          keyExtractor={(item) => item.unit}
          renderItem={({ item }) => <UnitBoard summary={item} />}
          contentContainerStyle={styles.list}
          refreshing={query.isRefetching}
          onRefresh={() => {
            query.refetch().catch(() => undefined);
          }}
        />
      )}
      <Text style={styles.footer}>
        <Eye size={10} color={theme.ink400} /> Refreshes every 15s · call, skip, and complete actions stay on the web
        workstation
      </Text>
    </Screen>
  );
}

function UnitBoard({ summary }: { summary: StaffQueueSummary }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);

  return (
    <View style={styles.board}>
      <View style={styles.boardHeader}>
        <Text style={styles.boardTitle}>{unitLabel(summary.unit)} · today</Text>
        <View style={styles.countRow}>
          <CountChip label="Waiting" value={summary.counts.waiting} />
          <CountChip label="Called" value={summary.counts.called} />
          <CountChip label="In room" value={summary.counts.in_consultation} />
          <CountChip label="Done" value={summary.counts.done} />
        </View>
      </View>

      <TicketColumn title="WAITING" tickets={summary.tickets.waiting} emptyText="No one waiting." />
      <TicketColumn title="CALLED" tickets={summary.tickets.called} emptyText="Nobody called yet." />
      <TicketColumn title="IN CONSULTATION" tickets={summary.tickets.in_consultation} emptyText="No active consultation." />
    </View>
  );
}

function CountChip({ label, value }: { label: string; value: number }) {
  const styles = makeStyles(useTheme().theme);
  return (
    <View style={styles.countChip}>
      <Text style={styles.countValue}>{value}</Text>
      <Text style={styles.countLabel}>{label}</Text>
    </View>
  );
}

function TicketColumn({ title, tickets, emptyText }: { title: string; tickets: StaffQueueTicket[]; emptyText: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.column}>
      <Text style={styles.columnTitle}>
        {title} · {tickets.length}
      </Text>
      {tickets.length === 0 ? (
        <Text style={styles.columnEmpty}>{emptyText}</Text>
      ) : (
        tickets.map((ticket) => (
          <View key={ticket.id} style={styles.ticketRow}>
            <Text style={styles.ticketCode}>{ticket.ticket_code}</Text>
            <Text style={styles.ticketName} numberOfLines={1}>
              {ticket.patient_name}
            </Text>
            {ticket.priority !== 'routine' ? <StatusPill tone="danger">{ticket.priority}</StatusPill> : null}
            {ticket.called_at ? <Text style={styles.ticketTime}>{formatTime(ticket.called_at)}</Text> : null}
          </View>
        ))
      )}
    </View>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    chips: { flexDirection: 'row', gap: 7, marginBottom: 12 },
    chip: {
      paddingHorizontal: 12,
      paddingVertical: 7,
      borderRadius: 20,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    chipActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    chipText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    chipTextActive: { color: '#ffffff' },
    loader: { marginTop: 30 },
    list: { paddingBottom: 14 },
    board: {
      marginBottom: 14,
      padding: 14,
      borderRadius: 16,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    boardHeader: { marginBottom: 10 },
    boardTitle: { fontSize: 12, fontWeight: '800', fontFamily: font.display, color: theme.ink900 },
    countRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 7, marginTop: 9 },
    countChip: { alignItems: 'center', gap: 3, minWidth: 64, padding: 8, borderRadius: 11, backgroundColor: theme.surfaceWarm },
    countValue: { fontSize: 18, fontWeight: '800', color: theme.ink900, fontFamily: font.display },
    countLabel: { fontSize: 8, color: theme.ink500, fontFamily: font.body },
    column: { marginTop: 10 },
    columnTitle: { color: theme.ink400, fontSize: 8, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    columnEmpty: { marginTop: 6, color: theme.ink400, fontSize: 9, fontFamily: font.body },
    ticketRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 9,
      marginTop: 7,
      padding: 10,
      borderRadius: 11,
      backgroundColor: theme.surfaceWarm,
    },
    ticketCode: { fontSize: 11, fontWeight: '800', color: palette.brand700, fontFamily: font.display },
    ticketName: { flex: 1, fontSize: 10, fontWeight: '600', color: theme.ink900, fontFamily: font.body },
    ticketTime: { fontSize: 8.5, color: theme.ink400, fontFamily: font.body },
    footer: { paddingTop: 8, textAlign: 'center', color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
  });
}
