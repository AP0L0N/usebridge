import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import type { Components } from 'react-markdown';
import ReactMarkdown from 'react-markdown';
import { Avatar } from '../../Components/Kanban/Bits';
import { fillPlaceholders } from '../../Components/SiteFooter';
import { IconOk } from '../../Components/Icons';
import { ApiError, send } from '../../lib/kanban';
import { MARKDOWN_OPTIONS, isSafeHref, prepareMarkdown } from '../../lib/markdown';
import { isExternalHref } from '../../lib/share';
import { t } from '../../lib/strings-share';

type PublicComment = { id: number; author: string; visitor: boolean; body: string; created_at: string | null };

type Props = {
  share: {
    project: string;
    workstream: string | null;
    column: string;
    title: string;
    description: string | null;
    comments: PublicComment[];
    comments_enabled: boolean;
  };
  /** The comment form's "opened at" stamp; null while visitor comments are off. */
  stamp: string | null;
  limits: { name: number; body: number };
  /** The footer: the © line (":link" = the AGPL source link) and nothing else. */
  legal: { copyright: string; label: string; href: string };
};

/**
 * Markdown on the public page: the app's safe pipeline (no raw HTML, no images), and only links to other sites stay
 * links (new tab, no referrer, nofollow). Links into the app and relative ones read as plain text.
 */
const components: Components = {
  a({ href, children }) {
    if (!isSafeHref(href) || !isExternalHref(href, window.location.origin)) return <>{children}</>;
    return <a href={href} target="_blank" rel="noopener noreferrer nofollow">{children}</a>;
  },
};

function PublicMarkdown({ content }: { content: string }) {
  if (!content.trim()) return null;
  return <div className="md"><ReactMarkdown {...MARKDOWN_OPTIONS} components={components}>{prepareMarkdown(content)}</ReactMarkdown></div>;
}

/** Dates in the visitor's own locale and timezone (no app clock on this page). */
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');

/**
 * The public, read-only ticket behind a share link (/s/{token}): project, workstream, column, title, description and
 * comments, nothing else. No app shell or nav, and no links into the app: the footer carries only the AGPL source link. Visitors may comment when the owner
 * turned it on for this link (name + comment; never owner input).
 */
export default function SharedTicket({ share, stamp, limits, legal }: Props) {
  return (
    <div className="kanban app pubshare">
      <Head title={share.title} />
      <main className="pubshare-main">
        <header className="pubshare-head">
          <p className="pubshare-crumb">{share.project}{share.workstream ? <> · {share.workstream}</> : null}</p>
          <span className="role pubshare-col">{share.column}</span>
          <h1>{share.title}</h1>
        </header>
        <section className="dsec" aria-labelledby="pub-desc">
          <h2 className="kb-sect" id="pub-desc">{t('pub.description')}</h2>
          {share.description?.trim() ? <PublicMarkdown content={share.description} /> : <p className="hint">{t('pub.noDescription')}</p>}
        </section>
        <hr className="kb-div" />
        <section aria-labelledby="pub-cm">
          <h2 className="kb-sect" id="pub-cm">{t('pub.comments')} <span className="cnt">{share.comments.length}</span></h2>
          {share.comments.length === 0 ? <p className="hint">{t('pub.noComments')}</p> : (
            <ol className="thread">
              {share.comments.map((c) => (
                <li key={c.id} className={`cm${c.visitor ? ' pub' : ''}`}>
                  <Avatar id={String(c.id)} name={c.author} type="human" lg />
                  <div className="bub">
                    <header>
                      <b>{c.author}</b>
                      {c.visitor && <span className="role pubtag">{t('pub.visitor')}</span>}
                      <time dateTime={c.created_at ?? undefined}>{when(c.created_at)}</time>
                    </header>
                    <PublicMarkdown content={c.body} />
                  </div>
                </li>
              ))}
            </ol>
          )}
          {share.comments_enabled && stamp && <CommentForm stamp={stamp} limits={limits} />}
        </section>
      </main>
      <footer className="pubshare-foot">
        <p>{fillPlaceholders(legal.copyright, { link: <a href={legal.href} rel="noopener">{legal.label}</a> })}</p>
      </footer>
    </div>
  );
}

/** Name + comment, with a honeypot field (`website`, hidden from people) and the form's opened-at stamp. */
function CommentForm({ stamp, limits }: { stamp: string; limits: Props['limits'] }) {
  const [name, setName] = useState('');
  const [body, setBody] = useState('');
  const [website, setWebsite] = useState('');
  const [state, setState] = useState<'idle' | 'busy' | 'ok'>('idle');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const submit = async () => {
    if (state === 'busy' || !name.trim() || !body.trim()) return;
    setState('busy'); setErrors({});
    try {
      await send('POST', `${window.location.pathname.replace(/\/$/, '')}/comments`, { name, body, website, stamp });
      setBody(''); setState('ok');
      router.reload({ only: ['share', 'stamp'] });
    } catch (e) {
      const fields = e instanceof ApiError ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, Array.isArray(v) ? String(v[0]) : String(v)])) : {};
      setErrors(Object.keys(fields).length ? fields : { form: e instanceof ApiError && e.status === 429 ? t('pub.form.limit') : t('pub.form.err') });
      setState('idle'); // the text is never cleared on error
    }
  };
  const fieldErr = (k: string): ReactNode => (errors[k] ? <p className="err-line" role="alert">{errors[k]}</p> : null);
  return (
    <form className="compose pubshare-form" onSubmit={(e) => { e.preventDefault(); submit(); }} noValidate>
      <h3 className="kb-sect">{t('pub.form.title')}</h3>
      <label className="pubshare-lbl" htmlFor="pub-name">{t('pub.form.name')}</label>
      <input id="pub-name" className="qta" type="text" autoComplete="name" maxLength={limits.name} required value={name} onChange={(e) => setName(e.target.value)} />
      {fieldErr('name')}
      <label className="pubshare-lbl" htmlFor="pub-body">{t('pub.form.body')}</label>
      <textarea id="pub-body" className="qta" rows={4} maxLength={limits.body} required value={body} onChange={(e) => setBody(e.target.value)} />
      {fieldErr('body')}
      {/* Honeypot: people never see or fill it; a filled one is dropped on the server. */}
      <div className="pubshare-hp" aria-hidden="true">
        <label htmlFor="pub-website">Website</label>
        <input id="pub-website" type="text" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
      </div>
      <p className="hint">{t('pub.form.public')}</p>
      <div className="row">
        <button className="btn" type="submit" disabled={state === 'busy' || !name.trim() || !body.trim()}>
          {state === 'busy' ? <><span className="spin" />{t('pub.form.sending')}</> : t('pub.form.send')}
        </button>
      </div>
      {state === 'ok' && <p className="ok-line" role="status"><IconOk />{t('pub.form.sent')}</p>}
      {fieldErr('form')}
      {fieldErr('stamp')}
    </form>
  );
}

/** No site layout: the public page carries no app navigation or legal links, only its own source-link footer. */
SharedTicket.layout = (page: ReactNode) => page;
