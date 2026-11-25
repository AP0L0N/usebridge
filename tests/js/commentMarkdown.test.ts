import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import Markdown from 'react-markdown';
import type { JSONContent } from '@tiptap/core';
import { linkHref, toMarkdown } from '../../resources/js/lib/commentMarkdown.ts';
import { MARKDOWN_OPTIONS, prepareMarkdown } from '../../resources/js/lib/markdown.ts';

const render = (text: string) => renderToStaticMarkup(createElement(Markdown, MARKDOWN_OPTIONS, prepareMarkdown(text)));
const text = (value: string, ...marks: (string | { type: string; attrs: Record<string, unknown> })[]): JSONContent =>
  ({ type: 'text', text: value, marks: marks.map((m) => (typeof m === 'string' ? { type: m } : m)) });
const p = (...content: JSONContent[]): JSONContent => ({ type: 'paragraph', content });
const doc = (...content: JSONContent[]): JSONContent => ({ type: 'doc', content });
const li = (...content: JSONContent[]): JSONContent => ({ type: 'listItem', content });
const link = (href: string) => ({ type: 'link', attrs: { href } });

test('formatting becomes Markdown that renders back to the same formatting', () => {
  const md = toMarkdown(doc(p(text('Hi '), text('bold', 'bold'), text(', '), text('it', 'italic'), text(' and '), text('x()', 'code'), text(' see '), text('docs', link('https://example.com/a')))));
  assert.equal(md, 'Hi **bold**, *it* and `x()` see [docs](https://example.com/a)');
  assert.equal(render(md), '<p>Hi <strong>bold</strong>, <em>it</em> and <code>x()</code> see <a href="https://example.com/a">docs</a></p>');
  assert.equal(toMarkdown(doc(p(text('both', 'bold', 'italic')))), '***both***');
  assert.equal(toMarkdown(doc(p(text('a ', 'bold'), text('b', 'bold', 'italic'), text(' c', 'bold')))), '**a *b* c**');
  assert.equal(toMarkdown(doc(p(text('x'), text(' spaced ', 'bold'), text('y')))), 'x **spaced** y');
  assert.equal(render(toMarkdown(doc(p(text('a `tick`', 'code'))))), '<p><code>a `tick`</code></p>');
  assert.equal(toMarkdown(doc(p(text('https://example.com/x', link('https://example.com/x'))))), '<https://example.com/x>');
});

test('paragraphs, line breaks and lists', () => {
  const md = toMarkdown(doc(
    p(text('line one'), { type: 'hardBreak' }, text('line two')),
    p(),
    { type: 'bulletList', content: [li(p(text('one'))), li(p(text('two')), { type: 'bulletList', content: [li(p(text('nested')))] })] },
    { type: 'orderedList', attrs: { start: 3 }, content: [li(p(text('three'))), li(p(text('four', 'bold')))] },
  ));
  assert.equal(md, 'line one\nline two\n\n- one\n- two\n  - nested\n\n3. three\n4. **four**');
  assert.equal(render(md), '<p>line one<br/>\nline two</p>\n<ul>\n<li>one</li>\n<li>two\n<ul>\n<li>nested</li>\n</ul>\n</li>\n</ul>\n<ol start="3">\n<li>three</li>\n<li><strong>four</strong></li>\n</ol>');
  assert.equal(toMarkdown(doc(p(), p(text('  ')))), '');
});

test('typed Markdown characters stay literal', () => {
  const typed = ['2 * 3 * 4', '**not bold**', '_x_ and snake_case', '# not a heading', '- not a list', '1. not a list', '> not a quote', '[x](javascript:alert(1))', '`tick`', '~~no~~', '&amp; stays', 'C:\\path\\*', '---'];
  for (const line of typed) {
    const html = render(toMarkdown(doc(p(text(line)))));
    const shown = html.replace(/^<p>|<\/p>$/g, '').replace(/&amp;/g, '&').replace(/&gt;/g, '>');
    assert.equal(shown, line, `${line} → ${html}`);
  }
  assert.equal(toMarkdown(doc(p(text('snake_case and templates/x')))), 'snake_case and templates/x');
  assert.equal(toMarkdown(doc(p(text('[[ticket:tk-1|BR-9]]')))), '[[ticket:tk-1|BR-9]]');
});

test('the link button takes http(s) and mailto only', () => {
  assert.equal(linkHref('https://a.b/c'), 'https://a.b/c');
  assert.equal(linkHref('example.com/x'), 'https://example.com/x');
  assert.equal(linkHref('a@b.co'), 'mailto:a@b.co');
  assert.equal(linkHref('MAILTO:a@b.co'), 'MAILTO:a@b.co');
  for (const bad of ['javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'ftp://x', 'has space.com', '']) assert.equal(linkHref(bad), null, bad);
});
