import type { Components } from 'react-markdown';
import ReactMarkdown from 'react-markdown';
import { TicketRef } from './TicketRefs';
import { isSafeHref, MARKDOWN_OPTIONS, prepareMarkdown } from '../lib/markdown';
import { parseRefHref } from '../lib/ticketRefs';

/**
 * Safe Markdown for descriptions, comments and status updates (SPEC §5): paragraphs, line breaks, bold, italic,
 * strikethrough, ordered/unordered lists, code, links (new tab, noopener; bare URLs too). No raw HTML (skipped; no
 * rehype-raw), no images, unsafe URL schemes stripped (lib/markdown.ts). The stored text is tidied at render time
 * only (labelled sections become paragraphs, stray `*` stay literal); ticket references render as ticket chips.
 */
const components: Components = {
  a({ href, children }) {
    const ref = parseRefHref(href);
    if (ref) return <TicketRef id={ref.id} fallback={ref.key} />;
    if (!isSafeHref(href)) return <>{children}</>;
    return <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>;
  },
};

export function MarkdownBody({ content }: { content: string }) {
  if (!content.trim()) return null;
  return (
    <div className="md">
      <ReactMarkdown {...MARKDOWN_OPTIONS} components={components}>{prepareMarkdown(content)}</ReactMarkdown>
    </div>
  );
}
