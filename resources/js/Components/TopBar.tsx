import { Link, usePage } from '@inertiajs/react';
import { t } from '../lib/strings';

const NAV = [
  { href: '/', label: 'Portfolio' },
  { href: '/usage', label: 'Usage' },
  { href: '/polymarket', label: 'Polymarket' },
];

const under = (url: string, base: string) => url === base || url.startsWith(`${base}/`) || url.startsWith(`${base}?`);

/** "/" and /projects/* belong to Portfolio, /usage* to Usage, /polymarket* to Polymarket. */
const activeHref = (url: string) => (under(url, '/usage') ? '/usage' : under(url, '/polymarket') ? '/polymarket' : '/');

export function TopBar({ nav = true }: { nav?: boolean } = {}) {
  const active = activeHref(usePage().url);
  const current = NAV.find((n) => n.href === active) ?? NAV[0];
  const items = NAV.map((n) => (
    <Link key={n.href} href={n.href} aria-current={n.href === active ? 'page' : undefined}>{n.label}</Link>
  ));

  return (
    <header className="top">
      <div className="wrap topin">
        <Link className="brand" href="/"><span className="mark" aria-hidden="true" /><span>{t('app.name')}</span></Link>
        {nav && (
          <>
            <nav className="nav" aria-label="Main">{items}</nav>
            <details className="navm">
              <summary aria-label={`Menu, current page: ${current.label}`}>{current.label} <span aria-hidden="true">▾</span></summary>
              <nav aria-label="Main (menu)"><ul>{items.map((a) => <li key={a.key}>{a}</li>)}</ul></nav>
            </details>
          </>
        )}
        <span className="who">Tilen</span>
      </div>
    </header>
  );
}
