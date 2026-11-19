// Shared Markdown pipeline for MarkdownBody (ticket descriptions, comments, status updates): pure, React-free, tested in
// tests/js/markdown.test.ts. Everything here is render-time only; the stored text (and the API payload) never changes.
import type { Options } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { refHref, splitRefs } from './ticketRefs.ts';

type MdNode = { type: string; value?: string; url?: string; children?: MdNode[] };

const FENCE = /^ {0,3}(`{3,}|~{3,})/;
/** `**Label:**` / `**Label**:` (1–40 chars, no newline or `*`) right after a sentence end + whitespace, mid-line. */
const MID_LABEL = /([.!?):])[ \t]+(?=\*\*[^*\s][^*\n]{0,39}(?::\*\*|\*\*:))/g;
const LIST_ITEM = /^[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]+/;
const SPACE = /\s/;
const PUNCT = /[!-/:-@[-`{-~]/;
const OPEN_BEFORE = /[([{"'-]/;
const NO_OPEN_AFTER = /[.,;:!?)\]}]/;
const CLOSE_BEFORE = /[\p{L}\p{N})\]}"'.!?:;,%]/u;
const CLOSE_AFTER = /[.,;:!?)\]}"'\-/]/;

/**
 * The paragraph with everything that is not plain inline text (code spans, backslash escapes, bare URLs) replaced by
 * `x` of the same length, so the rewrites below can look at positions and never touch those parts.
 */
function mask(text: string): string {
  const out = text.split('');
  const fill = (from: number, to: number) => { for (let i = from; i < to; i++) if (out[i] !== '\n') out[i] = 'x'; };
  for (let i = 0; i < text.length; i++) {
    if (text[i] === '\\' && PUNCT.test(text[i + 1] ?? '')) { fill(i, i + 2); i++; continue; }
    if (text[i] === '`') {
      let n = 1;
      while (text[i + n] === '`') n++;
      const close = new RegExp(`(?<!\`)\`{${n}}(?!\`)`, 'g');
      close.lastIndex = i + n;
      const m = close.exec(text);
      if (m) { fill(i, m.index + n); i = m.index + n - 1; } else i += n - 1;
      continue;
    }
    const url = /^(?:https?:\/\/|www\.)[^\s<>]*[^\s<>.,;:!?)'"]/i.exec(text.slice(i));
    if (url && (i === 0 || !/[\w.]/.test(text[i - 1]))) { fill(i, i + url[0].length); i += url[0].length - 1; }
  }
  return out.join('');
}

/**
 * A bold label that follows a sentence mid-line starts a new paragraph; inside a list item (a line with a list marker
 * and its lazy continuation lines) it starts a new line of the item instead, so the list goes on.
 */
function splitLabels(text: string): string {
  const masked = mask(text);
  let itemIndent: string | null = null;
  const lines = masked.split('\n').map((line) => {
    const marker = LIST_ITEM.exec(line)?.[0] ?? '';
    if (marker) itemIndent = ' '.repeat(marker.length);
    return { marker: marker.length, itemIndent };
  });
  let out = '';
  let last = 0;
  for (const m of masked.matchAll(MID_LABEL)) {
    const lineStart = masked.lastIndexOf('\n', m.index) + 1;
    const line = lines[masked.slice(0, lineStart).split('\n').length - 1];
    if (m.index - lineStart < line.marker) continue;
    const at = m.index + 1;
    out += text.slice(last, at) + (line.itemIndent === null ? '\n\n' : `\n${line.itemIndent}`);
    last = at + m[0].length - 1;
  }
  return out + text.slice(last);
}

/**
 * Lone `*` that would not make sensible emphasis get a backslash: path-like / intraword (`templates/*.json`, `a*b`),
 * spaced (`2 * 3`), and openers/closers without a partner in the paragraph. `**`/`***` runs, list markers and
 * `*italic*` stay as they are.
 */
function escapeStrayStars(text: string): string {
  const masked = mask(text);
  const escape: number[] = [];
  const open: number[] = [];
  for (let i = 0; i < masked.length; i++) {
    if (masked[i] !== '*') continue;
    if (masked[i - 1] === '*' || masked[i + 1] === '*') { while (masked[i + 1] === '*') i++; continue; }
    const lineStart = masked.lastIndexOf('\n', i - 1) + 1;
    if (/^[ \t]*$/.test(masked.slice(lineStart, i)) && /[ \t]/.test(masked[i + 1] ?? '')) continue;
    const before = masked[i - 1] ?? ' ';
    const after = masked[i + 1] ?? ' ';
    const canOpen = (SPACE.test(before) || OPEN_BEFORE.test(before)) && !SPACE.test(after) && !NO_OPEN_AFTER.test(after);
    const canClose = !SPACE.test(before) && CLOSE_BEFORE.test(before) && (SPACE.test(after) || CLOSE_AFTER.test(after));
    if (canClose && open.length) open.pop();
    else if (canOpen) open.push(i);
    else escape.push(i);
  }
  escape.push(...open);
  escape.sort((a, b) => a - b);
  let out = '';
  let last = 0;
  for (const at of escape) { out += `${text.slice(last, at)}\\`; last = at; }
  return out + text.slice(last);
}

/** Applies `fn` to each paragraph (run of non-blank lines) outside fenced code; indented code is left alone too. */
function eachParagraph(text: string, fn: (paragraph: string) => string): string {
  const lines = text.split('\n');
  const out: string[] = [];
  let para: string[] = [];
  let fence: string | null = null;
  const flush = () => {
    if (para.length) out.push(/^(?: {4}|\t)/.test(para[0]) ? para.join('\n') : fn(para.join('\n')));
    para = [];
  };
  for (const line of lines) {
    if (fence !== null) {
      out.push(line);
      if (new RegExp(`^ {0,3}${fence[0] === '`' ? '`' : '~'}{${fence.length},}[ \\t]*$`).test(line)) fence = null;
      continue;
    }
    const f = FENCE.exec(line);
    if (f) { flush(); fence = f[1]; out.push(line); continue; }
    if (!line.trim()) { flush(); out.push(line); continue; }
    para.push(line);
  }
  flush();
  return out.join('\n');
}

/** Render-time clean-up of stored text: LF line ends, labelled sections as paragraphs, stray asterisks escaped. */
export function prepareMarkdown(text: string): string {
  const lf = text.replace(/\r\n?/g, '\n');
  return eachParagraph(eachParagraph(lf, splitLabels), escapeStrayStars);
}

/** Remark plugin: `[[ticket:…]]` tokens in text nodes become links to `#ticket-ref:…` (code is not text; links are skipped). */
export function remarkTicketRefs() {
  const walk = (node: MdNode) => {
    if (!node.children || node.type === 'link') return;
    node.children = node.children.flatMap((child): MdNode[] => {
      if (child.type !== 'text' || !child.value?.includes('[[ticket:')) { walk(child); return [child]; }
      return splitRefs(child.value).map((p) => (typeof p === 'string'
        ? { type: 'text', value: p }
        : { type: 'link', url: refHref(p.id, p.key), children: [{ type: 'text', value: p.key ?? '' }] }));
    });
  };
  return (tree: MdNode) => { walk(tree); };
}

/** Remark plugin (like remark-breaks): a line break inside a paragraph is a `<br>`, not a space. */
export function remarkLineBreaks() {
  const walk = (node: MdNode) => {
    if (!node.children) return;
    node.children = node.children.flatMap((child): MdNode[] => {
      if (child.type !== 'text' || !child.value?.includes('\n')) { walk(child); return [child]; }
      return child.value.split(/[ \t]*\n[ \t]*/).flatMap((part, i): MdNode[] => [
        ...(i ? [{ type: 'break' }] : []),
        ...(part ? [{ type: 'text', value: part }] : []),
      ]);
    });
  };
  return (tree: MdNode) => { walk(tree); };
}

const SCHEME = /^([a-z][a-z0-9+.-]*):/i;

const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];
const CONTROL = /[\u0000- \u007f-\u009f]/g;

/**
 * http(s), mailto, tel, relative paths and `#…` (ticket refs included); never javascript:/data:/vbscript: etc. The
 * percent-decoded form without whitespace/control characters is checked too (`jav%09ascript:` → `javascript:`).
 */
export function isSafeHref(url: string | null | undefined): url is string {
  if (!url || /[\u0000- \u007f-\u009f\\]/.test(url)) return false;
  let decoded = url;
  try { decoded = decodeURIComponent(url); } catch { /* malformed escapes: judge the raw form */ }
  return [url, decoded.replace(CONTROL, '')].every((u) => {
    const scheme = SCHEME.exec(u)?.[1].toLowerCase();
    return scheme === undefined || SAFE_SCHEMES.includes(scheme);
  });
}

/** react-markdown options shared by MarkdownBody and the tests (no raw HTML, no images, safe links only). */
export const MARKDOWN_OPTIONS = {
  allowedElements: ['p', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'br', 'code', 'pre', 'del'],
  unwrapDisallowed: true,
  skipHtml: true,
  remarkPlugins: [[remarkGfm, { singleTilde: false }], remarkTicketRefs, remarkLineBreaks],
  urlTransform: (url: string) => (isSafeHref(url) ? url : ''),
} satisfies Options;
