import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { AuthLayout, ownLayout, type AuthPageProps } from '../../Components/Auth/AuthLayout';
import { Button } from '../../Components/Auth/Button';
import { CheckRow, Notice, pwToggle, TextField } from '../../Components/Auth/TextField';
import { fillPlaceholders } from '../../Components/SiteFooter';
import type { Shared } from '../../lib/account';
import { t, type AuthCopy } from '../../lib/authCopy';
import { longDate } from '../../lib/dates';
import { go, requestJson } from '../../lib/http';

type Refusal =
  | { kind: 'wrong'; triesLeft: number | null }
  | { kind: 'locked'; until: number }
  | { kind: 'disabled' }
  | { kind: 'deletion'; purgeAt: string | null }
  | { kind: 'other'; message: string };

type LoginAnswer = {
  redirect?: string; tries_left?: number; retry_after?: number; locked_until?: string; disabled?: boolean; deletion_pending?: boolean; purge_at?: string;
};

/** "15 minutes" / "40 seconds" (long) or "15 min" / "40 s" (button) from the seconds left on the lock. */
export function waitText(seconds: number, c: AuthCopy['login'], short = false): string {
  if (seconds >= 60) {
    const m = Math.ceil(seconds / 60);
    return short ? t(c.wait_short_minutes!, { count: m }) : m === 1 ? c.wait_minute! : t(c.wait_minutes!, { count: m });
  }
  const s = Math.max(1, Math.ceil(seconds));
  return short ? t(c.wait_short_seconds!, { count: s }) : s === 1 ? c.wait_second! : t(c.wait_seconds!, { count: s });
}

/**
 * Log in (Figma states default / wrong / locked / loading): POST /login; 422 tries_left, 429 retry_after (seconds
 * left on the lock, counted down here), 403 disabled / deletion pending.
 */
export default function Login({ copy, gateAvailable }: AuthPageProps & { gateAvailable: boolean }) {
  const c = copy.login;
  const { flash } = usePage<Shared>().props;
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<Refusal | null>(null);
  const [cancelled, setCancelled] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const pwRef = useRef<HTMLInputElement>(null);
  const status = typeof window !== 'undefined' ? c.status[new URLSearchParams(window.location.search).get('status') ?? ''] : undefined;

  const lockedUntil = refusal?.kind === 'locked' ? refusal.until : null;
  const secondsLeft = lockedUntil === null ? 0 : Math.max(0, (lockedUntil - now) / 1000);
  const locked = secondsLeft > 0;
  useEffect(() => {
    if (lockedUntil === null) return;
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, [lockedUntil]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (busy || locked) return;
    setBusy(true);
    const res = await requestJson<LoginAnswer>('POST', '/login', { email, password, remember });
    if (res.ok) {
      go(res.data.redirect ?? '/');
      return;
    }
    setBusy(false);
    const d = res.data;
    if (res.status === 422) setRefusal({ kind: 'wrong', triesLeft: d.tries_left ?? null });
    else if (res.status === 429) {
      const seconds = d.retry_after ?? (d.locked_until ? (Date.parse(d.locked_until) - Date.now()) / 1000 : 60);
      setNow(Date.now());
      setRefusal({ kind: 'locked', until: Date.now() + Math.max(1, seconds) * 1000 });
    } else if (res.status === 403 && d.deletion_pending) setRefusal({ kind: 'deletion', purgeAt: d.purge_at ?? null });
    else if (res.status === 403 && d.disabled) setRefusal({ kind: 'disabled' });
    else setRefusal({ kind: 'other', message: res.status === 0 ? copy.common.network : res.status === 419 ? copy.common.session_expired : copy.common.generic });
    if (res.status === 422) {
      setPassword('');
      requestAnimationFrame(() => pwRef.current?.focus());
    }
  };

  const cancelDeletion = async () => {
    setBusy(true);
    const res = await requestJson<LoginAnswer>('POST', '/login/cancel-deletion', { email, password });
    if (res.ok) {
      setCancelled(true);
      go('/');
      return;
    }
    setBusy(false);
    setRefusal({ kind: 'other', message: c.cancel_failed! });
  };

  const wrong = refusal?.kind === 'wrong';
  const resetLink = <Link href={email ? `/forgot-password?email=${encodeURIComponent(email.trim())}` : '/forgot-password'}>{c.locked_reset}</Link>;
  return (
    <AuthLayout title={c.title!}>
      <form className="auth-form" onSubmit={submit} noValidate aria-labelledby="h-login">
        <div className="auth-heading">
          <h1 id="h-login">{c.heading}</h1>
          <p className="auth-lede">{c.subtitle}</p>
        </div>

        {status && !refusal && <Notice kind="info" role="status">{status}</Notice>}
        {flash?.error && !refusal && <Notice kind="risk" role="alert">{flash.error}</Notice>}
        {flash?.success && !refusal && <Notice kind="ok" role="status">{flash.success}</Notice>}

        {wrong && (
          <Notice kind="risk" role="alert">
            {c.wrong}
            {refusal.triesLeft !== null && refusal.triesLeft > 0 && <> {refusal.triesLeft === 1 ? c.tries_left_one : t(c.tries_left_many!, { count: refusal.triesLeft })}</>}
          </Notice>
        )}
        {refusal?.kind === 'locked' && (
          <Notice kind="warn" role="alert">
            {fillPlaceholders(locked ? t(c.locked!, { wait: waitText(secondsLeft, c) }) : c.locked_over!, { reset: resetLink })}
          </Notice>
        )}
        {refusal?.kind === 'disabled' && <Notice kind="risk" role="alert">{c.disabled}</Notice>}
        {refusal?.kind === 'deletion' && (
          <Notice kind="warn" role="alert">
            <p>{refusal.purgeAt ? t(c.deletion_on!, { date: longDate(refusal.purgeAt) }) : c.deletion}</p>
            <Button type="button" variant="secondary" onClick={cancelDeletion} disabled={busy}>{cancelled ? c.deletion_cancelled : c.cancel_deletion}</Button>
          </Notice>
        )}
        {refusal?.kind === 'other' && <Notice kind="risk" role="alert">{refusal.message}</Notice>}

        <div className="auth-fields">
          <TextField id="email" name="email" type="email" label={copy.common.email} autoComplete="email" required value={email}
            disabled={busy || locked} onChange={(e) => setEmail(e.target.value)} autoFocus />
          <TextField ref={pwRef} id="pw" name="password" label={copy.common.password} autoComplete="current-password" required value={password}
            disabled={busy || locked} invalid={wrong} toggle={pwToggle(copy, c.password_label!)} onChange={(e) => setPassword(e.target.value)}
            below={<p className="auth-forgot"><Link href="/forgot-password">{c.forgot}</Link></p>} />
        </div>
        <CheckRow id="remember" name="remember" checked={remember} onChange={setRemember}>{c.remember}</CheckRow>

        <Button busy={busy} busyLabel={c.busy} busyNote={c.busy_note} disabled={locked}>
          {locked ? t(c.locked_button!, { wait: waitText(secondsLeft, c, true) }) : c.submit}
        </Button>

        <p className="auth-alt">{c.new} <Link href="/signup">{c.create}</Link></p>
        {gateAvailable && <p className="auth-fine auth-center">{c.gate} <a href="/gate">{c.gate_link}</a></p>}
      </form>
    </AuthLayout>
  );
}

Login.layout = ownLayout;
