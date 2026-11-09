import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { ListChecks } from 'lucide-react-native';
import { QueueTicket } from '../../../api/endpoints/queue';
import { unitLabel } from '../../appointments/status';
import { PlaceholderCard } from '../../../components/PlaceholderCard';
import { CardOutline } from '../../../components/CardOutline';
import { Skeleton } from '../../../components/Skeleton';
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
  // Same branded surface as the Home appointment card: maroon (or violet for
  // guidance) in light mode, black in dark mode. Content is white on all three.
  const dark = theme.mode === 'dark';
  const colors = dark
    ? ['#1c1c1c', '#0f0f0f']
    : guidance ? ['#4f3974', palette.violet600] : [palette.brand900, palette.brand700];

  return (
    <View
      accessible
      accessibilityLabel={`${unitLabel(ticket.unit)} ticket ${ticket.ticket_code}. ${view.statusLabel}. Place in line ${view.place}. People ahead ${view.peopleAhead}. Estimated wait ${view.wait}.`}
      style={[cardStyles.shell, dark && cardStyles.shellOnDark]}>
      <LinearGradient colors={colors} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={cardStyles.ticket}>
        <View style={cardStyles.top}>
          <View style={cardStyles.copy}>
            <View style={[cardStyles.pill, dark ? cardStyles.pillOnDark : cardStyles.pillOnLight]}>
            <Text style={[cardStyles.pillText, dark ? cardStyles.pillTextOnDark : guidance ? cardStyles.pillTextGuidance : cardStyles.pillTextClinic]}>
              Live waiting ticket
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
      {dark ? <CardOutline radius={16} testID="queueTicketOutline" /> : null}
    </View>
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
export function QueueTicketPlaceholder({ loading = false, error = false, waiting = false, onPress }: { loading?: boolean; error?: boolean; waiting?: boolean; onPress?: () => void }) {
  const { theme } = useTheme();
  if (loading) { return <Skeleton label="Loading queue…" variant="queue" testID="queue-ticket-skeleton" />; }
  return (
    <PlaceholderCard
      icon={<ListChecks size={22} color={theme.brandIcon} />}
      title={error ? 'Queue unavailable' : waiting ? 'Waiting to load queue' : 'No active queue ticket'}
      body={error
        ? 'We couldn’t load your ticket. Open Queue to retry; your place is not affected.'
        : waiting ? 'Your ticket has not loaded yet. Open Queue when this screen and your connection are active.'
        : 'Your live waiting room ticket appears here once the clinic or guidance center checks you in.'}
      onPress={onPress}
      actionLabel="Open queue"
    />
  );
}

const cardStyles = StyleSheet.create({
  // Outer shell owns the spacing, radius and shadow so the gradient fills the
  // full card and the outline can sit on its exact bounds (not the padded box).
  shell: {
    marginBottom: 10,
    borderRadius: 16,
    overflow: 'hidden',
    elevation: 6,
    shadowColor: '#800000',
    shadowOpacity: 0.2,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 8 },
  },
  // A maroon glow under a black card reads as a colour fringe; match the Home
  // appointment card's neutral dark shadow instead.
  shellOnDark: { shadowColor: '#000000', shadowOpacity: 0.5, shadowRadius: 25, shadowOffset: { width: 0, height: 12 } },
  ticket: { padding: 15 },
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  copy: { flex: 1, alignItems: 'flex-start' },
  pill: { paddingHorizontal: 8, paddingVertical: 4, borderRadius: 999 },
  pillOnLight: { backgroundColor: '#ffffff' },
  // On the black dark-mode surface a white pill would glare; match the Home
  // appointment card's on-brand pill instead (translucent white + white label).
  pillOnDark: { backgroundColor: 'rgba(255,255,255,0.18)' },
  pillText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body },
  pillTextClinic: { color: palette.brand700 },
  pillTextGuidance: { color: palette.violet600 },
  pillTextOnDark: { color: '#ffffff' },
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
