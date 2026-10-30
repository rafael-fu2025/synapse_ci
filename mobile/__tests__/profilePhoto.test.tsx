import React from 'react';
import { Text } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError, authorizedFetch } from '../src/api/client';
import * as health from '../src/api/endpoints/health';
import { queryKeys } from '../src/api/queryKeys';
import type { User } from '../src/api/types';
import { useProfilePhoto } from '../src/features/profile/useProfilePhoto';
import { useAuth } from '../src/store/auth';

jest.mock('../src/lib/config', () => ({ config: { apiBaseUrl: 'http://api.test' } }));
jest.mock('../src/lib/keychain', () => ({ getRefreshToken: jest.fn(), setRefreshToken: jest.fn() }));
jest.mock('../src/api/client', () => ({
  ...jest.requireActual('../src/api/client'),
  authorizedFetch: jest.fn(),
  request: jest.fn(),
}));
jest.mock('../src/store/auth', () => ({ useAuth: jest.fn() }));

const firstPhoto = 'data:image/png;base64,Zmlyc3Q=';
const nextPhoto = 'data:image/jpeg;base64,bmV4dA==';

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => { resolve = yes; });
  return { promise, resolve };
}

describe('profilePhoto API', () => {
  const originalFileReader = Object.getOwnPropertyDescriptor(globalThis, 'FileReader');
  let reader: {
    result: string | ArrayBuffer | null;
    onload: (() => void) | null;
    onerror: (() => void) | null;
    onabort: (() => void) | null;
    readAsDataURL: jest.Mock<void, [Blob]>;
  };
  let fileReader: jest.Mock;

  function response(contentType: string | null, status = 200) {
    const value = new Response(null, {
      status,
      headers: contentType === null ? {} : { 'Content-Type': contentType },
    });
    // The reader is controlled below; no native blob decoding or networking is needed.
    const blob = { type: contentType } as Blob;
    const readBlob = jest.spyOn(value, 'blob').mockResolvedValue(blob);
    jest.mocked(authorizedFetch).mockResolvedValueOnce(value);
    return { blob, readBlob };
  }

  async function startRead() {
    const started = deferred<void>();
    reader.readAsDataURL.mockImplementationOnce(() => started.resolve());
    const pending = health.profilePhoto();
    await started.promise;
    return { pending };
  }

  beforeEach(() => {
    jest.mocked(authorizedFetch).mockReset();
    reader = { result: null, onload: null, onerror: null, onabort: null, readAsDataURL: jest.fn() };
    fileReader = jest.fn(() => reader);
    Object.defineProperty(globalThis, 'FileReader', { configurable: true, writable: true, value: fileReader });
  });

  afterEach(() => {
    if (originalFileReader) {
      Object.defineProperty(globalThis, 'FileReader', originalFileReader);
    } else {
      Reflect.deleteProperty(globalThis, 'FileReader');
    }
    jest.restoreAllMocks();
  });

  it.each(['image/svg+xml', 'IMAGE/SVG+XML; charset=UTF-8'])('treats %s as no saved photo without requiring FileReader', async mime => {
    const { readBlob } = response(mime);
    Object.defineProperty(globalThis, 'FileReader', { configurable: true, writable: true, value: undefined });

    await expect(health.profilePhoto()).resolves.toBeNull();

    expect(authorizedFetch).toHaveBeenCalledTimes(1);
    expect(authorizedFetch).toHaveBeenCalledWith('api/v1/me/photo', { method: 'GET' });
    expect(readBlob).not.toHaveBeenCalled();
    expect(fileReader).not.toHaveBeenCalled();
  });

  it.each([
    ['image/png', 'image/png'],
    ['image/jpeg', 'image/jpeg'],
    ['image/webp', 'image/webp'],
    ['IMAGE/PNG; charset=binary', 'image/png'],
  ])('reads %s through the authorized client and returns only a data URI', async (contentType, mime) => {
    const { blob, readBlob } = response(contentType);
    const { pending } = await startRead();
    const dataUri = `data:${mime};base64,cGhvdG8=`;

    expect(authorizedFetch).toHaveBeenCalledTimes(1);
    expect(authorizedFetch).toHaveBeenCalledWith('api/v1/me/photo', { method: 'GET' });
    expect(readBlob).toHaveBeenCalledTimes(1);
    expect(fileReader).toHaveBeenCalledTimes(1);
    expect(reader.readAsDataURL).toHaveBeenCalledWith(blob);
    reader.result = dataUri;
    reader.onload!();

    const result = await pending;
    expect(result).toBe(dataUri);
    expect(result).not.toMatch(/https?:|Bearer|access_token|Authorization/);
  });

  it.each([401, 503])('rejects HTTP %s before reading the response as an image', async status => {
    const { readBlob } = response('image/png', status);
    const error = await health.profilePhoto().catch((failure: unknown) => failure);

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status, code: 'photo_unavailable', message: 'Your profile photo could not be loaded.' });
    expect(readBlob).not.toHaveBeenCalled();
    expect(fileReader).not.toHaveBeenCalled();
  });

  it.each([null, '', 'text/html', 'application/json', 'image/gif', 'image/jpg'])('rejects unsupported content type %s without decoding it', async mime => {
    const { readBlob } = response(mime);

    await expect(health.profilePhoto()).rejects.toThrow('The server returned an unsupported profile photo.');

    expect(readBlob).not.toHaveBeenCalled();
    expect(fileReader).not.toHaveBeenCalled();
  });

  it.each(['onerror', 'onabort'] as const)('rejects a FileReader %s instead of returning a broken URI', async event => {
    response('image/png');
    const { pending } = await startRead();
    expect(reader[event]).toEqual(expect.any(Function));
    reader[event]!();
    await expect(pending).rejects.toThrow('Your profile photo could not be read.');
  });

  it.each([null, new ArrayBuffer(8)])('rejects a non-string FileReader load result (%#)', async result => {
    response('image/png');
    const { pending } = await startRead();
    reader.result = result;
    reader.onload!();
    await expect(pending).rejects.toThrow('Your profile photo could not be read.');
  });

  it('propagates an authorized transport rejection without starting FileReader', async () => {
    const error = new Error('Session changed');
    jest.mocked(authorizedFetch).mockRejectedValueOnce(error);

    await expect(health.profilePhoto()).rejects.toBe(error);
    expect(fileReader).not.toHaveBeenCalled();
  });

  it('propagates a blob-read rejection without starting FileReader', async () => {
    const error = new Error('Unreadable response');
    response('image/png').readBlob.mockRejectedValueOnce(error);

    await expect(health.profilePhoto()).rejects.toBe(error);
    expect(fileReader).not.toHaveBeenCalled();
  });
});

describe('useProfilePhoto shared session cache', () => {
  const user: User = {
    id: 7, tenant_id: 11, person_id: 7, username: 'alex', email: null,
    name: 'Alex Rivera', avatar_initials: 'AR', identifier: 'alex', role: 'student',
    is_teaching: false, permissions: [],
  };
  let currentUser: User | null;
  let client: QueryClient;
  let renderer: ReactTestRenderer | undefined;
  let fetchPhoto: jest.SpyInstance<ReturnType<typeof health.profilePhoto>, Parameters<typeof health.profilePhoto>>;

  function Consumer({ index }: { index: number }) {
    const photo = useProfilePhoto();
    return <Text testID={`photo-${index}`}>{photo ?? 'no photo'}</Text>;
  }

  function tree(consumers: number) {
    return (
      <QueryClientProvider client={client}>
        {Array.from({ length: consumers }, (_, index) => <Consumer key={index} index={index} />)}
      </QueryClientProvider>
    );
  }

  async function render(consumers = 2) {
    await act(async () => {
      if (renderer) {
        renderer.update(tree(consumers));
      } else {
        renderer = create(tree(consumers));
      }
    });
  }

  async function flush() {
    // Query observer notifications are batched onto a timer, not just a microtask.
    await act(async () => { await jest.advanceTimersByTimeAsync(1); });
  }

  function expectPhotos(...photos: (string | null)[]) {
    expect(renderer!.root.findAllByType(Text).map(node => node.props.children))
      .toEqual(photos.map(photo => photo ?? 'no photo'));
  }

  beforeEach(() => {
    jest.useFakeTimers();
    currentUser = user;
    client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
    fetchPhoto = jest.spyOn(health, 'profilePhoto');
    jest.mocked(useAuth).mockImplementation(() => ({
      user: currentUser, status: currentUser ? 'signedIn' : 'signedOut',
      signIn: jest.fn(), signOut: jest.fn(), acknowledgeExpired: jest.fn(),
    }));
  });

  afterEach(() => {
    act(() => renderer?.unmount());
    renderer = undefined;
    client.clear();
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it('deduplicates two consumers into one in-flight fetch and delivers the same photo to both', async () => {
    const photo = deferred<string | null>();
    fetchPhoto.mockReturnValueOnce(photo.promise);
    await render();

    expectPhotos(null, null);
    expect(fetchPhoto).toHaveBeenCalledTimes(1);
    expect(queryKeys.profilePhoto(user.tenant_id, user.id)).toEqual(['me', 'photo', 11, 7]);

    await act(async () => { photo.resolve(firstPhoto); });
    await flush();

    expectPhotos(firstPhoto, firstPhoto);
    expect(fetchPhoto).toHaveBeenCalledTimes(1);
    expect(client.getQueryData(['me', 'photo', 11, 7])).toBe(firstPhoto);
  });

  it('updates both consumers after upload and removal invalidate the me prefix', async () => {
    fetchPhoto.mockResolvedValueOnce(firstPhoto).mockResolvedValueOnce(nextPhoto).mockResolvedValueOnce(null);
    await render();
    await flush();
    expectPhotos(firstPhoto, firstPhoto);

    // ProfilePhotoScreen already owns the mutation; exercise its shared invalidation contract.
    await act(async () => { await client.invalidateQueries({ queryKey: ['me'] }); });
    await flush();
    expectPhotos(nextPhoto, nextPhoto);
    expect(fetchPhoto).toHaveBeenCalledTimes(2);

    await act(async () => { await client.invalidateQueries({ queryKey: ['me'] }); });
    await flush();
    expectPhotos(null, null);
    expect(fetchPhoto).toHaveBeenCalledTimes(3);
    expect(client.getQueryData(queryKeys.profilePhoto(11, 7))).toBeNull();
  });

  it('reuses a photo for 60 seconds, then refetches when another consumer mounts', async () => {
    fetchPhoto.mockResolvedValueOnce(firstPhoto).mockResolvedValueOnce(nextPhoto);
    await render(1);
    await flush();
    const cachedAt = client.getQueryState(queryKeys.profilePhoto(11, 7))!.dataUpdatedAt;

    jest.setSystemTime(cachedAt + 59_999);
    await render(2);
    await flush();
    expectPhotos(firstPhoto, firstPhoto);
    expect(fetchPhoto).toHaveBeenCalledTimes(1);

    jest.setSystemTime(cachedAt + 60_001);
    await render(3);
    await flush();
    expectPhotos(nextPhoto, nextPhoto, nextPhoto);
    expect(fetchPhoto).toHaveBeenCalledTimes(2);
  });

  it.each([
    ['signed out', null],
    ['missing user ID', { ...user, id: 0 }],
    ['missing tenant ID', { ...user, tenant_id: 0 }],
    ['negative user ID', { ...user, id: -1 }],
    ['negative tenant ID', { ...user, tenant_id: -1 }],
  ] as const)('does not fetch for %s', async (_case, identity) => {
    currentUser = identity;
    await render();
    await flush();

    expectPhotos(null, null);
    expect(fetchPhoto).not.toHaveBeenCalled();
  });

  it.each([
    ['tenant', { ...user, tenant_id: 12 }],
    ['user', { ...user, id: 8 }],
  ] as const)('isolates the %s key and immediately drops the previous account photo', async (_case, identity) => {
    const replacement = deferred<string | null>();
    fetchPhoto.mockResolvedValueOnce(firstPhoto).mockReturnValueOnce(replacement.promise);
    await render();
    await flush();
    expectPhotos(firstPhoto, firstPhoto);

    currentUser = identity;
    await render();
    expectPhotos(null, null);
    expect(fetchPhoto).toHaveBeenCalledTimes(2);
    expect(client.getQueryData(queryKeys.profilePhoto(11, 7))).toBe(firstPhoto);
    expect(client.getQueryData(queryKeys.profilePhoto(identity.tenant_id, identity.id))).toBeUndefined();

    await act(async () => { replacement.resolve(nextPhoto); });
    await flush();
    expectPhotos(nextPhoto, nextPhoto);
    expect(client.getQueryData(queryKeys.profilePhoto(identity.tenant_id, identity.id))).toBe(nextPhoto);
  });

  it('clears the signed-out photo and cannot revive it from a late refetch or a same-user sign-in', async () => {
    const latePhoto = deferred<string | null>();
    const signedInPhoto = deferred<string | null>();
    fetchPhoto.mockResolvedValueOnce(firstPhoto)
      .mockReturnValueOnce(latePhoto.promise)
      .mockReturnValueOnce(signedInPhoto.promise);
    await render();
    await flush();
    expectPhotos(firstPhoto, firstPhoto);

    let refetch!: Promise<void>;
    act(() => { refetch = client.invalidateQueries({ queryKey: ['me'] }); });
    expect(fetchPhoto).toHaveBeenCalledTimes(2);
    act(() => {
      client.clear();
      currentUser = null;
      renderer!.update(tree(2));
    });
    expectPhotos(null, null);
    expect(client.getQueryData(queryKeys.profilePhoto(11, 7))).toBeUndefined();

    await act(async () => { latePhoto.resolve(firstPhoto); await refetch; });
    await flush();
    expectPhotos(null, null);
    expect(client.getQueryData(queryKeys.profilePhoto(11, 7))).toBeUndefined();
    expect(fetchPhoto).toHaveBeenCalledTimes(2);

    currentUser = user;
    await render();
    expectPhotos(null, null);
    expect(fetchPhoto).toHaveBeenCalledTimes(3);
    await act(async () => { signedInPhoto.resolve(nextPhoto); });
    await flush();
    expectPhotos(nextPhoto, nextPhoto);
  });

  it('keeps both consumers on the fallback when the photo request fails', async () => {
    fetchPhoto.mockRejectedValueOnce(new Error('Photo unavailable'));
    await render();
    await flush();

    expectPhotos(null, null);
    expect(fetchPhoto).toHaveBeenCalledTimes(1);
    expect(client.getQueryState(queryKeys.profilePhoto(11, 7))?.status).toBe('error');
  });
});
