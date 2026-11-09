export const CONNECTION_PROFILES = [
  { id: 'university', label: 'University', apiBaseUrl: 'http://172.16.153.237:8080' },
  { id: 'home', label: 'Home', apiBaseUrl: 'http://192.168.1.11:8080' },
] as const;

export type ConnectionProfile = typeof CONNECTION_PROFILES[number];
export type ConnectionProfileId = ConnectionProfile['id'];

export function findConnectionProfile(id: unknown): ConnectionProfile | null {
  return CONNECTION_PROFILES.find(profile => profile.id === id) ?? null;
}

export function resolveLanApiBaseUrl(value: string): string {
  const profile = CONNECTION_PROFILES.find(item => item.apiBaseUrl === value);
  if (!profile) { throw new Error('Choose an approved University or Home server.'); }
  return profile.apiBaseUrl;
}
