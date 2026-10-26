import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { BellRing, ListChecks, Radio } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { StatusPill } from '../../../components/StatusPill';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { QueueTicket } from '../../../api/endpoints/queue';
import { Theme, font, palette, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useQueueSnapshot } from '../hooks';
import { unitLabel } from '../../appointments/status';
import { formatTime } from '../../../lib/format';

export function QueueBoardScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const query = useQueueSnapshot();
  const tickets = query.data?.data.tickets ?? [];
  const active = tickets.filter((ticket) => ticket.status !== 'skipped');
  const lastUpdated = query.dataUpdatedAt > 0 ? formatTime(new Date(query.dataUpdatedAt).toISOString()) : null;

  if (query.isLoading) {
    return (
      <Screen scroll={false}>
        <PageHeader title="My Queue" subtitle="Live ticket status" />
        <ActivityIndicator style={styles.loader} color={theme.brand} />
      </Screen>
    );
  }

  if (active.length === 0) {
    return (
      <Screen scroll={false}>
        <PageHeader title="My Queue" subtitle="Live ticket status" />
        <EmptyState
          icon={<ListChecks size={30} color={theme.brand} />}
          title="No active ticket"
          body="You join the queue when the department checks you in for a confirmed appointment or registers a walk-in visit."
          action={
            <Action full onPress={() => query.refetch().catch(() => undefined)}>
              Refresh
            </Action>
          }
        />
      </Screen>
    );
  }

  return (
    <Screen>
      <PageHeader title="My Queue" subtitle="Live ticket status" />
      {lastUpdated ? <Text style={styles.lastUpdated}>Updated {lastUpdated} · refreshes every 15s</Text> : null}

      {active.map((ticket) => (
        <TicketCard key={`${ticket.unit}-${ticket.ticket_code}`} ticket={ticket} />
      ))}

      <View style={styles.notice}>
        <BellRing size={14} color={palette.blue600} />
        <Text style={styles.noticeText}>
          Watch your ticket here — the department will call your number when it is your turn. Keep the app handy or stay
          within calling distance.
        </Text>
      </View>
    </Screen>
  );
}

function TicketCard({ ticket }: { ticket: QueueTicket }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const isGuidance = ticket.unit === 'guidance';
  const statusLabel =
    ticket.status === 'waiting' ? 'Waiting' : ticket.status === 'called' ? 'Now calling' : 'In consultation';

  return (
    <LinearGradient
      colors={isGuidance ? ['#4f3974', palette.violet600] : [palette.brand900, palette.brand700]}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={styles.ticket}>
      <View style={styles.ticketCopy}>
        <StatusPill tone="neutral">{statusLabel}</StatusPill>
        <Text style={styles.ticketNumber}>{ticket.ticket_code}</Text>
        <Text style={styles.ticketUnit}>{unitLabel(ticket.unit)} · today</Text>
      </View>
      <View style={styles.ticketMeta}>
        <Radio size={16} color="#77e0b6" />
        <Text style={styles.ticketMetaText}>Live</Text>
      </View>
    </LinearGradient>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    loader: { marginTop: 30 },
    lastUpdated: { color: theme.ink400, fontSize: 8.5, textAlign: 'center', marginBottom: 12, fontFamily: font.body },
    ticket: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 12,
      marginBottom: 10,
      padding: 15,
      borderRadius: 16,
    },
    ticketCopy: { flexDirection: 'column', alignItems: 'flex-start' },
    ticketNumber: { ...type.hero, fontSize: 26, color: '#ffffff', marginTop: 7 },
    ticketUnit: { color: 'rgba(255,255,255,0.72)', fontSize: 9, marginTop: 2, fontFamily: font.body },
    ticketMeta: { flexDirection: 'column', alignItems: 'center', gap: 3 },
    ticketMetaText: { color: '#77e0b6', fontSize: 8, fontWeight: '800', fontFamily: font.body },
    notice: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 11,
      marginTop: 8,
      padding: 14,
      borderRadius: 14,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    noticeText: { flex: 1, color: theme.ink500, fontSize: 9.5, lineHeight: 14, fontFamily: font.body },
  });
}
