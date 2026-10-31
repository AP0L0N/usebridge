import { Link } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AuthLayout, ownLayout, type AuthPageProps } from '../../Components/Auth/AuthLayout';
import { Button, ButtonLink } from '../../Components/Auth/Button';
import { ErrorSummary, OkLine, pwToggle, StateIcon, StrengthMeter, TextField } from '../../Components/Auth/TextField';
import { t } from '../../lib/authCopy';
import { fieldErrors, requestJson } from '../../lib/http';

type Props = AuthPageProps & { token: string; email: string; valid: boolean; minPassword: number };

const FIELD_IDS = { password: 'n-pw', password_confirmation: 'n-pw2' };
const ORDER = ['password', 'password_confirmation', 'form'];

/**
 * Reset, Figma states set new / expired / success: POST /reset-password. `valid` (the token exists and is not older
 * than 60 minutes) decides between set new and expired on load; a token that fails on submit answers expired too.
 */
export default function ResetPassword({ copy, token, email, valid, minPassword }: Props) {
  const c = copy.reset;
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
    if (pw.length < minPassword) local.password = t(c.password_short!, { min: minPassword });
    else if (pw !== pw2) local.password_confirmation = copy.errors.password_mismatch;
    if (Object.keys(local).length) {
      setErrors(local);
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setErrors({});
    setBusy(true);
    const res = await requestJson<{ expired?: boolean }>('POST', '/reset-password', { token, email, password: pw });
    setBusy(false);
    if (res.ok || res.data.expired) {
      setState(res.ok ? 'success' : 'expired');
      requestAnimationFrame(() => heading.current?.focus());
      return;
    }
    setErrors(res.status === 422 ? fieldErrors(res.data.errors)
      : { form: res.status === 429 ? c.too_many! : res.status === 0 ? copy.common.network : copy.common.generic });
    requestAnimationFrame(() => summary.current?.focus());
  };

  if (state === 'expired') {
    return (
      <AuthLayout title={c.expired_title!}>
        <section className="auth-form auth-state" aria-labelledby="h-exp">
          <StateIcon kind="warn" />
          <div className="auth-heading">
            <h1 id="h-exp" tabIndex={-1} ref={heading}>{c.expired_heading}</h1>
            <p className="auth-lede auth-lede--sm">{c.expired_body}</p>
          </div>
          <div className="auth-state-actions">
            <ButtonLink href={email ? `/forgot-password?email=${encodeURIComponent(email)}` : '/forgot-password'}>{c.expired_action}</ButtonLink>
            <p className="auth-alt"><Link href="/login">{copy.common.back_to_login}</Link></p>
          </div>
        </section>
      </AuthLayout>
    );
  }

  if (state === 'success') {
    return (
      <AuthLayout title={c.success_title!}>
        <section className="auth-form auth-state" aria-labelledby="h-ok">
          <StateIcon kind="ok" />
          <div className="auth-heading">
            <h1 id="h-ok" tabIndex={-1} ref={heading}>{c.success_heading}</h1>
            <p className="auth-lede auth-lede--sm" role="status">{c.success_body}</p>
          </div>
          <div className="auth-state-actions">
            <ButtonLink href="/login?status=password-reset">{c.success_action}</ButtonLink>
          </div>
        </section>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title={c.title!}>
      <form className="auth-form" onSubmit={submit} noValidate aria-labelledby="h-new">
        <div className="auth-heading">
          <h1 id="h-new">{c.heading}</h1>
          <p className="auth-lede auth-lede--sm">{t(c.subtitle!, { email })}</p>
        </div>
        <ErrorSummary ref={summary} errors={errors} fieldIds={FIELD_IDS} order={ORDER} copy={copy.common} />
        <div className="auth-fields">
          <TextField id="n-pw" label={c.new_password} autoComplete="new-password" value={pw} disabled={busy} autoFocus error={errors.password}
            toggle={pwToggle(copy, c.new_password_label!)} aria-describedby="n-meter" onChange={(e) => setPw(e.target.value)}
            below={<StrengthMeter id="n-meter" value={pw} min={minPassword} copy={copy.strength} />} />
          <TextField id="n-pw2" label={c.confirm_password} autoComplete="new-password" value={pw2} disabled={busy} error={errors.password_confirmation}
            toggle={pwToggle(copy, c.confirm_password_label!)} aria-describedby="n-match" onChange={(e) => setPw2(e.target.value)}
            below={!errors.password_confirmation && <OkLine id="n-match">{match ? copy.strength.match : null}</OkLine>} />
        </div>
        <Button busy={busy} busyLabel={c.busy} busyNote={c.busy_note}>{c.submit}</Button>
      </form>
    </AuthLayout>
  );
}

ResetPassword.layout = ownLayout;
