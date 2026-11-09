import { createContext, useContext, useMemo } from 'react';
import { useMutation, type MutateOptions, type UseMutationOptions, type UseMutationResult, type UseQueryResult } from '@tanstack/react-query';
import { bindApiClient, getApiRuntime, type ApiClient, type ApiRuntime } from './client';

/** ConnectionProvider supplies only a ready runtime; legacy normal-mode tests need no provider. */
const ApiRuntimeContext = createContext<ApiRuntime | null>(null);
export const ApiRuntimeProvider = ApiRuntimeContext.Provider;

/** Capture runtime AND account generation at render, not when queued work eventually runs. */
export function useApiClient() {
  const runtime = useContext(ApiRuntimeContext) ?? getApiRuntime();
  const sessionVersion = runtime.sessionVersion;
  return useMemo(() => bindApiClient(runtime, sessionVersion), [runtime, sessionVersion]);
}

/** Observer methods are mutable too: a retained refetch may not follow new query options. */
export function bindApiQueryResult<Data, Failure>(client: ApiClient, query: UseQueryResult<Data, Failure>): UseQueryResult<Data, Failure> {
  return {
    ...query,
    refetch: (...args: Parameters<typeof query.refetch>) => client.isCurrent() ? query.refetch(...args) : Promise.resolve(query),
  };
}

type CapturedMutation<Data, Variables, Context> = {
  input: Variables;
  client: ApiClient;
  options: ApiMutationOptions<Data, Variables, Context>;
};

type ApiMutationOptions<Data, Variables, Context> = Omit<UseMutationOptions<Data, Error, Variables, Context>, 'mutationFn'> & {
  mutationFn(input: Variables, client: ApiClient): Promise<Data>;
};

/** TanStack can replace pending options. Keep the original operation/client in variables. */
export function useApiMutation<Data, Variables = void, Context = unknown>(
  options: ApiMutationOptions<Data, Variables, Context>,
): UseMutationResult<Data, Error, Variables, Context> {
  const client = useApiClient();
  type Captured = CapturedMutation<Data, Variables, Context>;
  const mutation = useMutation<Data, Error, Captured, Context>({
    ...options,
    mutationKey: ['api-runtime', client.runtime.id, client.sessionVersion, ...(options.mutationKey ?? [])],
    mutationFn: async captured => {
      captured.client.assertCurrent();
      const result = await captured.options.mutationFn(captured.input, captured.client);
      captured.client.assertCurrent();
      return result;
    },
    onMutate: async (captured, context) => {
      captured.client.assertCurrent();
      return await captured.options.onMutate?.(captured.input, context) as Context;
    },
    onSuccess: (data, captured, context, mutationContext) => {
      if (captured.client.isCurrent()) {
        return captured.options.onSuccess?.(data, captured.input, context, mutationContext);
      }
    },
    onError: (error, captured, context, mutationContext) => {
      if (captured.client.isCurrent()) {
        return captured.options.onError?.(error, captured.input, context, mutationContext);
      }
    },
    onSettled: (data, error, captured, context, mutationContext) => {
      if (captured.client.isCurrent()) {
        return captured.options.onSettled?.(data, error, captured.input, context, mutationContext);
      }
    },
  });
  const callOptions = (callbacks?: MutateOptions<Data, Error, Variables, Context>): MutateOptions<Data, Error, Captured, Context> | undefined => callbacks && ({
    onSuccess: (data, captured, context, mutationContext) => {
      if (captured.client.isCurrent()) { callbacks.onSuccess?.(data, captured.input, context, mutationContext); }
    },
    onError: (error, captured, context, mutationContext) => {
      if (captured.client.isCurrent()) { callbacks.onError?.(error, captured.input, context, mutationContext); }
    },
    onSettled: (data, error, captured, context, mutationContext) => {
      if (captured.client.isCurrent()) { callbacks.onSettled?.(data, error, captured.input, context, mutationContext); }
    },
  });
  return {
    ...mutation,
    variables: mutation.variables?.input,
    mutate: (input: Variables, callbacks?: MutateOptions<Data, Error, Variables, Context>) => mutation.mutate({ input, client, options }, callOptions(callbacks)),
    mutateAsync: (input: Variables, callbacks?: MutateOptions<Data, Error, Variables, Context>) => mutation.mutateAsync({ input, client, options }, callOptions(callbacks)),
  } as UseMutationResult<Data, Error, Variables, Context>;
}

export type { ApiBinding, ApiClient, ApiRuntime, ApiRuntimeOptions, ApiSession, RuntimeCleanup } from './client';
