import type { SurveyQuestion } from '../src/api/endpoints/surveys';
import { hasSurveyAnswer, orderedSurveyAnswers } from '../src/features/surveys/answers';

function question(type: SurveyQuestion['type']): SurveyQuestion {
  return { type, label: 'Question', required: true, options: ['First', 'Second'] };
}

describe('survey answer validation', () => {
  it.each<[SurveyQuestion['type'], unknown, boolean]>([
    ['text', undefined, false],
    ['text', null, false],
    ['text', '', false],
    ['text', ' \n\t ', false],
    ['text', '  A response  ', true],
    ['text', 0, false],
    ['single', 0, true],
    ['single', 1, true],
    ['single', -1, false],
    ['single', 2, false],
    ['single', '0', false],
    ['single', [], false],
    ['multiple', [], false],
    ['multiple', [0], true],
    ['multiple', [0, 1], true],
    ['multiple', [2], false],
    ['multiple', [null], false],
    ['rating', 0, false],
    ['rating', 1, true],
    ['rating', 5, true],
    ['rating', 6, false],
    ['rating', 1.5, false],
  ])('validates %s answer %p as %s', (type, answer, expected) => {
    expect(hasSurveyAnswer(question(type), answer)).toBe(expected);
  });

  it('preserves positional answers and option zero, trims text, and sends omitted optional answers as null', () => {
    const questions = ['text', 'single', 'multiple', 'rating', 'text', 'multiple', 'text'].map(type => ({
      ...question(type as SurveyQuestion['type']), required: false,
    }));
    expect(orderedSurveyAnswers(questions, {
      0: '  Useful feedback  ', 1: 0, 2: [0], 3: 5, 4: ' \t ', 5: [],
    })).toEqual(['Useful feedback', 0, [0], 5, null, null, null]);
  });
});
