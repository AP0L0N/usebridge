import { Link } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AuthShell } from '../../Components/Account/AuthShell';
import { BusyButton, Callout, described, Field } from '../../Components/Account/Fields';
import { fieldErrors, requestJson } from '../../lib/http';

/** Reset, steps request + sent: POST /forgot-password answers the same whether or not the account exists. */
export default function ForgotPassword() {
  const [email, setEmail] = useState(() => new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '').get('email') ?? '');
  const [busy, setBusy] = useState(false);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setError('Enter an email like name@example.com');
      return;
    }
    setBusy(true);
    const res = await requestJson('POST', '/forgot-password', { email: email.trim() });
    setBusy(false);
    if (!res.ok) {
      setError(res.status === 429 ? 'Too many requests. Wait a minute and try again.' : fieldErrors(res.data.errors).email ?? res.data.message ?? 'Something went wrong.');
      return;
    }
    setError(null);
    setSentTo(email.trim());
    requestAnimationFrame(() => heading.current?.focus());
  };

  if (sentTo) {
    return (
      <AuthShell title="Check your email">
        <section className="gcard" aria-labelledby="h-sent">
          <div className="eico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg></div>
          <h1 id="h-sent" tabIndex={-1} ref={heading}>Check your email</h1>
          <p className="lede" role="status">If <b>{sentTo}</b> has a Bridge account, a reset link is on its way. It works for 60 minutes.</p>
          <p className="fine">No email after a few minutes? Check spam, or <button type="button" className="lnk" onClick={() => setSentTo(null)}>send it again</button>.</p>
          <p className="alt"><Link href="/login">Back to log in</Link></p>
        </section>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Reset password">
      <form className="gcard" onSubmit={submit} noValidate aria-labelledby="h-req">
        <h1 id="h-req">Reset your password</h1>
        <p className="lede">Enter the email you signed up with and we'll send a link to set a new password.</p>
        {error && <Callout kind="bad" alert>{error}</Callout>}
        <Field id="r-email" label="Email">
          <input className="qin" id="r-email" type="email" name="email" autoComplete="email" required value={email} disabled={busy}
            onChange={(e) => setEmail(e.target.value)} {...described('r-email', error ?? undefined)} autoFocus />
        </Field>
        <BusyButton busy={busy} busyLabel="Sending…">Send reset link</BusyButton>
        <p className="alt">Remembered it? <Link href="/login">Log in</Link></p>
      </form>
    </AuthShell>
  );
}
