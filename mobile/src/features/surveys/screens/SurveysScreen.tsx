import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { CircleCheckBig, ClipboardList } from 'lucide-react-native';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { StatusPill } from '../../../components/StatusPill';
import { SurveyListItem } from '../../../api/endpoints/surveys';
import { Theme, font, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate } from '../../../lib/format';
import { useSurveys } from '../hooks';
import { RootStackParamList } from '../../../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;

const FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'available', label: 'Available' },
  { key: 'submitted', label: 'Submitted' },
] as const;

export function SurveysScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Nav>();
  const [status, setStatus] = useState('all');
  const query = useSurveys({ status });
  const items = query.data?.data ?? [];
  const counts = query.data?.counts;

  return (
    <Screen scroll={false}>
      <PageHeader title="Surveys" subtitle="Identified guidance assessments" onBack={navigation.goBack} />

      {counts && counts.available > 0 ? (
        <View style={styles.summary}>
          <View style={styles.summaryIcon}>
            <ClipboardList size={20} color="#ffffff" />
          </View>
          <View style={styles.summaryCopy}>
            <Text style={styles.summaryTitle}>
              {counts.available} survey{counts.available === 1 ? '' : 's'} waiting for you
            </Text>
            <Text style={styles.summaryBody}>Your answers help guidance staff improve campus services.</Text>
          </View>
        </View>
      ) : null}

      <View style={styles.chips}>
        {FILTERS.map((chip) => (
          <Pressable key={chip.key} onPress={() => setStatus(chip.key)} style={[styles.chip, status === chip.key && styles.chipActive]}>
            <Text style={[styles.chipText, status === chip.key && styles.chipTextActive]}>{chip.label}</Text>
          </Pressable>
        ))}
      </View>

      {query.isLoading ? (
        <ActivityIndicator style={styles.loader} color={palette.violet600} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={<ClipboardList size={30} color={palette.violet600} />}
          title="No surveys"
          body="When the guidance center publishes an assessment for you, it will appear here."
        />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) =>
            item.submitted || item.closed ? (
              <CompletedRow item={item} />
            ) : (
              <SurveyCard item={item} onOpen={() => navigation.navigate('SurveyForm', { id: item.id })} />
            )
          }
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

function SurveyCard({ item, onOpen }: { item: SurveyListItem; onOpen: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable onPress={onOpen} style={({ pressed }) => [styles.card, pressed && styles.pressed]}>
      <View style={styles.cardTop}>
        <Text style={styles.cardTitle} numberOfLines={2}>
          {item.title}
        </Text>
        <StatusPill tone="warning">Open</StatusPill>
      </View>
      {item.description ? (
        <Text style={styles.cardBody} numberOfLines={2}>
          {item.description}
        </Text>
      ) : null}
      <Text style={styles.cardMeta}>
        {item.ends_at ? `Answer before ${formatDate(item.ends_at)}` : 'No deadline published'}
      </Text>
    </Pressable>
  );
}

function CompletedRow({ item }: { item: SurveyListItem }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.completedRow}>
      <CircleCheckBig size={20} color={theme.brand} />
      <View style={styles.completedCopy}>
        <Text style={styles.completedTitle} numberOfLines={1}>
          {item.title}
        </Text>
        <Text style={styles.completedMeta}>
          {item.submitted ? 'You already submitted this survey.' : 'This survey has closed.'}
        </Text>
      </View>
    </View>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    summary: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 11,
      padding: 13,
      borderRadius: 14,
      backgroundColor: palette.violet600,
    },
    summaryIcon: {
      width: 42,
      height: 42,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      backgroundColor: 'rgba(255,255,255,0.14)',
    },
    summaryCopy: { flex: 1 },
    summaryTitle: { color: '#ffffff', fontSize: 11.5, fontWeight: '700', fontFamily: font.body },
    summaryBody: { marginTop: 2, color: '#e3dcef', fontSize: 9, fontFamily: font.body },
    chips: { flexDirection: 'row', gap: 7, marginTop: 13, marginBottom: 12 },
    chip: {
      paddingHorizontal: 12,
      paddingVertical: 7,
      borderRadius: 20,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    chipActive: { backgroundColor: palette.violet600, borderColor: palette.violet600 },
    chipText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    chipTextActive: { color: '#ffffff' },
    loader: { marginTop: 30 },
    list: { paddingBottom: 20 },
    card: { marginBottom: 10 },
    pressed: { opacity: 0.75 },
    cardTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8, marginBottom: 10 },
    cardTitle: { flex: 1, fontSize: 11.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    cardBody: { color: theme.ink500, fontSize: 10, lineHeight: 14, fontFamily: font.body },
    cardMeta: { marginTop: 9, color: theme.brand, fontSize: 9, fontWeight: '700', fontFamily: font.body },
    completedRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 9,
      padding: 12,
      borderRadius: 13,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
      marginBottom: 9,
    },
    completedCopy: { flex: 1 },
    completedTitle: { fontSize: 10.5, fontWeight: '600', fontFamily: font.body, color: theme.ink900 },
    completedMeta: { marginTop: 2, color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
  });
}
