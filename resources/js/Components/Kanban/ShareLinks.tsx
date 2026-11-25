import { useCallback, useEffect, useState } from 'react';
import { MarkdownBody } from '../MarkdownBody';
import { Avatar, useToasts } from './Bits';
import { updateTime } from '../../lib/dates';
import { ApiError, send, type Comment } from '../../lib/kanban';
import { DEFAULT_DAYS, EXPIRY_CHOICES, MAX_DAYS, expiryBody, expiryChoiceLabel, expiryLabel, type ExpiryChoice, type ShareLink } from '../../lib/share';
import { t } from '../../lib/strings-share';
import { t as tr } from '../../lib/strings';

/** The card's "shared publicly" mark (14px, explicit size like the shared icons; .svg-ic keeps it from shrinking). */
export const IconShared14 = () => (
  <svg className="svg-ic" width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
    <circle cx="7" cy="7" r="5.6" fill="none" stroke="currentColor" strokeWidth="1.3" />
    <path d="M1.6 7h10.8M7 1.4c1.6 1.5 2.4 3.4 2.4 5.6S8.6 11.1 7 12.6M7 1.4C5.4 2.9 4.6 4.8 4.6 7s.8 4.1 2.4 5.6" fill="none" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" />
  </svg>
);

const base = (slug: string, key: string) => `/projects/${slug}/tickets/${key}/share-links`;

/** Days select + the custom days input (1–365). */
function ExpiryPicker({ id, choice, custom, onChoice, onCustom }: {
  id: string; choice: ExpiryChoice; custom: string; onChoice: (c: ExpiryChoice) => void; onCustom: (v: string) => void;
}) {
  return (
    <div className="shl-exp">
      <label className="sr-only" htmlFor={`${id}-sel`}>{t('side.expiry')}</label>
      <select id={`${id}-sel`} className="shl-sel" value={choice} onChange={(e) => onChoice(e.target.value as ExpiryChoice)}>
        {EXPIRY_CHOICES.map((c) => <option key={c} value={c}>{expiryChoiceLabel(c)}</option>)}
      </select>
      {choice === 'custom' && (
        <>
          <label className="sr-only" htmlFor={`${id}-days`}>{t('side.expiry.days')}</label>
          <input id={`${id}-days`} className="shl-days" type="number" inputMode="numeric" min={1} max={MAX_DAYS} value={custom}
            placeholder={t('side.expiry.days')} onChange={(e) => onCustom(e.target.value)} />
        </>
      )}
    </div>
  );
}

/**
 * Sidebar "Public links" (above History): the ticket's share links with URL, expiry and comments setting, plus Copy,
 * Edit expiry and Revoke, and "Share publicly" to create one (7 days by default). Shown to the workspace owner only
 * (detail.can_share); the server checks it again on every call (Gate 'share-ticket').
 */
export function ShareLinksField({ slug, ticketKey, idPrefix, now, onChanged }: { slug: string; ticketKey: string; idPrefix: string; now: string; onChanged: () => void }) {
  const toasts = useToasts();
  const [links, setLinks] = useState<ShareLink[] | null>(null);
  const [loadErr, setLoadErr] = useState(false);
  const [creating, setCreating] = useState(false);
  const [choice, setChoice] = useState<ExpiryChoice>(String(DEFAULT_DAYS) as ExpiryChoice);
  const [custom, setCustom] = useState('');
  const [comments, setComments] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await fetch(base(slug, ticketKey), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      if (!res.ok) throw new Error(String(res.status));
      setLinks(((await res.json()) as { data: ShareLink[] }).data);
      setLoadErr(false);
    } catch {
      setLoadErr(true);
    }
  }, [slug, ticketKey]);
  useEffect(() => { load(); }, [load]);

  const fail = (e: unknown) => toasts.push({ tone: 'err', body: <p>{t('side.err', { message: e instanceof ApiError ? e.message : '' })}</p> });
  const replace = (link: ShareLink) => setLinks((ls) => (ls ?? []).map((l) => (l.id === link.id ? link : l)));

  const create = async () => {
    const expiry = expiryBody(choice, custom);
    if ('error' in expiry) { setErr(expiry.error); return; }
    setBusy(true);
    try {
      const res = await send<{ data: ShareLink }>('POST', base(slug, ticketKey), { ...expiry, allow_comments: comments });
      setLinks((ls) => [...(ls ?? []), res.data]);
      setCreating(false); setErr(null); setComments(false); setChoice(String(DEFAULT_DAYS) as ExpiryChoice); setCustom('');
      onChanged();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fld shl">
      <p className="k">{t('side.title')}</p>
      {links === null
        ? <p className="sm" role="status">{loadErr ? t('side.loadErr') : t('side.loading')}</p>
        : links.length === 0
          ? <p className="sm">{t('side.empty')}</p>
          : <ul className="shl-list">{links.map((l) => (
            <ShareLinkRow key={l.id} link={l} slug={slug} ticketKey={ticketKey} idPrefix={`${idPrefix}-${l.id}`} now={now}
              onSaved={replace} onRevoked={() => { setLinks((ls) => (ls ?? []).filter((x) => x.id !== l.id)); onChanged(); }} onError={fail} />
          ))}</ul>}
      {creating ? (
        <div className="shl-new">
          <ExpiryPicker id={`${idPrefix}-new`} choice={choice} custom={custom} onChoice={(c) => { setChoice(c); setErr(null); }} onCustom={(v) => { setCustom(v); setErr(null); }} />
          <label className="chkl"><input type="checkbox" checked={comments} onChange={(e) => setComments(e.target.checked)} />{t('side.comments')}</label>
          {err && <p className="err-line" role="alert">{err}</p>}
          <div className="shl-acts">
            <button className="btn" type="button" disabled={busy} onClick={create}>{t('side.create')}</button>
            <button className="gbtn" type="button" onClick={() => { setCreating(false); setErr(null); }}>{t('side.cancel')}</button>
          </div>
        </div>
      ) : links !== null && (
        <button className="abtn shl-share" type="button" onClick={() => setCreating(true)}>{t('side.share')}</button>
      )}
    </div>
  );
}

function ShareLinkRow({ link, slug, ticketKey, idPrefix, now, onSaved, onRevoked, onError }: {
  link: ShareLink; slug: string; ticketKey: string; idPrefix: string; now: string;
  onSaved: (l: ShareLink) => void; onRevoked: () => void; onError: (e: unknown) => void;
}) {
  const toasts = useToasts();
  const [editing, setEditing] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [choice, setChoice] = useState<ExpiryChoice>(String(DEFAULT_DAYS) as ExpiryChoice);
  const [custom, setCustom] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const url = `${base(slug, ticketKey)}/${link.id}`;

  const patch = async (body: Record<string, unknown>) => {
    try {
      onSaved((await send<{ data: ShareLink }>('PATCH', url, body)).data);
      return true;
    } catch (e) {
      onError(e);
      return false;
    }
  };
  const saveExpiry = async () => {
    const expiry = expiryBody(choice, custom);
    if ('error' in expiry) { setErr(expiry.error); return; }
    if (await patch(expiry)) { setEditing(false); setErr(null); }
  };
  const copy = async () => {
    try { await navigator.clipboard.writeText(link.url); toasts.push({ body: <p>{t('side.copied')}</p>, ms: 3000 }); }
    catch { toasts.push({ body: <p>{t('side.copyFallback', { url: link.url })}</p> }); }
  };
  const revoke = async () => {
    try { await send('DELETE', url); onRevoked(); toasts.push({ body: <p>{t('side.revoked')}</p>, ms: 3000 }); }
    catch (e) { onError(e); }
  };

  return (
    <li className={`shl-row${link.active ? '' : ' off'}`}>
      <code className="shl-url" title={link.url}>{link.url}</code>
      <p className="sm">{expiryLabel(link.expires_at, now)}</p>
      <label className="chkl"><input type="checkbox" checked={link.allow_comments} onChange={(e) => patch({ allow_comments: e.target.checked })} />{t('side.comments')}</label>
      {editing && (
        <div className="shl-new">
          <ExpiryPicker id={`${idPrefix}-ed`} choice={choice} custom={custom} onChoice={(c) => { setChoice(c); setErr(null); }} onCustom={(v) => { setCustom(v); setErr(null); }} />
          {err && <p className="err-line" role="alert">{err}</p>}
        </div>
      )}
      {confirm && <p className="sm2" role="alert">{t('side.revokeAsk')}</p>}
      <div className="shl-acts">
        {confirm ? (
          <>
            <button className="abtn dng" type="button" onClick={revoke}>{t('side.revoke')}</button>
            <button className="gbtn" type="button" onClick={() => setConfirm(false)}>{t('side.cancel')}</button>
          </>
        ) : editing ? (
          <>
            <button className="btn" type="button" onClick={saveExpiry}>{t('side.save')}</button>
            <button className="gbtn" type="button" onClick={() => { setEditing(false); setErr(null); }}>{t('side.cancel')}</button>
          </>
        ) : (
          <>
            <button className="gbtn" type="button" disabled={!link.active} onClick={copy}>{t('side.copy')}</button>
            <button className="gbtn" type="button" onClick={() => setEditing(true)}>{t('side.editExpiry')}</button>
            <button className="gbtn dng" type="button" onClick={() => setConfirm(true)}>{t('side.revoke')}</button>
          </>
        )}
      </div>
    </li>
  );
}

/** A visitor's comment from a public link in the ticket thread: labelled "Public" with the name they typed. Never input. */
export function PublicCommentItem({ c, now }: { c: Comment; now: string }) {
  return (
    <li className="cm pub">
      <Avatar id={c.author_id} name={c.author_name} type="human" lg />
      <div className="bub">
        <header>
          <b>{c.author_name ?? t('pub.visitor')}</b>
          <span className="role pubtag" title={t('thread.publicTip')}>{t('thread.public')}</span>
          <time dateTime={c.created_at ?? undefined}>{updateTime(c.created_at, now)}</time>
        </header>
        {c.retracted ? <p className="hint">{tr('ticket.removed')}</p> : c.body && <MarkdownBody content={c.body} />}
      </div>
    </li>
  );
}
