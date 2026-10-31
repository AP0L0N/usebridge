import { Link } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { AuthLayout, ownLayout, type AuthPageProps } from '../../Components/Auth/AuthLayout';
import { Button } from '../../Components/Auth/Button';
import { Notice, StateIcon, TextField } from '../../Components/Auth/TextField';
import { EMAIL_RE, t } from '../../lib/authCopy';
import { fieldErrors, requestJson } from '../../lib/http';

/** Reset, Figma states request + sent: POST /forgot-password answers the same whether or not the account exists. */
export default function ForgotPassword({ copy }: AuthPageProps) {
  const c = copy.forgot;
  const [email, setEmail] = useState(() => new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '').get('email') ?? '');
  const [busy, setBusy] = useState(false);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [again, setAgain] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);

  const send = async (to: string): Promise<boolean> => {
    setBusy(true);
    const res = await requestJson('POST', '/forgot-password', { email: to });
    setBusy(false);
    if (res.ok) return true;
    if (res.status === 422) setError(fieldErrors(res.data.errors).email ? copy.errors.email_invalid : copy.common.generic);
    else setFormError(res.status === 429 ? c.too_many! : res.status === 0 ? copy.common.network : copy.common.generic);
    return false;
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setFormError(null);
    if (!EMAIL_RE.test(email.trim())) {
      setError(copy.errors.email_invalid);
      return;
    }
    setError(null);
    if (await send(email.trim())) {
      setSentTo(email.trim());
      setAgain(false);
      requestAnimationFrame(() => heading.current?.focus());
    }
  };

  const sendAgain = async () => {
    setFormError(null);
    if (sentTo && (await send(sentTo))) setAgain(true);
  };

  if (sentTo) {
    return (
      <AuthLayout title={c.sent_title!}>
        <section className="auth-form auth-state" aria-labelledby="h-sent">
          <StateIcon kind="ok" />
          <div className="auth-heading">
            <h1 id="h-sent" tabIndex={-1} ref={heading}>{c.sent_heading}</h1>
            <p className="auth-lede auth-lede--sm" role="status">{t(c.sent_body!, { email: sentTo })}</p>
          </div>
          {formError && <Notice kind="risk" role="alert">{formError}</Notice>}
          {again && <Notice kind="ok" role="status">{c.sent_again}</Notice>}
          <div className="auth-state-actions">
            <p className="auth-fine">{c.sent_help}</p>
            <Button type="button" variant="secondary" busy={busy} busyLabel={c.busy} onClick={sendAgain}>{c.send_again}</Button>
            <p className="auth-alt"><Link href="/login">{copy.common.back_to_login}</Link></p>
          </div>
        </section>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title={c.title!}>
      <form className="auth-form" onSubmit={submit} noValidate aria-labelledby="h-req">
        <div className="auth-heading">
          <h1 id="h-req">{c.heading}</h1>
          <p className="auth-lede auth-lede--sm">{c.subtitle}</p>
        </div>
        {formError && <Notice kind="risk" role="alert">{formError}</Notice>}
        <TextField id="r-email" name="email" type="email" label={copy.common.email} autoComplete="email" required value={email} disabled={busy}
          error={error ?? undefined} onChange={(e) => setEmail(e.target.value)} autoFocus />
        <Button busy={busy} busyLabel={c.busy} busyNote={c.busy_note}>{c.submit}</Button>
        <p className="auth-alt">{c.remembered} <Link href="/login">{c.login}</Link></p>
      </form>
    </AuthLayout>
  );
}

ForgotPassword.layout = ownLayout;
