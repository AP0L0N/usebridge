import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { AuthShell } from '../../Components/Account/AuthShell';
import { BusyButton, Callout, described, ErrorSummary, Field, PasswordInput, StrengthMeter } from '../../Components/Account/Fields';
import { fieldErrors, go, requestJson } from '../../lib/http';

type Props = { formToken: string; turnstileSiteKey: string | null; minPassword: number };

declare global {
  interface Window { turnstile?: { reset: (el?: string | HTMLElement) => void; render: (el: HTMLElement, opts: Record<string, unknown>) => string } }
}

const TURNSTILE_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

/** Cloudflare Turnstile, only when a site key is configured: the script loads then, and renders into .cf-turnstile. */
function Turnstile({ siteKey }: { siteKey: string }) {
  const el = useRef<HTMLDivElement>(null);
  useEffect(() => {
    // Script already loaded (an earlier visit in this tab): render explicitly; else its onload scans .cf-turnstile.
    if (window.turnstile && el.current && el.current.childElementCount === 0) {
      window.turnstile.render(el.current, { sitekey: siteKey, 'response-field-name': 'cf-turnstile-response' });
      return;
    }
    if (document.querySelector(`script[src="${TURNSTILE_SRC}"]`)) return;
    const s = document.createElement('script');
    s.src = TURNSTILE_SRC;
    s.async = true;
    s.defer = true;
    document.head.appendChild(s);
  }, [siteKey]);
  return <div ref={el} className="cf-turnstile" data-sitekey={siteKey} data-response-field-name="cf-turnstile-response" />;
}

const FIELD_IDS = { name: 'name', email: 'email', password: 'pw' };

/** Sign up: POST /signup with the honeypot (`website`) + signed `form_token`, plus Turnstile when configured. */
export default function Signup({ formToken, turnstileSiteKey, minPassword }: Props) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [website, setWebsite] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [taken, setTaken] = useState<string | null>(null);
  const summary = useRef<HTMLDivElement>(null);
  const formRef = useRef<HTMLFormElement>(null);

  const check = (): Record<string, string> => {
    const e: Record<string, string> = {};
    if (name.trim() === '') e.name = 'Enter your name';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) e.email = 'Enter an email like name@example.com';
    if (password.length < minPassword) e.password = `Use at least ${minPassword} characters for your password`;
    return e;
  };

  const submit = async (ev: FormEvent) => {
    ev.preventDefault();
    if (busy) return;
    const local = check();
    setTaken(null);
    if (Object.keys(local).length > 0) {
      setErrors(local);
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setBusy(true);
    const tsResponse = formRef.current ? new FormData(formRef.current).get('cf-turnstile-response') : null;
    const res = await requestJson<{ redirect?: string }>('POST', '/signup', {
      name, email, password, website, form_token: formToken,
      ...(turnstileSiteKey ? { 'cf-turnstile-response': tsResponse ?? '' } : {}),
    });
    if (res.ok) {
      go('/welcome');
      return;
    }
    setBusy(false);
    window.turnstile?.reset();
    const fe = res.status === 422 ? fieldErrors(res.data.errors) : { form: res.status === 429 ? 'Too many sign-ups from this network. Try again in an hour.' : res.data.message ?? 'Something went wrong. Try again.' };
    if (fe.email && /already|taken/i.test(fe.email)) {
      setTaken(email.trim());
      fe.email = 'This email already has an account';
    }
    setErrors(fe);
    if (!(fe.email && Object.keys(fe).length === 1 && /already/.test(fe.email))) requestAnimationFrame(() => summary.current?.focus());
  };

  const summaryErrors = taken ? Object.fromEntries(Object.entries(errors).filter(([k]) => k !== 'email')) : errors;
  return (
    <AuthShell title="Create account">
      <form className="gcard" onSubmit={submit} noValidate aria-labelledby="h-signup" ref={formRef}>
        <h1 id="h-signup">Create your account</h1>
        <p className="lede">Track your own projects on a board, and let your Grok bot keep them up to date.</p>

        <ErrorSummary ref={summary} errors={summaryErrors} fieldIds={FIELD_IDS} what="continue" />
        {taken && (
          <Callout kind="bad" alert>
            <span className="t">{taken} already has an account.</span> <Link href="/login">Log in</Link> instead, or <Link href="/forgot-password">reset the password</Link> if you've forgotten it.
          </Callout>
        )}

        <Field id="name" label="Name" error={errors.name} hint="Shown on your boards and to your bots.">
          <input className="qin" id="name" name="name" autoComplete="name" required value={name} disabled={busy}
            onChange={(e) => setName(e.target.value)} {...described('name', errors.name, true)} autoFocus />
        </Field>
        <Field id="email" label="Email" error={errors.email}>
          <input className="qin" id="email" type="email" name="email" autoComplete="email" required value={email} disabled={busy}
            onChange={(e) => setEmail(e.target.value)} {...described('email', errors.email)} />
        </Field>
        <Field id="pw" label="Password" error={errors.password}>
          <PasswordInput id="pw" name="password" autoComplete="new-password" required value={password} disabled={busy}
            onChange={(e) => setPassword(e.target.value)} {...described('pw', errors.password, false, 'pw-meter')} />
          <StrengthMeter id="pw-meter" value={password} min={minPassword} />
        </Field>

        {/* honeypot: people never see or fill it; bots that fill every field are refused */}
        <div aria-hidden="true" style={{ position: 'absolute', left: '-10000px', width: 1, height: 1, overflow: 'hidden' }}>
          <label htmlFor="website">Website</label>
          <input id="website" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
        </div>
        {turnstileSiteKey && <Turnstile siteKey={turnstileSiteKey} />}

        <p className="fine">Bridge stores your projects and usage only to show them to you. You verify your email before you create API tokens for bots.</p>
        <BusyButton busy={busy} busyLabel="Creating account…">Create account</BusyButton>
        <p className="alt">Already have an account? <Link href="/login">Log in</Link></p>
      </form>
    </AuthShell>
  );
}
