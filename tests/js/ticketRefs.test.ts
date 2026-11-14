import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseRefHref, plainRefs, refHref, refIds, splitRefs } from '../../resources/js/lib/ticketRefs.ts';

test('splits text into plain parts and references', () => {
  assert.deepEqual(splitRefs('See [[ticket:tk-1|BR-9]] and [[ticket:tk-2]].'), ['See ', { id: 'tk-1', key: 'BR-9' }, ' and ', { id: 'tk-2', key: null }, '.']);
  assert.deepEqual(splitRefs('no refs'), ['no refs']);
  assert.deepEqual(refIds('[[ticket:tk-1|BR-9]] [[ticket:tk-1|BR-9]] [[ticket:tk-3|CA-1]]'), ['tk-1', 'tk-3']);
});

test('plain text uses the current key, then the stored one, then nothing', () => {
  const current = (id: string) => (id === 'tk-1' ? 'NEW-9' : null);
  assert.equal(plainRefs('[[ticket:tk-1|BR-9]] / [[ticket:tk-2|BR-10]] / [[ticket:tk-3]]', current), 'NEW-9 / BR-10 / ');
  assert.equal(plainRefs(null), null);
  assert.equal(plainRefs('BR-9 stays'), 'BR-9 stays');
});

test('reference links round-trip', () => {
  assert.deepEqual(parseRefHref(refHref('tk-01abc', 'BR-9')), { id: 'tk-01abc', key: 'BR-9' });
  assert.deepEqual(parseRefHref(refHref('tk-01abc', null)), { id: 'tk-01abc', key: null });
  assert.equal(parseRefHref('https://example.com'), null);
});
