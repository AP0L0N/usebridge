import assert from 'node:assert/strict';
import { test } from 'node:test';
import { badgeText, deleteMode, filterProjects, filterWorkstreams, finderKey, finderUrl, nameError, othersWithChanges, type WorkstreamRow } from '../../resources/js/lib/workstreams.ts';

const ws = (name: string, badge = 0, extra: Partial<WorkstreamRow> = {}): WorkstreamRow => ({ id: `ws-${name}`, name, isDefault: false, tickets: 0, forYou: 0, open: 0, allDone: false, badge, ...extra });
const projects = [
  { name: 'Garden planner', archived: false, pmStatus: 'waiting' as const, workstreams: [ws('Frontend'), ws('General')] },
  { name: 'Recipe box', archived: false, pmStatus: null, workstreams: [ws('Mobile'), ws('General')] },
  { name: 'Old landing page', archived: true, pmStatus: 'archived' as const, workstreams: [ws('Copy')] },
];

test('show archived off hides archived projects, also from search', () => {
  assert.deepEqual(filterProjects(projects, '', false).archived, []);
  assert.deepEqual(filterProjects(projects, 'old', false), { active: [], archived: [] });
  assert.deepEqual(filterProjects(projects, '', false).active.map((p) => p.name), ['Garden planner', 'Recipe box']);
});

test('show archived on lists them in their own section, search included', () => {
  assert.deepEqual(filterProjects(projects, '', true).archived.map((p) => p.name), ['Old landing page']);
  assert.deepEqual(filterProjects(projects, 'landing', true).archived.map((p) => p.name), ['Old landing page']);
  assert.deepEqual(filterProjects(projects, 'copy', true).archived.map((p) => p.name), ['Old landing page']);
});

test('search matches project and workstream names', () => {
  assert.deepEqual(filterProjects(projects, 'MOBILE', false).active.map((p) => p.name), ['Recipe box']);
  assert.deepEqual(filterWorkstreams(projects[1], 'mobile').map((w) => w.name), ['Mobile']);
  assert.deepEqual(filterWorkstreams(projects[1], 'recipe').map((w) => w.name), ['Mobile', 'General']);
});

test('badges hide at 0 and cap at 99+', () => {
  assert.equal(badgeText(0), null);
  assert.equal(badgeText(3), '3');
  assert.equal(badgeText(99), '99');
  assert.equal(badgeText(100), '99+');
});

test('the phone switcher counts other workstreams with changes', () => {
  assert.equal(othersWithChanges([ws('A', 3), ws('B', 1), ws('C')], 'ws-A'), 1);
});

test('names follow the server rules', () => {
  assert.equal(nameError('  '), 'required');
  assert.equal(nameError('x'.repeat(61)), 'max');
  assert.equal(nameError(' frontend ', ['Frontend']), 'taken');
  assert.equal(nameError('API & data', ['Frontend']), null);
});

test('delete asks for a target when there are tickets or it is the default, a new name when it is the only one', () => {
  assert.equal(deleteMode({ tickets: 0, isDefault: false }, 2), 'empty');
  assert.equal(deleteMode({ tickets: 3, isDefault: false }, 2), 'transfer');
  assert.equal(deleteMode({ tickets: 0, isDefault: true }, 1), 'transfer');
  assert.equal(deleteMode({ tickets: 2, isDefault: true }, 0), 'only');
});

test('finder selection lives in the URL', () => {
  assert.equal(finderUrl(null), '/portfolio');
  assert.equal(finderUrl('garden-planner'), '/portfolio?project=garden-planner');
  assert.equal(finderUrl('garden-planner', 'front', true), '/portfolio?project=garden-planner&q=front&archived=1');
});

test('keyboard: up/down within a column, right/enter into workstreams, enter opens, left goes back', () => {
  assert.deepEqual(finderKey('ArrowDown', { col: 0, index: 0 }, [3, 2]), { focus: { col: 0, index: 1 } });
  assert.deepEqual(finderKey('ArrowUp', { col: 0, index: 0 }, [3, 2]), { focus: { col: 0, index: 0 } });
  assert.deepEqual(finderKey('ArrowRight', { col: 0, index: 1 }, [3, 2]), { focus: { col: 1, index: 0 } });
  assert.deepEqual(finderKey('Enter', { col: 0, index: 1 }, [3, 2]), { focus: { col: 1, index: 0 } });
  assert.deepEqual(finderKey('Enter', { col: 1, index: 1 }, [3, 2]), { focus: { col: 1, index: 1 }, open: true });
  assert.deepEqual(finderKey('ArrowLeft', { col: 1, index: 1 }, [3, 2]), { focus: { col: 0, index: -1 } });
  assert.equal(finderKey('ArrowRight', { col: 0, index: 0 }, [3, 0]), null);
});
