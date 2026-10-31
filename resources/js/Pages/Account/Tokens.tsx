import { usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { SettingsLayout, VerifyEmailBanner } from '../../Components/Account/SettingsLayout';
import { Badge, Button, Card, Chip, CodeBox, CopyButton, describedBy, Dialog, Field, Input, Notice, Select, useToasts } from '../../Components/Account/redesign/Ui';
import type { ApiToken, Shared, TokenScope } from '../../lib/account';
import { daysUntil, longDate, updateTime } from '../../lib/dates';
import { fieldErrors, requestJson } from '../../lib/http';
import { t, tn, type AcctKey } from '../../lib/strings-account';

type Props = { tokens: ApiToken[]; mcpUrl: string; emailVerified: boolean; expiryDays: number[] };

/** The plaintext lives only in this component's state, from the POST response; never a prop, never logged. */
type Revealed = { token: ApiToken; plain: string };

const MASK = 'brg_live_••••';
const scopeLabel = (s: TokenScope) => t(`tok.scopeLabel.${s}` as AcctKey);
const expiryLabel = (v: string) => (v === 'never' ? t('tok.create.never') : v === '365' ? t('tok.create.inYear') : t('tok.create.inDays', { n: v }));

function Scopes({ scope }: { scope: TokenScope }) {
  return (
    <span className="acx-scopes">
      {scope === 'read' || scope === 'write' ? <Chip>{t('tok.scope.read')}</Chip> : <Chip>{t(`tok.scope.${scope}` as AcctKey)}</Chip>}
      {scope === 'write' && <Chip kind="write">{t('tok.scope.write')}</Chip>}
    </span>
  );
}

function Expires({ iso, now }: { iso: string | null; now: string }) {
  if (!iso) return <>{t('tok.never')}</>;
  const d = daysUntil(iso, now);
  if (d < 0) return <Badge tone="risk">{t('tok.expired', { date: longDate(iso) })}</Badge>;
  if (d <= 7) return <Badge tone="warn">{d === 0 ? t('tok.expiresToday') : tn('tok.expiresIn', d)}</Badge>;
  return <>{longDate(iso)}</>;
}

/** API tokens & MCP (Figma redesign): masked list, create (verified only), one-time reveal, revoke, MCP connector. */
export default function Tokens({ tokens: initial, mcpUrl, emailVerified, expiryDays }: Props) {
  const [tokens, setTokens] = useState(initial);
  const [modal, setModal] = useState<null | 'create' | 'reveal' | { revoke: ApiToken }>(null);
  const [revealed, setRevealed] = useState<Revealed | null>(null);
  const [newId, setNewId] = useState<number | null>(null);
  const { push, region } = useToasts();
  const now = new Date().toISOString();
  const email = usePage<Shared>().props.auth?.user?.email ?? '';

  // create form
  const defaultExpiry = String(expiryDays.includes(90) ? 90 : expiryDays[0] ?? 'never');
  const [name, setName] = useState('');
  const [scope, setScope] = useState<'read' | 'write'>('write');
  const [expires, setExpires] = useState<string>(defaultExpiry);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const openCreate = () => { setName(''); setScope('write'); setExpires(defaultExpiry); setErrors({}); setModal('create'); };
  const create = async (e: FormEvent) => {
    e.preventDefault();
    if (name.trim() === '') { setErrors({ name: t('tok.create.errName') }); return; }
    setBusy(true);
    const res = await requestJson<{ data: ApiToken; plain_text_token: string }>('POST', '/account/tokens', {
      name: name.trim(), scope, expires: expires === 'never' ? null : Number(expires),
    });
    setBusy(false);
    if (!res.ok) {
      setErrors(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? t('acct.generic') });
      return;
    }
    setTokens((cur) => [res.data.data, ...cur]);
    setNewId(res.data.data.id);
    setRevealed({ token: res.data.data, plain: res.data.plain_text_token });
    setModal('reveal');
  };
  // Closing the reveal drops the plaintext from state, so it leaves the DOM (JSON example included).
  const doneReveal = () => { setRevealed(null); setModal(null); };

  const revoke = async (tok: ApiToken) => {
    setBusy(true);
    const res = await requestJson('DELETE', `/account/tokens/${tok.id}`);
    setBusy(false);
    setModal(null);
    if (res.ok || res.status === 404) setTokens((cur) => cur.filter((x) => x.id !== tok.id));
    push(res.ok ? { text: t('tok.revoke.done', { name: tok.name }), sub: t('tok.revoke.doneSub') } : { text: res.data.message ?? t('tok.revoke.failed'), error: true });
  };

  const exampleToken = revealed?.plain ?? MASK;
  const json = JSON.stringify({ mcpServers: { bridge: { url: mcpUrl, headers: { Authorization: `Bearer ${exampleToken}` } } } }, null, 2);
  const createBtn = (block?: boolean) => <Button onClick={openCreate} disabled={!emailVerified} block={block}>{t('tok.create')}</Button>;

  return (
    <SettingsLayout page="tokens" title={t('acct.nav.tokens')} toasts={region}>
      {!emailVerified && <VerifyEmailBanner email={email} why={t('acct.verify.whyTokens')} onResent={(m) => push({ text: m })} />}

      <Card title={t('tok.title')} sub={t('tok.sub')} action={tokens.length > 0 ? createBtn() : undefined}>
        {tokens.length > 0 ? (
          <table className="acx-table">
            <caption className="sr-only">{t('tok.caption')}</caption>
            <thead><tr>
              <th scope="col">{t('tok.col.name')}</th><th scope="col">{t('tok.col.scopes')}</th><th scope="col">{t('tok.col.created')}</th>
              <th scope="col">{t('tok.col.lastUsed')}</th><th scope="col">{t('tok.col.expires')}</th><th scope="col"><span className="sr-only">{t('tok.col.actions')}</span></th>
            </tr></thead>
            <tbody>
              {tokens.map((tok) => (
                <tr key={tok.id}>
                  <th scope="row" className="acx-table__name">
                    <span className="acx-tokname">{tok.name}{tok.id === newId && <Chip kind="new">{t('tok.new')}</Chip>}</span>
                    <span className="acx-mono">{tok.masked ?? t('tok.legacy')}</span>
                  </th>
                  <td className="acx-table__scopes"><Scopes scope={tok.scope} /></td>
                  <td className="acx-table__created">{tok.created_at ? (daysUntil(tok.created_at, now) === 0 ? updateTime(tok.created_at, now) : longDate(tok.created_at)) : '—'}</td>
                  <td className="acx-table__used"><span className="acx-m-only">{t('tok.col.lastUsed')} </span>{tok.last_used_at ? updateTime(tok.last_used_at, now) : t('tok.never')}</td>
                  <td className="acx-table__exp">
                    {!(tok.expires_at && daysUntil(tok.expires_at, now) <= 7) && <span className="acx-m-only">{t('tok.col.expires')} </span>}
                    <Expires iso={tok.expires_at} now={now} />
                  </td>
                  <td className="acx-table__act"><Button kind="secondary" aria-label={t('tok.revokeLabel', { name: tok.name })} onClick={() => setModal({ revoke: tok })}>{t('tok.revoke')}</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="acx-empty">
            <span className="acx-empty__tile" aria-hidden="true"><span /></span>
            <h3>{t('tok.empty.title')}</h3>
            <p>{t('tok.empty.body')}</p>
            {createBtn()}
          </div>
        )}
      </Card>

      <Card eyebrow={<><span className="acx-eyebrow__dot" aria-hidden="true" />{t('mcp.eyebrow')}</>} title={t('mcp.title')} sub={t('mcp.sub')}>
        <CodeBox label={t('mcp.url')} copy={mcpUrl}>{mcpUrl}</CodeBox>
        <div className="acx-stack8">
          <CodeBox label={t('mcp.header')} copy={`Authorization: Bearer ${exampleToken}`}>Authorization: Bearer {exampleToken}</CodeBox>
          <p className="acx-hint">{t('mcp.headerHint', { mask: MASK })}</p>
        </div>
        <CodeBox label={t('mcp.json')} copy={json} multiline>{json}</CodeBox>
        <div className="acx-sub">
          <h3 className="acx-h3">{t('mcp.steps')}</h3>
          <ol className="acx-steps">
            {([1, 2, 3] as const).map((n) => (
              <li key={n} className="acx-step">
                <span className="acx-step__n" aria-hidden="true">{String(n).padStart(2, '0')}</span>
                <h4>{t(`mcp.step${n}.title` as AcctKey)}</h4>
                <p>{t(`mcp.step${n}.body` as AcctKey)}</p>
              </li>
            ))}
          </ol>
        </div>
        <div className="acx-sub">
          <h3 className="acx-h3">{t('mcp.can')}</h3>
          <div className="acx-can">
            <div className="acx-can__box">
              <h4><Chip>{t('tok.scope.read')}</Chip>{t('mcp.can.read')}</h4>
              <ul>{([1, 2, 3] as const).map((n) => <li key={n}>{t(`mcp.can.read.${n}` as AcctKey)}</li>)}</ul>
            </div>
            <div className="acx-can__box">
              <h4><Chip>{t('tok.scope.read')}</Chip><Chip kind="write">{t('tok.scope.write')}</Chip>{t('mcp.can.write')}</h4>
              <ul>{([1, 2, 3, 4] as const).map((n) => <li key={n}>{t(`mcp.can.write.${n}` as AcctKey)}</li>)}</ul>
            </div>
          </div>
        </div>
        <p className="acx-hint">{t('mcp.foot')}</p>
      </Card>

      {modal === 'create' && (
        <Dialog title={t('tok.create.title')} onClose={() => setModal(null)}
          footer={<><Button kind="secondary" onClick={() => setModal(null)}>{t('tok.create.cancel')}</Button><Button type="submit" form="tok-form" busy={busy} busyLabel={t('tok.create.busy')}>{t('tok.create')}</Button></>}>
          {errors.form && <Notice kind="risk" role="alert"><p>{errors.form}</p></Notice>}
          <form id="tok-form" className="acx-form" onSubmit={create} noValidate>
            <Field id="tok-name" label={t('tok.create.name')} error={errors.name} hint={t('tok.create.nameHint')}>
              <Input id="tok-name" type="text" placeholder={t('tok.create.namePlaceholder')} maxLength={60} value={name} data-autofocus
                onChange={(e) => setName(e.target.value)} {...describedBy('tok-name', errors.name, true)} />
            </Field>
            <fieldset className="acx-radios">
              <legend className="acx-label">{t('tok.create.access')}</legend>
              {(['read', 'write'] as const).map((v) => (
                <label key={v} className={`acx-radio${scope === v ? ' is-checked' : ''}`}>
                  <input type="radio" name="scope" value={v} checked={scope === v} onChange={() => setScope(v)} />
                  <span><b>{t(`tok.create.${v}` as AcctKey)}</b><span>{t(`tok.create.${v}Hint` as AcctKey)}</span></span>
                </label>
              ))}
              {errors.scope && <p className="acx-err">{errors.scope}</p>}
            </fieldset>
            <Field id="tok-exp" label={t('tok.create.expires')} error={errors.expires}>
              <Select id="tok-exp" value={expires} onChange={(e) => setExpires(e.target.value)} {...describedBy('tok-exp', errors.expires)}>
                {[...expiryDays.map(String), 'never'].map((v) => <option key={v} value={v}>{expiryLabel(v)}</option>)}
              </Select>
            </Field>
          </form>
        </Dialog>
      )}

      {modal === 'reveal' && revealed && (
        <Dialog title={t('tok.reveal.title')} onClose={doneReveal} closable={false}
          footer={<Button onClick={doneReveal}>{t('tok.reveal.done')}</Button>}>
          <div className="acx-secret">
            <p className="acx-secret__meta" id="newtok-l">
              <span className="acx-eyebrow__dot" aria-hidden="true" />
              {t('tok.reveal.meta', {
                name: revealed.token.name, scope: scopeLabel(revealed.token.scope),
                expiry: revealed.token.expires_at ? t('tok.reveal.expires', { date: longDate(revealed.token.expires_at) }) : t('tok.reveal.noExpiry'),
              })}
            </p>
            <div className="acx-secret__value">
              <output aria-labelledby="newtok-l">{revealed.plain}</output>
              <CopyButton text={revealed.plain} what={t('tok.reveal.copy').toLowerCase()} kind="signal" autoFocus />
            </div>
          </div>
          <Notice kind="warn"><p>{t('tok.reveal.warn')}</p></Notice>
        </Dialog>
      )}

      {modal && typeof modal === 'object' && 'revoke' in modal && (
        <Dialog title={t('tok.revoke.title', { name: modal.revoke.name })} onClose={() => setModal(null)} alert describedBy="d-revoke"
          footer={<><Button kind="secondary" onClick={() => setModal(null)} data-autofocus>{t('tok.revoke.keep')}</Button><Button kind="danger" onClick={() => revoke(modal.revoke)} busy={busy} busyLabel={t('tok.revoke.busy')}>{t('tok.revoke.confirm')}</Button></>}>
          <p id="d-revoke">{t('tok.revoke.body')}</p>
        </Dialog>
      )}
    </SettingsLayout>
  );
}
