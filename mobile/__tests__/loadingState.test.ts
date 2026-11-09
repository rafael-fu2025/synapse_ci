import { loadingState } from '../src/lib/loadingState';

it.each([null, { data: [] }, { data: [1] }])('treats a successful cached response as content even while refreshing or failing (%p)', data => {
  expect(loadingState({ data, isLoading: true, isError: true, fetchStatus: 'fetching' })).toBe('content');
  expect(loadingState({ data, isError: true, fetchStatus: 'paused' })).toBe('content');
});

it('distinguishes initial fetching, failure and waiting without pretending pending data is empty', () => {
  expect(loadingState({ fetchStatus: 'fetching' })).toBe('loading');
  expect(loadingState({ isLoading: true })).toBe('loading');
  expect(loadingState({ isError: true, fetchStatus: 'idle' })).toBe('error');
  expect(loadingState({ fetchStatus: 'paused' })).toBe('waiting');
  expect(loadingState({ fetchStatus: 'idle' })).toBe('waiting');
  expect(loadingState({})).toBe('waiting');
});
