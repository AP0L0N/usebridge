import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformWithOxc } from 'vite';

// Node cannot import .tsx, so the icon module is compiled in memory and loaded from a data: URL.
const file = new URL('../../resources/js/Components/Icons.tsx', import.meta.url);
const { code } = await transformWithOxc(readFileSync(file, 'utf8'), file.pathname, { jsx: { runtime: 'automatic' } });
const icons: Record<string, () => unknown> = await import('data:text/javascript,' + encodeURIComponent(code.replaceAll('"react/jsx-runtime"', JSON.stringify(import.meta.resolve('react/jsx-runtime')))));
const css = (name: string) => readFileSync(new URL(`../../resources/css/${name}.css`, import.meta.url), 'utf8');

test('every shared icon carries its own 14–16px size, so it cannot fill its container', () => {
  assert.ok(Object.keys(icons).includes('IconOk'));
  for (const [name, Icon] of Object.entries(icons)) {
    const html = renderToStaticMarkup(createElement(Icon as never));
    const size = (attr: string) => Number(html.match(new RegExp(`^<svg[^>]* ${attr}="(\\d+)"`))?.[1]);
    for (const attr of ['width', 'height']) {
      assert.ok(size(attr) >= 14 && size(attr) <= 16, `${name} ${attr} in ${html}`);
    }
    assert.match(html, /^<svg[^>]* class="svg-ic"/, name);
  }
  assert.match(css('bridge'), /\.svg-ic\{flex-shrink:0\}/);
});

test('the picked-up line stays one flex row with a small icon', () => {
  const detail = readFileSync(new URL('../../resources/js/Components/Kanban/TicketDetail.tsx', import.meta.url), 'utf8');
  assert.match(detail, /<p className="ack ok"><IcOk \/>\{tr\('ticket\.input\.pickedUp'/);
  const kanban = css('kanban');
  assert.match(kanban, /\.cm \.ack\{display:flex;align-items:center;/);
  assert.match(kanban, /\.cm \.ack svg\{flex:none;width:14px;height:14px\}/);
  assert.match(kanban, /\.ok-line svg\{flex:none;width:16px;height:16px\}/);
});
