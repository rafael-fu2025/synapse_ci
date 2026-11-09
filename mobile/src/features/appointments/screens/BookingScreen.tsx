import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { BackHandler, Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useNavigation, usePreventRemove } from '@react-navigation/native';
import type { CompositeNavigationProp, NavigatorScreenParams } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import { Check, ChevronRight, CircleCheckBig } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { TextField } from '../../../components/TextField';
import { Appointment, CLINIC_TYPES, GUIDANCE_TYPES } from '../../../api/endpoints/appointments';
import { Theme, font, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { useCreateAppointment } from '../hooks';
import { UNIT_PRESENTATION, unitLabel } from '../status';
import { AppTabsParamList, VisitsStackParamList } from '../../../navigation/types';
import { formatDateTile, formatFullDate } from '../../../lib/format';
import { BOOKING_STEPS, BookingStep, PREFERRED_TIMES, TYPE_LABELS, formatPreferredTime, isHalfDay, nextDays, previousBookingStep } from '../booking';
import { appointmentErrorMessage } from '../errors';
import { useKeyboardVisible } from '../../../lib/useKeyboardVisible';
import { CONTEXT_BAR_ACTION_CLEARANCE } from '../../../navigation/contextTabBarMetrics';
import { useOptionalWorkspace } from '../../workspace/WorkspaceContext';
import { useContextDraftRegistration } from '../../workspace/useContextDraftRegistration';
import { BookingStepTransition } from '../components/BookingStepTransition';

// Keep nested tab destinations typed locally; Booking may be hosted from Home or Visits.
type BookingTabs = Omit<AppTabsParamList, 'Visits'> & {
  Visits: NavigatorScreenParams<VisitsStackParamList>;
};
type Navigation = CompositeNavigationProp<
  NativeStackNavigationProp<VisitsStackParamList, 'Booking'>,
  BottomTabNavigationProp<BookingTabs>
>;

export function BookingScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Navigation>();
  const create = useCreateAppointment();
  const keyboardVisible = useKeyboardVisible();
  const workspace = useOptionalWorkspace();

  const [step, setStep] = useState<BookingStep>('unit');
  const [stepReset, setStepReset] = useState(0);
  const currentStep = useRef(step);
  currentStep.current = step;
  const [unit, setUnit] = useState<'clinic' | 'guidance' | null>(null);
  const [date, setDate] = useState<string | null>(null);
  const [time, setTime] = useState<string | null>(null);
  const [visitType, setVisitType] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [reviewed, setReviewed] = useState(false);
  const [received, setReceived] = useState<Appointment | null>(null);
  const submitting = useRef(false);
  useContextDraftRegistration({
    dirty: step !== 'success' && (unit !== null || reason !== '' || date !== null || visitType !== null),
    submitting: create.isPending,
    onDiscard: () => {
      setStepReset(value => value + 1);
      setStep('unit'); setUnit(null); setDate(null); setTime(null); setVisitType(null);
      setReason(''); setError(null); setReviewed(false); setReceived(null);
      submitting.current = false;
    },
  });

  const days = useMemo(() => nextDays(14), []);
  const types = unit === 'guidance' ? GUIDANCE_TYPES : CLINIC_TYPES;
  const morning = PREFERRED_TIMES.filter((value) => value < '12:00');
  // Saturdays are half-day, so the afternoon group is not offered at all.
  const halfDay = date !== null && isHalfDay(date);
  const afternoon = halfDay ? [] : PREFERRED_TIMES.filter((value) => value >= '12:00');

  // Switching to a Saturday must not leave an afternoon preference selected.
  useEffect(() => {
    if (halfDay && time !== null && time >= '12:00') {
      setTime(null);
    }
  }, [halfDay, time]);

  function viewVisits() {
    if (navigation.getState().routeNames.includes('Appointments')) {
      navigation.popTo('Appointments');
    } else {
      navigation.navigate('Visits', { screen: 'Appointments', initial: false });
    }
  }

  const backOneStep = useCallback(() => {
    if (submitting.current && step !== 'success') {
      return true;
    }
    const previous = previousBookingStep(step);
    if (!previous) {
      return false;
    }
    setError(null);
    setReviewed(false);
    setStep(previous);
    return true;
  }, [step]);

  useFocusEffect(useCallback(() => {
    const listener = BackHandler.addEventListener('hardwareBackPress', backOneStep);
    return () => listener.remove();
  }, [backOneStep]));
  usePreventRemove(previousBookingStep(step) !== null || create.isPending, backOneStep);

  function onBack() {
    if (!backOneStep()) {
      if (navigation.canGoBack()) {
        navigation.goBack();
      } else {
        viewVisits();
      }
    }
  }

  async function submit() {
    if (submitting.current || step !== 'review' || !reviewed || !unit || !date || !time || !visitType) {
      return;
    }
    // React's pending state is not committed until after another same-tick tap.
    submitting.current = true;
    setError(null);
    try {
      const response = await create.mutateAsync({ unit, date, preferred_time: time, type: visitType, reason: reason.trim() || undefined });
      setReceived(response.data);
      setStep('success');
    } catch (mutationError) {
      submitting.current = false;
      setError(appointmentErrorMessage(mutationError, 'The request could not be sent. Check your connection and try again.'));
    }
  }

  function renderStepContent(bodyStep: BookingStep, interactive: boolean) {
    const canEdit = () => interactive && currentStep.current === bodyStep && !submitting.current;
    if (bodyStep === 'success' && received) {
      const ticketDate = `${received.date}T00:00:00+08:00`;
      const tile = formatDateTile(ticketDate);
      return (
        <ScrollView style={styles.scroll} contentContainerStyle={styles.receiptContent} scrollEnabled={interactive}>
        <View style={styles.centered}>
          <View style={styles.successRing}>
            <CircleCheckBig size={34} color={theme.brandIcon} />
          </View>
          <Text style={styles.successTitle}>Request received</Text>
          <Text style={styles.successBody}>
            Your preferred {unitLabel(received.unit).toLowerCase()} time has been sent for review. This is not confirmed yet—the department will notify you when staff responds.
          </Text>
          <View style={styles.ticket}>
            <View style={styles.ticketDate}>
              <Text style={styles.selectedKicker}>{tile?.month}</Text>
              <Text style={styles.ticketDay}>{tile?.day}</Text>
              <Text style={styles.selectedKicker}>{received.date.slice(0, 4)}</Text>
            </View>
            <View style={styles.serviceCopy}>
              <Text style={styles.selectedTitle}>{UNIT_PRESENTATION[received.unit].label}</Text>
              <Text style={styles.serviceBody}>Preferred time: {formatPreferredTime(time ?? '')}</Text>
              <Text style={styles.serviceBody}>Reference #{received.id} · Awaiting confirmation</Text>
            </View>
          </View>
          <View style={styles.fullWidth}>
            <Action full onPress={() => {
              if (navigation.getState().routeNames.includes('AppointmentDetail')) {
                navigation.replace('AppointmentDetail', { id: received.id });
              } else {
                navigation.navigate('Visits', { screen: 'AppointmentDetail', params: { id: received.id }, initial: false });
              }
            }}>
              View request status
            </Action>
            <Action full variant="ghost" onPress={viewVisits}>View my visits</Action>
          </View>
        </View>
        </ScrollView>
      );
    }
    return (
      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled" scrollEnabled={interactive}>
          {bodyStep === 'unit' ? (
            <View>
              {(['clinic', 'guidance'] as const).map((key) => {
                const presentation = UNIT_PRESENTATION[key];
                const selected = unit === key;
                return (
                  <Pressable
                    key={key}
                    accessibilityRole="button"
                    accessibilityState={{ selected, disabled: !interactive }}
                    disabled={!interactive}
                    onPress={() => {
                      if (!canEdit()) { return; }
                      if (unit !== key) {
                        setVisitType(null);
                      }
                      setUnit(key);
                      setReviewed(false);
                      setError(null);
                      setStep('details');
                    }}
                    style={[styles.serviceChoice, selected && styles.serviceChoiceSelected]}>
                    <Image
                      source={key === 'clinic'
                        ? require('../../../assets/brand/health-services.png')
                        : require('../../../assets/brand/guidance-center.png')}
                      style={styles.serviceLogo}
                      resizeMode="contain"
                      accessible={false}
                    />
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

          {bodyStep === 'details' && unit ? (
            <View>
              <View style={styles.selectedBar}>
                <Image
                  source={unit === 'clinic'
                    ? require('../../../assets/brand/health-services.png')
                    : require('../../../assets/brand/guidance-center.png')}
                  style={styles.selectedLogo}
                  resizeMode="contain"
                  accessible={false}
                />
                <View style={styles.selectedCopy}>
                  <Text style={[styles.selectedKicker, theme.mode === 'light' && styles.selectedBarText]}>SELECTED SERVICE</Text>
                  <Text style={[styles.selectedTitle, theme.mode === 'light' && styles.selectedBarText]}>{UNIT_PRESENTATION[unit].label}</Text>
                </View>
              </View>


              <Text style={styles.formLabel}>Type of visit</Text>
              <View style={styles.typeRow}>
                {types.map((option) => (
                  <Pressable
                    key={option}
                    accessibilityRole="button"
                    accessibilityState={{ selected: visitType === option, disabled: !interactive }}
                    disabled={!interactive}
                    onPress={() => { if (canEdit()) { setVisitType(option); } }}
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
                maxLength={255}
                hint={`${reason.length} / 255 characters. Do not include emergency or highly sensitive information.`}
                value={reason}
                editable={interactive}
                onChangeText={value => { if (canEdit()) { setReason(value); } }}
              />

              {error ? <Text style={styles.formError}>{error}</Text> : null}
            </View>
          ) : null}

          {bodyStep === 'date-time' ? (
            <View>
              <Text style={styles.noticeText}>
                Choose a preferred date and time. These are suggestions, not live availability. Staff may propose another time.
              </Text>
              <Text style={styles.formLabel}>Preferred date</Text>
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.dateStrip} scrollEnabled={interactive}>
                {days.map((day) => {
                  const active = date === day.date;
                  return (
                    <Pressable
                      key={day.date}
                      accessibilityRole="button"
                      accessibilityLabel={formatFullDate(`${day.date}T00:00:00+08:00`)}
                      accessibilityState={{ selected: active, disabled: !interactive }}
                      disabled={!interactive}
                      onPress={() => { if (canEdit()) { setDate(day.date); } }}
                      style={[styles.dateCell, active && styles.dateCellActive]}>
                      <Text style={[styles.dateWeekday, active && styles.dateTextActive]}>{day.weekday}</Text>
                      <Text style={[styles.dateDay, active && styles.dateTextActive]}>{day.day}</Text>
                    </Pressable>
                  );
                })}
              </ScrollView>
              <Text style={styles.formLabel}>Preferred time</Text>
              {([['Morning', morning], ['Afternoon', afternoon]] as const)
                .filter(([, times]) => times.length > 0)
                .map(([period, times]) => (
                  <View key={period}>
                    <View style={styles.periodRow}>
                      <Text style={styles.periodLabel}>{period}</Text>
                      <View style={styles.periodLine} />
                    </View>
                    <View style={styles.timeGrid}>
                      {times.map(value => <TimeCell key={value} value={value} active={time === value} disabled={!interactive}
                        onPress={next => { if (canEdit()) { setTime(next); } }} />)}
                    </View>
                  </View>
                ))}
              {halfDay ? (
                <Text style={styles.noticeText}>Saturdays are half-day: only morning times are available.</Text>
              ) : null}
              {error ? <Text style={styles.formError} accessibilityRole="alert">{error}</Text> : null}
            </View>
          ) : null}

          {bodyStep === 'review' && unit && date && time && visitType ? (
            <View>
              <View style={styles.reviewBanner}>
                <Image
                  source={unit === 'clinic'
                    ? require('../../../assets/brand/health-services.png')
                    : require('../../../assets/brand/guidance-center.png')}
                  style={styles.selectedLogo}
                  resizeMode="contain"
                  accessible={false}
                />
                <View style={styles.selectedCopy}>
                  <Text style={styles.selectedKicker}>REVIEW</Text>
                  <Text style={styles.selectedTitle}>{UNIT_PRESENTATION[unit].label}</Text>
                </View>
              </View>
              <View style={styles.checkList}>
                <CheckRow label="Preferred date" value={formatFullDate(`${date}T00:00:00+08:00`)} />
                <CheckRow label="Preferred time" value={`${formatPreferredTime(time)} (30-minute request)`} />
                <CheckRow label="Assigned staff" value="Not yet assigned" />
                <CheckRow label="Visit type" value={TYPE_LABELS[visitType] ?? visitType} />
                {reason.trim() !== '' ? <CheckRow label="Reason" value={reason.trim()} /> : null}
              </View>
              <View style={styles.notice}>
                <Check size={14} color={theme.brandIcon} />
                <Text style={styles.noticeText}>
                  This is a preferred time, not a confirmed appointment. The department will review your request and notify you when a time is confirmed.
                </Text>
              </View>
              <Pressable
                accessibilityRole="checkbox"
                accessibilityLabel="I confirm that these appointment details are correct."
                accessibilityState={{ checked: reviewed, disabled: !interactive || create.isPending }}
                disabled={!interactive || create.isPending}
                onPress={() => { if (canEdit()) { setReviewed(value => !value); } }}
                style={styles.reviewCheck}>
                <View style={[styles.checkbox, reviewed && styles.checkboxChecked]}>
                  {reviewed ? <Check size={12} color="#ffffff" /> : null}
                </View>
                <Text style={styles.noticeText}>I confirm that these appointment details are correct.</Text>
              </Pressable>
              {error ? <Text style={styles.formError} accessibilityRole="alert">{error}</Text> : null}
            </View>
          ) : null}
      </ScrollView>
    );
  }

  const success = step === 'success' && received !== null;
  return (
    <Screen scroll={false} bodyStyle={!success && step !== 'unit' && workspace?.canSwitchContext && !keyboardVisible ? styles.raisedActions : undefined}>
      <PageHeader
        title={success ? 'Request received' : step === 'unit' ? 'Choose a service' : step === 'details' ? 'Appointment details' : step === 'date-time' ? 'Choose date & time' : 'Review appointment'}
        subtitle={success ? undefined : `Step ${BOOKING_STEPS.findIndex(value => value === step) + 1} of 4`}
        onBack={success ? viewVisits : onBack}
      />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.scroll}>
        {!success ? <View style={styles.progress} accessibilityLabel={`Step ${BOOKING_STEPS.findIndex(value => value === step) + 1} of 4`}>
          {BOOKING_STEPS.map((value, index) => (
            <View key={value} style={[styles.stepBar, index <= BOOKING_STEPS.findIndex(item => item === step) && styles.stepBarDone]} />
          ))}
        </View> : null}
        <BookingStepTransition step={step} reset={stepReset} renderStep={renderStepContent} />
        {!success && step !== 'unit' ? (
          <View style={styles.actions}>
            {step === 'details' && unit ? (
              <Action
                full
                onPress={() => {
                  if (submitting.current || currentStep.current !== 'details') { return; }
                  if (visitType === null) {
                    setError('Choose a visit type to continue.');
                    return;
                  }
                  setError(null);
                  setStep('date-time');
                }}>
                Continue to date & time
              </Action>
            ) : null}
            {step === 'date-time' ? (
              <Action full onPress={() => {
                if (submitting.current || currentStep.current !== 'date-time') { return; }
                if (!date || !time) {
                  setError('Choose a preferred date and time to continue.');
                  return;
                }
                setError(null);
                setReviewed(false);
                setStep('review');
              }}>Review appointment</Action>
            ) : null}
            {step === 'review' && unit && date && time && visitType ? (
              <Action full loading={create.isPending} disabled={!reviewed} onPress={submit}>
                Submit appointment request
              </Action>
            ) : null}
          </View>
        ) : null}
      </KeyboardAvoidingView>
    </Screen>
  );
}


function TimeCell({ value, active, disabled = false, onPress }: { value: string; active: boolean; disabled?: boolean; onPress: (value: string) => void }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const label = formatPreferredTime(value);
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ selected: active, disabled }} disabled={disabled}
      onPress={() => { if (!disabled) { onPress(value); } }} style={[styles.timeCell, active && styles.timeCellActive]}>
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
        <Check size={12} color={theme.brandIcon} />
      </View>
      <View style={styles.checkCopy}>
        <Text style={styles.checkLabel}>{label}</Text>
        <Text style={styles.checkValue}>{value}</Text>
      </View>
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    centered: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
    successRing: {
      width: 40,
      height: 40,
      alignItems: 'center',
      justifyContent: 'center',
      marginBottom: 20,
    },
    successTitle: { ...type.hero, fontSize: 22, color: theme.ink900 },
    successBody: { maxWidth: 290, marginTop: 7, marginBottom: 21, textAlign: 'center', color: theme.ink500, fontSize: 11.5, lineHeight: 18, fontFamily: font.body },
    fullWidth: { alignSelf: 'stretch', marginTop: 24 },
    progress: { flexDirection: 'row', gap: 6, marginTop: -6, marginBottom: 22 },
    stepBar: { flex: 1, height: 4, borderRadius: 4, backgroundColor: theme.line },
    stepBarDone: { backgroundColor: theme.brand },
    ticket: { alignSelf: 'stretch', flexDirection: 'row', alignItems: 'center', gap: 14, padding: 15, borderRadius: 15, borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    ticketDate: { minWidth: 60, alignItems: 'center', paddingRight: 14, borderRightWidth: 1, borderRightColor: theme.line, borderStyle: 'dashed' },
    ticketDay: { ...type.hero, fontSize: 25, color: theme.ink900 },
    reviewCheck: { flexDirection: 'row', alignItems: 'center', gap: 9, marginTop: 16, paddingVertical: 8 },
    checkbox: { width: 18, height: 18, borderRadius: 5, borderWidth: 1, borderColor: theme.brand, alignItems: 'center', justifyContent: 'center' },
    checkboxChecked: { backgroundColor: theme.brand },
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
    serviceLogo: { width: 52, height: 52 },

    serviceCopy: { flex: 1, minWidth: 0 },
    serviceTitle: { fontSize: 13.5, fontWeight: '700', fontFamily: font.display, color: theme.ink900 },
    serviceBody: { marginTop: 4, color: theme.ink500, fontSize: 10, lineHeight: 14, fontFamily: font.body },
    selectedBar: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      padding: 11,
      borderRadius: 13,
      backgroundColor: theme.mode === 'dark' ? theme.surface : theme.brand,
    },
    selectedBarText: { color: '#ffffff' },
    selectedLogo: { width: 37, height: 37 },
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
      minWidth: 88,
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
      width: 24,
      height: 24,
      alignItems: 'center',
      justifyContent: 'center',
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
    scroll: { flex: 1 },
    scrollContent: { flexGrow: 1, paddingBottom: 12 },
    receiptContent: { flexGrow: 1 },
    actions: { flexShrink: 0, paddingTop: 24 },
    raisedActions: { paddingBottom: CONTEXT_BAR_ACTION_CLEARANCE },
  });
}

const makeStyles = memoizeStyles(createStyles);
