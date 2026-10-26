import React, { useEffect } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { useRoute } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { CalendarDays } from 'lucide-react-native';
import { Screen } from '../../../components/Screen';
import { Theme, font, palette, type } from '../../../theme/tokens';
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

  if (query.isLoading) {
    return (
      <Screen scroll={false}>
        <View style={styles.headerGap} />
        <ActivityIndicator color={theme.brand} />
      </Screen>
    );
  }
  if (!announcement) {
    return (
      <Screen scroll={false}>
        <View style={styles.headerGap} />
        <Text style={styles.missing}>This announcement is no longer available.</Text>
      </Screen>
    );
  }

  return (
    <Screen scroll={false}>
      <LinearGradient colors={[palette.brand900, '#9d2020']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}>
        <Text style={styles.heroUnit}>{unitLabel(announcement.unit).toUpperCase()} ANNOUNCEMENT</Text>
      </LinearGradient>
      <ScrollView contentContainerStyle={styles.article}>
        <Text style={styles.title}>{announcement.title}</Text>
        <View style={styles.metaRow}>
          <CalendarDays size={11} color={theme.ink400} />
          <Text style={styles.metaText}>
            Published {formatDate(announcement.published_at)}
            {announcement.ends_at ? ` · until ${formatDate(announcement.ends_at)}` : ''}
          </Text>
        </View>
        <Text style={styles.body}>{announcement.body}</Text>
      </ScrollView>
    </Screen>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    headerGap: { height: 60 },
    missing: { color: theme.ink500, fontFamily: font.body, fontSize: 11.5 },
    heroUnit: {
      marginHorizontal: -18,
      paddingVertical: 40,
      paddingHorizontal: 18,
      color: '#ffffff',
      fontSize: 13,
      fontWeight: '800',
      letterSpacing: 1.6,
      fontFamily: font.display,
    },
    article: { paddingTop: 17, paddingBottom: 30 },
    title: { ...type.hero, fontSize: 22, lineHeight: 28, color: theme.ink900 },
    metaRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 7 },
    metaText: { color: theme.ink400, fontSize: 9, fontFamily: font.body },
    body: { marginTop: 17, color: theme.ink700, fontSize: 10.5, lineHeight: 18, fontFamily: font.body },
  });
}
