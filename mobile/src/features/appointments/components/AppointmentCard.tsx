import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { CalendarDays, Clock3, Stethoscope } from 'lucide-react-native';
import { Appointment } from '../../../api/endpoints/appointments';
import { StatusPill } from '../../../components/StatusPill';
import { Theme, font, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate, formatTime } from '../../../lib/format';
import { statusPresentation, unitLabel } from '../status';

export function AppointmentCard({ appointment, onOpen }: { appointment: Appointment; onOpen: (id: number) => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const status = statusPresentation(appointment.status);
  const isGuidance = appointment.unit === 'guidance';

  return (
    <Pressable onPress={() => onOpen(appointment.id)} style={({ pressed }) => [styles.card, pressed && styles.pressed]}>
      <View style={styles.top}>
        <View style={[styles.serviceIcon, isGuidance && styles.serviceIconGuidance]}>
          {isGuidance ? (
            <Text style={styles.guidanceGlyph}>GC</Text>
          ) : (
            <Stethoscope size={19} color={palette.brand700} />
          )}
        </View>
        <View style={styles.copy}>
          <Text style={styles.title}>{unitLabel(appointment.unit)} visit</Text>
          <Text style={styles.type}>{appointment.type.replace(/_/g, ' ')}</Text>
        </View>
        <StatusPill tone={status.tone}>{status.label}</StatusPill>
      </View>
      <View style={styles.schedule}>
        <View style={styles.scheduleItem}>
          <CalendarDays size={13} color={palette.brand600} />
          <Text style={styles.scheduleText}>{formatDate(appointment.starts_at)}</Text>
        </View>
        <View style={styles.scheduleItem}>
          <Clock3 size={13} color={palette.brand600} />
          <Text style={styles.scheduleText}>{formatTime(appointment.starts_at)}</Text>
        </View>
      </View>
      <View style={styles.footer}>
        <Text style={styles.footerText} numberOfLines={1}>
          {appointment.reason ?? 'No reason provided'}
        </Text>
        <Text style={styles.footerLink}>Details</Text>
      </View>
    </Pressable>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    card: {
      padding: 14,
      borderRadius: 15,
      marginBottom: 10,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
      shadowColor: theme.shadowColor,
      shadowOpacity: 0.07,
      shadowRadius: 9,
      shadowOffset: { width: 0, height: 2 },
      elevation: 2,
    },
    pressed: { opacity: 0.8 },
    top: { flexDirection: 'row', alignItems: 'center', gap: 10 },
    serviceIcon: {
      width: 39,
      height: 39,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 11,
      backgroundColor: theme.brand100,
    },
    serviceIconGuidance: { backgroundColor: palette.violet100 },
    guidanceGlyph: { fontWeight: '800', fontSize: 13, color: palette.violet600, fontFamily: font.display },
    copy: { flex: 1, minWidth: 0 },
    title: { fontSize: 11, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    type: { marginTop: 2, fontSize: 9.5, fontFamily: font.body, color: theme.ink500, textTransform: 'capitalize' },
    schedule: { flexDirection: 'row', gap: 18, paddingVertical: 13 },
    scheduleItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
    scheduleText: { fontSize: 10.5, fontFamily: font.body, color: theme.ink700 },
    footer: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      marginHorizontal: -14,
      marginTop: 2,
      paddingHorizontal: 14,
      paddingVertical: 9,
      borderTopWidth: 1,
      borderTopColor: theme.line,
    },
    footerText: { flex: 1, fontSize: 10, fontFamily: font.body, color: theme.ink500 },
    footerLink: { marginLeft: 8, fontSize: 10, fontWeight: '700', fontFamily: font.body, color: theme.brand },
  });
}
