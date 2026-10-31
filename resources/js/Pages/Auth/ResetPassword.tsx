import { Link } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AuthShell } from '../../Components/Account/AuthShell';
import { BusyButton, described, ErrorSummary, Field, IconCheck, PasswordInput, StrengthMeter } from '../../Components/Account/Fields';
import { fieldErrors, requestJson } from '../../lib/http';

type Props = { token: string; email: string; valid: boolean; minPassword: number };

/** Reset, steps set-new / expired / success: POST /reset-password; an invalid or used token answers expired. */
export default function ResetPassword({ token, email, valid, minPassword }: Props) {
  const [state, setState] = useState<'set-new' | 'expired' | 'success'>(valid ? 'set-new' : 'expired');
  const [pw, setPw] = useState('');
  const [pw2, setPw2] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const summary = useRef<HTMLDivElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const match = pw2 !== '' && pw === pw2;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (pw.length < minPassword) local.password = `Use at least ${minPassword} characters`;
    else if (pw !== pw2) local.password_confirmation = "The two passwords don't match";
    if (Object.keys(local).length) {
      setErrors(local);
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setBusy(true);
    const res = await requestJson<{ expired?: boolean }>('POST', '/reset-password', { token, email, password: pw });
    setBusy(false);
    if (res.ok) {
      setState('success');
      requestAnimationFrame(() => heading.current?.focus());
    } else if (res.data.expired) {
      setState('expired');
      requestAnimationFrame(() => heading.current?.focus());
    } else {
      setErrors(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.status === 429 ? 'Too many attempts. Wait a minute and try again.' : res.data.message ?? 'Something went wrong.' });
      requestAnimationFrame(() => summary.current?.focus());
    }
  };

  if (state === 'expired') {
    return (
      <AuthShell title="Link expired">
        <section className="gcard" aria-labelledby="h-exp">
          <div className="eico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg></div>
          <h1 id="h-exp" tabIndex={-1} ref={heading}>This link has expired</h1>
          <p className="lede">Reset links work once, for 60 minutes. Ask for a new one and use the newest email.</p>
          <Link className="btn wide" href={email ? `/forgot-password?email=${encodeURIComponent(email)}` : '/forgot-password'}>Send a new link</Link>
          <p className="alt"><Link href="/login">Back to log in</Link></p>
        </section>
      </AuthShell>
    );
  }

  if (state === 'success') {
    return (
      <AuthShell title="Password updated">
        <section className="gcard" aria-labelledby="h-ok">
          <div className="eico ok" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="m5 12 5 5 9-10" /></svg></div>
          <h1 id="h-ok" tabIndex={-1} ref={heading}>Password updated</h1>
          <p className="lede" role="status">Use your new password to log in. Other devices have been logged out.</p>
          <a className="btn wide" href="/login?status=password-reset">Log in</a>
        </section>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Set a new password">
      <form className="gcard" onSubmit={submit} noValidate aria-labelledby="h-new">
        <h1 id="h-new">Set a new password</h1>
        <p className="lede">For {email}. You'll be logged out on your other devices.</p>
        <ErrorSummary ref={summary} errors={errors} fieldIds={{ password: 'n-pw', password_confirmation: 'n-pw2' }} what="continue" />
        <Field id="n-pw" label="New password" error={errors.password}>
          <PasswordInput id="n-pw" autoComplete="new-password" showLabel="new password" value={pw} disabled={busy} autoFocus
            onChange={(e) => setPw(e.target.value)} {...described('n-pw', errors.password, false, 'n-meter')} />
          <StrengthMeter id="n-meter" value={pw} min={minPassword} />
        </Field>
        <Field id="n-pw2" label="Confirm new password" error={errors.password_confirmation}>
          <PasswordInput id="n-pw2" autoComplete="new-password" showLabel="confirmed password" value={pw2} disabled={busy}
            onChange={(e) => setPw2(e.target.value)} {...described('n-pw2', errors.password_confirmation, false, 'n-match')} />
          <p id="n-match" aria-live="polite" style={{ margin: 0 }}>{match && <span className="ok-line"><IconCheck />Passwords match</span>}</p>
        </Field>
        <BusyButton busy={busy} busyLabel="Updating…">Update password</BusyButton>
      </form>
    </AuthShell>
  );
}
