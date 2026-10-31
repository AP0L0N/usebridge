import { usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Callout, described, Field } from '../../Components/Account/Fields';
import { CopyButton, Modal, useToasts } from '../../Components/Account/Overlay';
import { SettingsLayout, VerifyEmailBanner } from '../../Components/Account/SettingsLayout';
import { SCOPE_LABEL, type ApiToken, type Shared } from '../../lib/account';
import { daysUntil, longDate, updateTime } from '../../lib/dates';
import { fieldErrors, requestJson } from '../../lib/http';

type Props = { tokens: ApiToken[]; mcpUrl: string; emailVerified: boolean; expiryDays: number[] };

/** The plaintext lives only in this component's state, from the POST response; it is never a prop. */
type Revealed = { token: ApiToken; plain: string };

const EXPIRY_LABEL: Record<string, string> = { 30: 'In 30 days', 90: 'In 90 days', 365: 'In 1 year', never: 'Never' };

function Scopes({ t }: { t: ApiToken }) {
  if (t.scope === 'read') return <span className="scopes"><span className="kb rd">read</span></span>;
  if (t.scope === 'write') return <span className="scopes"><span className="kb rd">read</span><span className="kb rw">write</span></span>;
  return <span className="scopes"><span className="kb rd">{SCOPE_LABEL[t.scope]}</span></span>;
}

function Expires({ iso, now }: { iso: string | null; now: string }) {
  if (!iso) return <span className="never">Never</span>;
  const d = daysUntil(iso, now);
  if (d < 0) return <span className="role off">Expired {longDate(iso)}</span>;
  if (d <= 7) return <span className="pend">{d === 0 ? 'Expires today' : `Expires in ${d} ${d === 1 ? 'day' : 'days'}`}</span>;
  return <>{longDate(iso)}</>;
}

/** API tokens & MCP (prototype tokens.html): masked list, create (verified only), one-time reveal, revoke, MCP snippet. */
export default function Tokens({ tokens: initial, mcpUrl, emailVerified, expiryDays }: Props) {
  const [tokens, setTokens] = useState(initial);
  const [modal, setModal] = useState<null | 'create' | 'reveal' | { revoke: ApiToken }>(null);
  const [revealed, setRevealed] = useState<Revealed | null>(null);
  const [newId, setNewId] = useState<number | null>(null);
  const { push, region } = useToasts();
  const now = new Date().toISOString();

  // create form
  const [name, setName] = useState('');
  const [scope, setScope] = useState<'read' | 'write'>('write');
  const [expires, setExpires] = useState<string>(String(expiryDays.includes(90) ? 90 : expiryDays[0] ?? 'never'));
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const email = usePage<Shared>().props.auth?.user?.email ?? 'your email address';

  const openCreate = () => { setName(''); setScope('write'); setErrors({}); setModal('create'); };
  const create = async (e: FormEvent) => {
    e.preventDefault();
    if (name.trim() === '') { setErrors({ name: 'Give the token a name' }); return; }
    setBusy(true);
    const res = await requestJson<{ data: ApiToken; plain_text_token: string; verified?: boolean }>('POST', '/account/tokens', {
      name: name.trim(), scope, expires: expires === 'never' ? null : Number(expires),
    });
    setBusy(false);
    if (!res.ok) {
      setErrors(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? 'Something went wrong.' });
      return;
    }
    setTokens((cur) => [res.data.data, ...cur]);
    setNewId(res.data.data.id);
    setRevealed({ token: res.data.data, plain: res.data.plain_text_token });
    setModal('reveal');
  };
  const doneReveal = () => { setRevealed(null); setModal(null); };

  const revoke = async (t: ApiToken) => {
    setBusy(true);
    const res = await requestJson('DELETE', `/account/tokens/${t.id}`);
    setBusy(false);
    setModal(null);
    if (res.ok || res.status === 404) setTokens((cur) => cur.filter((x) => x.id !== t.id));
    push(res.ok ? { text: `Revoked “${t.name}”`, sub: 'Bots using it stop working now.' } : { text: res.data.message ?? 'Could not revoke the token.', error: true });
  };

  const exampleToken = revealed?.plain ?? 'brg_live_••••';
  const json = JSON.stringify({ mcpServers: { bridge: { url: mcpUrl, headers: { Authorization: `Bearer ${exampleToken}` } } } }, null, 2);

  return (
    <SettingsLayout page="tokens" title="API tokens & MCP" toasts={region}>
      {!emailVerified && <VerifyEmailBanner email={email} why="Tokens and MCP need a verified email." onResent={(m) => push({ text: m })} />}

      <section className="card" aria-labelledby="h-tokens">
        <div className="chead">
          <h2 id="h-tokens">API tokens</h2>
          {tokens.length > 0 && <div className="right"><button type="button" className="btn" onClick={openCreate} disabled={!emailVerified}>Create token</button></div>}
        </div>
        <p className="sub">Tokens let your Grok bot read and update your Bridge boards. Each token only sees your projects.</p>
        {tokens.length > 0 ? (
          <div className="tscroll">
            <table className="tbl stack">
              <caption className="sr-only">Your API tokens</caption>
              <thead><tr>
                <th scope="col">Name</th><th scope="col">Scopes</th><th scope="col">Created</th><th scope="col">Last used</th><th scope="col">Expires</th><th scope="col"><span className="sr-only">Actions</span></th>
              </tr></thead>
              <tbody>
                {tokens.map((t) => (
                  <tr key={t.id}>
                    <th scope="row">
                      <div className="tokname">
                        <span className="nm">{t.name}{t.id === newId && <span className="kb rw">New</span>}</span>
                        <span className="secret">{t.masked ?? 'legacy token'}</span>
                      </div>
                    </th>
                    <td data-label="Scopes"><Scopes t={t} /></td>
                    <td data-label="Created">{t.created_at ? (daysUntil(t.created_at, now) === 0 ? updateTime(t.created_at, now) : longDate(t.created_at)) : '—'}</td>
                    <td data-label="Last used">{t.last_used_at ? updateTime(t.last_used_at, now) : <span className="never">Never</span>}</td>
                    <td data-label="Expires"><Expires iso={t.expires_at} now={now} /></td>
                    <td className="act"><button type="button" className="abtn sm bad" aria-label={`Revoke token ${t.name}`} onClick={() => setModal({ revoke: t })}>Revoke</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="empty" style={{ margin: '8px 0 0', padding: '48px 24px' }}>
            <div className="eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4" /><path d="M11 12l9-9M17 6l3 3M15 8l2 2" /></svg></div>
            <h2>No tokens yet</h2>
            <p>Create a token to connect your Grok bot. You'll see it once, then only its last four characters.</p>
            <button type="button" className="btn" onClick={openCreate} disabled={!emailVerified}>Create token</button>
          </div>
        )}
      </section>

      <section className="card" aria-labelledby="h-mcp">
        <h2 id="h-mcp">Connect your Grok bot</h2>
        <p className="sub">Bridge speaks MCP (streamable HTTP). Point a connector at this server and send your token as a header.</p>
        <div className="codes">
          <div className="code one">
            <div className="ch"><span id="l-url">Server URL</span><CopyButton text={mcpUrl} label="Copy server URL" /></div>
            <pre aria-labelledby="l-url">{mcpUrl}</pre>
          </div>
          <div className="code one">
            <div className="ch"><span id="l-hdr">Auth header</span><CopyButton text={`Authorization: Bearer ${exampleToken}`} label="Copy auth header" /></div>
            <pre aria-labelledby="l-hdr">Authorization: Bearer {exampleToken}</pre>
          </div>
          <p className="fhint">Use your own token in place of <span className="secret">brg_live_••••</span>.</p>
          <div className="code">
            <div className="ch"><span id="l-json">JSON connector config (Cursor <code>~/.cursor/mcp.json</code>, Claude Code <code>.mcp.json</code>)</span><CopyButton text={json} label="Copy JSON connector config" /></div>
            <pre aria-labelledby="l-json" tabIndex={0}>{json}</pre>
          </div>
        </div>
        <h3 className="sh">Three steps</h3>
        <ol className="steps">
          <li><h3>Create a token</h3><p>Pick read &amp; write if the bot should move tickets or post updates.</p>{emailVerified && <button type="button" className="lnk" style={{ padding: 0 }} onClick={openCreate}>Create token</button>}</li>
          <li><h3>Add an MCP connector</h3><p>Paste the server URL and the auth header with your token, then save the connector.</p></li>
          <li><h3>Ask your bot “list my Bridge boards”</h3><p>It should answer with your projects, and only yours.</p></li>
        </ol>
        <h3 className="sh">What your bot can do</h3>
        <div className="can">
          <div>
            <h3><span className="kb rd">read</span> Read only</h3>
            <ul><li>List your projects and boards</li><li>Read tickets, columns and comments</li><li>List agents and labels</li></ul>
          </div>
          <div>
            <h3><span className="kb rd">read</span><span className="kb rw">write</span> Read &amp; write</h3>
            <ul><li>Everything in read only, plus:</li><li>Create, update and move tickets</li><li>Add comments</li><li>Post status updates and set project status</li></ul>
          </div>
        </div>
        <Callout kind="info" style={{ marginTop: 16 }}>Bots never see other people's projects, and can't change your password, tokens or account. Each token is limited to 120 requests a minute.</Callout>
      </section>

      {modal === 'create' && (
        <Modal title="Create token" onClose={() => setModal(null)}
          footer={<><button type="button" className="abtn" onClick={() => setModal(null)}>Cancel</button><button type="submit" form="tok-form" className="btn" disabled={busy}>{busy ? <><span className="spin" aria-hidden="true" />Creating…</> : 'Create token'}</button></>}>
          {errors.form && <Callout kind="bad" alert>{errors.form}</Callout>}
          <form id="tok-form" onSubmit={create} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <Field id="tok-name" label="Name" error={errors.name} hint="So you can tell tokens apart later.">
              <input className="qin" id="tok-name" type="text" placeholder="e.g. Grok bot (Ava)" maxLength={60} value={name} data-autofocus
                onChange={(e) => setName(e.target.value)} {...described('tok-name', errors.name, true)} />
            </Field>
            <fieldset className="opts2">
              <legend>Access</legend>
              <label className="ropt"><input type="radio" name="scope" value="read" checked={scope === 'read'} onChange={() => setScope('read')} /><span><b>Read only</b><span>Lists boards, tickets and statuses. Can't change anything.</span></span></label>
              <label className="ropt"><input type="radio" name="scope" value="write" checked={scope === 'write'} onChange={() => setScope('write')} /><span><b>Read &amp; write</b><span>Also creates and moves tickets, adds comments and posts status updates.</span></span></label>
            </fieldset>
            <Field id="tok-exp" label="Expires" error={errors.expires}>
              <select className="qsel" id="tok-exp" value={expires} onChange={(e) => setExpires(e.target.value)}>
                {[...expiryDays.map(String), 'never'].map((v) => <option key={v} value={v}>{EXPIRY_LABEL[v] ?? `In ${v} days`}</option>)}
              </select>
            </Field>
          </form>
        </Modal>
      )}

      {modal === 'reveal' && revealed && (
        <Modal title="Token created" onClose={doneReveal} closable={false}
          footer={<button type="button" className="btn" onClick={doneReveal}>Done, I've saved it</button>}>
          <div className="reveal">
            <p className="flabel" id="newtok-l" style={{ margin: 0 }}>
              {revealed.token.name} · {SCOPE_LABEL[revealed.token.scope]} · {revealed.token.expires_at ? `expires ${longDate(revealed.token.expires_at)}` : 'no expiry'}
            </p>
            <div className="tokrow">
              <output aria-labelledby="newtok-l">{revealed.plain}</output>
              <CopyButton text={revealed.plain} label="Copy token" className="btn" autoFocus />
            </div>
          </div>
          <Callout kind="warn"><b>You won't see this again.</b> Copy it now and paste it into your bot's connector. If you lose it, revoke it and create a new one.</Callout>
        </Modal>
      )}

      {modal && typeof modal === 'object' && 'revoke' in modal && (
        <Modal title={`Revoke “${modal.revoke.name}”?`} onClose={() => setModal(null)} alert describedBy="d-revoke"
          footer={<><button type="button" className="abtn" onClick={() => setModal(null)} data-autofocus>Keep token</button><button type="button" className="btn danger" onClick={() => revoke(modal.revoke)} disabled={busy}>{busy ? 'Revoking…' : 'Revoke token'}</button></>}>
          <p id="d-revoke">Bots using this token stop working immediately. This can't be undone.</p>
        </Modal>
      )}
    </SettingsLayout>
  );
}
