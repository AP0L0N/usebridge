// The open board's change poll: every 60 s while the tab is visible, once more when it becomes visible again, never two
// checks at a time. The check itself (board-stamp ETag, then a partial reload) lives in BoardView.

export const BOARD_POLL_MS = 60_000;

/** The browser bits the poll uses (window timers, document visibility), injectable for tests. */
export type PollEnv = {
  setInterval: (run: () => void, ms: number) => number;
  clearInterval: (id: number) => void;
  visible: () => boolean;
  /** Calls `run` on every visibility change; returns the unsubscribe. */
  onVisibilityChange: (run: () => void) => () => void;
};

export const browserPollEnv = (): PollEnv => ({
  setInterval: (run, ms) => window.setInterval(run, ms),
  clearInterval: (id) => window.clearInterval(id),
  visible: () => document.visibilityState === 'visible',
  onVisibilityChange: (run) => {
    document.addEventListener('visibilitychange', run);
    return () => document.removeEventListener('visibilitychange', run);
  },
});

/**
 * Starts the poll and returns its stop (which also aborts a check in flight). A tick while hidden or while the last
 * check is still running is skipped; a failed check just waits for the next tick.
 */
export function startBoardPoll(check: (signal: AbortSignal) => Promise<unknown>, env: PollEnv, ms: number = BOARD_POLL_MS): () => void {
  let running: AbortController | null = null;
  const tick = () => {
    if (running || !env.visible()) return;
    const ctl = new AbortController();
    running = ctl;
    check(ctl.signal).catch(() => {}).finally(() => { if (running === ctl) running = null; });
  };
  const id = env.setInterval(tick, ms);
  const off = env.onVisibilityChange(() => { if (env.visible()) tick(); });
  return () => {
    env.clearInterval(id);
    off();
    running?.abort();
    running = null;
  };
}

/**
 * The cheap check: GET the board's change stamp with If-None-Match = the stamp the board was loaded with. True only
 * when it changed (200 with another ETag); 304, an error answer or the same ETag = false, so nothing is rebuilt.
 */
export async function boardChanged(url: string, stamp: string | undefined, signal: AbortSignal, fetcher: typeof fetch = fetch): Promise<boolean> {
  const res = await fetcher(url, {
    credentials: 'same-origin',
    cache: 'no-store',
    signal,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(stamp ? { 'If-None-Match': `"${stamp}"` } : {}) },
  });
  if (res.status === 304 || !res.ok) return false;
  const etag = res.headers.get('ETag')?.replace(/^W\//, '').replace(/"/g, '');
  return etag !== stamp;
}
