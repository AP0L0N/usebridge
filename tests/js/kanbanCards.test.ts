import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CARD_VIEW_KEY, cardDot, readCardView, saveCardView } from '../../resources/js/lib/kanbanCards.ts';

test('card view: Simple by default, Detailed once picked, survives a storage that throws', () => {
  const data = new Map<string, string>();
  const storage = { getItem: (k: string) => data.get(k) ?? null, setItem: (k: string, v: string) => { data.set(k, v); }, removeItem: (k: string) => { data.delete(k); } };
  assert.equal(readCardView(storage), 'simple');
  saveCardView(storage, 'detailed');
  assert.equal(data.get(CARD_VIEW_KEY), 'detailed');
  assert.equal(readCardView(storage), 'detailed');
  saveCardView(storage, 'simple');
  assert.equal(readCardView(storage), 'simple');
  const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); }, removeItem: () => { throw new Error('denied'); } };
  assert.equal(readCardView(broken), 'simple');
  assert.doesNotThrow(() => saveCardView(broken, 'detailed'));
  assert.equal(readCardView(null), 'simple');
});

test('the simple card dot shows the most urgent state: blocked > new activity > waiting on you > not picked up', () => {
  const quiet = { status: 'todo' as const, blocked_reason: null, unread_for_me: false, ask: null, waiting_level: null, owner_answered_ask: false, needs_pm_pickup: false };
  assert.equal(cardDot(quiet), null);
  assert.equal(cardDot({ ...quiet, needs_pm_pickup: true }), 'pickup');
  assert.equal(cardDot({ ...quiet, status: 'waiting_tilen', ask: 'Which logo?', needs_pm_pickup: true }), 'waiting');
  assert.equal(cardDot({ ...quiet, status: 'waiting_tilen', ask: 'Which logo?', owner_answered_ask: true, needs_pm_pickup: true }), 'pickup');
  assert.equal(cardDot({ ...quiet, waiting_level: 2, unread_for_me: true }), 'new');
  assert.equal(cardDot({ ...quiet, waiting_level: 2, unread_for_me: true, blocked_reason: 'No API key' }), 'blocked');
});
