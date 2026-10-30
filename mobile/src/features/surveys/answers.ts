import type { SurveyQuestion } from '../../api/endpoints/surveys';

/** Option zero is an answer; blank text and empty selections are not. */
export function hasSurveyAnswer(question: SurveyQuestion, answer: unknown): boolean {
  switch (question.type) {
    case 'text':
      return typeof answer === 'string' && answer.trim().length > 0;
    case 'rating':
      return typeof answer === 'number' && Number.isInteger(answer) && answer >= 1 && answer <= 5;
    case 'single':
      return isOptionIndex(question, answer);
    case 'multiple':
      return Array.isArray(answer) && answer.length > 0 && answer.every(value => isOptionIndex(question, value));
  }
}

function isOptionIndex(question: SurveyQuestion, value: unknown): boolean {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value < question.options.length;
}

export function orderedSurveyAnswers(questions: SurveyQuestion[], answers: Record<number, unknown>): unknown[] {
  return questions.map((question, index) => {
    const answer = answers[index];
    if (!hasSurveyAnswer(question, answer)) {
      return null;
    }
    return typeof answer === 'string' ? answer.trim() : answer;
  });
}
