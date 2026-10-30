import React from 'react';
import { Alert, BackHandler, FlatList, ScrollView, StyleSheet, Text, TextInput } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { usePreventRemove } from '@react-navigation/native';
import type { SurveyForm, SurveyListItem, SurveyQuestion } from '../src/api/endpoints/surveys';
import { ApiError, NetworkError } from '../src/api/client';
import { Action } from '../src/components/Action';
import { PageHeader } from '../src/components/PageHeader';
import { useSubmitSurvey, useSurveyForm, useSurveys } from '../src/features/surveys/hooks';
import { SurveyFormScreen } from '../src/features/surveys/screens/SurveyFormScreen';
import { SurveysScreen } from '../src/features/surveys/screens/SurveysScreen';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/features/surveys/hooks', () => ({ useSubmitSurvey: jest.fn(), useSurveyForm: jest.fn(), useSurveys: jest.fn() }));
jest.mock('../src/theme/ThemeProvider', () => ({
  useTheme: () => ({ theme: jest.requireActual('../src/theme/tokens').lightTheme }),
}));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: jest.requireActual('react-native').View }));
jest.mock('lucide-react-native', () => ({
  ArrowLeft: () => null, ChevronRight: () => null, CircleCheckBig: () => null,
  ClipboardList: () => null, Clock3: () => null, ListOrdered: () => null,
  MessageSquareText: () => null, ShieldCheck: () => null, TriangleAlert: () => null,
}));
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => mockNavigation,
  useRoute: () => ({ params: { id: 7 } }),
  usePreventRemove: jest.fn(),
  useFocusEffect: (effect: () => void | (() => void)) => require('react').useEffect(effect, [effect]),
}));

const mockNavigation = { goBack: jest.fn(), navigate: jest.fn(), dispatch: jest.fn() };
const mutateAsync = jest.fn();
const refetch = jest.fn();
let renderer: ReactTestRenderer | undefined;
let hardwareBack: () => boolean | null | undefined;
let removeBackListener: jest.Mock;
let formQuery: {
  data?: { data: SurveyForm }; isLoading: boolean; error?: Error; isFetching?: boolean; refetch?: typeof refetch;
};
let mutation: { mutateAsync: typeof mutateAsync; isPending: boolean; isSuccess: boolean };
let listQuery: {
  data?: { data: SurveyListItem[]; counts: { all: number; available: number; submitted: number; closed: number } };
  isLoading: boolean; isError: boolean; isFetching: boolean; isRefetching: boolean; refetch: typeof refetch;
};

function question(type: SurveyQuestion['type'], label = 'Question', required = true): SurveyQuestion {
  return { type, label, required, options: type === 'single' || type === 'multiple' ? ['First', 'Second'] : [] };
}

function renderForm(questions = [question('text')], submitted = false) {
  formQuery.data!.data.questions = questions;
  formQuery.data!.data.submitted = submitted;
  act(() => { renderer = create(<SurveyFormScreen />); });
}

function renderList() {
  act(() => { renderer = create(<SurveysScreen />); });
}

function refreshForm() {
  act(() => renderer!.update(<SurveyFormScreen />));
}

function action(label: string) {
  return renderer!.root.findAllByType(Action).find(node => node.props.children === label)!;
}

function tap(label: string) {
  act(() => action(label).props.onPress());
}

function hasText(text: string) {
  return renderer!.root.findAllByType(Text).some(node => node.props.children === text);
}

function input(value: string) {
  act(() => renderer!.root.findByType(TextInput).props.onChangeText(value));
}

function choice(index: number) {
  return renderer!.root.findAll(node =>
    ['radio', 'checkbox'].includes(node.props.accessibilityRole) &&
    typeof node.props.onPress === 'function' &&
    node.parent?.props.accessibilityRole !== node.props.accessibilityRole)[index];
}

function headerBack() {
  act(() => renderer!.root.findByType(PageHeader).props.onBack());
}

function guard() {
  const calls = jest.mocked(usePreventRemove).mock.calls;
  return calls[calls.length - 1];
}

function attemptRemoval() {
  act(() => guard()[1]({ data: { action: { type: 'GO_BACK' } } }));
}

beforeEach(() => {
  jest.clearAllMocks();
  mutateAsync.mockReset().mockResolvedValue({ data: { response_id: 42 } });
  refetch.mockReset().mockResolvedValue(undefined);
  formQuery = { data: { data: {
    id: 7, title: 'Campus care', description: 'Your feedback matters.', ends_at: null, questions: [], submitted: false,
  } }, isLoading: false };
  mutation = { mutateAsync, isPending: false, isSuccess: false };
  listQuery = { isLoading: false, isError: false, isFetching: false, isRefetching: false, refetch };
  jest.mocked(useSurveyForm).mockImplementation(() => formQuery as unknown as ReturnType<typeof useSurveyForm>);
  jest.mocked(useSubmitSurvey).mockImplementation(() => mutation as unknown as ReturnType<typeof useSubmitSurvey>);
  jest.mocked(useSurveys).mockImplementation(() => listQuery as unknown as ReturnType<typeof useSurveys>);
  jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  removeBackListener = jest.fn();
  jest.spyOn(BackHandler, 'addEventListener').mockImplementation((_, callback) => {
    hardwareBack = () => callback({} as Parameters<typeof callback>[0]);
    return { remove: removeBackListener };
  });
});

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
  jest.restoreAllMocks();
});

describe('SurveyFormScreen', () => {
  it.each([
    new NetworkError(),
    new ApiError(503, { code: 'unavailable', message: 'Service unavailable.' }),
    new Error('Unexpected failure'),
    Object.assign(new Error('Not an API 404'), { status: 404 }),
  ])('offers retry instead of claiming the survey is missing for %p', async error => {
    const loadedData = formQuery.data;
    formQuery = { isLoading: false, error, isFetching: false, refetch };
    act(() => { renderer = create(<SurveyFormScreen />); });
    expect(hasText('The survey could not be loaded. Please try again.')).toBe(true);
    expect(hasText('This survey is no longer available.')).toBe(false);
    expect(guard()[0]).toBe(false);
    headerBack();
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
    await act(async () => action('Retry').props.onPress());
    expect(refetch).toHaveBeenCalledTimes(1);
    formQuery.isFetching = true;
    refreshForm();
    expect(action('Retry').props.loading).toBe(true);
    formQuery = { data: loadedData, isLoading: false, isFetching: false, refetch };
    refreshForm();
    expect(action('Start survey')).toBeDefined();
    expect(hasText('The survey could not be loaded. Please try again.')).toBe(false);
  });

  it('shows the unavailable message only for an API 404 and keeps the back header', () => {
    formQuery = { isLoading: false, error: new ApiError(404, { code: 'not_found', message: 'Survey not found.' }), refetch };
    act(() => { renderer = create(<SurveyFormScreen />); });
    expect(hasText('This survey is no longer available.')).toBe(true);
    expect(hasText('The survey could not be loaded. Please try again.')).toBe(false);
    expect(action('Retry')).toBeUndefined();
    headerBack();
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
    expect(refetch).not.toHaveBeenCalled();
    expect(guard()[0]).toBe(false);
  });

  it('scrolls long question content while keeping the header and navigation controls outside the scroll area', async () => {
    const label = 'A detailed question about your experience. '.repeat(8);
    const options = Array.from({ length: 10 }, (_, index) => `${index}: ${'Detailed option text. '.repeat(5)}`);
    renderForm([{ ...question('single', label), options }, question('text', 'Tell us more')]);
    tap('Start survey');
    const content = renderer!.root.findByType(ScrollView);
    expect(StyleSheet.flatten(content.props.style).flex).toBe(1);
    expect(content.props.keyboardShouldPersistTaps).toBe('handled');
    expect(content.findAllByType(Text).some(node => node.props.children === label)).toBe(true);
    expect(content.findAllByType(Text).some(node => node.props.children === options[9])).toBe(true);
    expect(content.findAllByType(PageHeader)).toHaveLength(0);
    expect(content.findAllByType(Action)).toHaveLength(0);
    expect(action('Back')).toBeDefined();
    expect(action('Continue')).toBeDefined();
    act(() => choice(9).props.onPress());
    tap('Continue');
    const textContent = renderer!.root.findByType(ScrollView);
    expect(textContent.findByType(TextInput).props.multiline).toBe(true);
    expect(textContent.findAllByType(Action)).toHaveLength(0);
    const answer = 'More feedback.\n'.repeat(20);
    input(answer);
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    expect(mutateAsync).toHaveBeenCalledWith({ id: 7, answers: [9, answer.trim()] });
    expect(hasText('Response recorded')).toBe(true);
  });

  it.each(['', ' \n\t '])('does not count or submit blank required text %p on the final question', async value => {
    renderForm();
    tap('Start survey');
    input(value);
    const submit = renderer!.root.findAllByType(Action).at(-1)!;
    expect(submit.props.children.join('')).toBe('Submit (0/1)');
    await act(async () => submit.props.onPress());
    expect(mutateAsync).not.toHaveBeenCalled();
    expect(hasText('Please answer all required questions before submitting.')).toBe(true);
  });

  it('validates all questions on submit and returns to an earlier missing required answer', async () => {
    renderForm([question('text', 'Earlier feedback', false), question('single', 'Choose')]);
    tap('Start survey');
    tap('Continue');
    act(() => choice(0).props.onPress());
    const survey = formQuery.data!.data;
    formQuery.data = { data: { ...survey, questions: survey.questions.map(item => ({ ...item, required: true })) } };
    refreshForm();
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    expect(mutateAsync).not.toHaveBeenCalled();
    expect(hasText('Earlier feedback')).toBe(true);
    expect(hasText('Please answer all required questions before submitting.')).toBe(true);
  });

  it('rejects whitespace on Continue and empty multiple selections on Submit; accepts option zero', async () => {
    renderForm([question('text', 'Feedback'), question('multiple', 'Choose')]);
    tap('Start survey');
    input(' \t ');
    tap('Continue');
    expect(hasText('This question is required.')).toBe(true);
    expect(hasText('Feedback')).toBe(true);
    input(' Good support ');
    tap('Continue');
    act(() => choice(0).props.onPress());
    act(() => choice(0).props.onPress());
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    expect(mutateAsync).not.toHaveBeenCalled();
    expect(hasText('Please answer all required questions before submitting.')).toBe(true);
    act(() => choice(0).props.onPress());
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    expect(mutateAsync).toHaveBeenCalledWith({ id: 7, answers: ['Good support', [0]] });
    expect(hasText('Response recorded')).toBe(true);
  });

  it('accepts single option index zero and sends optional blanks as null', async () => {
    renderForm([question('single'), question('text', 'Optional', false)]);
    tap('Start survey');
    act(() => choice(0).props.onPress());
    tap('Continue');
    input('   ');
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    expect(mutateAsync).toHaveBeenCalledWith({ id: 7, answers: [0, null] });
  });

  it('blocks same-tick duplicate submission, edits, and back while pending, then releases removal after success', async () => {
    let resolve!: (value: unknown) => void;
    mutateAsync.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    renderForm();
    expect(guard()[0]).toBe(false);
    tap('Start survey');
    input('Original answer');
    const oldEdit = renderer!.root.findByType(TextInput).props.onChangeText;
    const submit = renderer!.root.findAllByType(Action).at(-1)!.props.onPress;
    act(() => {
      submit();
      submit();
      oldEdit('Changed answer');
    });
    expect(mutateAsync).toHaveBeenCalledTimes(1);
    expect(renderer!.root.findByType(TextInput).props.editable).toBe(false);
    expect(renderer!.root.findByType(TextInput).props.value).toBe('Original answer');
    expect(action('Back').props.disabled).toBe(true);
    expect(guard()[0]).toBe(true);
    headerBack();
    act(() => expect(hardwareBack()).toBe(true));
    attemptRemoval();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(mockNavigation.dispatch).not.toHaveBeenCalled();
    await act(async () => resolve({ data: { response_id: 42 } }));
    expect(guard()[0]).toBe(false);
    expect(hardwareBack()).toBe(false);
    tap('Back to surveys');
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
    expect(removeBackListener).toHaveBeenCalled();
  });

  it.each(['single', 'multiple', 'rating'] as const)('locks %s choices during submission, including old handlers', async type => {
    let resolve!: (value: unknown) => void;
    mutateAsync.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    renderForm([question(type)]);
    tap('Start survey');
    act(() => choice(0).props.onPress());
    const oldEdit = choice(1).props.onPress;
    act(() => {
      renderer!.root.findAllByType(Action).at(-1)!.props.onPress();
      oldEdit();
    });
    expect(choice(0).props.disabled).toBe(true);
    expect(choice(0).props.accessibilityState.checked).toBe(true);
    expect(choice(1).props.accessibilityState.checked).toBe(false);
    await act(async () => resolve({ data: { response_id: 42 } }));
  });

  it('keeps the local success view before submitted, loading, and missing-data refetch branches', async () => {
    renderForm();
    tap('Start survey');
    input('An answer');
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    formQuery.data!.data.submitted = true;
    refreshForm();
    expect(hasText('Response recorded')).toBe(true);
    expect(hasText('Already submitted')).toBe(false);
    mutation.isSuccess = false;
    formQuery = { isLoading: true };
    refreshForm();
    expect(hasText('Response recorded')).toBe(true);
    formQuery.isLoading = false;
    refreshForm();
    expect(hasText('Response recorded')).toBe(true);
    expect(guard()[0]).toBe(false);
    headerBack();
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('prioritizes mutation success over an already-submitted refetch even before the local receipt is set', () => {
    mutation.isSuccess = true;
    renderForm(undefined, true);
    expect(hasText('Response recorded')).toBe(true);
    expect(hasText('Already submitted')).toBe(false);
    expect(guard()[0]).toBe(false);
  });

  it('allows already-submitted surveys to leave without a draft guard', () => {
    renderForm(undefined, true);
    expect(hasText('Already submitted')).toBe(true);
    expect(guard()[0]).toBe(false);
    expect(hardwareBack()).toBe(false);
    tap('Back to surveys');
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
  });

  it('uses header and hardware back for previous questions/intro and preserves the draft on resume', () => {
    renderForm([question('text', 'First question'), question('text', 'Second question')]);
    tap('Start survey');
    input('Kept answer');
    tap('Continue');
    headerBack();
    expect(hasText('First question')).toBe(true);
    expect(renderer!.root.findByType(TextInput).props.value).toBe('Kept answer');
    tap('Continue');
    act(() => expect(hardwareBack()).toBe(true));
    expect(hasText('First question')).toBe(true);
    act(() => expect(hardwareBack()).toBe(true));
    expect(action('Resume survey')).toBeDefined();
    expect(mockNavigation.goBack).not.toHaveBeenCalled();
    tap('Resume survey');
    expect(renderer!.root.findByType(TextInput).props.value).toBe('Kept answer');
    headerBack();
    headerBack();
    expect(mockNavigation.goBack).toHaveBeenCalledTimes(1);
    attemptRemoval();
    const buttons = jest.mocked(Alert.alert).mock.calls[0][2]!;
    expect(buttons[0]).toMatchObject({ text: 'Keep editing', style: 'cancel' });
    expect(mockNavigation.dispatch).not.toHaveBeenCalled();
    act(() => buttons[1].onPress!());
    expect(mockNavigation.dispatch).toHaveBeenCalledWith({ type: 'GO_BACK' });
  });

  it('confirms leaving even an unanswered started draft, but not an untouched intro', () => {
    renderForm();
    expect(guard()[0]).toBe(false);
    expect(hardwareBack()).toBe(false);
    tap('Start survey');
    headerBack();
    expect(guard()[0]).toBe(true);
    expect(hardwareBack()).toBe(false);
    attemptRemoval();
    expect(Alert.alert).toHaveBeenCalledWith('Discard survey draft?', expect.any(String), expect.any(Array));
  });

  it('preserves answers after failure, unlocks editing, and allows a new submission', async () => {
    mutateAsync.mockRejectedValueOnce(new Error('Offline'));
    renderForm();
    tap('Start survey');
    input('Retry me');
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    expect(hasText('The survey could not be submitted. Try again.')).toBe(true);
    expect(renderer!.root.findByType(TextInput).props.value).toBe('Retry me');
    expect(renderer!.root.findByType(TextInput).props.editable).toBe(true);
    expect(guard()[0]).toBe(true);
    input('Updated answer');
    await act(async () => renderer!.root.findAllByType(Action).at(-1)!.props.onPress());
    expect(mutateAsync).toHaveBeenCalledTimes(2);
    expect(mutateAsync).toHaveBeenLastCalledWith({ id: 7, answers: ['Updated answer'] });
    expect(guard()[0]).toBe(false);
  });
});

describe('SurveysScreen', () => {
  const item: SurveyListItem = {
    id: 7, title: 'Campus care', description: 'Your feedback matters.', starts_at: null,
    ends_at: null, published_at: null, status: 'published', submitted: false, closed: false,
  };

  it('shows a real empty placeholder only after a successful empty response', () => {
    listQuery.data = { data: [], counts: { all: 0, available: 0, submitted: 0, closed: 0 } };
    renderList();
    expect(hasText('No surveys yet')).toBe(true);
    expect(hasText('Could not load surveys')).toBe(false);
    expect(action('Retry')).toBeUndefined();
  });

  it('shows fetch errors rather than an empty placeholder and supports retry', async () => {
    listQuery.isError = true;
    renderList();
    expect(hasText('Could not load surveys')).toBe(true);
    expect(hasText('No surveys yet')).toBe(false);
    await act(async () => action('Retry').props.onPress());
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('keeps cached cards visible on refresh failure with retry and uses only supported metadata', () => {
    listQuery.isError = true;
    listQuery.data = { data: [item], counts: { all: 1, available: 1, submitted: 0, closed: 0 } };
    renderList();
    expect(hasText('Could not refresh surveys. Showing the last loaded results.')).toBe(true);
    expect(hasText('Campus care')).toBe(true);
    expect(hasText('Identified response')).toBe(true);
    expect(hasText('No deadline published')).toBe(true);
    expect(renderer!.root.findByType(FlatList).props.data).toEqual([item]);
    act(() => renderer!.root.findByProps({ accessibilityLabel: 'Start survey: Campus care' }).props.onPress());
    expect(mockNavigation.navigate).toHaveBeenCalledWith('SurveyForm', { id: 7 });
    expect(hasText('3 minutes')).toBe(false);
    expect(hasText('Optional')).toBe(false);
  });

  it('distinguishes an empty filter from no surveys and lets the user reset it', () => {
    listQuery.data = { data: [], counts: { all: 2, available: 0, submitted: 2, closed: 0 } };
    renderList();
    act(() => renderer!.root.findAll(node => typeof node.props.onPress === 'function').find(node =>
      node.findAllByType(Text).some(text => text.props.children === 'Available'))!.props.onPress());
    expect(hasText('No available surveys')).toBe(true);
    expect(hasText('No surveys yet')).toBe(false);
    tap('View all surveys');
    expect(jest.mocked(useSurveys)).toHaveBeenLastCalledWith({ status: 'all' });
  });

  it('does not make submitted or closed surveys actionable', () => {
    listQuery.data = { data: [{ ...item, submitted: true }, { ...item, id: 8, closed: true }], counts: { all: 2, available: 0, submitted: 1, closed: 1 } };
    renderList();
    expect(hasText('You already submitted this survey.')).toBe(true);
    expect(hasText('This survey has closed.')).toBe(true);
    expect(renderer!.root.findAllByProps({ accessibilityLabel: 'Start survey: Campus care' })).toHaveLength(0);
  });
});
