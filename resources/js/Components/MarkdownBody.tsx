import type { Components } from 'react-markdown';
import ReactMarkdown, { defaultUrlTransform } from 'react-markdown';

/**
 * Safe Markdown for status updates (SPEC §5): paragraphs, bold, italic,
 * ordered/unordered lists, links (new tab, noopener). No raw HTML (react-markdown
 * escapes it; no rehype-raw), no images, unsafe URL schemes stripped.
 */
const ALLOWED = ['p', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'br', 'code'];

const SAFE_URL = /^(https?:|mailto:|tel:|\/|#)/i;

const components: Components = {
  a({ href, children }) {
    if (!href || !SAFE_URL.test(href)) return <>{children}</>;
    return <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>;
  },
};

export function MarkdownBody({ content }: { content: string }) {
  if (!content.trim()) return null;
  return (
    <div className="md">
      <ReactMarkdown
        allowedElements={ALLOWED}
        unwrapDisallowed
        skipHtml
        urlTransform={(url) => defaultUrlTransform(url)}
        components={components}
      >
        {content}
      </ReactMarkdown>
    </div>
  );
}
