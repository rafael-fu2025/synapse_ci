import React, { useEffect } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { BookOpenText, CalendarDays, HeartPulse } from 'lucide-react-native';
import { ApiError } from '../../../api/client';
import { Action } from '../../../components/Action';
import { PageHeader } from '../../../components/PageHeader';
import { Screen } from '../../../components/Screen';
import { Theme, font, palette, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate } from '../../../lib/format';
import { useAnnouncement, useMarkAnnouncementRead } from '../hooks';
import { unitLabel } from '../../appointments/status';
import { RootStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<RootStackParamList, 'AnnouncementDetail'>;

export function AnnouncementDetailScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const route = useRoute<Props['route']>();
  const navigation = useNavigation<Props['navigation']>();
  const query = useAnnouncement(route.params.id);
  const markRead = useMarkAnnouncementRead();

  const announcement = query.data?.data;

  // Opening the notice is the read signal, exactly like the web portal.
  useEffect(() => {
    if (announcement && announcement.read_at === null && !markRead.isPending) {
      markRead.mutate(announcement.id);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [announcement?.id, announcement?.read_at]);

  const unavailable = query.error instanceof ApiError && query.error.status === 404;
  const Icon = announcement?.unit === 'guidance' ? BookOpenText : HeartPulse;

  return (
    <Screen scroll={false}>
      <PageHeader title="Announcement" onBack={navigation.goBack} />
      {query.isLoading ? <ActivityIndicator color={theme.brandIcon} /> : null}
      {query.isError ? (
        <View style={styles.feedback}>
          <Text accessibilityRole="alert" style={styles.missing}>
            {unavailable ? 'This announcement is no longer available.' : announcement ? 'Could not refresh this announcement. Showing the saved version.' : 'Could not load this announcement.'}
          </Text>
          <Action variant="secondary" onPress={() => { query.refetch().catch(() => undefined); }} loading={query.isFetching}>Retry</Action>
        </View>
      ) : null}
      {!query.isLoading && !query.isError && !announcement ? <Text style={styles.missing}>This announcement is no longer available.</Text> : null}
      {announcement && !unavailable ? (
        <ScrollView contentContainerStyle={styles.article}>
          <LinearGradient colors={[palette.brand900, '#9d2020']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
            <Icon size={48} color="#ffffff" />
            <Text style={styles.heroUnit}>{unitLabel(announcement.unit).toUpperCase()}</Text>
          </LinearGradient>
          <Text style={styles.unit}>{unitLabel(announcement.unit)} announcement</Text>
          <Text style={styles.title}>{announcement.title}</Text>
          <View style={styles.metaRow}>
            <CalendarDays size={11} color={theme.ink400} />
            <Text style={styles.metaText}>
              Published {formatDate(announcement.published_at)}
              {announcement.ends_at ? ` · until ${formatDate(announcement.ends_at)}` : ''}
            </Text>
          </View>
          <Text style={styles.body}>{announcement.body}</Text>
          {markRead.isError ? (
            <View style={styles.feedback}>
              <Text accessibilityRole="alert" style={styles.missing}>Could not mark this announcement as read.</Text>
              <Action variant="secondary" onPress={() => markRead.mutate(announcement.id)} loading={markRead.isPending}>Retry marking read</Action>
            </View>
          ) : null}
        </ScrollView>
      ) : null}
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    feedback: { gap: 10, paddingVertical: 12 },
    hero: { minHeight: 132, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 13, marginBottom: 17, padding: 18 },
    unit: { color: theme.brandText, fontFamily: font.body, fontSize: 9, fontWeight: '700', marginBottom: 10 },
    missing: { color: theme.ink500, fontFamily: font.body, fontSize: 11.5 },
    heroUnit: {
      color: '#ffffff',
      fontSize: 13,
      fontWeight: '800',
      letterSpacing: 1.6,
      fontFamily: font.display,
    },
    article: { paddingBottom: 30 },
    title: { ...type.hero, fontSize: 22, lineHeight: 28, color: theme.ink900 },
    metaRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 7 },
    metaText: { color: theme.ink400, fontSize: 9, fontFamily: font.body },
    body: { marginTop: 17, color: theme.ink700, fontSize: 10.5, lineHeight: 18, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
