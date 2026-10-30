import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { ChevronRight, CircleCheckBig, ClipboardList, Clock3, MessageSquareText, ShieldCheck, TriangleAlert } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { StatusPill } from '../../../components/StatusPill';
import { SurveyListItem } from '../../../api/endpoints/surveys';
import { Theme, elevation, font, palette, memoizeStyles } from '../../../theme/tokens';
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
  const noSurveys = status === 'all' || counts?.all === 0;
  const retry = () => { query.refetch().catch(() => undefined); };

  return (
    <Screen scroll={false}>
      <PageHeader title="Surveys" subtitle="Your voice helps improve campus care" onBack={navigation.goBack} />

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

      {query.isError && items.length > 0 ? (
        <View style={styles.fetchError}>
          <Text style={styles.fetchErrorText}>Could not refresh surveys. Showing the last loaded results.</Text>
          <Action variant="secondary" onPress={retry} loading={query.isFetching}>Retry</Action>
        </View>
      ) : null}

      {query.isLoading ? (
        <ActivityIndicator style={styles.loader} color={palette.violet600} />
      ) : query.isError && items.length === 0 ? (
        <EmptyState
          icon={<TriangleAlert size={30} color={palette.violet600} />}
          title="Could not load surveys"
          body="Surveys are unavailable right now. Please try again."
          action={<Action full onPress={retry} loading={query.isFetching}>Retry</Action>}
        />
      ) : items.length === 0 ? (
        <EmptyState
          icon={<ClipboardList size={30} color={palette.violet600} />}
          title={noSurveys ? 'No surveys yet' : `No ${status} surveys`}
          body={noSurveys
            ? 'When the guidance center publishes an assessment for you, it will appear here.'
            : status === 'available'
              ? 'You have no surveys waiting for a response. Check All for other surveys.'
              : 'Your submitted surveys will appear here. Check Available for surveys you can answer.'}
          action={!noSurveys ? <Action full variant="secondary" onPress={() => setStatus('all')}>View all surveys</Action> : undefined}
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
          onRefresh={retry}
        />
      )}
    </Screen>
  );
}

function SurveyCard({ item, onOpen }: { item: SurveyListItem; onOpen: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={`Start survey: ${item.title}`} onPress={onOpen} style={({ pressed }) => [styles.card, pressed && styles.pressed]}>
      <View style={styles.cardTop}>
        <View style={styles.cardIcon}><MessageSquareText size={21} color={palette.violet600} /></View>
        <StatusPill tone={item.ends_at ? 'warning' : 'neutral'}>
          {item.ends_at ? `Due ${formatDate(item.ends_at)}` : 'Open'}
        </StatusPill>
      </View>
      <Text style={styles.cardTitle} numberOfLines={2}>{item.title}</Text>
      {item.description ? (
        <Text style={styles.cardBody} numberOfLines={2}>
          {item.description}
        </Text>
      ) : null}
      <View style={styles.cardMeta}>
        <View style={styles.metaItem}>
          <ShieldCheck size={15} color={theme.ink500} />
          <Text style={styles.metaText}>Identified response</Text>
        </View>
        {!item.ends_at ? (
          <View style={styles.metaItem}>
            <Clock3 size={15} color={theme.ink500} />
            <Text style={styles.metaText}>No deadline published</Text>
          </View>
        ) : null}
      </View>
      <View style={styles.cardLink}>
        <Text style={styles.cardLinkText}>Start survey</Text>
        <ChevronRight size={16} color={palette.violet600} />
      </View>
    </Pressable>
  );
}

function CompletedRow({ item }: { item: SurveyListItem }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.completedRow}>
      {item.submitted ? <CircleCheckBig size={20} color={theme.brandIcon} /> : <Clock3 size={20} color={theme.ink500} />}
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

function createStyles(theme: Theme) {
  return StyleSheet.create({
    summary: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 11,
      padding: 13,
      borderRadius: 14,
      backgroundColor: palette.violet600,
    },
    summaryIcon: { width: 24, height: 28, alignItems: 'center', justifyContent: 'center' },
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
    card: { marginBottom: 10, padding: 15, borderRadius: 15, backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.line, ...elevation.sm },
    cardIcon: { width: 24, height: 28, alignItems: 'center', justifyContent: 'center' },
    fetchError: { gap: 8, padding: 12, marginBottom: 12, borderWidth: 1, borderColor: theme.line, borderRadius: 12, backgroundColor: theme.surface },
    fetchErrorText: { fontFamily: font.body, fontSize: 10.5, lineHeight: 16, color: theme.ink700 },
    pressed: { opacity: 0.75 },
    cardTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8, marginBottom: 10 },
    cardTitle: { fontSize: 11.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    cardBody: { marginTop: 5, color: theme.ink500, fontSize: 10, lineHeight: 14, fontFamily: font.body },
    cardMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 15, marginTop: 11 },
    metaItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
    metaText: { color: theme.ink500, fontSize: 9, fontFamily: font.body },
    cardLink: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 5, padding: 9, borderTopWidth: 1, borderColor: theme.line, marginTop: 12, marginHorizontal: -15, marginBottom: -15 },
    cardLinkText: { color: palette.violet600, fontSize: 10.5, fontWeight: '700', fontFamily: font.body },
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

const makeStyles = memoizeStyles(createStyles);
