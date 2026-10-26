import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Megaphone } from 'lucide-react-native';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { AnnouncementListItem } from '../../../api/endpoints/announcements';
import { Theme, font, palette } from '../../../theme/tokens';
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

  const unit = filter === 'clinic' || filter === 'guidance' ? filter : 'all';
  const status = filter === 'unread' ? 'unread' : 'all';
  const query = useAnnouncements({ unit, status, page: 1 });
  const items = query.data?.data ?? [];

  return (
    <Screen scroll={false}>
      <PageHeader title="Announcements" subtitle="Campus health and guidance updates" onBack={navigation.goBack} />
      <View style={styles.chips}>
        {FILTERS.map((chip) => (
          <Pressable key={chip.key} onPress={() => setFilter(chip.key)} style={[styles.chip, filter === chip.key && styles.chipActive]}>
            <Text style={[styles.chipText, filter === chip.key && styles.chipTextActive]}>{chip.label}</Text>
          </Pressable>
        ))}
      </View>

      {query.isLoading ? (
        <ActivityIndicator style={styles.loader} color={theme.brand} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={<Megaphone size={30} color={theme.brand} />}
          title="No announcements"
          body="Updates from the clinic and guidance centers will appear here as they are published."
        />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => <AnnouncementRow item={item} onOpen={() => navigation.navigate('AnnouncementDetail', { id: item.id })} />}
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

function AnnouncementRow({ item, onOpen }: { item: AnnouncementListItem; onOpen: () => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const unread = item.read_at === null;
  const isGuidance = item.unit === 'guidance';

  return (
    <Pressable onPress={onOpen} style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
      <View style={[styles.rowIcon, isGuidance && styles.rowIconGuidance]}>
        <Megaphone size={19} color={isGuidance ? palette.violet600 : palette.brand700} />
      </View>
      <View style={styles.rowCopy}>
        <Text style={styles.rowMeta}>
          {unitLabel(item.unit).toUpperCase()} · {formatDate(item.published_at)}
        </Text>
        <Text style={styles.rowTitle} numberOfLines={2}>
          {item.title}
        </Text>
        <Text style={styles.rowBody} numberOfLines={1}>
          {item.excerpt}
        </Text>
      </View>
      {unread ? <View style={styles.unreadDot} /> : null}
    </Pressable>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    chips: { flexDirection: 'row', gap: 7, marginBottom: 12 },
    chip: {
      paddingHorizontal: 12,
      paddingVertical: 7,
      borderRadius: 20,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    chipActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    chipText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    chipTextActive: { color: '#ffffff' },
    loader: { marginTop: 30 },
    list: { paddingBottom: 20 },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingVertical: 12,
      borderBottomWidth: 1,
      borderBottomColor: theme.line,
    },
    pressed: { opacity: 0.7 },
    rowIcon: {
      width: 44,
      height: 44,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      backgroundColor: theme.brand100,
    },
    rowIconGuidance: { backgroundColor: palette.violet100 },
    rowCopy: { flex: 1, minWidth: 0 },
    rowMeta: { color: theme.brand, fontSize: 8, fontWeight: '700', fontFamily: font.body, letterSpacing: 0.5 },
    rowTitle: { marginTop: 3, fontSize: 11, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    rowBody: { marginTop: 2, color: theme.ink500, fontSize: 9, fontFamily: font.body },
    unreadDot: { width: 7, height: 7, borderRadius: 999, backgroundColor: theme.brand },
  });
}
