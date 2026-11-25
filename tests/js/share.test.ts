import assert from 'node:assert/strict';
import { test } from 'node:test';
import { expiryBody, expiryChoiceLabel, expiryLabel, isExternalHref } from '../../resources/js/lib/share.ts';

const now = '2026-11-25T10:00:00Z';

test('expiry label: never, expired, within a day and whole days rounded up', () => {
  assert.equal(expiryLabel(null, now), 'Never expires');
  assert.equal(expiryLabel('2026-11-25T09:59:59Z', now), 'Expired');
  assert.equal(expiryLabel(now, now), 'Expired');
  assert.equal(expiryLabel('2026-11-26T09:00:00Z', now), 'Expires within a day');
  assert.equal(expiryLabel('2026-11-26T10:00:01Z', now), 'Expires in 2 days');
  assert.equal(expiryLabel('2026-12-02T10:00:00Z', now), 'Expires in 7 days');
  assert.equal(expiryLabel('2026-12-02T10:00:01Z', now), 'Expires in 8 days');
});

test('expiry body: presets, custom days and never', () => {
  assert.deepEqual(expiryBody('7', ''), { expires_in_days: 7 });
  assert.deepEqual(expiryBody('never', '12'), { never_expires: true });
  assert.deepEqual(expiryBody('custom', ' 45 '), { expires_in_days: 45 });
  assert.deepEqual(expiryBody('custom', '365'), { expires_in_days: 365 });
  for (const bad of ['', '0', '366', '2.5', '-3', 'abc', '1e2']) {
    assert.ok('error' in expiryBody('custom', bad), bad);
  }
});

test('expiry choice labels', () => {
  assert.equal(expiryChoiceLabel('1'), '1 day');
  assert.equal(expiryChoiceLabel('30'), '30 days');
  assert.equal(expiryChoiceLabel('never'), 'Never expires');
});

test('public page links: only other origins (http, https) and mailto stay links', () => {
  const origin = 'https://usebridge.si';
  assert.equal(isExternalHref('https://example.com/a', origin), true);
  assert.equal(isExternalHref('mailto:a@example.com', origin), true);
  assert.equal(isExternalHref('https://usebridge.si/projects/x/tickets/X-1', origin), false);
  assert.equal(isExternalHref('https://USEBRIDGE.si/s/abc', origin), false);
  assert.equal(isExternalHref('/projects/x', origin), false);
  assert.equal(isExternalHref('//usebridge.si/projects', origin), false);
  assert.equal(isExternalHref('#ticket-ref:tk-1:X-1', origin), false);
  assert.equal(isExternalHref('javascript:alert(1)', origin), false);
  assert.equal(isExternalHref('', origin), false);
  assert.equal(isExternalHref(undefined, origin), false);
});
