import { Link, usePage } from '@inertiajs/react';
import type { Shared } from '../lib/account';
import { t, type StrKey } from '../lib/strings';
import { IconChev, LogoMark } from './AppIcons';
import { AccountMenu, NAV, activeHref } from './TopBar';

const LABEL: Record<(typeof NAV)[number]['href'], StrKey> = {
  '/': 'nav.portfolio', '/usage': 'nav.usage', '/polymarket': 'nav.polymarket', '/admin/users': 'nav.admin',
};

/**
 * The redesigned 64px ink top bar (temporary: the app shell replaces it). Same nav gating and account menu as TopBar;
 * only Portfolio uses it for now. At phone width the nav collapses into the current-page menu.
 */
export function AppTopBar() {
  const page = usePage<Shared>();
  const active = activeHref(page.url);
  const links = NAV.filter((n) => !('feature' in n) || page.props.features?.[n.feature] === true);
  const current = links.find((n) => n.href === active);
  const items = links.map((n) => (
    <Link key={n.href} href={n.href} aria-current={n.href === active ? 'page' : undefined}>{t(LABEL[n.href])}</Link>
  ));
  const currentLabel = current ? t(LABEL[current.href]) : t('nav.menu');

  return (
    <header className="atb">
      <div className="atb-in">
        <Link className="atb-brand" href="/"><LogoMark /><span>{t('app.name').toLowerCase()}</span></Link>
        <nav className="atb-nav" aria-label={t('nav.main')}>{items}</nav>
        <details className="atb-navm">
          <summary aria-label={current ? `${t('nav.menu')}, ${currentLabel}` : t('nav.menu')}>{currentLabel}<IconChev /></summary>
          <nav aria-label={`${t('nav.main')} (${t('nav.menu')})`}><ul>{items.map((a) => <li key={a.key}>{a}</li>)}</ul></nav>
        </details>
        <AccountMenu url={page.url} app />
      </div>
    </header>
  );
}
