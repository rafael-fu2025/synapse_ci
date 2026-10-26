import React from 'react';
import { ActivityIndicator, FlatList, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Bell, BellRing, CalendarCheck2, ClipboardList, Megaphone } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { NotificationItem } from '../../../api/endpoints/notifications';
import { poll } from '../../../api/poll';
import { Theme, font, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate, formatTime } from '../../../lib/format';
import { useMarkAllNotificationsRead, useNotificationHistory, useNotificationInbox } from '../hooks';
import { RootStackParamList } from '../../../navigation/types';

type Nav = NativeStackNavigationProp<RootStackParamList>;

export function NotificationsScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Nav>();
  const inboxQuery = useNotificationInbox();
  const historyQuery = useNotificationHistory(1, 'all');
  const markAll = useMarkAllNotificationsRead();

  const items = historyQuery.data?.data.items ?? inboxQuery.data?.data.items ?? [];
  const unread = historyQuery.data?.data.unread_count ?? inboxQuery.data?.data.unread_count ?? 0;

  return (
    <Screen scroll={false}>
      <PageHeader
        title="Notifications"
        subtitle={unread > 0 ? `${unread} unread` : 'You are all caught up'}
        onBack={navigation.goBack}
        action={
          unread > 0 ? (
            <Action variant="secondary" onPress={() => markAll.mutate()} loading={markAll.isPending}>
              Mark all read
            </Action>
          ) : undefined
        }
      />

      {historyQuery.isLoading && inboxQuery.isLoading ? (
        <ActivityIndicator style={styles.loader} color={theme.brand} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={<BellRing size={30} color={theme.brand} />}
          title="Nothing yet"
          body="Appointment confirmations, referrals, announcements, and survey invites arrive here."
        />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => <NotificationRow item={item} />}
          contentContainerStyle={styles.list}
          refreshing={historyQuery.isRefetching}
          onRefresh={() => {
            historyQuery.refetch().catch(() => undefined);
          }}
        />
      )}
      <Text style={styles.footer}>Refreshes every {poll.notificationsMs / 1000}s</Text>
    </Screen>
  );
}

function NotificationRow({ item }: { item: NotificationItem }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const unread = item.read_at === null;
  const visual: NotificationVisual = VISUALS[item.title] ?? { icon: Bell, color: palette.blue600, background: palette.blue100 };

  return (
    <View style={[styles.row, unread && styles.rowUnread]}>
      <View style={[styles.rowIcon, { backgroundColor: visual.background }]}>
        <visual.icon size={18} color={visual.color} />
      </View>
      <View style={styles.rowCopy}>
        <Text style={styles.rowTitle}>{item.title}</Text>
        <Text style={styles.rowMessage} numberOfLines={2}>
          {item.message}
        </Text>
        <Text style={styles.rowTime}>
          {formatDate(item.created_at)} · {formatTime(item.created_at)}
        </Text>
      </View>
      {unread ? <View style={styles.unreadDot} /> : null}
    </View>
  );
}

interface NotificationVisual {
  icon: typeof Bell;
  color: string;
  background: string;
}

const VISUALS: Record<string, NotificationVisual> = {
  'Appointment confirmed': { icon: CalendarCheck2, color: palette.brand700, background: 'rgba(128,0,0,0.12)' },
  'New announcement': { icon: Megaphone, color: palette.gold600, background: palette.gold100 },
  'New survey': { icon: ClipboardList, color: palette.violet600, background: palette.violet100 },
};

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    loader: { marginTop: 30 },
    list: { paddingBottom: 10 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: theme.line },
    rowUnread: { backgroundColor: theme.brand50, marginHorizontal: -8, paddingHorizontal: 8, borderRadius: 10 },
    rowIcon: { width: 42, height: 42, alignItems: 'center', justifyContent: 'center', borderRadius: 12 },
    rowCopy: { flex: 1, minWidth: 0 },
    rowTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    rowMessage: { marginTop: 2, color: theme.ink500, fontSize: 9, lineHeight: 13, fontFamily: font.body },
    rowTime: { marginTop: 5, color: theme.ink400, fontSize: 8, fontFamily: font.body },
    unreadDot: { width: 7, height: 7, borderRadius: 999, backgroundColor: theme.brand },
    footer: { paddingTop: 10, textAlign: 'center', color: theme.ink400, fontSize: 8.5, fontFamily: font.body },
  });
}
