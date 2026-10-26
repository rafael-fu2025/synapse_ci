/** Refetch cadence mirrors the web client's authoritative polling fallback. */
export const poll = {
  queueMs: 15_000,
  notificationsMs: 30_000,
  dashboardMs: 30_000,
} as const;
