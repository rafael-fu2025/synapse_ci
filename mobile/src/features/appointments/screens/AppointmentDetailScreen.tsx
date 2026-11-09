import React, { useEffect, useState } from 'react';
import {
  AccessibilityInfo,
  Animated,
  Easing,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { CalendarDays, ChevronRight, Clock3, Stethoscope, UserRound } from 'lucide-react-native';
import { Skeleton } from '../../../components/Skeleton';
import { loadingState } from '../../../lib/loadingState';
import { ApiError } from '../../../api/client';
import { DetailRow } from '../../../components/DetailRow';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { StatusPill } from '../../../components/StatusPill';
import { Action } from '../../../components/Action';
import { Theme, font, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate, formatTime } from '../../../lib/format';
import { useAppointment } from '../hooks';
import { progressStep, statusPresentation, unitLabel } from '../status';
import { VisitsStackParamList } from '../../../navigation/types';
import { AppointmentLoadError } from '../components/AppointmentLoadError';
import { useKeyboardVisible } from '../../../lib/useKeyboardVisible';
import { CONTEXT_BAR_ACTION_CLEARANCE } from '../../../navigation/contextTabBarMetrics';
import { useOptionalWorkspace } from '../../workspace/WorkspaceContext';

type Props = NativeStackScreenProps<VisitsStackParamList, 'AppointmentDetail'>;

const STEP_COUNT = 4;

const STEP_ANIMATION_MS = 280;

/**
 * Honour the OS "remove animations" setting: motion is off until the preference
 * resolves, matching LoginCarousel and the tab transitions.
 */
function useReduceMotion(): boolean {
  const [reduceMotion, setReduceMotion] = useState(true);
  useEffect(() => {
    let mounted = true;
    let changed = false;
    AccessibilityInfo.isReduceMotionEnabled()
      .then(value => { if (mounted && !changed) { setReduceMotion(value); } })
      .catch(() => undefined);
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', value => {
      changed = true;
      setReduceMotion(value);
    });
    return () => {
      mounted = false;
      subscription.remove();
    };
  }, []);
  return reduceMotion;
}

/**
 * One progress segment: a static track with a maroon fill that animates in.
 *
 * The fill scales vertically rather than animating width, because width cannot
 * run on the native driver.
 */
function StepBar({
  done,
  reduceMotion,
  styles,
}: {
  done: boolean;
  reduceMotion: boolean;
  styles: ReturnType<typeof createStyles>;
}) {
  const [progress] = useState(() => new Animated.Value(done ? 1 : 0));

  useEffect(() => {
    const toValue = done ? 1 : 0;
    if (reduceMotion) {
      progress.setValue(toValue);
      return undefined;
    }
    const transition = Animated.timing(progress, {
      toValue,
      duration: STEP_ANIMATION_MS,
      easing: Easing.out(Easing.cubic),
      useNativeDriver: true,
      isInteraction: false,
    });
    transition.start();
    return () => transition.stop();
  }, [done, progress, reduceMotion]);

  return (
    <View style={styles.stepColumn}>
      <View style={styles.stepTrack}>
        <Animated.View
          style={[
            styles.stepFill,
            {
              opacity: progress,
              transform: [{ scaleY: progress.interpolate({ inputRange: [0, 1], outputRange: [0.35, 1] }) }],
            },
          ]}
        />
      </View>
    </View>
  );
}

export function AppointmentDetailScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const route = useRoute<Props['route']>();
  const query = useAppointment(route.params.id);
  const state = loadingState(query);
  const authorityRemoved = query.isError && query.error instanceof ApiError && [401, 403, 404].includes(query.error.status);
  const appointment = authorityRemoved ? undefined : query.data?.data;
  // Hooks must run before the early returns below.
  const reduceMotion = useReduceMotion();
  const keyboardVisible = useKeyboardVisible();
  const workspace = useOptionalWorkspace();

  if (state === 'loading') {
    return (
      <Screen scroll={false}>
        <PageHeader title="Appointment" onBack={navigation.goBack} />
        <Skeleton label="Loading appointment…" variant="detail" />
      </Screen>
    );
  }
  if (!appointment) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Appointment" onBack={navigation.goBack} />
        {query.isError ? (
          <AppointmentLoadError error={query.error} onRetry={() => { query.refetch().catch(() => undefined); }} />
        ) : (
          <Text style={styles.missing}>{state === 'waiting'
            ? 'Waiting to load this appointment. It will load when this screen and your connection are active.'
            : 'This appointment is no longer available.'}</Text>
        )}
      </Screen>
    );
  }

  const status = statusPresentation(appointment.status);
  const step = progressStep(appointment.status);
  const cancellable = ['requested', 'scheduled', 'confirmed'].includes(appointment.status);
  const showAction = !appointment.cancellation_reason && cancellable;

  return (
    <Screen scroll={false} bodyStyle={showAction && workspace?.canSwitchContext && !keyboardVisible ? styles.raisedActions : undefined}>
      <PageHeader title="Appointment details" onBack={navigation.goBack} />
      <ScrollView style={styles.scroll} contentContainerStyle={styles.scrollContent}>
        {query.isError ? <AppointmentLoadError error={query.error} onRetry={() => { query.refetch().catch(() => undefined); }} /> : null}
        <View
          style={styles.progress}
          accessible
          accessibilityRole="progressbar"
          accessibilityLabel={step > 0 ? `${status.label}, step ${step} of ${STEP_COUNT}` : status.label}
          accessibilityValue={{ min: 0, max: STEP_COUNT, now: step }}>
          {Array.from({ length: STEP_COUNT }, (_, index) => (
            <StepBar
              key={index}
              done={index < step}
              reduceMotion={reduceMotion}
              styles={styles}
            />
          ))}
        </View>

        <View style={styles.hero}>
        <Text style={styles.heroTitle}>{unitLabel(appointment.unit)} visit</Text>
        <Text style={styles.heroSubtitle}>{appointment.type.replace(/_/g, ' ')} · Reference #{appointment.id}</Text>
        <StatusPill tone={status.tone}>{status.label}</StatusPill>
      </View>

      {appointment.status === 'requested' ? (
        <View style={styles.cancelNote}>
          <Text style={styles.cancelNoteText}>This is a preferred time, not yet confirmed. The department will review your request and notify you when a time is confirmed.</Text>
        </View>
      ) : null}
      <View style={styles.card}>
        <DetailRow
          icon={<CalendarDays size={16} color={theme.brandIcon} />}
          label="DATE"
          value={formatDate(appointment.starts_at)}
        />
        <DetailRow
          icon={<Clock3 size={16} color={theme.brandIcon} />}
          label={appointment.status === 'requested' ? 'PREFERRED TIME' : 'TIME'}
          value={`${formatTime(appointment.starts_at)} – ${formatTime(appointment.ends_at)}`}
        />
        {appointment.provider_name ? (
          <DetailRow
            icon={<UserRound size={16} color={theme.brandIcon} />}
            label="ASSIGNED PROVIDER"
            value={appointment.provider_name}
          />
        ) : (
          <DetailRow
            icon={<Stethoscope size={16} color={theme.brandIcon} />}
            label="ASSIGNED PROVIDER"
            value="Not yet assigned"
          />
        )}
        <DetailRow
          icon={<ChevronRight size={16} color={theme.brandIcon} />}
          label="REASON"
          value={appointment.reason ?? 'No reason provided'}
          last
        />
      </View>

      {appointment.cancellation_reason ? (
        <View style={styles.cancelNote}>
          <Text style={styles.cancelNoteLabel}>Cancellation reason</Text>
          <Text style={styles.cancelNoteText}>{appointment.cancellation_reason}</Text>
        </View>
      ) : null}
      </ScrollView>

      {showAction ? (
        <View style={styles.actions}>
          <Action variant="danger" full onPress={() => navigation.navigate('CancelAppointment', { id: appointment.id })}>
            Cancel this appointment
          </Action>
        </View>
      ) : null}
    </Screen>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    missing: { color: theme.ink500, fontFamily: font.body, fontSize: 11.5 },
    // The ScrollView owns the free space; the action bar below it stays pinned.
    scroll: { flex: 1 },
    scrollContent: { flexGrow: 1, paddingBottom: 12 },
    progress: { flexDirection: 'row', gap: 6, marginBottom: 22, marginTop: -6 },
    stepColumn: { flex: 1 },
    stepTrack: { height: 4, borderRadius: 4, backgroundColor: theme.line, overflow: 'hidden' },
    stepFill: { height: 4, borderRadius: 4, backgroundColor: theme.brand },
    hero: { alignItems: 'center', paddingBottom: 19 },
    heroTitle: { ...type.hero, fontSize: 19, color: theme.ink900, marginTop: 6 },
    heroSubtitle: {
      marginTop: 3,
      marginBottom: 10,
      color: theme.ink500,
      fontSize: 10,
      fontFamily: font.body,
      textTransform: 'capitalize',
    },
    card: { paddingHorizontal: 14 },
    cancelNote: {
      marginTop: 14,
      padding: 12,
      borderRadius: 11,
      backgroundColor: theme.brand50,
    },
    cancelNoteLabel: { fontSize: 9, fontWeight: '800', fontFamily: font.body, color: theme.ink500, letterSpacing: 1 },
    cancelNoteText: { marginTop: 4, fontSize: 10.5, fontFamily: font.body, color: theme.ink700 },
    // Sibling of the ScrollView: flexShrink 0 keeps it pinned above the tab bar.
    actions: { flexShrink: 0, paddingTop: 24 },
    raisedActions: { paddingBottom: CONTEXT_BAR_ACTION_CLEARANCE },
  });
}

const makeStyles = memoizeStyles(createStyles);
