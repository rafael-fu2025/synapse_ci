import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Droplets, HeartPulse, Phone, ShieldAlert } from 'lucide-react-native';
import { DetailRow } from '../../../components/DetailRow';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { useQuery } from '@tanstack/react-query';
import { HealthSummary, healthSummary } from '../../../api/endpoints/health';
import { Theme, font, palette } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { ProfileStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<ProfileStackParamList, 'HealthProfile'>;

export function HealthProfileScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const query = useQuery({ queryKey: ['health-summary'], queryFn: healthSummary });
  const data: HealthSummary | undefined = query.data?.data;

  if (query.isLoading) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Health profile" onBack={navigation.goBack} />
        <ActivityIndicator color={theme.brand} />
      </Screen>
    );
  }
  if (!data) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Health profile" onBack={navigation.goBack} />
        <Text style={styles.missing}>Your health summary is unavailable right now.</Text>
      </Screen>
    );
  }

  const allergies = data.health.allergies && data.health.allergies.trim() !== '';
  const chronic = data.health.chronic_conditions && data.health.chronic_conditions.trim() !== '';

  return (
    <Screen>
      <PageHeader title="Health profile" subtitle="Shown to clinic staff during consultations" onBack={navigation.goBack} />

      <LinearGradient colors={['#520000', '#800000', '#a52222']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.idCard}>
        <Text style={styles.idLabel}>SYNAPSE HEALTH ID</Text>
        <Text style={styles.idName}>{data.person.name}</Text>
        <Text style={styles.idNumber}>
          {data.person.identifier ?? '—'} · {data.person.kind === 'student' ? 'Student' : 'Employee'}
        </Text>
        <View style={styles.idGrid}>
          <View style={styles.idCell}>
            <Text style={styles.idCellLabel}>BLOOD TYPE</Text>
            <Text style={styles.idCellValue}>{data.health.blood_type ?? 'Not set'}</Text>
          </View>
          <View style={styles.idCell}>
            <Text style={styles.idCellLabel}>DEPARTMENT</Text>
            <Text style={styles.idCellValue}>{data.person.department ?? '—'}</Text>
          </View>
          <View style={styles.idCell}>
            <Text style={styles.idCellLabel}>{data.person.kind === 'student' ? 'PROGRAM' : 'POSITION'}</Text>
            <Text style={styles.idCellValue}>
              {data.person.kind === 'student' ? data.person.program ?? '—' : data.person.position ?? '—'}
            </Text>
          </View>
          <View style={styles.idCell}>
            <Text style={styles.idCellLabel}>ALLERGIES</Text>
            <Text style={styles.idCellValue}>{allergies ? 'On record' : 'None recorded'}</Text>
          </View>
        </View>
      </LinearGradient>

      {allergies ? (
        <View style={[styles.flag, styles.flagWarning]}>
          <ShieldAlert size={16} color="#8a5e0c" />
          <View style={styles.flagCopy}>
            <Text style={styles.flagTitle}>Allergies on record</Text>
            <Text style={styles.flagBody}>{data.health.allergies}</Text>
          </View>
        </View>
      ) : null}
      {chronic ? (
        <View style={styles.flag}>
          <HeartPulse size={16} color={palette.brand700} />
          <View style={styles.flagCopy}>
            <Text style={styles.flagTitle}>Chronic conditions</Text>
            <Text style={styles.flagBody}>{data.health.chronic_conditions}</Text>
          </View>
        </View>
      ) : null}

      <View style={styles.card}>
        <DetailRow icon={<Droplets size={16} color={theme.brand} />} label="BLOOD TYPE" value={data.health.blood_type ?? 'Not set'} />
        <DetailRow
          icon={<Phone size={16} color={theme.brand} />}
          label="EMERGENCY CONTACT"
          value={
            data.health.emergency_contact_name
              ? `${data.health.emergency_contact_name}${data.health.emergency_contact_relationship ? ` (${data.health.emergency_contact_relationship})` : ''} · ${data.health.emergency_contact_phone ?? ''}`
              : 'Not set'
          }
          last
        />
      </View>

      <Text style={styles.note}>
        Only you and authorized clinic staff can view this summary. Update your emergency contact through the clinic
        during your next visit.
      </Text>
    </Screen>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    missing: { color: theme.ink500, fontFamily: font.body, fontSize: 11.5 },
    idCard: {
      minHeight: 242,
      padding: 18,
      borderRadius: 18,
      elevation: 8,
      shadowColor: '#800000',
      shadowOpacity: 0.23,
      shadowRadius: 30,
      shadowOffset: { width: 0, height: 14 },
    },
    idLabel: { color: '#f2cece', fontSize: 8, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    idName: { marginTop: 20, maxWidth: 250, fontSize: 19, fontWeight: '800', lineHeight: 23, color: '#ffffff', fontFamily: font.display },
    idNumber: { marginTop: 4, color: '#f0cccc', fontSize: 9, fontFamily: font.body },
    idGrid: { marginTop: 'auto', flexDirection: 'row', flexWrap: 'wrap', gap: 10, paddingTop: 20 },
    idCell: { minWidth: '46%', flexGrow: 1 },
    idCellLabel: { color: '#e7baba', fontSize: 7, fontWeight: '700', letterSpacing: 0.6, fontFamily: font.body },
    idCellValue: { marginTop: 2, color: '#ffffff', fontSize: 9, fontFamily: font.body },
    flag: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      marginTop: 10,
      padding: 12,
      borderRadius: 12,
      backgroundColor: theme.brand50,
      borderWidth: 1,
      borderColor: theme.brand100,
    },
    flagWarning: { backgroundColor: palette.gold100, borderColor: '#ecd397' },
    flagCopy: { flex: 1 },
    flagTitle: { fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    flagBody: { marginTop: 2, color: theme.ink500, fontSize: 9, fontFamily: font.body },
    card: { marginTop: 14, paddingHorizontal: 14 },
    note: { marginTop: 16, color: theme.ink500, fontSize: 9, lineHeight: 14, fontFamily: font.body },
  });
}
