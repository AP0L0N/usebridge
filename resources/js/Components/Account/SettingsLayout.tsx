import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { Notice } from './redesign/Ui';
import type { Shared } from '../../lib/account';
import { t } from '../../lib/strings-account';
import { requestJson } from '../../lib/http';
import { TopBar } from '../TopBar';

const SECTIONS = [
  { id: 'profile', label: t('acct.nav.profile') },
  { id: 'password', label: t('acct.nav.password') },
  { id: 'sessions', label: t('acct.nav.sessions') },
] as const;

/**
 * Account settings frame (Figma redesign): heading + mono "name · email", a 240px sub-nav (a horizontal scrolling tab
 * row on phones) and the card column, all under .acx (resources/css/account-redesign.css) on the paper background. On
 * /account the in-page links mark the section in view; on /account/tokens "API tokens & MCP" is current.
 */
export function SettingsLayout({ page, title, toasts, children }: { page: 'account' | 'tokens'; title: string; toasts?: ReactNode; children: ReactNode }) {
  const user = usePage<Shared>().props.auth?.user;
  const [inView, setInView] = useState<string>('profile');
  useEffect(() => {
    if (page !== 'account' || typeof IntersectionObserver === 'undefined') return;
    const ids = [...SECTIONS.map((s) => s.id), 'danger'];
    const io = new IntersectionObserver((entries) => {
      const top = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
      if (top) setInView(top.target.id);
    }, { rootMargin: '0px 0px -60% 0px' });
    ids.forEach((id) => { const el = document.getElementById(id); if (el) io.observe(el); });
    return () => io.disconnect();
  }, [page]);

  const href = (id: string) => (page === 'account' ? `#${id}` : `/account#${id}`);
  const cls = (id: string, bad?: boolean) => ['acx-nav__item', bad ? 'is-danger' : '', page === 'account' && inView === id ? 'is-current' : ''].filter(Boolean).join(' ');
  const section = (id: string, label: string, bad?: boolean) =>
    page === 'account'
      ? <a href={href(id)} aria-current={page === 'account' && inView === id ? 'true' : undefined} className={cls(id, bad)}>{label}</a>
      : <Link href={href(id)} className={cls(id, bad)}>{label}</Link>;

  return (
    <>
      <Head title={title} />
      <TopBar />
      <main className="wrap">
        <div className="acx acx-page">
          <header className="acx-head">
            <h1>{t('acct.title')}</h1>
            {user && <p className="acx-head__who">{user.name} · {user.email}</p>}
          </header>
          <div className="acx-grid">
            <nav className="acx-nav" aria-label={t('acct.nav.label')}>
              <ul>
                {SECTIONS.map((s) => <li key={s.id}>{section(s.id, s.label)}</li>)}
                <li><Link href="/account/tokens" className={`acx-nav__item${page === 'tokens' ? ' is-current' : ''}`} aria-current={page === 'tokens' ? 'page' : undefined}>{t('acct.nav.tokens')}</Link></li>
                <li>{section('danger', t('acct.nav.danger'), true)}</li>
              </ul>
            </nav>
            <div className="acx-col">{children}</div>
          </div>
          {toasts}
        </div>
      </main>
    </>
  );
}

/** "Verify your email" notice with a resend button (POST /email/verification-notification). */
export function VerifyEmailBanner({ email, why, onResent }: { email: string; why: string; onResent: (message: string) => void }) {
  const [busy, setBusy] = useState(false);
  const resend = async () => {
    setBusy(true);
    const res = await requestJson('POST', '/email/verification-notification');
    setBusy(false);
    onResent(res.ok ? t('acct.verify.resent', { email }) : res.status === 429 ? t('acct.throttled') : res.data.message ?? t('acct.generic'));
  };
  return (
    <Notice kind="violet" role="status">
      <p><b>{t('acct.verify.title')}</b> {t('acct.verify.body', { email })} {why}</p>
      <div className="acx-notice__links">
        <button type="button" className="acx-link" onClick={resend} disabled={busy}>{busy ? t('acct.verify.sending') : t('acct.verify.resend')}</button>
      </div>
    </Notice>
  );
}
