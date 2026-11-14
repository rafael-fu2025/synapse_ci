'use strict';

// Production component state tests, without a browser, server or database.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const api = {};
const source = fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/survey-builder.js'), 'utf8');
new Function('window', source)(api);
const blank = () => ({ type: 'text', label: '', required: true, options: [] });
const item = (id, revision = 1) => ({ id, revision, category: 'Feedback', audience: 'all',
  question: { type: 'single', label: 'Question ' + id, required: true, options: ['A', 'B'] } });
const builder = questions => api.SynapseSurveyBuilder(questions || [blank()], 'all', 'http://localhost/catalog');

test('bulk add replaces the initial blank, preserves selection order/revisions and clears selection', () => {
  const state = builder();
  state.toggleBankSelection(item(2, 3), true);
  state.toggleBankSelection(item(1, 7), true);
  state.addSelectedBankQuestions();
  assert.equal(state.questions.length, 2);
  assert.deepEqual(state.questions.map(q => [q.bank_id, q.bank_revision]), [[2, 3], [1, 7]]);
  assert.equal(state.bankSelected.length, 0);
  assert.match(state.bankMessage, /Added 2/);
});

test('page selection persists across result changes and deselection affects only the visible page', () => {
  const state = builder();
  state.bankItems = [item(1), item(2)];
  state.toggleBankSelection(state.bankItems[0], true);
  assert.equal(state.bankPagePartlySelected(), true);
  state.toggleBankPage(true);
  assert.equal(state.bankPageSelected(), true);
  state.bankItems = [item(3)];
  assert.equal(state.bankPageSelected(), false);
  state.toggleBankPage(true);
  assert.equal(state.bankSelected.length, 3);
  state.bankItems = [item(1), item(2)];
  assert.equal(state.bankPageSelected(), true);
  state.toggleBankPage(false);
  assert.deepEqual(state.bankSelected.map(q => q.id), [3]);
  state.clearBankSelection();
  assert.equal(state.bankSelected.length, 0);
});

test('over-capacity bulk add is atomic and retains selections for correction', () => {
  const state = builder(Array.from({ length: 19 }, (_, i) => ({ ...blank(), label: 'Custom ' + i })));
  state.toggleBankSelection(item(1), true);
  state.toggleBankSelection(item(2), true);
  const before = JSON.stringify(state.questions);
  state.addSelectedBankQuestions();
  assert.equal(JSON.stringify(state.questions), before);
  assert.equal(state.bankSelected.length, 2);
  assert.match(state.bankSelectionError, /Only 1.*nothing was added/);
  state.questions.pop();
  state.addSelectedBankQuestions();
  assert.equal(state.questions.length, 20);
  assert.equal(state.bankSelected.length, 0);
  assert.equal(state.bankSelectionError, '');
});

test('twenty selections fit exactly when the sole initial question is blank', () => {
  const state = builder();
  state.bankItems = Array.from({ length: 20 }, (_, i) => item(i + 1));
  state.toggleBankPage(true);
  state.addSelectedBankQuestions();
  assert.equal(state.questions.length, 20);
  assert.equal(state.questions[0].bank_id, 1);
  assert.equal(state.questions[19].bank_id, 20);
  assert.equal(state.remainingQuestionSlots(), 0);
});

test('select-page and individual selection never silently exceed twenty selections', () => {
  const state = builder();
  for (let i = 1; i <= 10; i++) state.toggleBankSelection(item(i), true);
  state.bankItems = Array.from({ length: 11 }, (_, i) => item(i + 11));
  state.toggleBankPage(true);
  assert.equal(state.bankSelected.length, 10);
  assert.equal(state.bankPageSelected(), false);
  assert.match(state.bankSelectionError, /exceed 20/);
  for (let i = 11; i <= 20; i++) state.toggleBankSelection(item(i), true);
  state.toggleBankSelection(item(21), true);
  assert.equal(state.bankSelected.length, 20);
  assert.equal(state.isBankSelected(item(21)), false);
});

test('selection deduplicates exact revisions and snapshots do not share choice arrays', () => {
  const state = builder();
  const original = item(1);
  state.toggleBankSelection(original, true);
  state.toggleBankSelection(original, true);
  assert.equal(state.bankSelected.length, 1);
  original.question.options[0] = 'Later catalog edit';
  state.toggleBankSelection(item(1, 2), true);
  assert.equal(state.bankSelected.length, 2);
  state.addSelectedBankQuestions();
  assert.equal(state.questions[0].options[0], 'A');
  state.questions[0].options[0] = 'Custom';
  assert.equal(state.questions[1].options[0], 'A');
  state.detach(state.questions[0]);
  assert.equal(state.questions[0].bank_id, undefined);
  assert.equal(state.questions[1].bank_id, 1);
});

test('single-copy action removes only that selected revision and keeps other selections', () => {
  const state = builder();
  state.toggleBankSelection(item(1), true);
  state.toggleBankSelection(item(2), true);
  state.addBankQuestion(item(1));
  assert.deepEqual(state.bankSelected.map(q => q.id), [2]);
  assert.equal(state.questions.length, 1);
  state.addSelectedBankQuestions();
  assert.deepEqual(state.questions.map(q => q.bank_id), [1, 2]);
});

test('empty batch is a no-op and clearing selection does not hide a catalog error', () => {
  const state = builder();
  state.addSelectedBankQuestions();
  assert.deepEqual(state.questions, [blank()]);
  state.bankError = 'Catalog unavailable';
  state.bankSelectionError = 'Selection is too large';
  state.toggleBankSelection(item(1), true);
  state.clearBankSelection();
  assert.equal(state.bankSelectionError, '');
  assert.equal(state.bankError, 'Catalog unavailable');
});

test('surveyMode defaults to internal and setMode(external) closes the question bank', () => {
  const defaultState = api.SynapseSurveyBuilder([], null, 'http://localhost/bank');
  assert.equal(defaultState.surveyMode, 'internal');
  assert.equal(defaultState.externalUrl, '');

  const externalState = api.SynapseSurveyBuilder(
    [],
    null,
    'http://localhost/bank',
    'external',
    'https://docs.google.com/forms/d/e/test/viewform'
  );
  assert.equal(externalState.surveyMode, 'external');
  assert.equal(externalState.externalUrl, 'https://docs.google.com/forms/d/e/test/viewform');

  defaultState.bankOpen = true;
  defaultState.bankQuery = 'stress';
  defaultState.bankBusy = true;
  defaultState.setMode('external');
  assert.equal(defaultState.surveyMode, 'external');
  assert.equal(defaultState.bankOpen, false);
  assert.equal(defaultState.bankBusy, false);

  defaultState.setMode('invalid-mode');
  assert.equal(defaultState.surveyMode, 'internal');
});

test('AI prefill applyAiResult handles replace, append, 20-question limit, and blank title/description', () => {
  const state = api.SynapseSurveyBuilder(
    [blank()],
    'all',
    'http://localhost/catalog',
    'internal',
    '',
    'http://localhost/guidance/surveys/ai-prefill'
  );
  const titleEl = { value: '' };
  const descEl = { value: 'Existing description' };

  const applied = state.applyAiResult({
    suggested_title: 'Wellbeing Orientation',
    suggested_description: 'Should not overwrite non-empty description',
    questions: [
      { type: 'rating', label: 'How helpful was orientation?', required: true, options: [] },
      { type: 'single', label: 'Single choice with 1 option', required: false, options: ['Only'] },
    ],
    warnings: ['Trimmed extra blank'],
  }, titleEl, descEl);

  assert.equal(applied, true);
  assert.equal(titleEl.value, 'Wellbeing Orientation');
  assert.equal(descEl.value, 'Existing description');
  assert.equal(state.questions.length, 2);
  assert.equal(state.questions[0].type, 'rating');
  assert.equal(state.questions[1].type, 'text');
  assert.deepEqual(state.aiWarnings, ['Trimmed extra blank']);

  // Append mode respects 20-question ceiling cleanly
  state.questions.splice(0, state.questions.length, ...Array.from({ length: 19 }, (_, i) => ({ ...blank(), label: 'Existing ' + i })));
  state.aiMergeMode = 'append';
  const appended = state.applyAiResult({
    questions: [
      { type: 'text', label: 'Slot 20', required: true, options: [] },
      { type: 'text', label: 'Overflow 21', required: true, options: [] },
    ],
  }, titleEl, descEl);
  assert.equal(appended, true);
  assert.equal(state.questions.length, 20);
  assert.equal(state.questions[19].label, 'Slot 20');
  assert.ok(state.aiWarnings.some(w => /20-question limit/.test(w)));

  // Switching to external closes AI panel too
  state.aiOpen = true;
  state.aiBusy = true;
  state.setMode('external');
  assert.equal(state.aiOpen, false);
  assert.equal(state.aiBusy, false);
});

test('runAiPrefill validates inputs, sends multipart FormData with CSRF headers, and syncs rotated CSRF', async () => {
  const calls = [];
  const synced = [];
  const formUploads = [];
  class FakeFormData {
    constructor() { this.fields = []; formUploads.push(this.fields); }
    append(k, v) { this.fields.push([k, v]); }
  }
  const context = {
    URL,
    AbortController,
    FormData: FakeFormData,
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    TextDecoder,
    location: new URL('http://localhost/index.php/guidance/surveys/create'),
    window: {
      SynapseCsrf: {
        headers: () => ({ 'X-CSRF-TOKEN': 'initial-csrf' }),
        fromResponse: () => false,
        sync: (token) => synced.push(token),
      },
    },
    fetch: async (url, options) => {
      calls.push({ url: String(url), options });
      return {
        ok: true,
        status: 200,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => ({
          success: true,
          csrf: { name: 'csrf_test_name', hash: 'rotated-csrf' },
          suggested_title: 'Stress Survey',
          suggested_description: 'Answer honestly.',
          questions: [{ type: 'text', label: 'How are you coping?', required: true, options: [] }],
          warnings: [],
        }),
      };
    },
  };
  vm.runInNewContext(source, context);
  const state = context.window.SynapseSurveyBuilder(
    [blank()],
    'all',
    'http://localhost/index.php/guidance/question-bank/catalog',
    'internal',
    '',
    'http://localhost/index.php/guidance/surveys/ai-prefill'
  );

  // Reject empty input
  await state.runAiPrefill({ files: [] }, { value: '' }, { value: '' });
  assert.match(state.aiError, /Paste survey questions/);
  assert.equal(calls.length, 0);

  // Valid pasted + small file extraction
  state.aiText = '1. How are you coping?';
  const titleEl = { value: '' };
  const descEl = { value: '' };
  await state.runAiPrefill({ files: [{ name: 'form.txt', size: 120 }] }, titleEl, descEl);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].options.method, 'POST');
  assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'initial-csrf');
  assert.deepEqual(synced, [{ name: 'csrf_test_name', hash: 'rotated-csrf' }]);
  assert.equal(titleEl.value, 'Stress Survey');
  assert.equal(state.questions.length, 1);
  assert.equal(state.questions[0].label, 'How are you coping?');
  assert.equal(formUploads[0].length, 2);
});

test('runAiPrefill consumes SSE progress, raw SSE frames, rating labels, and clarification re-extract', async () => {
  const formUploads = [];
  const synced = [];
  class FakeFormData {
    constructor() { this.fields = []; formUploads.push(this.fields); }
    append(k, v) { this.fields.push([k, v]); }
  }
  const encoder = new TextEncoder();
  const makeSseResponse = blocks => {
    let idx = 0;
    return {
      ok: true,
      status: 200,
      headers: new Headers({ 'Content-Type': 'text/event-stream; charset=utf-8' }),
      body: {
        getReader() {
          return {
            async read() {
              if (idx >= blocks.length) return { done: true, value: undefined };
              return { done: false, value: encoder.encode(blocks[idx++]) };
            },
          };
        },
      },
    };
  };

  let requestNum = 0;
  const context = {
    URL,
    AbortController,
    FormData: FakeFormData,
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    TextDecoder,
    location: new URL('http://localhost/index.php/guidance/surveys/create'),
    window: {
      SynapseCsrf: {
        headers: () => ({ 'X-CSRF-TOKEN': 'csrf-1' }),
        fromResponse: () => false,
        sync: token => synced.push(token),
      },
    },
    fetch: async () => {
      ++requestNum;
      if (requestNum === 1) {
        return makeSseResponse([
          'event: stage\ndata: {"step":1,"total":4,"percent":15,"stage":"extracting","label":"Reading text..."}\n\n',
          'event: raw\ndata: {"line":"data: {\\"choices\\":[{\\"delta\\":{\\"content\\":\\"{\\"}}]}"}\n\n',
          'event: result\ndata: ' + JSON.stringify({
            success: true,
            csrf: { name: 'csrf_test_name', hash: 'csrf-2' },
            suggested_title: 'Scale Check',
            suggested_description: '',
            questions: [
              { type: 'rating', label: 'Comfort level', required: true, options: [], scale_min_label: 'Lowest', scale_max_label: 'Highest' },
            ],
            clarifications: [
              { id: 'c1', question: 'Does 1 mean Lowest?', suggested_options: ['Yes, 1 = Lowest', 'No, 1 = Highest'], answer: '' },
            ],
            warnings: [],
          }) + '\n\n',
        ]);
      }
      return {
        ok: true,
        status: 200,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => ({
          success: true,
          csrf: { name: 'csrf_test_name', hash: 'csrf-3' },
          suggested_title: 'Scale Check',
          suggested_description: '',
          questions: [
            { type: 'rating', label: 'Comfort level', required: true, options: [], scale_min_label: 'Lowest', scale_max_label: 'Highest' },
            { type: 'single', label: 'Contact channel', required: true, options: ['Email', 'SMS'], allow_other: true },
          ],
          clarifications: [],
          warnings: [],
        }),
      };
    },
  };

  vm.runInNewContext(source, context);
  const state = context.window.SynapseSurveyBuilder(
    [blank()],
    'all',
    'http://localhost/index.php/guidance/question-bank/catalog',
    'internal',
    '',
    'http://localhost/index.php/guidance/surveys/ai-prefill'
  );
  state.aiText = '1. Comfort level (1-5)';
  await state.runAiPrefill({ files: [] }, { value: '' }, { value: '' }, false);

  assert.equal(state.aiPercent, 100);
  assert.equal(state.aiStage, 'done');
  assert.equal(state.aiRawLog.length, 1);
  assert.equal(state.questions[0].scale_min_label, 'Lowest');
  assert.equal(state.questions[0].scale_max_label, 'Highest');
  assert.equal(state.aiClarifications.length, 1);

  // Pick a clarification chip & re-extract
  state.pickClarificationOption(state.aiClarifications[0], 'Yes, 1 = Lowest');
  await state.runAiPrefill({ files: [] }, { value: '' }, { value: '' }, true);
  assert.equal(state.aiClarifications.length, 0);
  assert.equal(state.questions[1].allow_other, true);
  assert.ok(formUploads[1].some(([k, v]) => k === 'clarifications_json' && /Yes, 1 = Lowest/.test(v)));
  assert.deepEqual(JSON.parse(JSON.stringify(synced)), [
    { name: 'csrf_test_name', hash: 'csrf-2' },
    { name: 'csrf_test_name', hash: 'csrf-3' },
  ]);
});
