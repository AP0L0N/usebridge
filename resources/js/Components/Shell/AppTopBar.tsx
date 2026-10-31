/**
 * The app shell top bar (64px ink): Wordmark, main nav, account menu, and a menu button on phones.
 *
 *   import { AppTopBar } from '../Components/Shell/AppTopBar';
 *   <AppTopBar />                    // signed-in pages: role (user / owner / admin) comes from the shared Inertia props
 *   <AppTopBar variant="guest" />    // Auth pages: the Wordmark only, linking to /
 *
 * Nav: Portfolio + Usage for everyone; Polymarket only with the workspace feature (the owner's workspace); Admin only
 * for admins. The "Owner" chip is the existing is_admin flag. Phone width (≤720px): the nav lives behind the menu
 * button and the account name is hidden (the avatar stays).
 */
import { Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import type { Shared } from '../../lib/account';
import { go, requestJson } from '../../lib/http';
import { t, type StrKey } from '../../lib/strings';
import { LogoMark } from '../AppIcons';

type NavItem = { href: string; label: StrKey; feature?: 'polymarket' | 'admin' };
export const NAV: readonly NavItem[] = [
  { href: '/', label: 'nav.portfolio' },
  { href: '/usage', label: 'nav.usage' },
  { href: '/polymarket', label: 'nav.polymarket', feature: 'polymarket' },
  { href: '/admin/users', label: 'nav.admin', feature: 'admin' },
];

const under = (url: string, base: string) => url === base || url.startsWith(`${base}/`) || url.startsWith(`${base}?`);

/** "/" and /projects/* belong to Portfolio, /usage* to Usage, /polymarket* to Polymarket, /admin* to Admin; /account* to none. */
export function activeHref(url: string): string | null {
  for (const base of ['/usage', '/polymarket', '/admin']) if (under(url, base)) return base === '/admin' ? '/admin/users' : base;
  if (under(url, '/account') || under(url, '/welcome')) return null;
  return '/';
}

/** The nav entries this viewer may see (the server still answers 404 for the hidden routes). */
export function navFor(features: Shared['features']): NavItem[] {
  return NAV.filter((n) => !n.feature || features?.[n.feature] === true);
}

export function Wordmark() {
  return (
    <Link className="atb-brand" href="/" aria-label={t('shell.home')}>
      <LogoMark /><span aria-hidden="true">{t('app.name').toLowerCase()}</span>
    </Link>
  );
}

/**
 * Disclosure behaviour shared by the account menu and the mobile nav: Esc and click outside close it (focus returns to
 * the button), focus moves into the panel on open and tabbing out closes it, and so does navigating.
 */
function useDisclosure(url: string) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);
  const close = useCallback((refocus = false) => { setOpen(false); if (refocus) button.current?.focus(); }, []);
  useEffect(() => { setOpen(false); }, [url]);
  useEffect(() => {
    if (!open) return;
    root.current?.querySelector<HTMLElement>('[aria-current=page], a, button.atb-mi')?.focus();
    const down = (e: PointerEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false); };
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') { e.stopPropagation(); close(true); } };
    document.addEventListener('pointerdown', down);
    document.addEventListener('keydown', key, true);
    return () => { document.removeEventListener('pointerdown', down); document.removeEventListener('keydown', key, true); };
  }, [open, close]);
  const onBlur = (e: React.FocusEvent) => { if (open && !root.current?.contains(e.relatedTarget as Node | null) && e.relatedTarget) setOpen(false); };
  return { open, setOpen, root, button, close, onBlur };
}

function OwnerBadge() {
  return <span className="atb-badge"><i aria-hidden="true" />{t('shell.owner')}</span>;
}

export function AccountMenu({ url }: { url: string }) {
  const user = usePage<Shared>().props.auth?.user;
  const d = useDisclosure(url);
  const id = useId();
  const [busy, setBusy] = useState(false);
  if (!user) return null;
  const cur = (href: string) => (url.split('?')[0] === href ? ('page' as const) : undefined);
  const logout = async () => {
    setBusy(true);
    await requestJson('POST', '/logout');
    go('/login');
  };
  return (
    <div className="atb-pop" ref={d.root} onBlur={d.onBlur}>
      <button ref={d.button} type="button" className="atb-acct" aria-haspopup="true" aria-expanded={d.open} aria-controls={id}
        aria-label={t(user.is_admin ? 'account.menu.ariaOwner' : 'account.menu.aria', { name: user.name })} onClick={() => d.setOpen((v) => !v)}>
        <span className="atb-avi" aria-hidden="true">{user.initials}</span>
        <span className="atb-an" aria-hidden="true">{user.name}</span>
        <span className="atb-car" aria-hidden="true">▾</span>
      </button>
      {d.open && (
        <div className="atb-menu" id={id}>
          <div className="atb-who">
            <span className="atb-avi dark" aria-hidden="true">{user.initials}</span>
            <span className="atb-name"><b>{user.name}</b><span>{user.email}</span></span>
            {user.is_admin && <OwnerBadge />}
          </div>
          <ul>
            <li><Link className="atb-mi" href="/account" aria-current={cur('/account')}>{t('shell.account')}</Link></li>
            <li><Link className="atb-mi" href="/account/tokens" aria-current={cur('/account/tokens')}>{t('shell.tokens')}</Link></li>
            <li className="atb-sep" role="separator" />
            <li><button type="button" className="atb-mi muted" onClick={logout} disabled={busy}>{busy ? t('shell.loggingOut') : t('shell.logout')}</button></li>
          </ul>
        </div>
      )}
    </div>
  );
}

export function MobileNav({ url, items }: { url: string; items: NavItem[] }) {
  const d = useDisclosure(url);
  const id = useId();
  const active = activeHref(url);
  return (
    <div className="atb-pop atb-mnav" ref={d.root} onBlur={d.onBlur}>
      <button ref={d.button} type="button" className="atb-burger" aria-haspopup="true" aria-expanded={d.open} aria-controls={id}
        aria-label={t(d.open ? 'shell.menu.close' : 'shell.menu.open')} onClick={() => d.setOpen((v) => !v)}>
        <i /><i /><i />
      </button>
      {d.open && (
        <nav className="atb-menu" id={id} aria-label={t('shell.nav.menu')}>
          <ul>
            {items.map((n) => <li key={n.href}><Link className="atb-mi" href={n.href} aria-current={n.href === active ? 'page' : undefined}>{t(n.label)}</Link></li>)}
          </ul>
        </nav>
      )}
    </div>
  );
}

export function AppTopBar({ variant = 'app' }: { variant?: 'guest' | 'app' }) {
  const page = usePage<Shared>();
  const user = page.props.auth?.user ?? null;
  const items = variant === 'app' ? navFor(page.props.features) : [];
  const active = activeHref(page.url);
  const right: ReactNode = variant === 'app' && (
    <>
      {user?.is_admin && <span className="atb-chip">{t('shell.owner')}</span>}
      <AccountMenu url={page.url} />
      {items.length > 0 && <MobileNav url={page.url} items={items} />}
    </>
  );
  return (
    <header className="atb">
      <div className="atb-in">
        <Wordmark />
        {items.length > 0 && (
          <nav className="atb-nav" aria-label={t('nav.main')}>
            {items.map((n) => <Link key={n.href} href={n.href} aria-current={n.href === active ? 'page' : undefined}>{t(n.label)}</Link>)}
          </nav>
        )}
        <div className="atb-right">{right}</div>
      </div>
    </header>
  );
}
