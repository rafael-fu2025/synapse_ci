import { summary } from '../src/api/endpoints/staffQueue';
import { bindApiClient, request } from '../src/api/client';
import { useStaffQueueSummary } from '../src/features/staff-queue/hooks';
import { useQuery } from '@tanstack/react-query';
import { useRealtimePoll } from '../src/lib/useRealtimePoll';
jest.mock('../src/api/client', () => ({ request: jest.fn(), bindApiClient: jest.fn() }));
jest.mock('../src/api/runtime', () => ({ useApiClient: () => mockClient, bindApiQueryResult: (_client: unknown, result: unknown) => result }));
const mockClient = { request: jest.fn() };
jest.mock('@tanstack/react-query', () => ({ useQuery: jest.fn() }));
jest.mock('../src/lib/useRealtimePoll', () => ({ useRealtimePoll: jest.fn(() => 15000) }));
beforeEach(() => {
  jest.clearAllMocks();
  mockClient.request.mockImplementation(request);
  jest.mocked(bindApiClient).mockReturnValue(mockClient as unknown as ReturnType<typeof bindApiClient>);
});

it('requests the read-only all-authorized projection without a unit filter', async () => {
  const response = { data: [], meta: { date: '2026-11-05', generated_at: '2026-11-05T10:00:00Z' } };
  jest.mocked(request).mockResolvedValueOnce(response);
  await expect(summary()).resolves.toEqual(response);
  expect(request).toHaveBeenCalledWith('api/v1/staff/queue-summary');
});
it('retains API errors rather than misreporting an empty queue', async () => {
  jest.mocked(request).mockRejectedValueOnce(new Error('forbidden'));
  await expect(summary()).rejects.toThrow('forbidden');
});
it('uses one all-unit query and the realtime-aware 15 second fallback polling policy', async () => {
  useStaffQueueSummary();
  expect(useRealtimePoll).toHaveBeenCalledWith(15000);
  const options = jest.mocked(useQuery).mock.calls[0][0];
  expect(options.refetchInterval).toBe(15000);
  await (options.queryFn as () => Promise<unknown>)();
  expect(request).toHaveBeenLastCalledWith('api/v1/staff/queue-summary');
});
it('keeps the query eligible when realtime pauses fallback polling', async () => {
  jest.mocked(useRealtimePoll).mockReturnValueOnce(false);
  useStaffQueueSummary();
  const options = jest.mocked(useQuery).mock.calls[0][0];
  expect(options.refetchInterval).toBe(false);
  expect(options.enabled).not.toBe(false);
  await (options.queryFn as () => Promise<unknown>)();
  expect(request).toHaveBeenLastCalledWith('api/v1/staff/queue-summary');
});
