import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BOARD_POLL_MS, boardChanged, startBoardPoll, type PollEnv } from '../../resources/js/lib/boardPoll.ts';

/** A fake clock + visibility: `fire()` runs the interval once, `show()` / `hide()` flip visibility and notify. */
function fakeEnv() {
  let tick: (() => void) | null = null;
  let onVis: (() => void) | null = null;
  const state = { visible: true, ms: 0, cleared: false, unsubscribed: false };
  const env: PollEnv = {
    setInterval: (run, ms) => { tick = run; state.ms = ms; return 1; },
    clearInterval: () => { state.cleared = true; tick = null; },
    visible: () => state.visible,
    onVisibilityChange: (run) => { onVis = run; return () => { state.unsubscribed = true; onVis = null; }; },
  };
  return {
    env, state,
    fire: () => tick?.(),
    show: () => { state.visible = true; onVis?.(); },
    hide: () => { state.visible = false; onVis?.(); },
  };
}

/** A check that stays in flight until `finish()`. */
function pendingChecks() {
  const calls: AbortSignal[] = [];
  let resolve: (() => void) | null = null;
  const check = (signal: AbortSignal) => { calls.push(signal); return new Promise<void>((r) => { resolve = r; }); };
  return { calls, check, finish: async () => { resolve?.(); await new Promise((r) => setImmediate(r)); } };
}

test('the poll runs every 60 seconds', async () => {
  const f = fakeEnv(), c = pendingChecks();
  const stop = startBoardPoll(c.check, f.env);
  assert.equal(BOARD_POLL_MS, 60_000);
  assert.equal(f.state.ms, 60_000);
  assert.equal(c.calls.length, 0);
  f.fire();
  assert.equal(c.calls.length, 1);
  await c.finish();
  f.fire();
  assert.equal(c.calls.length, 2);
  stop();
});

test('no overlapping checks: a tick while one is in flight is skipped', async () => {
  const f = fakeEnv(), c = pendingChecks();
  const stop = startBoardPoll(c.check, f.env);
  f.fire();
  f.fire();
  f.show();
  assert.equal(c.calls.length, 1);
  await c.finish();
  f.fire();
  assert.equal(c.calls.length, 2);
  stop();
});

test('a failed check does not stop the poll', async () => {
  const f = fakeEnv();
  let n = 0;
  const stop = startBoardPoll(async () => { n++; throw new Error('offline'); }, f.env);
  f.fire();
  await new Promise((r) => setImmediate(r));
  f.fire();
  assert.equal(n, 2);
  stop();
});

test('hidden: ticks are skipped; becoming visible again checks once', async () => {
  const f = fakeEnv(), c = pendingChecks();
  const stop = startBoardPoll(c.check, f.env);
  f.hide();
  f.fire();
  f.fire();
  assert.equal(c.calls.length, 0);
  f.show();
  assert.equal(c.calls.length, 1);
  await c.finish();
  f.hide();
  assert.equal(c.calls.length, 1);
  stop();
});

test('stop clears the interval, unsubscribes and aborts the check in flight', () => {
  const f = fakeEnv(), c = pendingChecks();
  const stop = startBoardPoll(c.check, f.env);
  f.fire();
  assert.equal(c.calls[0].aborted, false);
  stop();
  assert.equal(c.calls[0].aborted, true);
  assert.deepEqual([f.state.cleared, f.state.unsubscribed], [true, true]);
  f.fire();
  f.show();
  assert.equal(c.calls.length, 1);
});

test('the cheap check: 304 or the same ETag is unchanged, another ETag is changed, errors are unchanged', async () => {
  const sent: RequestInit[] = [];
  const answer = (status: number, etag?: string) => (async (_url: unknown, init?: RequestInit) => {
    sent.push(init ?? {});
    return new Response(status === 304 ? null : '{}', { status, headers: etag ? { ETag: etag } : {} });
  }) as typeof fetch;
  const signal = new AbortController().signal;
  assert.equal(await boardChanged('/projects/x/board-stamp?ws=1', 'abc', signal, answer(304)), false);
  assert.equal((sent[0].headers as Record<string, string>)['If-None-Match'], '"abc"');
  assert.equal(sent[0].cache, 'no-store');
  assert.equal(sent[0].signal, signal);
  assert.equal(await boardChanged('/u', 'abc', signal, answer(200, '"abc"')), false);
  assert.equal(await boardChanged('/u', 'abc', signal, answer(200, 'W/"abc"')), false);
  assert.equal(await boardChanged('/u', 'abc', signal, answer(200, '"def"')), true);
  assert.equal(await boardChanged('/u', 'abc', signal, answer(500)), false);
});
