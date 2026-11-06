import { summary } from '../src/api/endpoints/staffQueue';
import { request } from '../src/api/client';
import { useStaffQueueSummary } from '../src/features/staff-queue/hooks';
import { useQuery } from '@tanstack/react-query';
import { useFocusPoll } from '../src/lib/useFocusPoll';
jest.mock('../src/api/client', () => ({ request: jest.fn() }));
jest.mock('@tanstack/react-query', () => ({ useQuery: jest.fn() }));
jest.mock('../src/lib/useFocusPoll', () => ({ useFocusPoll: jest.fn(() => 15000) }));

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
it('uses one all-unit query and the existing focus-aware 15 second polling policy', async () => {
  useStaffQueueSummary();
  expect(useFocusPoll).toHaveBeenCalledWith(15000);
  const options = jest.mocked(useQuery).mock.calls[0][0];
  expect(options.refetchInterval).toBe(15000);
  await (options.queryFn as () => Promise<unknown>)();
  expect(request).toHaveBeenLastCalledWith('api/v1/staff/queue-summary');
});
