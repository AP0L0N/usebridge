import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { AuthShell } from '../../Components/Account/AuthShell';
import { BusyButton, Callout, Field, PasswordInput } from '../../Components/Account/Fields';
import type { Shared } from '../../lib/account';
import { clockTime, longDate } from '../../lib/dates';
import { go, requestJson } from '../../lib/http';

type Refusal =
  | { kind: 'wrong'; triesLeft: number | null }
  | { kind: 'locked'; until: string | null }
  | { kind: 'disabled' }
  | { kind: 'deletion'; purgeAt: string | null }
  | { kind: 'other'; message: string };

type LoginAnswer = { redirect?: string; tries_left?: number; locked_until?: string; disabled?: boolean; deletion_pending?: boolean; purge_at?: string };

/** Notices the app links here with ?status= (only these keys; never free text from the URL). */
const STATUS: Record<string, string> = {
  'deletion-requested': 'Your account will be deleted in 7 days. Log in before then to cancel.',
  'logged-out-everywhere': 'You were logged out of all devices.',
  'password-reset': 'Password updated. Log in with your new password.',
};

/** Log in (evolves the AUTH-1 gate): POST /login; 422 tries_left, 429 locked_until, 403 disabled / deletion pending. */
export default function Login({ gateAvailable }: { gateAvailable: boolean }) {
  const { flash } = usePage<Shared>().props;
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<Refusal | null>(null);
  const [cancelled, setCancelled] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const pwRef = useRef<HTMLInputElement>(null);
  const status = typeof window !== 'undefined' ? STATUS[new URLSearchParams(window.location.search).get('status') ?? ''] : undefined;

  const lockedUntil = refusal?.kind === 'locked' && refusal.until ? Date.parse(refusal.until) : null;
  const locked = lockedUntil !== null && lockedUntil > now;
  useEffect(() => {
    if (lockedUntil === null) return;
    const t = window.setInterval(() => setNow(Date.now()), 15000);
    return () => window.clearInterval(t);
  }, [lockedUntil]);
  const minutesLeft = lockedUntil ? Math.max(1, Math.ceil((lockedUntil - now) / 60000)) : 15;

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
    if (res.status === 422 && d.tries_left !== undefined) setRefusal({ kind: 'wrong', triesLeft: d.tries_left });
    else if (res.status === 422) setRefusal({ kind: 'wrong', triesLeft: null });
    else if (res.status === 429) setRefusal({ kind: 'locked', until: d.locked_until ?? new Date(Date.now() + 60000).toISOString() });
    else if (res.status === 403 && d.deletion_pending) setRefusal({ kind: 'deletion', purgeAt: d.purge_at ?? null });
    else if (res.status === 403 && d.disabled) setRefusal({ kind: 'disabled' });
    else setRefusal({ kind: 'other', message: d.message ?? 'Something went wrong. Try again.' });
    if (res.status === 422) {
      setPassword('');
      pwRef.current?.focus();
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
    setRefusal({ kind: 'other', message: res.data.message ?? 'The deletion could not be cancelled.' });
  };

  const wrong = refusal?.kind === 'wrong';
  return (
    <AuthShell title="Log in">
      <form className="gcard" onSubmit={submit} noValidate aria-labelledby="h-login">
        <h1 id="h-login">Log in</h1>
        <p className="lede">Your projects, boards and bots, in one place.</p>

        {status && !refusal && <Callout kind="info" status>{status}</Callout>}
        {flash?.error && !refusal && <Callout kind="bad" alert>{flash.error}</Callout>}
        {flash?.success && !refusal && <Callout kind="ok" status>{flash.success}</Callout>}

        {wrong && (
          <Callout kind="bad" alert>
            <span className="t">That email and password don't match.</span> Check both and try again.
            {refusal.triesLeft !== null && refusal.triesLeft > 0 && <> {refusal.triesLeft} {refusal.triesLeft === 1 ? 'try' : 'tries'} left before a 15-minute pause.</>}
          </Callout>
        )}
        {refusal?.kind === 'locked' && (
          <Callout kind="warn" icon="lock" alert>
            <span className="t">Too many attempts. Try again {locked ? `in ${minutesLeft} ${minutesLeft === 1 ? 'minute' : 'minutes'}` : 'now'}{locked && refusal.until ? ` (at ${clockTime(refusal.until)})` : ''}.</span>{' '}
            Or <Link href="/forgot-password">reset your password</Link> now; the link works right away.
          </Callout>
        )}
        {refusal?.kind === 'disabled' && (
          <Callout kind="bad" alert><span className="t">This account is disabled.</span> Ask the Bridge owner to enable it again.</Callout>
        )}
        {refusal?.kind === 'deletion' && (
          <div className="callout warn" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M12 3l10 18H2z" /><path d="M12 10v4M12 17h.01" /></svg>
            <div>
              <p><span className="t">This account is scheduled for deletion{refusal.purgeAt ? ` on ${longDate(refusal.purgeAt)}` : ''}.</span> Cancel the deletion to keep it and log in.</p>
              <p style={{ marginTop: 8 }}><button type="button" className="abtn sm" onClick={cancelDeletion} disabled={busy}>{cancelled ? 'Deletion cancelled' : 'Cancel deletion and log in'}</button></p>
            </div>
          </div>
        )}
        {refusal?.kind === 'other' && <Callout kind="bad" alert>{refusal.message}</Callout>}

        <Field id="email" label="Email">
          <input className="qin" id="email" type="email" name="email" autoComplete="email" required value={email} disabled={busy}
            aria-invalid={wrong || undefined} onChange={(e) => setEmail(e.target.value)} autoFocus />
        </Field>
        <Field id="pw" label="Password" labelRow={<Link href="/forgot-password">Forgot password?</Link>}>
          <PasswordInput ref={pwRef} id="pw" name="password" autoComplete="current-password" required value={password} disabled={busy}
            aria-invalid={wrong || undefined} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        <label className="chkrow"><input type="checkbox" name="remember" checked={remember} onChange={(e) => setRemember(e.target.checked)} /> Keep me logged in on this device</label>

        {locked
          ? <button className="btn wide" type="button" disabled aria-disabled="true">Log in (paused for {minutesLeft} min)</button>
          : <BusyButton busy={busy} busyLabel="Logging in…">Log in</BusyButton>}

        <p className="alt">New to Bridge? <Link href="/signup">Create account</Link></p>
        {gateAvailable && <p className="fine" style={{ textAlign: 'center' }}>Shared team access? <a href="/gate">Workspace password</a></p>}
      </form>
    </AuthShell>
  );
}
