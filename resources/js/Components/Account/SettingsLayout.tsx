import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import type { Shared } from '../../lib/account';
import { requestJson } from '../../lib/http';
import { AppTopBar } from '../Shell/AppTopBar';

const SECTIONS = [
  { id: 'profile', label: 'Profile' },
  { id: 'password', label: 'Password' },
  { id: 'sessions', label: 'Sessions' },
] as const;

/**
 * Account settings frame: heading + 220px sub-nav (pills ≤720px) + the content column. On /account the in-page links
 * mark the section in view; on /account/tokens "API tokens & MCP" is current.
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
  const cur = (id: string) => (page === 'account' && inView === id ? ('true' as const) : undefined);
  const section = (id: string, label: string, bad?: boolean) =>
    page === 'account'
      ? <a href={href(id)} aria-current={cur(id)} className={bad ? 'bad' : undefined}>{label}</a>
      : <Link href={href(id)} className={bad ? 'bad' : undefined}>{label}</Link>;

  return (
    <>
      <Head title={title} />
      <AppTopBar />
      <main className="wrap">
        <div className="sethead">
          <h1>Account</h1>
          {user && <p className="summary">{user.name} · {user.email}</p>}
        </div>
        <div className="setgrid">
          <nav className="subnav" aria-label="Account settings">
            <ul>
              {SECTIONS.map((s) => <li key={s.id}>{section(s.id, s.label)}</li>)}
              <li><Link href="/account/tokens" aria-current={page === 'tokens' ? 'page' : undefined}>API tokens &amp; MCP</Link></li>
              <li>{section('danger', 'Danger zone', true)}</li>
            </ul>
          </nav>
          <div className="setcol">{children}</div>
        </div>
      </main>
      {toasts}
    </>
  );
}

/** "Verify your email" banner with a resend button (POST /email/verification-notification). */
export function VerifyEmailBanner({ email, why, onResent }: { email: string; why: string; onResent: (message: string) => void }) {
  const [busy, setBusy] = useState(false);
  const resend = async () => {
    setBusy(true);
    const res = await requestJson('POST', '/email/verification-notification');
    setBusy(false);
    onResent(res.ok ? `If ${email} is not verified yet, a new link is on its way.` : res.status === 429 ? 'Too many requests. Wait a minute and try again.' : res.data.message ?? 'Something went wrong.');
  };
  return (
    <div className="callout warn" role="status">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg>
      <p>
        <span className="t">Verify your email.</span> We sent a link to <b>{email}</b>. {why}{' '}
        <button type="button" className="lnk" style={{ minHeight: 0, padding: 0, fontSize: 'inherit' }} onClick={resend} disabled={busy}>{busy ? 'Sending…' : 'Resend link'}</button>
      </p>
    </div>
  );
}
