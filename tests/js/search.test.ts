import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EMPTY_RESULTS, isMacPlatform, isSearchShortcut, moveIndex, searchItems, showArchivedIn, type SearchResults } from '../../resources/js/lib/search.ts';

const key = (k: string, mods: { meta?: boolean; ctrl?: boolean; alt?: boolean; shift?: boolean } = {}) =>
  ({ key: k, metaKey: !!mods.meta, ctrlKey: !!mods.ctrl, altKey: !!mods.alt, shiftKey: !!mods.shift });

test('Cmd+K on Mac, Ctrl+K elsewhere, and nothing else', () => {
  assert.equal(isSearchShortcut(key('k', { meta: true }), true), true);
  assert.equal(isSearchShortcut(key('K', { meta: true }), true), true, 'Caps Lock');
  assert.equal(isSearchShortcut(key('k', { ctrl: true }), true), false);
  assert.equal(isSearchShortcut(key('k', { ctrl: true }), false), true);
  assert.equal(isSearchShortcut(key('k', { meta: true }), false), false);
  assert.equal(isSearchShortcut(key('k', { ctrl: true, shift: true }), false), false);
  assert.equal(isSearchShortcut(key('k', { meta: true, alt: true }), true), false);
  assert.equal(isSearchShortcut(key('k'), true), false);
  assert.equal(isSearchShortcut(key('j', { ctrl: true }), false), false);
});

test('Mac-like platforms', () => {
  for (const p of ['MacIntel', 'macOS', 'iPhone', 'iPad']) assert.equal(isMacPlatform(p), true, p);
  for (const p of ['Win32', 'Linux x86_64', '', null, undefined]) assert.equal(isMacPlatform(p), false, String(p));
});

test('"Show archived" comes from the page URL', () => {
  assert.equal(showArchivedIn('?project=bh&archived=1'), true);
  assert.equal(showArchivedIn('?archived=0'), false);
  assert.equal(showArchivedIn(''), false);
});

test('rows are flattened Tickets, Projects, Workstreams with the existing URLs', () => {
  const r: SearchResults = {
    tickets: [{ id: 'tk-1', key: 'CC-50', title: 'Fix it', project_slug: 'cc', project_name: 'CC', archived: false }],
    projects: [{ id: 'p-1', slug: 'cc', name: 'CC', archived: true }],
    workstreams: [{ id: 'ws 1', name: 'Launch', project_slug: 'cc', project_name: 'CC', archived: false }],
  };
  const ticketHref = (slug: string, k: string) => `/projects/${slug}/tickets/${k}`;
  assert.deepEqual(searchItems(r, true, ticketHref), [
    { group: 'tickets', id: 'tk-1', href: '/projects/cc/tickets/CC-50', archived: false },
    { group: 'projects', id: 'p-1', href: '/portfolio?project=cc&archived=1', archived: true },
    { group: 'workstreams', id: 'ws 1', href: '/projects/cc?ws=ws%201', archived: false },
  ]);
  assert.equal(searchItems(r, false, ticketHref)[1].href, '/portfolio?project=cc');
  assert.deepEqual(searchItems(EMPTY_RESULTS, false, ticketHref), []);
});

test('ArrowUp / ArrowDown wrap around the rows', () => {
  assert.equal(moveIndex(-1, 1, 3), 0);
  assert.equal(moveIndex(-1, -1, 3), 2);
  assert.equal(moveIndex(0, 1, 3), 1);
  assert.equal(moveIndex(2, 1, 3), 0);
  assert.equal(moveIndex(0, -1, 3), 2);
  assert.equal(moveIndex(0, 1, 0), -1);
});
