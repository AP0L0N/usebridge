import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { AuthLayout, ownLayout, type AuthPageProps } from '../../Components/Auth/AuthLayout';
import { Button } from '../../Components/Auth/Button';
import { Notice, StateIcon } from '../../Components/Auth/TextField';
import { t } from '../../lib/authCopy';
import { go, requestJson } from '../../lib/http';

type Props = AuthPageProps & { email: string; resendUrl: string };

/**
 * GET /email/verify for a logged-in, unverified user (after login): resend the link (POST, 6 per minute) or go on to the
 * boards, which work without verifying; API tokens and MCP need the verified email.
 */
export default function VerifyEmail({ email, resendUrl, copy }: Props) {
  const c = copy.verify;
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);

  const resend = async () => {
    setBusy(true);
    const res = await requestJson('POST', resendUrl);
    setBusy(false);
    setResult(res.ok
      ? { ok: true, text: t(c.sent!, { email }) }
      : { ok: false, text: res.status === 429 ? c.too_many! : res.status === 0 ? copy.common.network : res.data.message ?? copy.common.generic });
  };

  const logout = async () => {
    await requestJson('POST', '/logout');
    go('/login');
  };

  return (
    <AuthLayout title={c.title!}>
      <section className="auth-form auth-state" aria-labelledby="h-verify">
        <StateIcon kind="warn" />
        <div className="auth-heading">
          <h1 id="h-verify">{c.heading}</h1>
          <p className="auth-lede auth-lede--sm">{t(c.body!, { email })}</p>
        </div>
        <Notice kind="info">{c.tokens_note}</Notice>
        {result && <Notice kind={result.ok ? 'ok' : 'risk'} role={result.ok ? 'status' : 'alert'}>{result.text}</Notice>}
        <div className="auth-state-actions">
          <p className="auth-fine">{c.help}</p>
          <Button type="button" variant="secondary" busy={busy} busyLabel={c.busy} busyNote={c.busy_note} onClick={resend}>{c.send_again}</Button>
          <p className="auth-alt"><Link href="/">{c.continue}</Link></p>
          <Button type="button" variant="ghost" onClick={logout}>{c.logout}</Button>
        </div>
      </section>
    </AuthLayout>
  );
}

VerifyEmail.layout = ownLayout;
