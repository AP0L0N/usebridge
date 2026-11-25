import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createElement, type ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import Markdown from 'react-markdown';
import { isSafeHref, MARKDOWN_OPTIONS, prepareMarkdown } from '../../resources/js/lib/markdown.ts';
import { parseRefHref } from '../../resources/js/lib/ticketRefs.ts';

/** The MarkdownBody pipeline with a stand-in for its `a` component: ticket refs → `<span data-ref>`, unsafe → text. */
function a({ href, children }: { href?: string; children?: ReactNode }) {
  const ref = parseRefHref(href);
  if (ref) return createElement('span', { 'data-ref': ref.id }, ref.key);
  if (!isSafeHref(href)) return children;
  return createElement('a', { href, target: '_blank', rel: 'noopener noreferrer' }, children);
}
const render = (text: string) => renderToStaticMarkup(createElement(Markdown, { ...MARKDOWN_OPTIONS, components: { a } }, prepareMarkdown(text)));

const CS29 = "**Effort:** light (Haiku 5.5). **Source:** Shodan's CS-28 preview check. **Problem:** the blog tag pill 'ALL' comes from the section setting all_label. Both are set in templates/*.json, so the CS-28 locale keys don't reach them. **Fix:** in the section .liquid only, fall back to the locale keys (no templates/* change). **URL:** https://chipolo-global.myshopify.com/de/blogs/chipolo (behind the storefront password). **Done when:** built on a preview, then live push per the Oct 12 rule. **Needed from Tilen:** nothing.";

test('a one-line labelled description renders one paragraph per label', () => {
  const html = render(CS29);
  const paragraphs = html.match(/<p>.*?<\/p>/g) ?? [];
  assert.deepEqual(paragraphs.map((p) => p.match(/^<p><strong>([^<]+)<\/strong>/)?.[1]), ['Effort:', 'Source:', 'Problem:', 'Fix:', 'URL:', 'Done when:', 'Needed from Tilen:']);
  assert.ok(!html.includes('<em>'));
  assert.ok(html.includes('templates/*.json') && html.includes('templates/* change'));
  assert.ok(html.includes('<a href="https://chipolo-global.myshopify.com/de/blogs/chipolo" target="_blank" rel="noopener noreferrer">'));
});

test('labels split only mid-line after a sentence end, never inside code', () => {
  assert.equal(prepareMarkdown('Run it --nodelete). **Needed from Tilen:** the production go.'), 'Run it --nodelete).\n\n**Needed from Tilen:** the production go.');
  assert.equal(prepareMarkdown('Done. **Fix**: this'), 'Done.\n\n**Fix**: this');
  assert.equal(prepareMarkdown('**Fix:** at line start'), '**Fix:** at line start');
  assert.equal(prepareMarkdown('a **bold** word. **not a label** here'), 'a **bold** word. **not a label** here');
  assert.equal(prepareMarkdown('`x. **Fix:** y`'), '`x. **Fix:** y`');
  assert.equal(prepareMarkdown('```\nx. **Fix:** y\n```'), '```\nx. **Fix:** y\n```');
  assert.equal(prepareMarkdown('a\r\nb\rc'), 'a\nb\nc');
});

test('a label inside a list item starts a new line of the item, and the list goes on', () => {
  assert.equal(render('- Done. **Fix:** x\n- next'), '<ul>\n<li>Done.<br/>\n<strong>Fix:</strong> x</li>\n<li>next</li>\n</ul>');
  assert.equal(render('1. a. **Fix:** b\n2. c'), '<ol>\n<li>a.<br/>\n<strong>Fix:</strong> b</li>\n<li>c</li>\n</ol>');
  assert.equal(render('- one\n  lazy. **Fix:** y\n- two'), '<ul>\n<li>one<br/>\nlazy.<br/>\n<strong>Fix:</strong> y</li>\n<li>two</li>\n</ul>');
  assert.equal(prepareMarkdown('1. **Fix:** x'), '1. **Fix:** x');
});

test('stray asterisks stay literal, real emphasis still works', () => {
  assert.equal(render('Set in templates/*.json and templates/*) or a*b or 2 * 3'), '<p>Set in templates/*.json and templates/*) or a*b or 2 * 3</p>');
  assert.equal(render('an *unclosed opener runs on'), '<p>an *unclosed opener runs on</p>');
  assert.equal(render('*italic*, (*also*) **bold** ***both***'), '<p><em>italic</em>, (<em>also</em>) <strong>bold</strong> <em><strong>both</strong></em></p>');
  assert.equal(render('**bold *nested* bold**'), '<p><strong>bold <em>nested</em> bold</strong></p>');
  assert.equal(prepareMarkdown('`templates/*.json` *x*'), '`templates/*.json` *x*');
  assert.equal(prepareMarkdown('```\na * b\n```'), '```\na * b\n```');
});

test('lists, line breaks and paragraphs', () => {
  assert.equal(render('* one\n* two'), '<ul>\n<li>one</li>\n<li>two</li>\n</ul>');
  assert.equal(render('- one\n- two'), '<ul>\n<li>one</li>\n<li>two</li>\n</ul>');
  assert.equal(render('1. one\n2. two'), '<ol>\n<li>one</li>\n<li>two</li>\n</ol>');
  assert.equal(render('line one\nline two'), '<p>line one<br/>\nline two</p>');
  assert.equal(render('para one\n\npara two'), '<p>para one</p>\n<p>para two</p>');
  assert.equal(render('~~gone~~ but ~one~ stays'), '<p><del>gone</del> but ~one~ stays</p>');
  assert.equal(render('```\ncode * here\n```'), '<pre><code>code * here\n</code></pre>');
});

test('links open in a new tab, bare URLs too', () => {
  assert.equal(render('[docs](https://example.com/a)'), '<p><a href="https://example.com/a" target="_blank" rel="noopener noreferrer">docs</a></p>');
  assert.equal(render('see https://example.com.'), '<p>see <a href="https://example.com" target="_blank" rel="noopener noreferrer">https://example.com</a>.</p>');
  assert.equal(render('[mail](mailto:a@b.c) [rel](/tickets/1)'), '<p><a href="mailto:a@b.c" target="_blank" rel="noopener noreferrer">mail</a> <a href="/tickets/1" target="_blank" rel="noopener noreferrer">rel</a></p>');
});

test('stored plain text keeps its line breaks and shows HTML as text', () => {
  assert.equal(render('Checked it.\nuse <div> in the template\n\n<b>not bold</b> & a < b'), '<p>Checked it.<br/>\nuse &lt;div&gt; in the template</p>\n<p>&lt;b&gt;not bold&lt;/b&gt; &amp; a &lt; b</p>');
  assert.equal(render('<script>alert(1)</script>'), '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
  assert.equal(render('<div onclick="x()">\nhi\n</div>'), '<p>&lt;div onclick=&quot;x()&quot;&gt;<br/>\nhi<br/>\n&lt;/div&gt;</p>');
  assert.equal(render('- item <i>x</i>'), '<ul>\n<li>item &lt;i&gt;x&lt;/i&gt;</li>\n</ul>');
  assert.equal(render('use \\<div> (saved escaped)'), '<p>use &lt;div&gt; (saved escaped)</p>');
});

test('no raw HTML and no unsafe link targets', () => {
  const payloads = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '![x](https://example.com/x.png)',
    '[x](javascript:alert(1))',
    '[x](JaVaScRiPt:alert(1))',
    '[x](&#106;avascript:alert(1))',
    '[x](&#x6A;avascript&colon;alert(1))',
    '[x](data:text/html,<script>alert(1)</script>)',
    '[x](vbscript:msgbox(1))',
    '[x](<jav\tascript:alert(1)>)',
    '<a href="javascript:alert(1)">x</a>',
    '[x]: javascript:alert(1)\n\n[y][x]',
  ];
  for (const p of payloads) {
    const html = render(p);
    assert.doesNotMatch(html, /<(script|img|iframe)|<[^>]*\son\w+=|href="(?!https?:\/\/)/i, `${p} → ${html}`);
  }
  for (const url of ['javascript:alert(1)', 'JAVASCRIPT:x', 'data:text/html,x', 'vbscript:x', ' javascript:x', 'java\nscript:x', '']) assert.equal(isSafeHref(url), false, url);
  for (const url of ['https://a.b', 'http://a.b', 'mailto:a@b.c', 'tel:+386', '/x', 'x/y', '#top', '#ticket-ref:tk-1:BR-9']) assert.equal(isSafeHref(url), true, url);
});

test('ticket references become chips anywhere in text, but not in code', () => {
  assert.equal(render('See [[ticket:tk-1|BR-9]] first'), '<p>See <span data-ref="tk-1">BR-9</span> first</p>');
  assert.equal(render('**[[ticket:tk-1|BR-9]]** and [[ticket:tk-2]]'), '<p><strong><span data-ref="tk-1">BR-9</span></strong> and <span data-ref="tk-2"></span></p>');
  assert.equal(render('- after [[ticket:tk-1|BR-9]]\n- next'), '<ul>\n<li>after <span data-ref="tk-1">BR-9</span></li>\n<li>next</li>\n</ul>');
  assert.equal(render('Done. **Blocked by:** [[ticket:tk-1|BR-9]]\nthen [[ticket:tk-2|BR-10]]'), '<p>Done.</p>\n<p><strong>Blocked by:</strong> <span data-ref="tk-1">BR-9</span><br/>\nthen <span data-ref="tk-2">BR-10</span></p>');
  assert.equal(render('`[[ticket:tk-1|BR-9]]`'), '<p><code>[[ticket:tk-1|BR-9]]</code></p>');
});
