import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { CalendarDays, ChevronRight, Clock3, Stethoscope, UserRound } from 'lucide-react-native';
import { DetailRow } from '../../../components/DetailRow';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { StatusPill } from '../../../components/StatusPill';
import { Action } from '../../../components/Action';
import { Theme, font, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate, formatTime } from '../../../lib/format';
import { useAppointment } from '../hooks';
import { progressStep, statusPresentation, unitLabel } from '../status';
import { VisitsStackParamList } from '../../../navigation/types';
import { AppointmentLoadError } from '../components/AppointmentLoadError';

type Props = NativeStackScreenProps<VisitsStackParamList, 'AppointmentDetail'>;

const STEPS = ['Requested', 'Confirmed', 'Checked in', 'Done'];

export function AppointmentDetailScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const route = useRoute<Props['route']>();
  const query = useAppointment(route.params.id);
  const appointment = query.data?.data;

  if (query.isLoading) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Appointment" onBack={navigation.goBack} />
        <ActivityIndicator style={styles.loader} color={theme.brandIcon} />
      </Screen>
    );
  }
  if (!appointment) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Appointment" onBack={navigation.goBack} />
        {query.isError ? (
          <AppointmentLoadError error={query.error} onRetry={() => { query.refetch().catch(() => undefined); }} />
        ) : (
          <Text style={styles.missing}>This appointment is no longer available.</Text>
        )}
      </Screen>
    );
  }

  const status = statusPresentation(appointment.status);
  const step = progressStep(appointment.status);
  const cancellable = ['requested', 'scheduled', 'confirmed'].includes(appointment.status);

  return (
    <Screen>
      <PageHeader title="Appointment details" onBack={navigation.goBack} />
      {query.isError ? <AppointmentLoadError error={query.error} onRetry={() => { query.refetch().catch(() => undefined); }} /> : null}
      <View style={styles.progress}>
        {STEPS.map((label, index) => (
          <View key={label} style={styles.stepColumn}>
            <View style={[styles.stepBar, index < step && styles.stepBarDone]} />
            <Text style={[styles.stepLabel, index < step && styles.stepLabelDone]}>{label}</Text>
          </View>
        ))}
      </View>

      <View style={styles.hero}>
        <Text style={styles.heroTitle}>{unitLabel(appointment.unit)} visit</Text>
        <Text style={styles.heroSubtitle}>{appointment.type.replace(/_/g, ' ')} · Reference #{appointment.id}</Text>
        <StatusPill tone={status.tone}>{status.label}</StatusPill>
      </View>

      {appointment.status === 'requested' ? (
        <View style={styles.cancelNote}>
          <Text style={styles.cancelNoteText}>This is a preferred time, not yet confirmed. The department will review your request and notify you when a time is confirmed.</Text>
        </View>
      ) : null}
      <View style={styles.card}>
        <DetailRow
          icon={<CalendarDays size={16} color={theme.brandIcon} />}
          label="DATE"
          value={formatDate(appointment.starts_at)}
        />
        <DetailRow
          icon={<Clock3 size={16} color={theme.brandIcon} />}
          label={appointment.status === 'requested' ? 'PREFERRED TIME' : 'TIME'}
          value={`${formatTime(appointment.starts_at)} – ${formatTime(appointment.ends_at)}`}
        />
        {appointment.provider_name ? (
          <DetailRow
            icon={<UserRound size={16} color={theme.brandIcon} />}
            label="ASSIGNED PROVIDER"
            value={appointment.provider_name}
          />
        ) : (
          <DetailRow
            icon={<Stethoscope size={16} color={theme.brandIcon} />}
            label="ASSIGNED PROVIDER"
            value="Not yet assigned"
          />
        )}
        <DetailRow
          icon={<ChevronRight size={16} color={theme.brandIcon} />}
          label="REASON"
          value={appointment.reason ?? 'No reason provided'}
          last
        />
      </View>

      {appointment.cancellation_reason ? (
        <View style={styles.cancelNote}>
          <Text style={styles.cancelNoteLabel}>Cancellation reason</Text>
          <Text style={styles.cancelNoteText}>{appointment.cancellation_reason}</Text>
        </View>
      ) : cancellable ? (
        <View style={styles.actions}>
          <Action variant="danger" full onPress={() => navigation.navigate('CancelAppointment', { id: appointment.id })}>
            Cancel this appointment
          </Action>
        </View>
      ) : null}
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    loader: { marginTop: 30 },
    missing: { color: theme.ink500, fontFamily: font.body, fontSize: 11.5 },
    progress: { flexDirection: 'row', gap: 6, marginBottom: 22, marginTop: -6 },
    stepColumn: { flex: 1 },
    stepBar: { height: 4, borderRadius: 4, backgroundColor: theme.line },
    stepBarDone: { backgroundColor: theme.brand },
    stepLabel: { marginTop: 5, fontSize: 8, fontWeight: '700', fontFamily: font.body, color: theme.ink400 },
    stepLabelDone: { color: theme.brandText },
    hero: { alignItems: 'center', paddingBottom: 19 },
    heroTitle: { ...type.hero, fontSize: 19, color: theme.ink900, marginTop: 6 },
    heroSubtitle: {
      marginTop: 3,
      marginBottom: 10,
      color: theme.ink500,
      fontSize: 10,
      fontFamily: font.body,
      textTransform: 'capitalize',
    },
    card: { paddingHorizontal: 14 },
    cancelNote: {
      marginTop: 14,
      padding: 12,
      borderRadius: 11,
      backgroundColor: theme.brand50,
    },
    cancelNoteLabel: { fontSize: 9, fontWeight: '800', fontFamily: font.body, color: theme.ink500, letterSpacing: 1 },
    cancelNoteText: { marginTop: 4, fontSize: 10.5, fontFamily: font.body, color: theme.ink700 },
    actions: { marginTop: 'auto', paddingTop: 24 },
  });
}

const makeStyles = memoizeStyles(createStyles);
