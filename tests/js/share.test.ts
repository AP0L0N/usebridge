import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { expiryBody, expiryChoiceLabel, expiryLabel, isExternalHref } from '../../resources/js/lib/share.ts';

const now = '2026-11-25T10:00:00Z';
const src = (path: string) => readFileSync(new URL(`../../resources/${path}`, import.meta.url), 'utf8');

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

test('the shared icon is on Simple and Detailed cards: 14px with its own size', () => {
  assert.match(src('js/Components/Kanban/ShareLinks.tsx'), /IconShared14 = \(\) => \(\s*<svg className="svg-ic" width="14" height="14" /);
  const card = src('js/Components/Kanban/TicketCard.tsx');
  assert.match(card, /const shared = t\.shared \? <span className="shr" title=\{ts\('card\.shared'\)\}><IconShared14 \/>/);
  const [simple, detailed] = card.split('if (simple) {')[1].split('const strips');
  assert.equal(simple.split('{shared}').length, 2);
  assert.equal(detailed.split('{shared}').length, 2);
  const css = src('css/share.css');
  assert.match(css, /\.tk \.shr svg\{flex:none;width:14px;height:14px\}/);
  assert.match(css, /\.tk\.simple \.shr\{position:relative;z-index:1;/);
});

test('visitor names render as typed: text only, no transform or truncation', () => {
  assert.match(src('js/Pages/Share/Show.tsx'), /<b>\{c\.author\}<\/b>/);
  assert.match(src('js/Components/Kanban/ShareLinks.tsx'), /<b>\{c\.author_name \?\? t\('pub\.visitor'\)\}<\/b>/);
  for (const file of ['js/Pages/Share/Show.tsx', 'js/Components/Kanban/ShareLinks.tsx']) {
    assert.doesNotMatch(src(file), /dangerouslySetInnerHTML/, file);
  }
  const css = src('css/share.css');
  assert.doesNotMatch(css, /text-transform/);
  assert.match(css, /\.cm\.pub header b\{white-space:pre-wrap\}/);
  assert.match(css, /\.pubshare \.cm\.pub header b\{white-space:pre-wrap\}/);
});

test('the public page footer is the source link only', () => {
  const page = src('js/Pages/Share/Show.tsx');
  const footer = page.slice(page.indexOf('<footer'), page.indexOf('</footer>'));
  assert.match(footer, /fillPlaceholders\(legal\.copyright, \{ link: <a href=\{legal\.href\} rel="noopener">\{legal\.label\}<\/a> \}\)/);
  assert.equal(footer.split('<a ').length, 2);
  assert.doesNotMatch(page, /<Link\b|SiteFooter \/>/);
});

/** WCAG contrast ratio of two #RRGGBB colours. */
function contrast(a: string, b: string): number {
  const lum = (hex: string) => {
    const [r, g, bl] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
  };
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

test('person avatars: --a-ink-950 is defined in both themes and readable against the avatar text', () => {
  assert.match(src('css/kanban.css'), /\.av\.h\{background:var\(--a-ink-950\);color:var\(--a-surface\)\}/);
  const token = (css: string, name: string) => css.match(new RegExp(`--a-${name}: (#[0-9A-F]{6});`))?.[1] ?? '';
  const light = src('css/app-shell.css');
  const dark = src('css/dark.css').split('html[data-theme="dark"] :is(.app, .atb) {')[1].split('}')[0];
  for (const [theme, css] of [['light', light], ['dark', dark]] as const) {
    assert.equal(token(css, 'ink-950'), token(css, 'ink'), theme);
    assert.ok(contrast(token(css, 'ink-950'), token(css, 'surface')) >= 4.5, theme);
  }
  // Every --a-ink-* the stylesheets read is defined wherever --a-ink is (light, dark and the dark-in-light bars).
  for (const block of src('css/dark.css').match(/--a-ink: [^}]+/g) ?? []) {
    assert.match(block, /--a-ink-950: #/);
  }
  assert.equal((src('css/dark.css').match(/--a-ink: /g) ?? []).length, 3);
});
