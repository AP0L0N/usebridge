// The comment composer's rich text (Tiptap JSON) → the Markdown that is stored and rendered by MarkdownBody. Pure and
// React-free, tested in tests/js/commentMarkdown.test.ts. Only what the composer offers: paragraphs, line breaks,
// bold, italic, inline code, links, bullet and numbered lists. Typed text is escaped so it stays literal.
import type { JSONContent } from '@tiptap/core';

type Mark = { type: string; attrs?: Record<string, unknown> };
type Segment = { text: string; marks: Mark[] };

/** Outermost first; code is innermost (Tiptap's code mark excludes the others). */
const MARK_ORDER = ['link', 'bold', 'italic', 'code'] as const;

/** Inline text → Markdown text: punctuation that would start markup gets a backslash, nothing else changes. */
export function escapeText(text: string): string {
  return text
    .replace(/\\(?=[!-/:-@[-`{-~])/g, '\\\\')
    .replace(/[*`]/g, '\\$&')
    .replace(/~~/g, '\\~\\~')
    .replace(/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/gu, '\\_')
    .replace(/\](?=[([:])/g, '\\]')
    .replace(/&(?=#?\w+;)/g, '\\&');
}

/** A line that would start a block (heading, quote, list item, rule / setext underline) gets its marker escaped. */
function escapeLineStart(line: string): string {
  return line
    .replace(/^([ \t]*)(#{1,6}(?=[ \t]|$)|>|[-+](?=[ \t]|$)|=+[ \t]*$|-+[ \t]*$)/, '$1\\$2')
    .replace(/^([ \t]*\d{1,9})([.)])(?=[ \t]|$)/, '$1\\$2');
}

function codeSpan(text: string): string {
  const longest = Math.max(0, ...(text.match(/`+/g) ?? []).map((run) => run.length));
  const fence = '`'.repeat(longest + 1);
  const pad = text.startsWith('`') || text.endsWith('`') ? ' ' : '';
  return `${fence}${pad}${text}${pad}${fence}`;
}

/** Delimiters hug the text: surrounding whitespace moves outside (`** a **` would not be bold). */
function wrap(inner: string, open: string, close: string): string {
  const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(inner)!;
  return m[2] ? `${m[1]}${open}${m[2]}${close}${m[3]}` : inner;
}

function markKey(mark: Mark | undefined): string {
  return mark ? `${mark.type}:${String(mark.attrs?.href ?? '')}` : '';
}

function inline(segments: Segment[], depth = 0): string {
  if (depth === MARK_ORDER.length) return segments.map((s) => escapeText(s.text)).join('');
  const type = MARK_ORDER[depth];
  const groups: { mark?: Mark; items: Segment[] }[] = [];
  for (const s of segments) {
    const mark = s.marks.find((m) => m.type === type);
    const last = groups[groups.length - 1];
    if (last && markKey(last.mark) === markKey(mark)) last.items.push(s);
    else groups.push({ mark, items: [s] });
  }
  return groups.map(({ mark, items }) => {
    if (!mark) return inline(items, depth + 1);
    if (type === 'code') {
      const [, before, code, after] = /^(\s*)([\s\S]*?)(\s*)$/.exec(items.map((s) => s.text).join(''))!;
      return code ? before + codeSpan(code) + after : before + after;
    }
    const inner = inline(items, depth + 1);
    if (type === 'link') {
      const href = String(mark.attrs?.href ?? '');
      if (!href) return inner;
      if (/^https?:\/\/[^\s<>]+$/.test(href) && items.every((s) => s.marks.length === 1) && items.map((s) => s.text).join('') === href) return `<${href}>`;
      return /^[^\s()<>\\]+$/.test(href) ? `[${inner}](${href})` : `[${inner}](<${href.replace(/[\s<>\\]/g, encodeURIComponent)}>)`;
    }
    return type === 'bold' ? wrap(inner, '**', '**') : wrap(inner, '*', '*');
  }).join('');
}

/** A paragraph's lines (hard breaks split them), each escaped at its start. */
function paragraph(node: JSONContent): string[] {
  const lines: Segment[][] = [[]];
  for (const child of node.content ?? []) {
    if (child.type === 'hardBreak') lines.push([]);
    else if (child.type === 'text' && child.text) lines[lines.length - 1].push({ text: child.text, marks: (child.marks ?? []) as Mark[] });
  }
  return lines.map((segments) => escapeLineStart(inline(segments).replace(/^[ \t]+|[ \t]+$/g, '')));
}

function list(node: JSONContent): string[] {
  const start = Number(node.attrs?.start ?? 1);
  return (node.content ?? []).flatMap((item, i) => {
    const marker = node.type === 'orderedList' ? `${start + i}. ` : '- ';
    const body = (item.content ?? []).flatMap(block);
    if (!body.length) body.push('');
    const indent = ' '.repeat(marker.length);
    return body.map((line, j) => (j ? (line ? indent + line : '') : marker + line));
  });
}

function block(node: JSONContent): string[] {
  if (node.type === 'paragraph') return paragraph(node);
  if (node.type === 'bulletList' || node.type === 'orderedList') return list(node);
  return [];
}

/** The composer's document as Markdown: blocks separated by a blank line, empty paragraphs dropped. */
export function toMarkdown(doc: JSONContent): string {
  return (doc.content ?? [])
    .map((node) => block(node).join('\n').replace(/^\s+$/, ''))
    .filter(Boolean)
    .join('\n\n');
}

/** What the composer's link button accepts: http(s) and mailto; a bare address gets `https://` (`mailto:` for an email). */
export function linkHref(input: string): string | null {
  const url = input.trim();
  if (!url || /\s/.test(url)) return null;
  const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url)?.[1].toLowerCase();
  if (scheme !== undefined) return ['http', 'https', 'mailto'].includes(scheme) ? url : null;
  return /^[^@/]+@[^@/]+\.[^@/]+$/.test(url) ? `mailto:${url}` : `https://${url}`;
}
