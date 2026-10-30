import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { BellRing, CircleHelp, ListChecks, RefreshCw, WifiOff } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { EmptyState } from '../../../components/EmptyState';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { IconAction } from '../../../components/IconAction';
import { Theme, font, memoizeStyles, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useQueueSnapshot } from '../hooks';
import { QueueTicketCard } from '../components/QueueTicketCard';
import { formatTime } from '../../../lib/format';

import type { QueueNavigation } from '../../../navigation/types';

export function QueueBoardScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<QueueNavigation>();
  const query = useQueueSnapshot();
  // A held ("skipped") ticket is still the patient's ticket; the web card shows it as On hold.
  const tickets = query.data?.data.tickets ?? [];
  const lastUpdated = query.dataUpdatedAt > 0 ? formatTime(new Date(query.dataUpdatedAt).toISOString()) : null;
  const refresh = (
    <IconAction
      label="Refresh queue"
      onPress={() => {
        query.refetch().catch(() => undefined);
      }}
      icon={<RefreshCw size={19} color={theme.ink700} />}
    />
  );

  if (query.isLoading) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Queue status" subtitle="Live updates from your current visit" />
        <ActivityIndicator style={styles.loader} color={theme.brandIcon} />
      </Screen>
    );
  }

  if (query.isError && !query.data) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Queue status" subtitle="Live updates from your current visit" />
        <EmptyState
          icon={<WifiOff size={30} color={theme.brandIcon} />}
          title="Can’t load your queue"
          body="Check your connection and try again. Your ticket is not affected."
          action={
            <Action full onPress={() => query.refetch().catch(() => undefined)}>
              Try again
            </Action>
          }
        />
      </Screen>
    );
  }

  if (tickets.length === 0) {
    return (
      <Screen>
        <PageHeader title="Queue status" subtitle="Live updates from your current visit" action={refresh} />
        <EmptyState
          icon={<ListChecks size={30} color={theme.brandIcon} />}
          title="You’re not in a queue"
          body="When you check in for a clinic or guidance visit, your live position will appear here."
          action={
            <Action full variant="secondary" onPress={() => navigation.navigate('Visits', { screen: 'Appointments' })}>
              View appointments
            </Action>
          }
        />
        <View style={styles.infoCard}>
          <View style={styles.infoIcon}>
            <CircleHelp size={20} color={palette.blue600} />
          </View>
          <View style={styles.infoCopy}>
            <Text style={styles.infoTitle}>How check-in works</Text>
            <Text style={styles.infoBody}>
              Arrive at your service location and show your university ID. Staff will add you to the queue.
            </Text>
          </View>
        </View>
      </Screen>
    );
  }

  return (
    <Screen>
      <PageHeader
        title="My waiting room"
        subtitle={`${tickets.length} active ticket${tickets.length === 1 ? '' : 's'} today`}
        action={refresh}
      />
      {tickets.map((ticket) => (
        <QueueTicketCard key={`${ticket.unit}-${ticket.ticket_code}`} ticket={ticket} />
      ))}

      <View style={styles.notice}>
        <BellRing size={18} color={palette.blue600} />
        <Text style={styles.noticeText}>
          Each department manages its own queue. Keep this app nearby; staff will call your number when it is your turn.
        </Text>
      </View>
      {lastUpdated ? <Text style={styles.lastUpdated}>Updated {lastUpdated} · refreshes every 15s</Text> : null}
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    loader: { marginTop: 30 },
    lastUpdated: { marginTop: 4, color: theme.ink400, fontSize: 8.5, textAlign: 'center', fontFamily: font.body },
    notice: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 9,
      marginVertical: 13,
      padding: 11,
      borderRadius: 11,
      backgroundColor: theme.mode === 'dark' ? theme.surfaceWarm : palette.blue100,
    },
    noticeText: { flex: 1, color: theme.mode === 'dark' ? theme.ink700 : '#315f74', fontSize: 10, lineHeight: 14.5, fontFamily: font.body },
    infoCard: {
      flexDirection: 'row',
      gap: 11,
      marginTop: 5,
      padding: 14,
      borderRadius: 14,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    infoIcon: { width: 24, height: 28, alignItems: 'center', justifyContent: 'center' },
    infoCopy: { flex: 1, gap: 4 },
    infoTitle: { fontSize: 11, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    infoBody: { color: theme.ink500, fontSize: 9.5, lineHeight: 14, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
