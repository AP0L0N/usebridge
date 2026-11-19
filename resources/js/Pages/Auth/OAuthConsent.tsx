import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { AuthLayout, ownLayout, type AuthPageProps } from '../../Components/Auth/AuthLayout';
import { Button } from '../../Components/Auth/Button';
import { Notice, StateIcon } from '../../Components/Auth/TextField';
import { t } from '../../lib/authCopy';
import { go, requestJson } from '../../lib/http';

type Props = AuthPageProps & {
  client: { name: string; dynamic: boolean; redirectUri: string } | null;
  error: string | null;
  account?: { email: string; workspace: string | null };
  emailVerified?: boolean;
  params?: Record<string, string>;
  decideUrl?: string;
};

/**
 * GET /oauth/authorize for a logged-in user (docs/MCP-OAUTH.md): an MCP client (Gemini, ...) asks to act as them.
 * Approve / Deny post the same request back (POST /oauth/authorize) and the answer says where to go: the client's
 * redirect URI. error (no client): the request cannot be trusted, so nothing is sent back to the app.
 */
export default function OAuthConsent({ client, error, account, emailVerified, params, decideUrl, copy }: Props) {
  const c = copy.oauth;
  const [busy, setBusy] = useState<'approve' | 'deny' | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  if (!client || !account || !decideUrl) {
    return (
      <AuthLayout title={c.title!}>
        <section className="auth-form auth-state" aria-labelledby="h-oauth">
          <StateIcon kind="warn" />
          <div className="auth-heading">
            <h1 id="h-oauth">{c.error_heading}</h1>
          </div>
          <Notice kind="risk" role="alert">{error ?? copy.common.generic}</Notice>
          <p className="auth-fine">{c.error_help}</p>
          <p className="auth-alt"><Link href="/">{c.continue}</Link></p>
        </section>
      </AuthLayout>
    );
  }

  const decide = async (decision: 'approve' | 'deny') => {
    setBusy(decision);
    setFailure(null);
    const res = await requestJson<{ redirect?: string }>('POST', decideUrl, { ...params, decision });
    if (res.ok && res.data.redirect) {
      go(res.data.redirect);
      return;
    }
    setBusy(null);
    setFailure(res.status === 0 ? copy.common.network : res.data.message ?? copy.common.generic);
  };

  const names = { client: client.name, email: account.email, workspace: account.workspace ?? '' };
  return (
    <AuthLayout title={c.title!}>
      <section className="auth-form auth-state" aria-labelledby="h-oauth">
        <div className="auth-heading">
          <h1 id="h-oauth">{t(c.heading!, names)}</h1>
          <p className="auth-lede auth-lede--sm">{t(c.body!, names)}</p>
        </div>
        <p className="auth-fine">{c.can}</p>
        <Notice kind="info">
          <p>{c.redirect_label}</p>
          <p><code>{client.redirectUri}</code></p>
        </Notice>
        {client.dynamic && <Notice kind="warn">{c.dynamic_note}</Notice>}
        {!emailVerified && <Notice kind="risk" role="alert">{c.unverified}</Notice>}
        {failure && <Notice kind="risk" role="alert">{failure}</Notice>}
        <div className="auth-state-actions">
          <Button type="button" busy={busy === 'approve'} busyLabel={c.busy} busyNote={c.busy_note} disabled={busy !== null || !emailVerified}
            onClick={() => decide('approve')}>{c.approve}</Button>
          <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => decide('deny')}>{c.deny}</Button>
          <p className="auth-fine">{c.revoke_note}</p>
        </div>
      </section>
    </AuthLayout>
  );
}

OAuthConsent.layout = ownLayout;
