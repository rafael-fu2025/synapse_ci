import React, { useMemo } from 'react';
import { ActivityIndicator, SectionList, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { FileText, ShieldCheck } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { StatusPill } from '../../../components/StatusPill';
import { useAppointments } from '../../appointments/hooks';
import { statusPresentation, unitLabel } from '../../appointments/status';
import { Appointment } from '../../../api/endpoints/appointments';
import { Theme, font, palette, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate } from '../../../lib/format';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'Records'>;

export function RecordsScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const query = useAppointments({ service: 'all', status: 'completed', sort: 'date_desc' });
  const sections = useMemo(() => {
    const byMonth = new Map<string, Appointment[]>();
    for (const appointment of query.data?.data ?? []) {
      if (appointment.status !== 'completed') {
        continue;
      }
      const month = appointment.date.slice(0, 7);
      const group = byMonth.get(month);
      if (group) {
        group.push(appointment);
      } else {
        byMonth.set(month, [appointment]);
      }
    }
    return Array.from(byMonth, ([month, data]) => ({ month, data }));
  }, [query.data]);

  return (
    <Screen scroll={false}>
      <PageHeader title="Personal records" subtitle="Completed appointment history" onBack={navigation.goBack} />
      <View style={styles.notice}>
        <ShieldCheck size={18} color={theme.brandIcon} />
        <Text style={styles.noticeText}>This timeline shows completed appointments, not clinical records or shared care summaries. Internal clinical and counseling notes are not shown here.</Text>
      </View>
      {query.isLoading ? (
        <ActivityIndicator color={theme.brandIcon} />
      ) : query.isError ? (
        <EmptyState
          icon={<FileText size={30} color={theme.brandIcon} />}
          title="Could not load appointment history"
          body="Your history is unavailable right now. Check your connection and try again."
          action={<Action full onPress={() => { query.refetch().catch(() => undefined); }} loading={query.isFetching}>Try again</Action>}
        />
      ) : sections.length === 0 ? (
        <EmptyState
          icon={<FileText size={30} color={theme.brandIcon} />}
          title="No completed appointments yet"
          body="Completed clinic and guidance appointments will appear here. Appointment requests and upcoming visits are in Appointment history."
        />
      ) : (
        <SectionList
          sections={sections}
          keyExtractor={appointment => String(appointment.id)}
          renderSectionHeader={({ section }) => <Text style={styles.monthLabel}>{formatMonth(section.month)}</Text>}
          renderItem={({ item }) => <RecordRow appointment={item} />}
          stickySectionHeadersEnabled={false}
          refreshing={query.isFetching}
          onRefresh={() => { query.refetch().catch(() => undefined); }}
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
        <Text style={styles.recordMonth}>{new Date(`${appointment.date}T12:00:00`).toLocaleDateString('en-PH', { month: 'short' }).toUpperCase()}</Text>
      </View>
      <View style={styles.recordCopy}>
        <StatusPill tone={status.tone}>{status.label}</StatusPill>
        <Text style={styles.recordTitle}>{unitLabel(appointment.unit)} appointment</Text>
        <Text style={styles.recordMeta}>{formatDate(appointment.starts_at)} · {appointment.type.replace(/_/g, ' ')}</Text>
      </View>

    </View>
  );
}

function formatMonth(ym: string): string {
  const [year, month] = ym.split('-');
  const parsed = new Date(Number(year), Number(month) - 1, 1);
  return parsed.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }).toUpperCase();
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    list: { paddingBottom: 20, paddingLeft: 8, borderLeftWidth: 1, borderLeftColor: theme.line },
    notice: { flexDirection: 'row', alignItems: 'flex-start', gap: 8, padding: 12, marginBottom: 12, borderRadius: 12, backgroundColor: theme.brand50 },
    noticeText: { flex: 1, color: theme.ink500, fontSize: 10, lineHeight: 15, fontFamily: font.body },
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

const makeStyles = memoizeStyles(createStyles);
