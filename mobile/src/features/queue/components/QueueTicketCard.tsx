import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { ListChecks } from 'lucide-react-native';
import { QueueTicket } from '../../../api/endpoints/queue';
import { unitLabel } from '../../appointments/status';
import { PlaceholderCard } from '../../../components/PlaceholderCard';
import { font, palette, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';

/** Presentation strings shared with the web waiting-room card (portal/_queue_tickets.php). */
export function ticketPresentation(ticket: QueueTicket) {
  const { status, position, people_ahead: ahead, estimated_wait_minutes: minutes } = ticket;
  const statusLabel = status === 'in_consultation' ? 'In consultation' : status === 'called' ? 'Now calling' : status === 'skipped' ? 'On hold' : 'Waiting';

  const place =
    status === 'called' ? 'Your turn'
    : status === 'in_consultation' ? 'In progress'
    : status === 'skipped' ? 'On hold'
    : typeof position === 'number' && position > 0 ? `#${position}` : '—';

  const peopleAhead =
    status === 'skipped' ? '—'
    : status === 'called' || status === 'in_consultation' ? '0'
    : typeof ahead === 'number' && ahead >= 0 ? String(ahead) : '—';

  const wait =
    status === 'called' ? 'Now'
    : status === 'in_consultation' ? 'In progress'
    : status === 'skipped' ? 'Ask reception'
    : minutes === 0 ? 'You’re next'
    : typeof minutes === 'number' && minutes > 0 ? `~${minutes} min`
    : 'Not enough data';

  return { statusLabel, place, peopleAhead, wait };
}

export function QueueTicketCard({ ticket }: { ticket: QueueTicket }) {
  const { theme } = useTheme();
  const guidance = ticket.unit === 'guidance';
  const view = ticketPresentation(ticket);
  const priority = ticket.priority ? ticket.priority.charAt(0).toUpperCase() + ticket.priority.slice(1) : 'Normal';

  return (
    <LinearGradient
      colors={guidance ? ['#4f3974', palette.violet600] : [palette.brand900, palette.brand700]}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      accessible
      accessibilityLabel={`${unitLabel(ticket.unit)} ticket ${ticket.ticket_code}. ${view.statusLabel}. Place in line ${view.place}. People ahead ${view.peopleAhead}. Estimated wait ${view.wait}.`}
      style={cardStyles.ticket}>
      <View style={cardStyles.top}>
        <View style={cardStyles.copy}>
          <View style={[cardStyles.pill, !guidance && theme.mode === 'dark' && { backgroundColor: theme.surface }]}>
            <Text style={[cardStyles.pillText, { color: guidance ? palette.violet600 : theme.brandText }]}>
              Live waiting room ticket
            </Text>
          </View>
          <Text style={cardStyles.code}>{ticket.ticket_code}</Text>
          <Text style={cardStyles.meta}>
            {unitLabel(ticket.unit)} · {view.statusLabel}
          </Text>
        </View>
        <View style={cardStyles.priority}>
          <Text style={cardStyles.priorityLabel}>PRIORITY</Text>
          <Text style={cardStyles.priorityValue}>{priority}</Text>
        </View>
      </View>
      <View style={cardStyles.stats}>
        <Stat label="Place in line" value={view.place} />
        <Stat label="People ahead" value={view.peopleAhead} divider />
        <Stat label="Est. wait" value={view.wait} divider />
      </View>
    </LinearGradient>
  );
}

function Stat({ label, value, divider = false }: { label: string; value: string; divider?: boolean }) {
  return (
    <View style={[cardStyles.stat, divider && cardStyles.statDivider]}>
      <Text style={cardStyles.statValue} numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </Text>
      <Text style={cardStyles.statLabel}>{label}</Text>
    </View>
  );
}

/** Shown whenever there is no ticket, so the slot never disappears from Home or Queue. */
export function QueueTicketPlaceholder({ loading = false, error = false, onPress }: { loading?: boolean; error?: boolean; onPress?: () => void }) {
  const { theme } = useTheme();
  return (
    <PlaceholderCard
      icon={<ListChecks size={22} color={theme.brandIcon} />}
      title={loading ? 'Loading queue…' : error ? 'Queue unavailable' : 'No active queue ticket'}
      body={error
        ? 'We couldn’t load your ticket. Open Queue to retry; your place is not affected.'
        : 'Your live waiting room ticket appears here once the clinic or guidance center checks you in.'}
      loading={loading}
      onPress={onPress}
      actionLabel="Open queue"
    />
  );
}

const cardStyles = StyleSheet.create({
  ticket: {
    marginBottom: 10,
    padding: 15,
    borderRadius: 16,
    elevation: 6,
    shadowColor: '#800000',
    shadowOpacity: 0.2,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 8 },
  },
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  copy: { flex: 1, alignItems: 'flex-start' },
  pill: { paddingHorizontal: 8, paddingVertical: 4, borderRadius: 999, backgroundColor: '#ffffff' },
  pillText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body },
  code: { ...type.hero, marginTop: 7, fontSize: 26, color: '#ffffff' },
  meta: { marginTop: 2, color: 'rgba(255,255,255,0.72)', fontSize: 9.5, fontFamily: font.body },
  priority: { alignItems: 'flex-end' },
  priorityLabel: { color: 'rgba(255,255,255,0.65)', fontSize: 7.5, fontWeight: '700', fontFamily: font.body },
  priorityValue: { marginTop: 2, color: '#ffffff', fontSize: 10, fontWeight: '700', fontFamily: font.body },
  stats: {
    flexDirection: 'row',
    marginTop: 14,
    paddingTop: 11,
    borderTopWidth: 1,
    borderTopColor: 'rgba(255,255,255,0.14)',
  },
  stat: { flex: 1, alignItems: 'center', paddingHorizontal: 4 },
  statDivider: { borderLeftWidth: 1, borderLeftColor: 'rgba(255,255,255,0.14)' },
  statValue: { color: '#ffffff', fontSize: 15, fontWeight: '800', fontFamily: font.display },
  statLabel: { marginTop: 2, color: 'rgba(255,255,255,0.72)', fontSize: 8.5, fontFamily: font.body },
});
