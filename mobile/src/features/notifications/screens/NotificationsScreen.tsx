import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Bell, BellRing, CalendarCheck2, ClipboardList, Megaphone } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { NotificationItem } from '../../../api/endpoints/notifications';
import { poll } from '../../../api/poll';
import { Theme, font, palette, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate, formatTime } from '../../../lib/format';
import { useMarkAllNotificationsRead, useMarkNotificationRead, useNotificationHistory } from '../hooks';
import { navigateAuthorizedIntent } from '../../workspace/NavigationIntentGate';
import { useWorkspace } from '../../workspace/WorkspaceContext';
import { useAuth } from '../../../store/auth';
import { notificationDestination, NotificationDestination } from '../targets';
import { RootStackParamList } from '../../../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;

function openDestination(
  destination: NotificationDestination,
  profile: ReturnType<typeof useAuth>['profile'],
  openAuthorizedUnit: ReturnType<typeof useWorkspace>['openAuthorizedUnit'],
): void {
  navigateAuthorizedIntent(destination, profile, openAuthorizedUnit);
}

export function NotificationsScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Nav>();
  const { profile } = useAuth();
  const { openAuthorizedUnit } = useWorkspace();
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<'all' | 'unread'>('all');
  const query = useNotificationHistory(page, filter);
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();

  const data = query.data?.data;
  const items = data?.items ?? [];
  const unread = data?.unread_count;
  const currentPage = data?.page ?? page;
  const busy = markRead.isPending || markAll.isPending;
  const mutationError = markRead.isError || markAll.isError;

  return (
    <Screen scroll={false}>
      <PageHeader
        title="Notifications"
        subtitle={unread === undefined ? 'Your in-app updates' : unread > 0 ? `${unread >= 100 ? '100+' : unread} unread` : 'You are all caught up'}
        onBack={navigation.goBack}
        action={
          unread !== undefined && unread > 0 ? (
            <Action variant="secondary" onPress={() => { markRead.reset(); markAll.mutate(); }} loading={markAll.isPending} disabled={busy}>Mark all read</Action>
          ) : undefined
        }
      />
      <View style={styles.filters}>
        {(['all', 'unread'] as const).map(value => (
          <Pressable
            key={value}
            accessibilityRole="button"
            accessibilityState={{ selected: filter === value }}
            onPress={() => { setFilter(value); setPage(1); }}
            style={[styles.filter, filter === value && styles.filterActive]}>
            <Text style={[styles.filterText, filter === value && styles.filterTextActive]}>{value === 'all' ? 'All' : 'Unread'}</Text>
          </Pressable>
        ))}
      </View>
      {query.isError ? (
        <View style={styles.feedback}>
          <Text accessibilityRole="alert" style={styles.feedbackText}>
            {data ? 'Could not refresh notifications. Showing saved updates.' : 'Could not load notifications.'}
          </Text>
          <Action variant="secondary" onPress={() => { query.refetch().catch(() => undefined); }} loading={query.isFetching}>Retry</Action>
        </View>
      ) : null}
      {mutationError ? (
        <View style={styles.feedback}>
          <Text accessibilityRole="alert" style={styles.feedbackText}>Could not update read status. Please try again.</Text>
          <Action
            variant="secondary"
            disabled={busy}
            onPress={() => {
              if (markAll.isError) {
                markAll.mutate();
              } else if (markRead.variables !== undefined) {
                markRead.mutate(markRead.variables);
              }
            }}>Retry marking read</Action>
        </View>
      ) : null}
      {query.isLoading ? (
        <ActivityIndicator style={styles.loader} color={theme.brandIcon} />
      ) : data && items.length === 0 && !query.isError ? (
        <EmptyState
          icon={<BellRing size={30} color={theme.brandIcon} />}
          title={filter === 'unread' ? 'No unread notifications' : 'Nothing yet'}
          body="Appointment confirmations, referrals, announcements, and survey invites appear here when available."
        />
      ) : data ? (
        <FlatList
          key={`${filter}:${currentPage}`}
          data={items}
          keyExtractor={(item) => String(item.id)}
          // Cancel the Screen's 18px gutter so rows and their dividers run edge to edge.
          style={styles.listBleed}
          renderItem={({ item }) => {
            const destination = notificationDestination(item);
            return (
              <NotificationRow
                item={item}
                disabled={busy}
                onRead={() => { markAll.reset(); markRead.mutate(item.id); }}
                onOpen={destination ? () => openDestination(destination, profile, openAuthorizedUnit) : undefined}
              />
            );
          }}
          contentContainerStyle={styles.list}
          refreshing={query.isRefetching}
          onRefresh={() => { query.refetch().catch(() => undefined); }}
        />
      ) : null}
      {(data && data.pages > 1) || page > 1 ? (
        <View style={styles.paging}>
          <Action variant="secondary" disabled={currentPage <= 1 || query.isFetching || busy} onPress={() => setPage(currentPage - 1)}>Previous</Action>
          <Text style={styles.pageLabel}>Page {currentPage}{data ? ` of ${data.pages}` : ''}</Text>
          <Action variant="secondary" disabled={!data || currentPage >= data.pages || query.isFetching || busy} onPress={() => setPage(currentPage + 1)}>Next</Action>
        </View>
      ) : null}
      <Text style={styles.footer}>Checks for in-app updates every {poll.notificationsMs / 1000}s while this screen is open.</Text>
    </Screen>
  );
}

function NotificationRow({ item, disabled, onRead, onOpen }: {
  item: NotificationItem;
  disabled: boolean;
  onRead: () => void;
  onOpen?: () => void;
}) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const unread = item.read_at === null;
  const openable = onOpen !== undefined;
  const visual: NotificationVisual = VISUALS[item.title] ?? { icon: Bell, color: palette.blue600 };

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${item.title}. ${item.message}. ${openable
        ? (unread ? 'Opens the related item and marks it read' : 'Opens the related item')
        : (unread ? 'Mark as read' : 'Read')}`}
      accessibilityState={{ disabled: disabled || (!unread && !openable) }}
      disabled={disabled || (!unread && !openable)}
      onPress={() => { if (unread) { onRead(); } onOpen?.(); }}
      style={[styles.row, unread && styles.rowUnread]}>
      <View style={styles.rowIcon}>
        <visual.icon size={18} color={visual.color ?? theme.brandIcon} />
      </View>
      <View style={styles.rowCopy}>
        <Text style={styles.rowTitle}>{item.title}</Text>
        <Text style={styles.rowMessage}>{item.message}</Text>
        <Text style={styles.rowTime}>{formatDate(item.created_at)} · {formatTime(item.created_at)}</Text>
      </View>
      {unread ? <View style={styles.unreadDot} /> : null}
    </Pressable>
  );
}

interface NotificationVisual {
  icon: typeof Bell;
  color?: string;

}

const VISUALS: Record<string, NotificationVisual> = {
  'Appointment confirmed': { icon: CalendarCheck2 },
  'New announcement': { icon: Megaphone, color: palette.gold600 },
  'New survey': { icon: ClipboardList, color: palette.violet600 },
};

function createStyles(theme: Theme) {
  return StyleSheet.create({
    loader: { marginTop: 30 },
    list: { paddingBottom: 10 },
    listBleed: { marginHorizontal: -18 },
    filters: { flexDirection: 'row', gap: 7, marginBottom: 12 },
    filter: { paddingHorizontal: 12, paddingVertical: 7, borderRadius: 20, backgroundColor: theme.surface, borderWidth: 1, borderColor: theme.line },
    filterActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    filterText: { fontSize: 9.5, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    filterTextActive: { color: '#ffffff' },
    feedback: { gap: 10, paddingVertical: 12 },
    feedbackText: { color: theme.ink700, fontFamily: font.body, fontSize: 11.5 },
    paging: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 6, paddingTop: 10 },
    pageLabel: { color: theme.ink500, fontFamily: font.body, fontSize: 10 },
    // Rows fill the list, which is itself bled past the Screen gutter.
    row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12, paddingHorizontal: 18, borderBottomWidth: 1, borderBottomColor: theme.line },
    rowUnread: { backgroundColor: theme.brand50 },
    rowIcon: { width: 24, height: 28, alignItems: 'center', justifyContent: 'center' },
    rowCopy: { flex: 1, minWidth: 0 },
    rowTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    rowMessage: { marginTop: 2, color: theme.ink500, fontSize: 9, lineHeight: 13, fontFamily: font.body },
    rowTime: { marginTop: 5, color: theme.ink400, fontSize: 8, fontFamily: font.body },
    unreadDot: { width: 7, height: 7, borderRadius: 999, backgroundColor: theme.brand },
    footer: { paddingTop: 10, textAlign: 'center', color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
