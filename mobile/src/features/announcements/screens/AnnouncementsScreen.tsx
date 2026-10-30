import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { BookOpenText, HeartPulse, Megaphone } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { AnnouncementListItem } from '../../../api/endpoints/announcements';
import { Theme, font, palette, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate } from '../../../lib/format';
import { useAnnouncements } from '../hooks';
import { unitLabel } from '../../appointments/status';
import { RootStackParamList } from '../../../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;

const FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'clinic', label: 'Clinic' },
  { key: 'guidance', label: 'Guidance' },
  { key: 'unread', label: 'Unread' },
] as const;

export function AnnouncementsScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Nav>();
  const [filter, setFilter] = useState<string>('all');
  const [page, setPage] = useState(1);

  const unit = filter === 'clinic' || filter === 'guidance' ? filter : 'all';
  const status = filter === 'unread' ? 'unread' : 'all';
  const query = useAnnouncements({ unit, status, page });
  const items = query.data?.data ?? [];
  const currentPage = query.data?.meta.page ?? page;
  const pages = query.data?.meta.pages;
  // There is no featured flag in the API: spotlight the first returned story,
  // only on page one, without duplicating it in the latest rows.
  const featured = currentPage === 1 ? items[0] : undefined;
  const latest = featured ? items.slice(1) : items;
  const open = (item: AnnouncementListItem) => navigation.navigate('AnnouncementDetail', { id: item.id });

  return (
    <Screen scroll={false}>
      <PageHeader title="Announcements" subtitle="Campus health and guidance updates" onBack={navigation.goBack} />
      <View style={styles.chips}>
        {FILTERS.map((chip) => (
          <Pressable
            key={chip.key}
            accessibilityRole="button"
            accessibilityState={{ selected: filter === chip.key }}
            onPress={() => { setFilter(chip.key); setPage(1); }}
            style={[styles.chip, filter === chip.key && styles.chipActive]}>
            <Text style={[styles.chipText, filter === chip.key && styles.chipTextActive]}>{chip.label}</Text>
          </Pressable>
        ))}
      </View>

      {query.isError ? (
        <View style={styles.feedback}>
          <Text accessibilityRole="alert" style={styles.feedbackText}>
            {query.data ? 'Could not refresh announcements. Showing saved updates.' : 'Could not load announcements.'}
          </Text>
          <Action variant="secondary" onPress={() => { query.refetch().catch(() => undefined); }} loading={query.isFetching}>Retry</Action>
        </View>
      ) : null}
      {query.isLoading ? (
        <ActivityIndicator style={styles.loader} color={theme.brandIcon} />
      ) : query.data && items.length === 0 && !query.isError ? (
        <EmptyState
          icon={<Megaphone size={30} color={theme.brandIcon} />}
          title="No announcements"
          body="Updates from the clinic and guidance centers will appear here as they are published."
        />
      ) : query.data ? (
        <FlatList
          key={`${filter}:${currentPage}`}
          data={latest}
          ListHeaderComponent={
            <>
              {featured ? <FeaturedStory item={featured} onOpen={() => open(featured)} /> : null}
              {latest.length > 0 ? <Text style={styles.latestLabel}>{currentPage === 1 ? 'LATEST' : 'MORE UPDATES'}</Text> : null}
            </>
          }
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => <AnnouncementRow item={item} onOpen={() => open(item)} />}
          contentContainerStyle={styles.list}
          refreshing={query.isRefetching}
          onRefresh={() => { query.refetch().catch(() => undefined); }}
        />
      ) : null}
      {(pages !== undefined && pages > 1) || page > 1 ? (
        <View style={styles.paging}>
          <Action variant="secondary" disabled={currentPage <= 1 || query.isFetching} onPress={() => setPage(currentPage - 1)}>Previous</Action>
          <Text style={styles.pageLabel}>Page {currentPage}{pages !== undefined ? ` of ${pages}` : ''}</Text>
          <Action variant="secondary" disabled={pages === undefined || currentPage >= pages || query.isFetching} onPress={() => setPage(currentPage + 1)}>Next</Action>
        </View>
      ) : null}
    </Screen>
  );
}

function FeaturedStory({ item, onOpen }: { item: AnnouncementListItem; onOpen: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const Icon = item.unit === 'clinic' ? HeartPulse : BookOpenText;
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={item.title} onPress={onOpen} style={({ pressed }) => [styles.featured, pressed && styles.pressed]}>
      <LinearGradient colors={[palette.brand900, '#9d2020']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.featuredArt}>
        <Icon size={44} color="#ffffff" />
        <Text style={styles.featuredUnit}>{unitLabel(item.unit).toUpperCase()}</Text>
      </LinearGradient>
      <View style={styles.featuredBody}>
        <View style={styles.featuredMeta}>
          <Text style={styles.featuredBadge}>Featured</Text>
          {item.read_at === null ? <Text style={styles.rowMeta}>Unread</Text> : null}
        </View>
        <Text style={styles.featuredTitle}>{item.title}</Text>
        <Text style={styles.featuredExcerpt} numberOfLines={2}>{item.excerpt}</Text>
        <Text style={styles.featuredTime}>{formatDate(item.published_at)}</Text>
      </View>
    </Pressable>
  );
}

function AnnouncementRow({ item, onOpen }: { item: AnnouncementListItem; onOpen: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const unread = item.read_at === null;
  const isGuidance = item.unit === 'guidance';

  return (
    <Pressable accessibilityRole="button" accessibilityLabel={item.title} onPress={onOpen} style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
      <View style={styles.rowIcon}>
        <BookOpenText size={22} color={isGuidance ? palette.violet600 : theme.brandIcon} />
      </View>
      <View style={styles.rowCopy}>
        <Text style={styles.rowMeta}>{unitLabel(item.unit).toUpperCase()} · {formatDate(item.published_at)}</Text>
        <Text style={styles.rowTitle} numberOfLines={2}>{item.title}</Text>
        <Text style={styles.rowBody} numberOfLines={1}>{item.excerpt}</Text>
      </View>
      {unread ? <View accessibilityLabel="Unread" style={styles.unreadDot} /> : null}
    </Pressable>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 7, marginBottom: 12 },
    chip: { paddingHorizontal: 12, paddingVertical: 7, borderRadius: 20, backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.line },
    chipActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    chipText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    chipTextActive: { color: '#ffffff' },
    loader: { marginTop: 30 },
    list: { paddingBottom: 20 },
    feedback: { gap: 10, paddingVertical: 12 },
    feedbackText: { color: theme.ink700, fontFamily: font.body, fontSize: 11.5 },
    paging: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 6, paddingTop: 10 },
    pageLabel: { color: theme.ink500, fontFamily: font.body, fontSize: 10 },
    featured: { overflow: 'hidden', borderRadius: 16, backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.line },
    featuredArt: { minHeight: 105, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 13, padding: 13 },
    featuredUnit: { color: '#ffffff', fontFamily: font.display, fontSize: 14, fontWeight: '800', letterSpacing: 1.3 },
    featuredBody: { padding: 13 },
    featuredMeta: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    featuredBadge: { color: palette.blue600, backgroundColor: palette.blue100, borderRadius: 8, paddingHorizontal: 8, paddingVertical: 4, fontSize: 9, fontFamily: font.body, fontWeight: '700' },
    featuredTitle: { marginTop: 7, fontSize: 15, lineHeight: 21, fontWeight: '700', fontFamily: font.display, color: theme.ink900 },
    featuredExcerpt: { marginTop: 5, color: theme.ink500, fontFamily: font.body, fontSize: 10.5, lineHeight: 16 },
    featuredTime: { marginTop: 9, color: theme.ink400, fontFamily: font.body, fontSize: 8.5 },
    latestLabel: { marginTop: 18, marginBottom: 4, color: theme.ink400, fontSize: 8.5, fontWeight: '700', fontFamily: font.body, letterSpacing: 1 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: theme.line },
    pressed: { opacity: 0.7 },
    rowIcon: { width: 24, height: 28, alignItems: 'center', justifyContent: 'center' },
    rowCopy: { flex: 1, minWidth: 0 },
    rowMeta: { color: theme.brandText, fontSize: 8, fontWeight: '700', fontFamily: font.body, letterSpacing: 0.5 },
    rowTitle: { marginTop: 3, fontSize: 11, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    rowBody: { marginTop: 2, color: theme.ink500, fontSize: 9, fontFamily: font.body },
    unreadDot: { width: 7, height: 7, borderRadius: 999, backgroundColor: theme.brand },
  });
}

const makeStyles = memoizeStyles(createStyles);
