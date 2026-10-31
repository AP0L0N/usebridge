import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { AuthShell } from '../../Components/Account/AuthShell';
import { BusyButton, Callout } from '../../Components/Account/Fields';
import { go, requestJson } from '../../lib/http';

type Props = { email: string; resendUrl: string };

/**
 * GET /email/verify for a logged-in, unverified user (after login): resend the link (POST, 6 per minute) or go on to the
 * boards, which work without verifying; API tokens and MCP need the verified email.
 */
export default function VerifyEmail({ email, resendUrl }: Props) {
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);

  const resend = async () => {
    setBusy(true);
    const res = await requestJson('POST', resendUrl);
    setBusy(false);
    setResult(res.ok
      ? { ok: true, text: `A new link is on its way to ${email}. It works for 60 minutes.` }
      : { ok: false, text: res.status === 429 ? 'Too many requests. Wait a minute and try again.' : res.data.message ?? 'Something went wrong. Try again.' });
  };

  const logout = async () => {
    await requestJson('POST', '/logout');
    go('/login');
  };

  return (
    <AuthShell title="Verify your email">
      <section className="gcard" aria-labelledby="h-verify">
        <h1 id="h-verify">Verify your email</h1>
        <p className="lede">We sent a link to <b>{email}</b>. Follow it to verify your address; you need that before you create API tokens for bots or use MCP.</p>
        {result && <Callout kind={result.ok ? 'ok' : 'bad'} alert={!result.ok} status={result.ok}>{result.text}</Callout>}
        <BusyButton busy={busy} busyLabel="Sending…" type="button" onClick={resend}>Send the link again</BusyButton>
        <p className="fine">No email after a few minutes? Check spam first.</p>
        <p className="alt"><Link href="/">Continue to Bridge</Link> · <button type="button" className="lnk" onClick={logout}>Log out</button></p>
      </section>
    </AuthShell>
  );
}
