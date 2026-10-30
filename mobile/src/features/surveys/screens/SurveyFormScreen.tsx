import React, { useCallback, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, BackHandler, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useNavigation, usePreventRemove, useRoute } from '@react-navigation/native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { CircleCheckBig, ClipboardList, Clock3, ListOrdered, ShieldCheck } from 'lucide-react-native';
import { Action } from '../../../components/Action';
import { Screen } from '../../../components/Screen';
import { PageHeader } from '../../../components/PageHeader';
import { TextField } from '../../../components/TextField';
import { ApiError } from '../../../api/client';
import { Theme, font, palette, type, memoizeStyles } from '../../../theme/tokens';
import { useTheme } from '../../../theme/ThemeProvider';
import { formatDate } from '../../../lib/format';
import { useSubmitSurvey, useSurveyForm } from '../hooks';
import { hasSurveyAnswer, orderedSurveyAnswers } from '../answers';
import { RootStackParamList } from '../../../navigation/types';

type Props = NativeStackScreenProps<RootStackParamList, 'SurveyForm'>;

export function SurveyFormScreen() {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  const navigation = useNavigation<Props['navigation']>();
  const route = useRoute<Props['route']>();
  const query = useSurveyForm(route.params.id, true);
  const submit = useSubmitSurvey();

  const [started, setStarted] = useState(false);
  const [index, setIndex] = useState(0);
  /** Sparse answers aligned to question positions. */
  const [answers, setAnswers] = useState<Record<number, unknown>>({});
  const [error, setError] = useState<string | null>(null);
  const [hasDraft, setHasDraft] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [successTitle, setSuccessTitle] = useState<string | null>(null);
  const submissionInFlight = useRef(false);
  const submitted = useRef(false);

  const survey = query.data?.data;
  const questions = useMemo(() => survey?.questions ?? [], [survey]);
  const question = questions[index];
  const isLast = index === questions.length - 1;
  const answer = answers[index];

  const answeredCount = useMemo(
    () => questions.filter((item, position) => hasSurveyAnswer(item, answers[position])).length,
    [answers, questions],
  );

  const busy = isSubmitting || submit.isPending;
  const complete = successTitle !== null || submit.isSuccess || Boolean(survey?.submitted);

  const previousQuestion = useCallback(() => {
    if (submissionInFlight.current || busy || complete) {
      return;
    }
    setError(null);
    if (index > 0) {
      setIndex(index - 1);
    } else {
      setStarted(false);
    }
  }, [busy, complete, index]);

  usePreventRemove(!complete && (hasDraft || busy), ({ data }) => {
    if (submissionInFlight.current || busy || submitted.current) {
      return;
    }
    Alert.alert('Discard survey draft?', 'Your response has not been submitted. Leaving will discard this draft.', [
      { text: 'Keep editing', style: 'cancel' },
      { text: 'Discard draft', style: 'destructive', onPress: () => navigation.dispatch(data.action) },
    ]);
  });

  useFocusEffect(useCallback(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (complete) {
        return false;
      }
      if (submissionInFlight.current || busy) {
        return true;
      }
      if (started) {
        previousQuestion();
        return true;
      }
      return false;
    });
    return () => subscription.remove();
  }, [busy, complete, previousQuestion, started]));

  function onBack() {
    if (submissionInFlight.current || busy) {
      return;
    }
    if (started && !complete) {
      previousQuestion();
    } else {
      navigation.goBack();
    }
  }

  function updateAnswer(value: unknown) {
    if (!submissionInFlight.current && !busy && !submitted.current && !complete) {
      setAnswers(current => ({ ...current, [index]: value }));
      setError(null);
    }
  }

  async function onSubmit() {
    if (!survey || submissionInFlight.current || busy || submitted.current || complete) {
      return;
    }
    const missingIndex = questions.findIndex((item, position) => item.required && !hasSurveyAnswer(item, answers[position]));
    if (missingIndex >= 0) {
      setIndex(missingIndex);
      setError('Please answer all required questions before submitting.');
      return;
    }
    submissionInFlight.current = true;
    setIsSubmitting(true);
    setError(null);
    try {
      await submit.mutateAsync({ id: survey.id, answers: orderedSurveyAnswers(questions, answers) });
      submitted.current = true;
      setSuccessTitle(survey.title);
      setHasDraft(false);
      setStarted(false);
      setAnswers({});
    } catch (mutationError) {
      setError(mutationError instanceof ApiError ? mutationError.message : 'The survey could not be submitted. Try again.');
    } finally {
      submissionInFlight.current = false;
      setIsSubmitting(false);
    }
  }

  // A successful local submission takes precedence over every refetch state.
  if (successTitle !== null || submit.isSuccess) {
    return (
      <Screen scroll={false}>
        <PageHeader title={successTitle ?? survey?.title ?? 'Survey'} onBack={navigation.goBack} />
        <View style={styles.centered}>
          <View style={styles.successRing}>
            <CircleCheckBig size={32} color="#ffffff" />
          </View>
          <Text style={styles.successTitle}>Response recorded</Text>
          <Text style={styles.successBody}>Thank you. Your answers are stored securely and identified for guidance follow-up.</Text>
          <Action full onPress={navigation.goBack}>
            Back to surveys
          </Action>
        </View>
      </Screen>
    );
  }

  if (query.isLoading) {
    return (
      <Screen scroll={false}>
        <PageHeader title="Survey" onBack={navigation.goBack} />
        <ActivityIndicator color={palette.violet600} />
      </Screen>
    );
  }
  if (!survey) {
    const notFound = query.error instanceof ApiError && query.error.status === 404;
    return (
      <Screen scroll={false}>
        <PageHeader title="Survey" onBack={navigation.goBack} />
        <Text style={styles.missing}>
          {notFound ? 'This survey is no longer available.' : 'The survey could not be loaded. Please try again.'}
        </Text>
        {!notFound ? (
          <Action full variant="secondary" style={styles.retry} loading={query.isFetching} onPress={() => {
            query.refetch().catch(() => undefined);
          }}>
            Retry
          </Action>
        ) : null}
      </Screen>
    );
  }
  if (survey.submitted) {
    return (
      <Screen scroll={false}>
        <PageHeader title={survey.title} onBack={navigation.goBack} />
        <View style={styles.centered}>
          <View style={styles.successRing}>
            <CircleCheckBig size={32} color="#ffffff" />
          </View>
          <Text style={styles.successTitle}>Already submitted</Text>
          <Text style={styles.successBody}>Thank you — your identified response has been recorded.</Text>
          <Action full variant="secondary" onPress={navigation.goBack}>
            Back to surveys
          </Action>
        </View>
      </Screen>
    );
  }

  if (!started) {
    return (
      <Screen>
        <PageHeader title="Survey details" onBack={onBack} />
        <View style={styles.introIcon}>
          <ClipboardList size={30} color={palette.violet600} />
        </View>
        <Text style={styles.surveyTitle}>{survey.title}</Text>
        {survey.description ? <Text style={styles.surveyDescription}>{survey.description}</Text> : null}
        <View style={styles.facts}>
          <FactRow icon={<ListOrdered size={15} color={palette.violet600} />} label="QUESTIONS" value={`${questions.length} questions`} />
          <FactRow
            icon={<Clock3 size={15} color={palette.violet600} />}
            label="DEADLINE"
            value={survey.ends_at ? formatDate(survey.ends_at) : 'No deadline published'}
          />
          <FactRow
            icon={<ShieldCheck size={15} color={palette.violet600} />}
            label="PRIVACY"
            value="Identified — guidance staff can follow up with you"
          />
        </View>
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <View style={styles.actions}>
          <Action full disabled={busy} onPress={() => {
            if (!submissionInFlight.current && !busy && !complete) {
              setHasDraft(true);
              setStarted(true);
            }
          }}>
            {hasDraft ? 'Resume survey' : 'Start survey'}
          </Action>
        </View>
      </Screen>
    );
  }

  if (!question) {
    return null;
  }

  return (
    <Screen scroll={false}>
      <PageHeader title={survey.title} subtitle={`Question ${index + 1} of ${questions.length}`} onBack={onBack} />
      <ScrollView key={index} style={styles.questionScroll} keyboardShouldPersistTaps="handled">
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { width: `${((index + 1) / questions.length) * 100}%` }]} />
        </View>
        <Text style={styles.questionNumber}>
          QUESTION {index + 1} OF {questions.length}
          {question.required ? ' · REQUIRED' : ' · OPTIONAL'}
        </Text>
        <Text style={styles.questionTitle}>{question.label}</Text>

        {question.type === 'text' ? (
          <TextField
            label="Your answer"
            multiline
            value={(answer as string) ?? ''}
            editable={!busy}
            onChangeText={updateAnswer}
          />
        ) : question.type === 'rating' ? (
          <View style={styles.ratingRow}>
            {[1, 2, 3, 4, 5].map((value) => (
              <Pressable
                key={value}
                disabled={busy}
                accessibilityRole="radio"
                accessibilityState={{ checked: answer === value, disabled: busy }}
                onPress={() => updateAnswer(value)}
                style={[styles.ratingCell, answer === value && styles.ratingCellActive]}>
                <Text style={[styles.ratingText, answer === value && styles.ratingTextActive]}>{value}</Text>
              </Pressable>
            ))}
          </View>
        ) : (
          <View style={styles.answerList}>
            {question.options.map((option, optionIndex) => {
              const selected =
                question.type === 'single'
                  ? answer === optionIndex
                  : Array.isArray(answer) && (answer as number[]).includes(optionIndex);
              return (
                <Pressable
                  key={option}
                  disabled={busy}
                  accessibilityRole={question.type === 'single' ? 'radio' : 'checkbox'}
                  accessibilityState={{ checked: selected, disabled: busy }}
                  onPress={() => {
                    if (submissionInFlight.current || busy || submitted.current) {
                      return;
                    }
                    if (question.type === 'single') {
                      updateAnswer(optionIndex);
                    } else {
                      setAnswers(current => {
                        const list = Array.isArray(current[index]) ? current[index] as number[] : [];
                        return { ...current, [index]: list.includes(optionIndex) ? list.filter(value => value !== optionIndex) : [...list, optionIndex] };
                      });
                      setError(null);
                    }
                  }}
                  style={[styles.answerRow, selected && styles.answerRowSelected]}>
                  <View style={[styles.radio, selected && styles.radioSelected]}>
                    {selected ? <View style={styles.radioDot} /> : null}
                  </View>
                  <Text style={styles.answerText}>{option}</Text>
                </Pressable>
              );
            })}
          </View>
        )}

        {error ? <Text style={styles.error}>{error}</Text> : null}
      </ScrollView>

      <View style={styles.navActions}>
        <Action variant="secondary" disabled={busy} onPress={previousQuestion}>
          Back
        </Action>
        {isLast ? (
          <Action style={styles.grow} onPress={onSubmit} loading={busy}>
            Submit ({answeredCount}/{questions.length})
          </Action>
        ) : (
          <Action
            style={styles.grow}
            disabled={busy}
            onPress={() => {
              if (submissionInFlight.current || busy || submitted.current) {
                return;
              }
              if (question.required && !hasSurveyAnswer(question, answer)) {
                setError('This question is required.');
                return;
              }
              setError(null);
              setIndex(index + 1);
            }}>
            Continue
          </Action>
        )}
      </View>
    </Screen>
  );
}

function FactRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  const { theme } = useTheme();
  const styles = makeStyles(theme);
  return (
    <View style={styles.factRow}>
      {icon}
      <View style={styles.factCopy}>
        <Text style={styles.factLabel}>{label}</Text>
        <Text style={styles.factValue}>{value}</Text>
      </View>
    </View>
  );
}

function createStyles(theme: Theme) {
  return StyleSheet.create({
    missing: { color: theme.ink500, fontFamily: font.body, fontSize: 11.5 },
    retry: { marginTop: 16 },
    questionScroll: { flex: 1 },
    centered: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8 },
    successRing: {
      width: 78,
      height: 78,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 999,
      backgroundColor: palette.violet600,
      marginBottom: 20,
    },
    successTitle: { ...type.hero, fontSize: 22, color: theme.ink900 },
    successBody: { maxWidth: 290, marginTop: 7, marginBottom: 21, textAlign: 'center', color: theme.ink500, fontSize: 11.5, lineHeight: 18, fontFamily: font.body },
    introIcon: {
      width: 40,
      height: 40,
      alignItems: 'center',
      justifyContent: 'center',
      marginTop: 12,
      marginBottom: 15,
    },
    surveyTitle: { ...type.hero, fontSize: 22, lineHeight: 29, color: theme.ink900 },
    surveyDescription: { marginTop: 8, color: theme.ink500, fontSize: 11, lineHeight: 17, fontFamily: font.body },
    facts: { marginTop: 19, borderTopWidth: 1, borderBottomWidth: 1, borderColor: theme.line },
    factRow: { flexDirection: 'row', alignItems: 'center', gap: 11, paddingVertical: 11 },
    factCopy: { flex: 1 },
    factLabel: { color: theme.ink500, fontSize: 9, fontFamily: font.body, letterSpacing: 0.5 },
    factValue: { marginTop: 1, color: theme.ink900, fontSize: 10.5, fontWeight: '600', fontFamily: font.body },
    progressTrack: { height: 5, borderRadius: 5, backgroundColor: theme.line, overflow: 'hidden', marginBottom: 20 },
    progressFill: { height: '100%' as unknown as number, backgroundColor: palette.violet600 },
    questionNumber: { color: palette.violet600, fontSize: 9.5, fontWeight: '800', letterSpacing: 1.1, fontFamily: font.body },
    questionTitle: { marginTop: 10, ...type.hero, fontSize: 19, lineHeight: 27, color: theme.ink900 },
    ratingRow: { flexDirection: 'row', gap: 9, marginTop: 19 },
    ratingCell: {
      flex: 1,
      minHeight: 48,
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    ratingCellActive: { backgroundColor: palette.violet600, borderColor: palette.violet600 },
    ratingText: { fontSize: 13, fontWeight: '700', color: theme.ink700, fontFamily: font.body },
    ratingTextActive: { color: '#ffffff' },
    answerList: { marginTop: 19, gap: 8 },
    answerRow: {
      minHeight: 48,
      flexDirection: 'row',
      alignItems: 'center',
      gap: 11,
      paddingHorizontal: 13,
      paddingVertical: 11,
      borderRadius: 12,
      borderWidth: 1,
      borderColor: theme.line,
      backgroundColor: theme.surface,
    },
    answerRowSelected: { borderColor: '#a691c5', backgroundColor: '#f7f4fb' },
    radio: {
      width: 18,
      height: 18,
      borderRadius: 999,
      borderWidth: 1.5,
      borderColor: '#afbfbc',
      alignItems: 'center',
      justifyContent: 'center',
    },
    radioSelected: { borderColor: palette.violet600 },
    radioDot: { width: 9, height: 9, borderRadius: 999, backgroundColor: palette.violet600 },
    answerText: { flex: 1, fontSize: 11, fontWeight: '600', color: theme.ink700, fontFamily: font.body },
    error: { marginTop: 14, color: '#a33b3b', fontSize: 10, fontFamily: font.body },
    navActions: { flexShrink: 0, flexDirection: 'row', gap: 8, paddingTop: 24, paddingBottom: 4 },
    grow: { flexGrow: 1 },
    actions: { marginTop: 'auto', paddingTop: 24 },
  });
}

const makeStyles = memoizeStyles(createStyles);
