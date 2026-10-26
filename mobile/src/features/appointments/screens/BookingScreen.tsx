import React, { useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Check, ChevronRight, CircleCheckBig, Stethoscope } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { TextField } from '../../../components/TextField';
import { ApiError } from '../../../api/client';
import { CLINIC_TYPES, GUIDANCE_TYPES } from '../../../api/endpoints/appointments';
import { Theme, font, palette, type } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useCreateAppointment } from '../hooks';
import { UNIT_PRESENTATION, unitLabel } from '../status';
import { VisitsStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<VisitsStackParamList, 'Booking'>;
type Step = 'unit' | 'details' | 'review' | 'success';

const TIMES = [
  '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
  '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30',
];

const TYPE_LABELS: Record<string, string> = {
  routine: 'Routine consultation',
  initial: 'First session',
  follow_up: 'Follow-up',
  referral_based: 'Referral visit',
};

interface DayOption {
  date: string;
  weekday: string;
  day: string;
}

function nextDays(count: number): DayOption[] {
  const days: DayOption[] = [];
  const base = new Date();
  for (let offset = 1; days.length < count; offset += 1) {
    const day = new Date(base.getFullYear(), base.getMonth(), base.getDate() + offset);
    const weekday = day.toLocaleDateString('en-PH', { weekday: 'short' });
    days.push({
      date: `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`,
      weekday,
      day: String(day.getDate()),
    });
  }
  return days;
}

export function BookingScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const create = useCreateAppointment();

  const [step, setStep] = useState<Step>('unit');
  const [unit, setUnit] = useState<'clinic' | 'guidance' | null>(null);
  const [date, setDate] = useState<string | null>(null);
  const [time, setTime] = useState<string | null>(null);
  const [visitType, setVisitType] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const days = useMemo(() => nextDays(14), []);
  const types = unit === 'guidance' ? GUIDANCE_TYPES : CLINIC_TYPES;
  const morning = TIMES.filter((value) => value < '12:00');
  const afternoon = TIMES.filter((value) => value >= '12:00');

  async function submit() {
    if (!unit || !date || !time || !visitType) {
      return;
    }
    setError(null);
    try {
      await create.mutateAsync({ unit, date, preferred_time: time, type: visitType, reason: reason.trim() || undefined });
      setStep('success');
    } catch (mutationError) {
      if (mutationError instanceof ApiError) {
        setError(mutationError.message);
      } else {
        setError('The request could not be sent. Check your connection and try again.');
      }
    }
  }

  if (step === 'success') {
    return (
      <Screen scroll={false}>
        <PageHeader title="Request received" onBack={() => navigation.popToTop()} />
        <View style={styles.centered}>
          <View style={styles.successRing}>
            <CircleCheckBig size={34} color="#ffffff" />
          </View>
          <Text style={styles.successTitle}>You&apos;re on the list</Text>
          <Text style={styles.successBody}>
            The {unitLabel(unit ?? 'clinic')} team reviews requests during office hours. You will receive a notification
            once your appointment is confirmed.
          </Text>
          <View style={styles.fullWidth}>
            <Action full onPress={() => navigation.popToTop()}>
              View my visits
            </Action>
          </View>
        </View>
      </Screen>
    );
  }

  return (
    <Screen>
      <PageHeader
        title={step === 'unit' ? 'Choose a service' : step === 'details' ? 'Booking details' : 'Review booking'}
        subtitle={step === 'unit' ? 'Which department do you need?' : undefined}
        onBack={step === 'unit' ? () => navigation.goBack() : () => setStep(step === 'review' ? 'details' : 'unit')}
      />

      {step === 'unit' ? (
        <View>
          {(['clinic', 'guidance'] as const).map((key) => {
            const presentation = UNIT_PRESENTATION[key];
            const selected = unit === key;
            return (
              <Pressable
                key={key}
                onPress={() => {
                  setUnit(key);
                  setVisitType(null);
                  setStep('details');
                }}
                style={[styles.serviceChoice, selected && styles.serviceChoiceSelected]}>
                <View style={[styles.serviceIcon, key === 'guidance' && styles.serviceIconGuidance]}>
                  {key === 'guidance' ? (
                    <Text style={styles.guidanceGlyph}>GC</Text>
                  ) : (
                    <Stethoscope size={22} color={palette.brand700} />
                  )}
                </View>
                <View style={styles.serviceCopy}>
                  <Text style={styles.serviceTitle}>{presentation.label}</Text>
                  <Text style={styles.serviceBody}>
                    {key === 'clinic'
                      ? 'Consultations, vitals, medicine dispensing, and health clearances.'
                      : 'Confidential counselling sessions and developmental assessments.'}
                  </Text>
                </View>
                <ChevronRight size={18} color={theme.ink400} />
              </Pressable>
            );
          })}
        </View>
      ) : null}

      {step === 'details' && unit ? (
        <View>
          <View style={styles.selectedBar}>
            <View style={[styles.selectedIcon, unit === 'guidance' && styles.serviceIconGuidance]}>
              {unit === 'guidance' ? (
                <Text style={styles.guidanceGlyph}>GC</Text>
              ) : (
                <Stethoscope size={18} color={palette.brand700} />
              )}
            </View>
            <View style={styles.selectedCopy}>
              <Text style={styles.selectedKicker}>SELECTED SERVICE</Text>
              <Text style={styles.selectedTitle}>{UNIT_PRESENTATION[unit].label}</Text>
            </View>
          </View>

          <Text style={styles.formLabel}>Preferred date</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.dateStrip}>
            {days.map((day) => {
              const active = date === day.date;
              return (
                <Pressable
                  key={day.date}
                  onPress={() => setDate(day.date)}
                  style={[styles.dateCell, active && styles.dateCellActive]}>
                  <Text style={[styles.dateWeekday, active && styles.dateTextActive]}>{day.weekday}</Text>
                  <Text style={[styles.dateDay, active && styles.dateTextActive]}>{day.day}</Text>
                </Pressable>
              );
            })}
          </ScrollView>

          <Text style={styles.formLabel}>Preferred time</Text>
          <View style={styles.periodRow}>
            <Text style={styles.periodLabel}>Morning</Text>
            <View style={styles.periodLine} />
          </View>
          <View style={styles.timeGrid}>
            {morning.map((value) => (
              <TimeCell key={value} value={value} active={time === value} onPress={setTime} />
            ))}
          </View>
          <View style={styles.periodRow}>
            <Text style={styles.periodLabel}>Afternoon</Text>
            <View style={styles.periodLine} />
          </View>
          <View style={styles.timeGrid}>
            {afternoon.map((value) => (
              <TimeCell key={value} value={value} active={time === value} onPress={setTime} />
            ))}
          </View>

          <Text style={styles.formLabel}>Type of visit</Text>
          <View style={styles.typeRow}>
            {types.map((option) => (
              <Pressable
                key={option}
                onPress={() => setVisitType(option)}
                style={[styles.typeChip, visitType === option && styles.typeChipActive]}>
                <Text style={[styles.typeText, visitType === option && styles.typeTextActive]}>
                  {TYPE_LABELS[option] ?? option}
                </Text>
              </Pressable>
            ))}
          </View>

          <TextField
            label="Reason for the visit (optional)"
            placeholder="Help the staff prepare for your consultation"
            multiline
            value={reason}
            onChangeText={setReason}
          />

          {error ? <Text style={styles.formError}>{error}</Text> : null}

          <View style={styles.actions}>
            <Action
              full
              loading={create.isPending}
              onPress={() => {
                if (date === null || time === null || visitType === null) {
                  setError('Choose a date, time, and visit type to continue.');
                  return;
                }
                setError(null);
                setStep('review');
              }}>
              Review booking
            </Action>
          </View>
        </View>
      ) : null}

      {step === 'review' && unit && date && time && visitType ? (
        <View>
          <View style={styles.reviewBanner}>
            <View style={[styles.selectedIcon, unit === 'guidance' && styles.serviceIconGuidance]}>
              {unit === 'guidance' ? (
                <Text style={styles.guidanceGlyph}>GC</Text>
              ) : (
                <Stethoscope size={18} color={palette.brand700} />
              )}
            </View>
            <View style={styles.selectedCopy}>
              <Text style={styles.selectedKicker}>REVIEW</Text>
              <Text style={styles.selectedTitle}>{UNIT_PRESENTATION[unit].label}</Text>
            </View>
          </View>
          <View style={styles.checkList}>
            <CheckRow label="Preferred date" value={formatDay(date, days)} />
            <CheckRow label="Preferred time" value={`${time} (30 minutes)`} />
            <CheckRow label="Visit type" value={TYPE_LABELS[visitType] ?? visitType} />
            {reason.trim() !== '' ? <CheckRow label="Reason" value={reason.trim()} /> : null}
          </View>
          <View style={styles.notice}>
            <Check size={14} color={theme.brand} />
            <Text style={styles.noticeText}>
              Requests are reviewed during office hours. Your slot is reserved once the department confirms.
            </Text>
          </View>
          {error ? <Text style={styles.formError}>{error}</Text> : null}
          <View style={styles.actions}>
            <Action full loading={create.isPending} onPress={submit}>
              Submit request
            </Action>
          </View>
        </View>
      ) : null}
    </Screen>
  );
}

function formatDay(date: string, days: DayOption[]): string {
  const match = days.find((day) => day.date === date);
  return match ? `${match.weekday}, ${match.day}` : date;
}

function TimeCell({ value, active, onPress }: { value: string; active: boolean; onPress: (value: string) => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const label = `${value.replace(':00', '').replace(':30', ':30')}`;
  return (
    <Pressable onPress={() => onPress(value)} style={[styles.timeCell, active && styles.timeCellActive]}>
      <Text style={[styles.timeText, active && styles.timeTextActive]}>{label}</Text>
    </Pressable>
  );
}

function CheckRow({ label, value }: { label: string; value: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.checkRow}>
      <View style={styles.fakeCheck}>
        <Check size={12} color="#ffffff" />
      </View>
      <View style={styles.checkCopy}>
        <Text style={styles.checkLabel}>{label}</Text>
        <Text style={styles.checkValue}>{value}</Text>
      </View>
    </View>
  );
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    centered: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
    successRing: {
      width: 78,
      height: 78,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 999,
      backgroundColor: theme.brand,
      marginBottom: 20,
    },
    successTitle: { ...type.hero, fontSize: 22, color: theme.ink900 },
    successBody: { maxWidth: 290, marginTop: 7, marginBottom: 21, textAlign: 'center', color: theme.ink500, fontSize: 11.5, lineHeight: 18, fontFamily: font.body },
    fullWidth: { alignSelf: 'stretch' },
    serviceChoice: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      marginTop: 13,
      padding: 15,
      borderRadius: 16,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    serviceChoiceSelected: { borderColor: '#8dbbb4' },
    serviceIcon: {
      width: 52,
      height: 52,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 15,
      backgroundColor: theme.brand100,
    },
    serviceIconGuidance: { backgroundColor: palette.violet100 },
    guidanceGlyph: { fontWeight: '800', fontSize: 16, color: palette.violet600, fontFamily: font.display },
    serviceCopy: { flex: 1, minWidth: 0 },
    serviceTitle: { fontSize: 13.5, fontWeight: '700', fontFamily: font.display, color: theme.ink900 },
    serviceBody: { marginTop: 4, color: theme.ink500, fontSize: 10, lineHeight: 14, fontFamily: font.body },
    selectedBar: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      padding: 11,
      borderRadius: 13,
      backgroundColor: theme.brand50,
    },
    selectedIcon: {
      width: 37,
      height: 37,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 11,
      backgroundColor: theme.brand100,
    },
    selectedCopy: { flex: 1 },
    selectedKicker: { color: theme.ink500, fontSize: 8.5, fontWeight: '800', letterSpacing: 1, fontFamily: font.body },
    selectedTitle: { marginTop: 2, fontSize: 11.5, fontWeight: '700', fontFamily: font.body, color: theme.ink900 },
    reviewBanner: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      marginBottom: 13,
      padding: 13,
      borderRadius: 14,
      backgroundColor: theme.brand50,
    },
    formLabel: { marginTop: 17, marginBottom: 8, fontSize: 10.5, fontWeight: '700', fontFamily: font.body, color: theme.ink700 },
    dateStrip: { gap: 6, paddingRight: 8 },
    dateCell: {
      minWidth: 52,
      alignItems: 'center',
      gap: 5,
      paddingVertical: 9,
      paddingHorizontal: 8,
      borderRadius: 11,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    dateCellActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    dateWeekday: { fontSize: 8, fontWeight: '700', fontFamily: font.body, color: theme.ink500 },
    dateDay: { fontSize: 14, fontWeight: '700', fontFamily: font.display, color: theme.ink900 },
    dateTextActive: { color: '#ffffff' },
    periodRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginVertical: 8 },
    periodLabel: { color: theme.ink500, fontSize: 9, fontWeight: '700', fontFamily: font.body },
    periodLine: { flex: 1, height: 1, backgroundColor: theme.line },
    timeGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
    timeCell: {
      minWidth: 68,
      minHeight: 38,
      alignItems: 'center',
      justifyContent: 'center',
      flexGrow: 1,
      borderRadius: 10,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    timeCellActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    timeText: { fontSize: 10, fontWeight: '600', fontFamily: font.body, color: theme.ink700 },
    timeTextActive: { color: '#ffffff' },
    typeRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 7 },
    typeChip: {
      paddingHorizontal: 12,
      paddingVertical: 9,
      borderRadius: 10,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    typeChipActive: { backgroundColor: theme.brand, borderColor: theme.brand },
    typeText: { fontSize: 10, fontWeight: '600', fontFamily: font.body, color: theme.ink700 },
    typeTextActive: { color: '#ffffff' },
    formError: { marginTop: 10, color: '#a33b3b', fontSize: 10, fontFamily: font.body },
    checkList: { gap: 6 },
    checkRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 9, paddingVertical: 4 },
    fakeCheck: {
      width: 18,
      height: 18,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 5,
      backgroundColor: theme.brand,
      marginTop: 1,
    },
    checkCopy: { flex: 1, flexDirection: 'column' },
    checkLabel: { fontSize: 9, fontFamily: font.body, color: theme.ink500 },
    checkValue: { fontSize: 10.5, fontWeight: '600', fontFamily: font.body, color: theme.ink900, marginTop: 1 },
    notice: {
      flexDirection: 'row',
      alignItems: 'flex-start',
      gap: 9,
      marginTop: 13,
      padding: 11,
      borderRadius: 11,
      backgroundColor: theme.brand50,
    },
    noticeText: { flex: 1, color: theme.ink700, fontSize: 10, lineHeight: 14, fontFamily: font.body },
    actions: { marginTop: 24 },
  });
}
