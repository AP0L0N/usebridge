import type { Components } from 'react-markdown';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

type Props = {
  content?: string;
  children?: string;
  className?: string;
};

function isExternalHref(href: string | undefined): boolean {
  if (!href) return false;
  return /^(https?:|mailto:|tel:)/i.test(href);
}

const components: Components = {
  a({ href, children, ...rest }) {
    const external = isExternalHref(href);
    return (
      <a
        href={href}
        {...(external
          ? { target: '_blank', rel: 'noopener noreferrer' }
          : {})}
        {...rest}
      >
        {children}
      </a>
    );
  },
};

export function MarkdownBody({ content, children, className }: Props) {
  const source = content ?? children ?? '';
  if (!source.trim()) return null;

  return (
    <div className={['md-body', className].filter(Boolean).join(' ')}>
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
        {source}
      </ReactMarkdown>
    </div>
  );
}
