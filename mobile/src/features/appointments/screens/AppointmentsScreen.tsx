import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { CalendarX2 } from 'lucide-react-native';
import { useNavigation } from '@react-navigation/native';
import { AppointmentFilters } from '../../../api/endpoints/appointments';
import { EmptyState } from '../../../components/EmptyState';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { Theme, font, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useAppointments } from '../hooks';
import { AppointmentCard } from '../components/AppointmentCard';
import { AppointmentLoadError } from '../components/AppointmentLoadError';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { VisitsStackParamList } from '../../../navigation/types';

type Nav = NativeStackNavigationProp<VisitsStackParamList>;

const SERVICE_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'clinic', label: 'Clinic' },
  { key: 'guidance', label: 'Guidance' },
] as const;

const STATUS_FILTERS = [
  { key: 'active', label: 'Active' },
  { key: 'requested', label: 'Requested' },
  { key: 'confirmed', label: 'Confirmed' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
] as const;

export function AppointmentsScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Nav>();
  const [service, setService] = useState<AppointmentFilters['service']>('all');
  const [status, setStatus] = useState('active');

  const query = useAppointments({ service, status, sort: 'upcoming' });
  const appointments = query.data?.data ?? [];

  return (
    <Screen scroll={false}>
      <PageHeader
        title="My Visits"
        subtitle="Clinic and guidance appointments"
        action={
          <Action variant="secondary" onPress={() => navigation.navigate('Booking')}>
            Request
          </Action>
        }
      />
      <View style={styles.chipsRow}>
        {SERVICE_FILTERS.map((chip) => (
          <Pressable
            key={chip.key}
            onPress={() => setService(chip.key)}
            style={[styles.chip, service === chip.key && styles.chipActive]}>
            <Text style={[styles.chipText, service === chip.key && styles.chipTextActive]}>{chip.label}</Text>
          </Pressable>
        ))}
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.statusScroll}>
        {STATUS_FILTERS.map((chip) => (
          <Pressable
            key={chip.key}
            onPress={() => setStatus(chip.key)}
            style={[styles.chip, status === chip.key && styles.chipActive, styles.statusChip]}>
            <Text style={[styles.chipText, status === chip.key && styles.chipTextActive]}>{chip.label}</Text>
          </Pressable>
        ))}
      </ScrollView>

      {query.isError ? <AppointmentLoadError error={query.error} onRetry={() => { query.refetch().catch(() => undefined); }} /> : null}
      {query.isLoading ? (
        <ActivityIndicator style={styles.loader} color={theme.brandIcon} />
      ) : appointments.length === 0 && query.isError ? null : appointments.length === 0 ? (
        <EmptyState
          icon={<CalendarX2 size={30} color={theme.brandIcon} />}
          title={status === 'active' ? 'No upcoming visits' : 'Nothing here'}
          body={
            status === 'active'
              ? 'When you request a clinic or guidance appointment, it will appear here with its live status.'
              : 'No appointments match this filter right now.'
          }
          action={<Action full onPress={() => navigation.navigate('Booking')}>Request a visit</Action>}
        />
      ) : (
        <FlatList
          data={appointments}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => (
            <AppointmentCard appointment={item} onOpen={(id) => navigation.navigate('AppointmentDetail', { id })} />
          )}
          contentContainerStyle={styles.list}
          refreshing={query.isRefetching}
          onRefresh={() => {
            query.refetch().catch(() => undefined);
          }}
        />
      )}
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    chipsRow: { flexDirection: 'row', gap: 7, marginBottom: 8 },
    statusScroll: { flexGrow: 0, marginBottom: 12 },
    chip: {
      paddingHorizontal: 12,
      paddingVertical: 7,
      borderRadius: 20,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    statusChip: { marginRight: 7 },
    chipActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    chipText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    chipTextActive: { color: '#ffffff' },
    loader: { marginTop: 30 },
    list: { paddingBottom: 20 },
  });
}

const makeStyles = memoizeStyles(createStyles);
