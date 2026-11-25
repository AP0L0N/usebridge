import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isTypingTarget, ticketNavStep, ticketNeighbours } from '../../resources/js/lib/ticketNav.ts';

const col = (...keys: string[]) => ({ tickets: keys.map((key) => ({ key })) });
const board = [col('B-1', 'B-2', 'B-3'), col('T-1'), col()];

test('neighbours: first, middle and last in a column, never wrapping or crossing columns', () => {
  assert.deepEqual(ticketNeighbours(board, 'B-1'), { prev: null, next: 'B-2' });
  assert.deepEqual(ticketNeighbours(board, 'B-2'), { prev: 'B-1', next: 'B-3' });
  assert.deepEqual(ticketNeighbours(board, 'B-3'), { prev: 'B-2', next: null });
});

test('neighbours: a column with one ticket, or a ticket not on the board, has neither', () => {
  assert.deepEqual(ticketNeighbours(board, 'T-1'), { prev: null, next: null });
  assert.deepEqual(ticketNeighbours(board, 'X-9'), { prev: null, next: null });
});

test('neighbours: a ticket hidden by the filter is skipped (the visible columns are passed in)', () => {
  const all = [col('B-1', 'B-2', 'B-3', 'B-4')];
  const visible = all.map((c) => ({ ...c, tickets: c.tickets.filter((t) => t.key !== 'B-2') }));
  assert.deepEqual(ticketNeighbours(visible, 'B-1'), { prev: null, next: 'B-3' });
  assert.deepEqual(ticketNeighbours(visible, 'B-3'), { prev: 'B-1', next: 'B-4' });
});

test('the prev/next buttons are disabled exactly when there is no neighbour', () => {
  const disabled = (key: string) => {
    const n = ticketNeighbours(board, key);
    return { prev: !n.prev, next: !n.next };
  };
  assert.deepEqual(disabled('B-1'), { prev: true, next: false });
  assert.deepEqual(disabled('B-2'), { prev: false, next: false });
  assert.deepEqual(disabled('B-3'), { prev: false, next: true });
  assert.deepEqual(disabled('T-1'), { prev: true, next: true });
});

const key = (over: Partial<Parameters<typeof ticketNavStep>[0]> = {}) => ({
  key: 'ArrowRight', ctrlKey: true, metaKey: false, altKey: false, shiftKey: false, isComposing: false, keyCode: 39, target: { tagName: 'H1', isContentEditable: false }, ...over,
});

test('shortcut: Ctrl+ArrowLeft / Ctrl+ArrowRight only', () => {
  assert.equal(ticketNavStep(key()), 1);
  assert.equal(ticketNavStep(key({ key: 'ArrowLeft', keyCode: 37 })), -1);
  assert.equal(ticketNavStep(key({ ctrlKey: false })), null);
  assert.equal(ticketNavStep(key({ metaKey: true })), null);
  assert.equal(ticketNavStep(key({ altKey: true })), null);
  assert.equal(ticketNavStep(key({ shiftKey: true })), null);
  assert.equal(ticketNavStep(key({ key: 'ArrowUp' })), null);
  assert.equal(ticketNavStep(key({ target: null })), 1);
});

test('shortcut: ignored while typing in an input, textarea, select or contenteditable (the comment editor)', () => {
  for (const tagName of ['INPUT', 'TEXTAREA', 'SELECT', 'input']) {
    assert.equal(isTypingTarget({ tagName, isContentEditable: false }), true);
    assert.equal(ticketNavStep(key({ target: { tagName, isContentEditable: false } })), null);
  }
  // Tiptap's #cmp and anything inside it report isContentEditable.
  assert.equal(ticketNavStep(key({ target: { tagName: 'DIV', isContentEditable: true } })), null);
  assert.equal(ticketNavStep(key({ target: { tagName: 'P', isContentEditable: true } })), null);
  assert.equal(isTypingTarget({ tagName: 'BUTTON', isContentEditable: false }), false);
});

test('shortcut: ignored during IME composition', () => {
  assert.equal(ticketNavStep(key({ isComposing: true })), null);
  assert.equal(ticketNavStep(key({ keyCode: 229 })), null);
});
