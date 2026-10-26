import React from 'react';
import { ActivityIndicator, FlatList, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ChevronRight, FileText } from 'lucide-react-native';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { StatusPill } from '../../../components/StatusPill';
import { useAppointments } from '../../appointments/hooks';
import { statusPresentation, unitLabel } from '../../appointments/status';
import { Appointment } from '../../../api/endpoints/appointments';
import { Theme, font, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate } from '../../../lib/format';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'Records'>;

export function RecordsScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const query = useAppointments({ service: 'all', status: 'all', sort: 'date_desc' });
  const records = query.data?.data ?? [];

  const byMonth = records.reduce<Record<string, Appointment[]>>((groups, appointment) => {
    const month = appointment.date.slice(0, 7);
    groups[month] = [...(groups[month] ?? []), appointment];
    return groups;
  }, {});

  const sections = Object.entries(byMonth);

  return (
    <Screen scroll={false}>
      <PageHeader title="My records" subtitle="Visit timeline" onBack={navigation.goBack} />
      {query.isLoading ? (
        <ActivityIndicator color={theme.brand} />
      ) : records.length === 0 ? (
        <EmptyState
          icon={<FileText size={30} color={theme.brand} />}
          title="No records yet"
          body="Completed clinic and guidance visits will build your personal timeline here."
        />
      ) : (
        <FlatList
          data={sections}
          keyExtractor={(section) => section[0]}
          renderItem={({ item: [month, appointments] }) => (
            <View>
              <Text style={styles.monthLabel}>{formatMonth(month)}</Text>
              {appointments.map((appointment) => (
                <RecordRow key={appointment.id} appointment={appointment} />
              ))}
            </View>
          )}
          contentContainerStyle={styles.list}
        />
      )}
    </Screen>
  );
}

function RecordRow({ appointment }: { appointment: Appointment }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const status = statusPresentation(appointment.status);
  const day = appointment.date.slice(8, 10);
  const isGuidance = appointment.unit === 'guidance';

  return (
    <View style={styles.recordCard}>
      <View style={[styles.recordDot, isGuidance && styles.recordDotGuidance]} />
      <View style={styles.recordDate}>
        <Text style={styles.recordDay}>{day}</Text>
        <Text style={styles.recordMonth}>{formatMonth(appointment.date.slice(0, 7))}</Text>
      </View>
      <View style={styles.recordCopy}>
        <StatusPill tone={status.tone}>{status.label}</StatusPill>
        <Text style={styles.recordTitle}>{unitLabel(appointment.unit)} visit</Text>
        <Text style={styles.recordMeta}>{formatDate(appointment.starts_at)} · {appointment.type.replace(/_/g, ' ')}</Text>
      </View>
      <ChevronRight size={15} color={theme.ink400} />
    </View>
  );
}

function formatMonth(ym: string): string {
  const [year, month] = ym.split('-');
  const parsed = new Date(Number(year), Number(month) - 1, 1);
  return parsed.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }).toUpperCase();
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    list: { paddingBottom: 20 },
    monthLabel: { marginVertical: 12, marginLeft: 2, color: theme.ink400, fontSize: 8, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    recordCard: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      marginBottom: 9,
      marginLeft: 12,
      padding: 12,
      borderRadius: 13,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    recordDot: {
      position: 'absolute' as const,
      left: -17,
      width: 9,
      height: 9,
      borderRadius: 999,
      backgroundColor: theme.brand,
    },
    recordDotGuidance: { backgroundColor: palette.violet600 },
    recordDate: { alignItems: 'center', paddingRight: 9, borderRightWidth: 1, borderRightColor: theme.line },
    recordDay: { fontSize: 15, fontWeight: '800', color: theme.ink900, fontFamily: font.display },
    recordMonth: { color: theme.ink400, fontSize: 7.5, fontFamily: font.body },
    recordCopy: { flex: 1, alignItems: 'flex-start', gap: 3 },
    recordTitle: { marginTop: 4, fontSize: 10, fontWeight: '700', color: theme.ink900, fontFamily: font.body },
    recordMeta: { color: theme.ink400, fontSize: 8, fontFamily: font.body },
  });
}
