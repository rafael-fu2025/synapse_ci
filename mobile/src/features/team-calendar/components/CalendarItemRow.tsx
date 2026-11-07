import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { CalendarClock, CheckSquare2, MapPin, Users } from 'lucide-react-native';
import type { TeamCalendarItem } from '../../../api/endpoints/teamCalendar';
import { StatusPill } from '../../../components/StatusPill';
import { useTheme } from '../../../theme/ThemeProvider';
import { Theme, memoizeStyles, type } from '../../../theme/tokens';
import { formatManilaTimeFromInstant } from '../manila';

const TASK_STATUS = {
  todo: 'To do',
  in_progress: 'In progress',
  blocked: 'Blocked',
  done: 'Done',
} as const;

export function CalendarItemRow({
  item,
  onPress,
}: {
  item: TeamCalendarItem;
  onPress: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const event = item.type === 'event';
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${event ? 'Open event' : 'Open task deadline'} ${item.title}`}
      onPress={onPress}
      style={({ pressed }) => [styles.row, event ? styles.eventRow : styles.taskRow, pressed && styles.pressed]}>
      <View style={[styles.icon, event ? styles.eventIcon : styles.taskIcon]}>
        {event
          ? <CalendarClock size={18} color={theme.brandIcon} />
          : <CheckSquare2 size={18} color={theme.mode === 'dark' ? '#a6d6f0' : '#347293'} />}
      </View>
      <View style={styles.copy}>
        <View style={styles.titleRow}>
          <Text numberOfLines={2} style={styles.title}>{item.title}</Text>
          <StatusPill tone={event
            ? item.status === 'cancelled' ? 'danger' : 'info'
            : item.status === 'done' ? 'success' : item.status === 'blocked' ? 'danger' : 'neutral'}>
            {event ? item.status : TASK_STATUS[item.status]}
          </StatusPill>
        </View>
        {event ? (
          <>
            <Text style={styles.time}>{formatManilaTimeFromInstant(item.starts_at)} – {formatManilaTimeFromInstant(item.ends_at)}</Text>
            {item.location ? <View style={styles.fact}><MapPin size={13} color={theme.ink500} /><Text numberOfLines={1} style={styles.meta}>{item.location}</Text></View> : null}
            {item.participants.length > 0 ? <View style={styles.fact}><Users size={13} color={theme.ink500} /><Text style={styles.meta}>{item.participants.length} participant{item.participants.length === 1 ? '' : 's'}</Text></View> : null}
          </>
        ) : (
          <Text style={styles.meta}>{item.priority} priority task · Due {item.due_date}</Text>
        )}
      </View>
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    row: { minHeight: 72, flexDirection: 'row', gap: 10, padding: 12, marginBottom: 8, borderRadius: 14, borderWidth: 1, backgroundColor: theme.surface },
    eventRow: { borderColor: theme.line },
    taskRow: { borderColor: theme.mode === 'dark' ? theme.line : '#cfe2eb' },
    icon: { width: 36, height: 36, alignItems: 'center', justifyContent: 'center', borderRadius: 11 },
    eventIcon: { backgroundColor: theme.brand50 },
    taskIcon: { backgroundColor: theme.mode === 'dark' ? '#20313c' : '#e3f0f6' },
    copy: { flex: 1, minWidth: 0 },
    titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 7 },
    title: { ...type.cardTitle, flex: 1, color: theme.ink900 },
    time: { ...type.bodyStrong, marginTop: 4, color: theme.ink700 },
    fact: { flexDirection: 'row', alignItems: 'center', gap: 5, marginTop: 3 },
    meta: { ...type.caption, flexShrink: 1, marginTop: 3, color: theme.ink500, textTransform: 'capitalize' },
    pressed: { opacity: 0.72 },
  });
}

const makeStyles = memoizeStyles(createStyles);
