import type { Components } from 'react-markdown';
import ReactMarkdown, { defaultUrlTransform } from 'react-markdown';
import { TicketRef } from './TicketRefs';
import { parseRefHref, refHref, splitRefs } from '../lib/ticketRefs';

/**
 * Safe Markdown for status updates (SPEC §5): paragraphs, bold, italic,
 * ordered/unordered lists, links (new tab, noopener). No raw HTML (react-markdown
 * escapes it; no rehype-raw), no images, unsafe URL schemes stripped.
 * Ticket references ([[ticket:…]] tokens, lib/ticketRefs.ts) render as ticket chips.
 */
const ALLOWED = ['p', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'br', 'code'];

const SAFE_URL = /^(https?:|mailto:|tel:|\/|#)/i;

const components: Components = {
  a({ href, children }) {
    const ref = parseRefHref(href);
    if (ref) return <TicketRef id={ref.id} fallback={ref.key} />;
    if (!href || !SAFE_URL.test(href)) return <>{children}</>;
    return <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>;
  },
};

type MdNode = { type: string; value?: string; url?: string; children?: MdNode[] };

/** Remark plugin: tokens in text nodes become links to `#ticket-ref:…` (code spans / blocks are not text nodes; links are skipped). */
function remarkTicketRefs() {
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

const REMARK = [remarkTicketRefs];

export function MarkdownBody({ content }: { content: string }) {
  if (!content.trim()) return null;
  return (
    <div className="md">
      <ReactMarkdown
        allowedElements={ALLOWED}
        unwrapDisallowed
        skipHtml
        remarkPlugins={REMARK}
        urlTransform={(url) => defaultUrlTransform(url)}
        components={components}
      >
        {content}
      </ReactMarkdown>
    </div>
  );
}
