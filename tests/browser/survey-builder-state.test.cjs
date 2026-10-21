'use strict';

// Production component state tests, without a browser, server or database.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const api = {};
new Function('window', fs.readFileSync(path.resolve(__dirname, '../../public/assets/js/survey-builder.js'), 'utf8'))(api);
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
