import { Link } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AuthLayout, ownLayout, type AuthPageProps } from '../../Components/Auth/AuthLayout';
import { Button } from '../../Components/Auth/Button';
import { CheckRow, ErrorSummary, Notice, pwToggle, StrengthMeter, TextField } from '../../Components/Auth/TextField';
import { fillPlaceholders } from '../../Components/SiteFooter';
import { EMAIL_RE, t } from '../../lib/authCopy';
import { fieldErrors, go, requestJson } from '../../lib/http';
import { turnstileToken } from '../../lib/turnstile';

type Acceptance = {
  checkbox: string; smallPrint: string; termsLink: string; privacyLink: string; smallPrintTermsLink: string; smallPrintPrivacyLink: string;
  termsUrl: string; privacyUrl: string;
};
type Props = AuthPageProps & { formToken: string; turnstileSiteKey: string | null; minPassword: number; acceptTerms: boolean; acceptance: Acceptance };

const FIELD_IDS = { name: 'name', email: 'email', password: 'pw', accept_terms: 'accept-terms' };
const ORDER = ['name', 'email', 'password', 'accept_terms', 'form', 'turnstile'];

/**
 * Sign up (Figma states default / errors / loading / email taken): POST /signup with the honeypot (`website`) + signed
 * `form_token`, plus a Turnstile token when a site key is configured. Turnstile loads only here, on submit (lib/turnstile):
 * nothing from Cloudflare on page render. The terms + 18+ checkbox is required and never pre-ticked.
 */
export default function Signup({ copy, formToken, turnstileSiteKey, minPassword, acceptTerms, acceptance }: Props) {
  const c = copy.signup;
  const [name, setName] = useState('');
  const [accepted, setAccepted] = useState(acceptTerms);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [website, setWebsite] = useState('');
  // idle → verifying (Turnstile running) → prompt (Cloudflare wants a tick) → creating (POST /signup in flight).
  // Constance QA S1: "Creating account…" only shows once the request has really started.
  const [phase, setPhase] = useState<'idle' | 'verifying' | 'prompt' | 'creating'>('idle');
  const busy = phase !== 'idle';
  const setBusy = (on: boolean) => setPhase(on ? 'creating' : 'idle');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [taken, setTaken] = useState<string | null>(null);
  const summary = useRef<HTMLDivElement>(null);
  const takenRef = useRef<HTMLDivElement>(null);
  const tsRef = useRef<HTMLDivElement>(null);

  const check = (): Record<string, string> => {
    const e: Record<string, string> = {};
    if (name.trim() === '') e.name = copy.errors.name_required;
    if (!EMAIL_RE.test(email.trim())) e.email = copy.errors.email_invalid;
    if (password.length < minPassword) e.password = t(copy.errors.password_short, { min: minPassword });
    return e;
  };

  const submit = async (ev: FormEvent) => {
    ev.preventDefault();
    if (busy || !accepted) return;
    const local = check();
    setTaken(null);
    if (Object.keys(local).length > 0) {
      setErrors(local);
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setErrors({});
    let tsResponse: string | null = null;
    if (turnstileSiteKey && tsRef.current) {
      setPhase('verifying');
      tsResponse = await turnstileToken(turnstileSiteKey, tsRef.current, () => setPhase('prompt')).catch(() => null);
      if (tsResponse === null) {
        setBusy(false);
        setErrors({ turnstile: c.turnstile_failed! });
        requestAnimationFrame(() => summary.current?.focus());
        return;
      }
    }
    setPhase('creating');
    const res = await requestJson<{ redirect?: string; email_taken?: boolean }>('POST', '/signup', {
      name, email, password, website, form_token: formToken, accept_terms: accepted,
      ...(tsResponse !== null ? { 'cf-turnstile-response': tsResponse } : {}),
    });
    if (res.ok) {
      go('/welcome');
      return;
    }
    setBusy(false);
    if (res.status === 422 && res.data.email_taken) {
      setTaken(email.trim());
      setErrors({ email: copy.errors.email_taken });
      requestAnimationFrame(() => takenRef.current?.focus());
      return;
    }
    const fe = res.status === 422 ? fieldErrors(res.data.errors)
      : { form: res.status === 429 ? c.too_many! : res.status === 0 ? copy.common.network : res.status === 419 ? copy.common.session_expired : copy.common.generic };
    if (fe.turnstile) fe.turnstile = c.turnstile_failed!;
    setErrors(fe);
    requestAnimationFrame(() => summary.current?.focus());
  };

  const fieldError = (k: keyof typeof FIELD_IDS) => errors[k];
  const summaryErrors = taken ? {} : errors;
  return (
    <AuthLayout title={c.title!}>
      <form className="auth-form" onSubmit={submit} noValidate aria-labelledby="h-signup">
        <div className="auth-heading">
          <h1 id="h-signup">{c.heading}</h1>
          <p className="auth-lede">{c.subtitle}</p>
        </div>

        <ErrorSummary ref={summary} errors={summaryErrors} fieldIds={FIELD_IDS} order={ORDER} copy={copy.common} />
        {taken && (
          <div ref={takenRef} tabIndex={-1} className="auth-focus-target">
            <Notice kind="warn" role="alert">
              {fillPlaceholders(c.taken!, {
                email: <b>{taken}</b>,
                login: <Link href="/login">{c.taken_login}</Link>,
                reset: <Link href={`/forgot-password?email=${encodeURIComponent(taken)}`}>{c.taken_reset}</Link>,
              })}
            </Notice>
          </div>
        )}

        <div className="auth-fields">
          <TextField id="name" name="name" label={c.name} autoComplete="name" required value={name} disabled={busy} autoFocus
            error={fieldError('name')} hint={c.name_hint} onChange={(e) => setName(e.target.value)} />
          <TextField id="email" name="email" type="email" label={copy.common.email} autoComplete="email" required value={email} disabled={busy}
            error={fieldError('email')} onChange={(e) => setEmail(e.target.value)} />
          <TextField id="pw" name="password" label={copy.common.password} autoComplete="new-password" required value={password} disabled={busy}
            error={fieldError('password')} toggle={pwToggle(copy, c.password_label!)} aria-describedby="pw-meter"
            onChange={(e) => setPassword(e.target.value)}
            below={<StrengthMeter id="pw-meter" value={password} min={minPassword} copy={copy.strength} />} />
        </div>

        {/* honeypot: people never see or fill it; bots that fill every field are refused */}
        <div aria-hidden="true" style={{ position: 'absolute', left: '-10000px', width: 1, height: 1, overflow: 'hidden' }}>
          <label htmlFor="website">Website</label>
          <input id="website" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
        </div>
        {/* the Turnstile widget renders here on submit, and only shows if Cloudflare needs the person to act */}
        {turnstileSiteKey && <div ref={tsRef} className="auth-turnstile" />}

        <p className="auth-fine">{c.storage_note}</p>
        <div className="auth-terms">
          <CheckRow id="accept-terms" name="accept_terms" required checked={accepted} disabled={busy} onChange={setAccepted}
            invalid={Boolean(errors.accept_terms)} describedBy={errors.accept_terms ? 'accept-terms-err' : undefined}>
            {fillPlaceholders(acceptance.checkbox, {
              terms: <a href={acceptance.termsUrl} target="_blank" rel="noopener">{acceptance.termsLink}</a>,
              privacy: <a href={acceptance.privacyUrl} target="_blank" rel="noopener">{acceptance.privacyLink}</a>,
            })}
          </CheckRow>
          {errors.accept_terms && <p className="auth-msg auth-msg--error" id="accept-terms-err"><span className="auth-dot" aria-hidden="true" />{errors.accept_terms}</p>}
        </div>
        <Button busy={busy}
          busyLabel={phase === 'prompt' ? c.verify_prompt : phase === 'verifying' ? c.verifying : c.busy}
          busyNote={phase === 'prompt' ? c.verify_prompt_note : phase === 'verifying' ? c.verifying_note : c.busy_note} disabled={!accepted}>{c.submit}</Button>
        <p className="auth-fine" id="signup-small-print">{fillPlaceholders(acceptance.smallPrint, {
          terms: <a href={acceptance.termsUrl} target="_blank" rel="noopener">{acceptance.smallPrintTermsLink}</a>,
          privacy: <a href={acceptance.privacyUrl} target="_blank" rel="noopener">{acceptance.smallPrintPrivacyLink}</a>,
        })}</p>
        <p className="auth-alt">{c.have_account} <Link href="/login">{c.login}</Link></p>
      </form>
    </AuthLayout>
  );
}

Signup.layout = ownLayout;
