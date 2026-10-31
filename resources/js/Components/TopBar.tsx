import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { Shared } from '../lib/account';
import { go, requestJson } from '../lib/http';
import { t } from '../lib/strings';
import { IconChev } from './AppIcons';

export const NAV = [
  { href: '/', label: 'Portfolio' },
  { href: '/usage', label: 'Usage' },
  { href: '/polymarket', label: 'Polymarket', feature: 'polymarket' },
  { href: '/admin/users', label: 'Admin', feature: 'admin' },
] as const;

const under = (url: string, base: string) => url === base || url.startsWith(`${base}/`) || url.startsWith(`${base}?`);

/** "/" and /projects/* belong to Portfolio, /usage* to Usage, /polymarket* to Polymarket, /admin* to Admin; /account* to none. */
export function activeHref(url: string): string | null {
  for (const base of ['/usage', '/polymarket', '/admin']) if (under(url, base)) return base === '/admin' ? '/admin/users' : base;
  if (under(url, '/account') || under(url, '/welcome')) return null;
  return '/';
}

/**
 * Logged-in pages: avatar initials + name → Account, API tokens & MCP, Log out (DESIGN.md `.acct`). An "Owner" chip
 * for the Bridge owner (is_admin). Closes on Esc, outside click and navigation.
 */
export function AccountMenu({ url, app = false }: { url: string; app?: boolean }) {
  const user = usePage<Shared>().props.auth?.user;
  const ref = useRef<HTMLDetailsElement>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    const onDoc = (e: MouseEvent) => { if (d.open && !d.contains(e.target as Node)) d.open = false; };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && d.open) { d.open = false; d.querySelector('summary')?.focus(); }
    };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); };
  }, []);
  useEffect(() => { if (ref.current) ref.current.open = false; }, [url]);
  if (!user) return null;

  const logout = async () => {
    setBusy(true);
    await requestJson('POST', '/logout');
    go('/login');
  };
  const cur = (href: string) => (url.split('?')[0] === href ? ('page' as const) : undefined);

  return (
    <details className={app ? 'acct atb-acct' : 'acct'} ref={ref}>
      <summary aria-label={t(user.is_admin ? 'account.menu.ariaOwner' : 'account.menu.aria', { name: user.name })}>
        {user.is_admin && <span className="ownr">Owner</span>}
        <span className="avi" aria-hidden="true">{user.initials}</span>
        <span className="an">{user.name}</span>
        <span className="car" aria-hidden="true">{app ? <IconChev /> : '▾'}</span>
      </summary>
      <div className="menu">
        <p className="aid"><b>{user.name}</b><span>{user.email}</span>{user.is_admin && <span className="ownr">Owner</span>}</p>
        <ul>
          <li><Link href="/account" aria-current={cur('/account')}>Account</Link></li>
          <li><Link href="/account/tokens" aria-current={cur('/account/tokens')}>API tokens &amp; MCP</Link></li>
          <li className="sep" role="separator" />
          <li><button type="button" className="mi" onClick={logout} disabled={busy}>{busy ? 'Logging out…' : 'Log out'}</button></li>
        </ul>
      </div>
    </details>
  );
}

export function TopBar({ nav = true }: { nav?: boolean } = {}) {
  const page = usePage<Shared>();
  const active = activeHref(page.url);
  const user = page.props.auth?.user ?? null;
  const links = NAV.filter((n) => !('feature' in n) || page.props.features?.[n.feature] === true);
  const current = links.find((n) => n.href === active);
  const items = links.map((n) => (
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
              <summary aria-label={current ? `Menu, current page: ${current.label}` : 'Menu'}>{current?.label ?? 'Menu'} <span aria-hidden="true">▾</span></summary>
              <nav aria-label="Main (menu)"><ul>{items.map((a) => <li key={a.key}>{a}</li>)}</ul></nav>
            </details>
          </>
        )}
        {user ? <AccountMenu url={page.url} /> : nav && <span className="who">Tilen</span>}
      </div>
    </header>
  );
}
