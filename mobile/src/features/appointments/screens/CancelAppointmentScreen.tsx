import React, { useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { CalendarX2 } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { TextField } from '../../../components/TextField';
import { Theme, font, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate, formatTime } from '../../../lib/format';
import { useAppointment, useCancelAppointment } from '../hooks';
import { AppointmentLoadError } from '../components/AppointmentLoadError';
import { appointmentErrorMessage } from '../errors';
import { TYPE_LABELS } from '../booking';
import { unitLabel } from '../status';
import { VisitsStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<VisitsStackParamList, 'CancelAppointment'>;

export function CancelAppointmentScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const route = useRoute<Props['route']>();
  const query = useAppointment(route.params.id);
  const appointment = query.data?.data;
  const cancel = useCancelAppointment();
  const submitting = useRef(false);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [reasonError, setReasonError] = useState<string | null>(null);
  const cancellable = appointment && ['requested', 'scheduled', 'confirmed'].includes(appointment.status);

  async function onSubmit() {
    if (submitting.current || !cancellable || query.isError) {
      return;
    }
    const trimmed = reason.trim();
    if (trimmed === '') {
      setReasonError('Tell the department why you are cancelling.');
      return;
    }
    if (Array.from(trimmed).length > 255) {
      setReasonError('Reason must be at most 255 characters.');
      return;
    }
    submitting.current = true;
    setReasonError(null);
    setError(null);
    try {
      await cancel.mutateAsync({ id: route.params.id, reason: trimmed });
      navigation.goBack();
    } catch (mutationError) {
      submitting.current = false;
      setError(appointmentErrorMessage(mutationError, 'The appointment could not be cancelled. Try again.'));
    }
  }

  return (
    <Screen>
      <PageHeader title="Cancel appointment" onBack={() => { if (!submitting.current) { navigation.goBack(); } }} />
      {query.isLoading ? <ActivityIndicator color={theme.brandIcon} /> : null}
      {query.isError ? <AppointmentLoadError error={query.error} onRetry={() => { query.refetch().catch(() => undefined); }} /> : null}
      {!query.isLoading && !query.isError && !appointment ? <Text style={styles.caption}>This appointment is no longer available.</Text> : null}
      {appointment ? (
        <>
          <View style={styles.warningIcon}><CalendarX2 size={28} color="#a33b3b" /></View>
          <Text style={styles.heading}>Are you sure you want to cancel?</Text>
          <View style={styles.summary}>
            <Text style={styles.summaryTitle}>{unitLabel(appointment.unit)} · {TYPE_LABELS[appointment.type] ?? appointment.type.replace(/_/g, ' ')}</Text>
            <Text style={styles.caption}>{formatDate(appointment.starts_at)} at {formatTime(appointment.starts_at)}</Text>
            <Text style={styles.caption}>{appointment.status === 'requested' ? 'Preferred time · Awaiting confirmation' : 'Appointment time'} · Reference #{appointment.id}</Text>
            <Text style={styles.caption}>{appointment.provider_name ?? 'Not yet assigned'}</Text>
          </View>
          {cancellable ? (
            <>
              <Text style={styles.caption}>Cancellations cannot be undone. You will need to request a new appointment if your plans change.</Text>
              <TextField
                label="Reason for cancelling"
                multiline
                maxLength={255}
                style={styles.textarea}
                value={reason}
                onChangeText={value => { setReason(value); setReasonError(null); }}
                editable={!cancel.isPending}
                error={reasonError ?? undefined}
              />
              {error ? <Text style={styles.error} accessibilityRole="alert">{error}</Text> : null}
              <View style={styles.actions}>
                <Action variant="danger" full onPress={onSubmit} loading={cancel.isPending} disabled={query.isError}>Yes, cancel appointment</Action>
                <Action variant="ghost" full disabled={cancel.isPending} onPress={navigation.goBack}>Keep my appointment</Action>
              </View>
            </>
          ) : (
            <Text style={styles.caption}>This appointment can no longer be cancelled.</Text>
          )}
        </>
      ) : null}
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    heading: { ...type.hero, fontSize: 19, color: theme.ink900, textAlign: 'center' },
    caption: { marginTop: 5, color: theme.ink500, fontSize: 11.5, lineHeight: 17, fontFamily: font.body },
    warningIcon: { width: 40, height: 40, alignSelf: 'center', alignItems: 'center', justifyContent: 'center', marginVertical: 16 },
    summary: { padding: 14, marginVertical: 16, borderRadius: 15, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    summaryTitle: { color: theme.ink900, fontFamily: font.body, fontWeight: '700', fontSize: 12 },
    textarea: { minHeight: 105, paddingTop: 12, textAlignVertical: 'top' },
    error: { marginTop: 10, color: '#a33b3b', fontFamily: font.body, fontSize: 11.5 },
    actions: { marginTop: 24, gap: 7, paddingBottom: 4 },
  });
}

const makeStyles = memoizeStyles(createStyles);
