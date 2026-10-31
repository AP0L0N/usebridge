import { Link, usePage } from '@inertiajs/react';
import { Fragment, type ReactNode } from 'react';
import type { Footer, Shared } from '../lib/account';

/**
 * Replaces :name placeholders in a translated string with nodes ("… the :terms. …" + {terms: <a/>}); text stays text,
 * so nothing from the lang files is ever parsed as HTML.
 */
export function fillPlaceholders(text: string, nodes: Record<string, ReactNode>): ReactNode[] {
  const names = Object.keys(nodes);
  if (names.length === 0) return [text];
  return text.split(new RegExp(`(:(?:${names.join('|')}))\\b`)).map((part, i) => {
    const node = part.startsWith(':') ? nodes[part.slice(1)] : undefined;
    return <Fragment key={i}>{node ?? part}</Fragment>;
  });
}

/** EN | SL on the legal pages only: links to the same page in each language, the current one marked. */
function LanguageSwitch({ sw, locale }: { sw: NonNullable<Footer['switch']>; locale: string }) {
  return (
    <span className="sfoot-lang" role="group" aria-label={sw.label}>
      <Link href={sw.en} hrefLang="en" lang="en" aria-current={locale === 'en' ? 'page' : undefined}>EN</Link>
      <span aria-hidden="true"> | </span>
      <Link href={sw.sl} hrefLang="sl" lang="sl" aria-current={locale === 'sl' ? 'page' : undefined}>SL</Link>
    </span>
  );
}

/**
 * The one site footer (every page, via the layout in app.tsx): Imprint · Terms · Privacy · Source code, and the © line
 * with the AGPL source link (the deployed commit when known). Copy from lang/{en,sl}/legal.php via the shared `footer`.
 */
export function SiteFooter() {
  const { footer } = usePage<Shared>().props;
  if (!footer) return null;
  const sep = <span className="sfoot-sep" aria-hidden="true"> · </span>;
  return (
    <footer className="sfoot" lang={footer.locale}>
      <div className="sfoot-in">
        <nav aria-label={footer.navLabel}>
          <ul className="sfoot-links">
            {footer.links.map((l) => (
              <li key={l.href}><Link href={l.href}>{l.label}</Link>{sep}</li>
            ))}
            <li><a href={footer.source.href} rel="noopener">{footer.source.label}</a>{footer.switch && sep}</li>
            {footer.switch && <li><LanguageSwitch sw={footer.switch} locale={footer.locale} /></li>}
          </ul>
        </nav>
        <p className="sfoot-copy">
          {fillPlaceholders(footer.copyright, { link: <a href={footer.source.href} rel="noopener">{footer.getSource}</a> })}
        </p>
      </div>
    </footer>
  );
}

/** Default layout for every Inertia page: the page, then the footer. */
export function SiteLayout({ children }: { children: ReactNode }) {
  return (
    <div className="site">
      <div className="site-main">{children}</div>
      <SiteFooter />
    </div>
  );
}
