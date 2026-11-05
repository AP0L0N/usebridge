import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { parsePercent, saveFailureKind, showStopSlider } from '../../resources/js/lib/powerSaverField.ts';
import { S } from '../../resources/js/lib/strings-power.ts';

const invalid = 'invalid';

test('an empty field is no threshold, and 0 is a real stop', () => {
  assert.deepEqual(parsePercent('', 0, 100, invalid), { ok: true, value: null });
  assert.deepEqual(parsePercent('0', 0, 100, invalid), { ok: true, value: 0 });
  assert.deepEqual(parsePercent('100', 0, 100, invalid), { ok: true, value: 100 });
  assert.equal(parsePercent('150', 0, 100, invalid).ok, false);
  assert.equal(parsePercent('12.5', 0, 100, invalid).ok, false);
});

test('the slider is hidden for no threshold and shown for 0', () => {
  assert.equal(showStopSlider(parsePercent('', 0, 100, invalid)), false);
  assert.equal(showStopSlider(parsePercent('0', 0, 100, invalid)), true);
  assert.equal(showStopSlider(parsePercent('40', 0, 100, invalid)), true);
  assert.equal(showStopSlider({ ok: false, message: invalid }), false);
});

test('validation failures are not described as a network retry', () => {
  assert.equal(saveFailureKind(422, true), 'validation');
  assert.equal(saveFailureKind(0, false), 'network');
  assert.equal(saveFailureKind(500, false), 'network');
  assert.equal(S['ps.correct'], 'Correct the value and save again.');
  assert.equal(S['ps.retry'], 'Try again');
  assert.match(S['ps.error.save'], /connection/);
  assert.notEqual(S['ps.correct'], S['ps.retry']);
});

test('the number field and the slider share a stable error description', () => {
  const page = readFileSync(new URL('../../resources/js/Pages/PowerSaver.tsx', import.meta.url), 'utf8');
  assert.match(page, /const errorId = useId\(\)/);
  assert.match(page, /aria-describedby=\{describedBy\}/);
  assert.match(page, /id=\{errorId\} role="alert"/);
  assert.match(page, /showStopSlider\(parsed\)/);
  assert.match(page, /errorKind === 'validation' \? t\('ps\.correct'\) : t\('ps\.retry'\)/);
  const slider = page.slice(page.indexOf("type=\"range\"") - 400, page.indexOf("type=\"range\"") + 200);
  assert.match(slider, /aria-describedby=\{describedBy\}/);
});
