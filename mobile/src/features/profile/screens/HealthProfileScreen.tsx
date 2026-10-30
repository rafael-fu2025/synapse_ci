import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { CircleAlert, HeartPulse, LockKeyhole, Phone, WifiOff } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { DetailRow } from '../../../components/DetailRow';
import { EmptyState } from '../../../components/EmptyState';
import { PageHeader } from '../../../components/PageHeader';
import { ProfileCard } from '../../../components/ProfileCard';
import { Screen } from '../../../components/Screen';
import { Theme, font, memoizeStyles, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useHealthSummary } from '../hooks';
import { profileCardFromSummary } from '../profileCardModel';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'HealthProfile'>;

function filled(value: string | null | undefined): string | null {
  const text = value?.trim();
  return text ? text : null;
}

export function HealthProfileScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const query = useHealthSummary();
  const data = query.data?.data;

  if (!data) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Health profile" subtitle="University profile and medical flags" onBack={navigation.goBack} />
        {query.isLoading ? (
          <ActivityIndicator color={theme.brandIcon} />
        ) : (
          <EmptyState
            icon={<WifiOff size={30} color={theme.brandIcon} />}
            title="Health profile unavailable"
            body="We couldn’t load your health summary right now. Check your connection and try again."
            action={
              <Action full onPress={() => query.refetch().catch(() => undefined)}>
                Try again
              </Action>
            }
          />
        )}
      </Screen>
    );
  }

  const allergies = filled(data.health.allergies);
  const chronic = filled(data.health.chronic_conditions);
  const contactName = filled(data.health.emergency_contact_name);
  const contactPhone = filled(data.health.emergency_contact_phone);
  const relationship = filled(data.health.emergency_contact_relationship);
  const card = profileCardFromSummary(data);

  return (
    <Screen>
      <PageHeader title="Health profile" subtitle="University profile and medical flags" onBack={navigation.goBack} />

      <ProfileCard {...card} />

      <Text style={styles.sectionLabel}>MEDICAL FLAGS</Text>
      {allergies ? (
        <View style={[styles.flag, styles.flagWarning]}>
          <CircleAlert size={20} color={palette.gold600} />
          <View style={styles.flagCopy}>
            <Text style={styles.flagTitle}>Allergy recorded</Text>
            <Text style={styles.flagBody}>{allergies}</Text>
          </View>
        </View>
      ) : (
        <View style={styles.flag}>
          <HeartPulse size={20} color={theme.brandIcon} />
          <View style={styles.flagCopy}>
            <Text style={styles.flagTitle}>No allergies recorded</Text>
            <Text style={styles.flagBody}>Tell the clinic about any allergy at your next visit.</Text>
          </View>
        </View>
      )}
      {chronic ? (
        <View style={styles.flag}>
          <HeartPulse size={20} color={theme.brandIcon} />
          <View style={styles.flagCopy}>
            <Text style={styles.flagTitle}>Chronic conditions</Text>
            <Text style={styles.flagBody}>{chronic}</Text>
          </View>
        </View>
      ) : null}

      <Text style={styles.sectionLabel}>EMERGENCY CONTACT</Text>
      {contactName || contactPhone ? (
        <View style={styles.card}>
          <DetailRow
            icon={<Phone size={17} color={theme.brandIcon} />}
            label="Contact person"
            value={`${contactName ?? '—'}${relationship ? ` (${relationship})` : ''}`}
          />
          <DetailRow icon={<Phone size={17} color={theme.brandIcon} />} label="Phone" value={contactPhone ?? '—'} last />
        </View>
      ) : (
        <View style={styles.flag}>
          <Phone size={20} color={theme.brandIcon} />
          <View style={styles.flagCopy}>
            <Text style={styles.flagTitle}>No emergency contact recorded yet</Text>
            <Text style={styles.flagBody}>Ask the clinic to add one so staff know who to reach in an emergency.</Text>
          </View>
        </View>
      )}

      <View style={styles.privacy}>
        <LockKeyhole size={18} color={palette.violet600} />
        <Text style={styles.privacyText}>
          This summary is shown only to you and authorized health staff. Contact the university office to correct
          identity details.
        </Text>
      </View>
    </Screen>
  );
}

function createStyles(theme: Theme) {
  const dark = theme.mode === 'dark';
  return StyleSheet.create({
    sectionLabel: { marginHorizontal: 2, marginTop: 18, marginBottom: 7, color: theme.ink400, fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    flag: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 11,
      marginBottom: 8,
      padding: 12,
      borderRadius: 12,
      backgroundColor: theme.surface,
      borderWidth: 1,
      borderColor: theme.line,
    },
    flagWarning: { backgroundColor: dark ? theme.surfaceWarm : palette.gold100, borderColor: '#ecd397' },
    flagCopy: { flex: 1 },
    flagTitle: { fontSize: 11.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    flagBody: { marginTop: 2, color: theme.ink500, fontSize: 10, lineHeight: 14, fontFamily: font.body },
    card: {
      paddingHorizontal: 14,
      borderRadius: 13,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    privacy: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 9,
      marginTop: 12,
      padding: 11,
      borderRadius: 11,
      backgroundColor: dark ? theme.surfaceWarm : '#eeedf5',
    },
    privacyText: { flex: 1, color: dark ? theme.ink700 : '#4f5268', fontSize: 10, lineHeight: 14.5, fontFamily: font.body },
  });
}

const makeStyles = memoizeStyles(createStyles);
