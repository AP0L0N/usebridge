import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AppTopBar } from '../Components/Shell/AppTopBar';
import { IconArchive14, IconArchive18, IconCheck14, IconClock14, IconDash14, IconInbox24, IconMore, IconSearch20, IconSearch24, IconX } from '../Components/AppIcons';
import { Highlight } from '../Components/Highlight';
import { ConfirmSheet, MOBILE, Sheet, refocus, useDismiss, useMedia } from '../Components/Kanban/Bits';
import { StatusForm } from '../Components/Kanban/StatusBlock';
import type { Shared } from '../lib/account';
import { ago } from '../lib/dates';
import { archiveMessage, firstName, send, type ColumnKey, type PortfolioProject, type StatusSubject } from '../lib/kanban';
import { ageAria, t, type StrKey } from '../lib/strings';

type Props = { projects: PortfolioProject[]; now: string };

const COLS: [ColumnKey, StrKey][] = [
  ['backlog', 'portfolio.col.backlog'], ['todo', 'portfolio.col.todo'], ['in_progress', 'portfolio.col.in_progress'],
  ['waiting_tilen', 'portfolio.col.waiting'], ['review', 'portfolio.col.review'], ['done', 'portfolio.col.done'],
];

const isArch = (p: PortfolioProject) => p.archived || p.pmStatus === 'archived';

function heat(p: PortfolioProject): string {
  if (isArch(p)) return 'arch';
  if (p.pmStatus === 'smooth') return 'ok';
  if (p.pmStatus === 'waiting') return `w${p.pmLevel ?? 1}`;
  return 'none';
}

function readUrl() {
  const u = new URLSearchParams(window.location.search);
  return { q: u.get('q') ?? '', archived: u.get('archived') === '1' };
}

/** Icon + colour + text, always together. */
function Badge({ p }: { p: PortfolioProject }) {
  const h = heat(p);
  if (h === 'arch') return <span className="pf-badge pf-b-arch"><IconArchive14 /><span>{t('status.archived')}</span></span>;
  if (h === 'ok') return <span className="pf-badge pf-b-ok"><IconCheck14 /><span>{t('status.smooth')}</span></span>;
  if (h === 'none') return <span className="pf-badge pf-b-none"><IconDash14 /><span>{t('portfolio.status.none')}</span></span>;
  const level = p.pmLevel ?? 1;
  const days = p.pmAgeDays ?? 0;
  return (
    <span className={`pf-badge pf-b-${h}`} aria-label={ageAria(days)}>
      <IconClock14 /><span>{t('status.waiting')}</span>
      <span className="pf-pips" aria-hidden="true">{[1, 2, 3].map((i) => <i key={i} className={i <= level ? 'on' : ''} />)}</span>
      <span className="pf-mono pf-age" aria-hidden="true">{days < 1 ? t('portfolio.age.lt1') : t('status.waiting.age', { n: days })}</span>
    </span>
  );
}

function BotLine({ p, now }: { p: PortfolioProject; now: string }) {
  if (!p.pmStatus || !p.pmStatusSetAt) return <p className="pf-nostatus">{t('portfolio.noStatus')}</p>;
  const name = p.pmStatusSetBy === p.pmAgentId ? firstName(p.pmStatusSetByName) : p.pmStatusSetByName;
  const via = p.pmStatusSource ? t(`portfolio.source.${p.pmStatusSource}`) : null;
  return (
    <div className="pf-bot">
      <div className="pf-botline">
        <i className={`pf-dot${p.pmStatusSource === 'ui' ? ' human' : ''}`} aria-hidden="true" />
        <span className="pf-mono">{t('portfolio.setBy', { name: name ?? '', when: ago(p.pmStatusSetAt, now, { yesterday: true }) })}</span>
        {via && <span className="pf-mono pf-via">{t('portfolio.setBy.via', { source: via })}</span>}
      </div>
    </div>
  );
}

function Card({ p, q, now }: { p: PortfolioProject; q: string; now: string }) {
  // The project's own column names (Column labels on the board) over the defaults; Done counts the last 7 days.
  const cols = COLS.map(([k, key]) => {
    const own = p.columnLabels?.[k];
    return { k, label: own ? `${own}${k === 'done' ? ' (7 days)' : ''}` : t(key), n: p.ticketCounts?.[k] ?? 0 };
  });
  const total = cols.reduce((s, c) => s + c.n, 0);
  const archived = isArch(p);
  const waiting = p.waitingTilenCount ?? 0;
  return (
    <article className={`pf-card pf-h-${heat(p)}${archived ? ' archived' : ''}`}>
      <div className="pf-heat" aria-hidden="true" />
      <div className="pf-head">
        <Link className="pf-title" href={`/projects/${p.slug}`}>
          <h2><Highlight text={p.name} q={q} /></h2>
          {p.pmName && <p className="pf-pm"><span>{t('portfolio.pm')}</span> <Highlight text={p.pmName} q={q} /></p>}
        </Link>
        <CardMenu p={p} archived={archived} />
      </div>
      <Link className="pf-rest" href={`/projects/${p.slug}`} tabIndex={-1}>
        <div className="pf-body">
          <Badge p={p} />
          <BotLine p={p} now={now} />
          {p.pmStatus && p.pmStatusSetAt && p.pmStatusNote && <p className="pf-note">“<Highlight text={p.pmStatusNote} q={q} />”</p>}
          <div className="pf-cols">
            <div className="pf-bar" role="img" aria-label={t('portfolio.tickets.aria', { n: total })}>
              {cols.filter((c) => c.n > 0).map((c) => <i key={c.k} className={`pf-c-${c.k}`} style={{ flex: c.n }} />)}
            </div>
            <ul className="pf-legend">
              {cols.map((c) => <li key={c.k} className={c.n === 0 ? 'pf-zero' : ''}><i className={`pf-c-${c.k}`} aria-hidden="true" /><span>{c.label}</span> <b className="pf-mono">{c.n}</b></li>)}
            </ul>
          </div>
        </div>
        <div className="pf-foot">
          {waiting > 0
            ? <span className="pf-chip" aria-label={t('portfolio.forYou.aria', { n: waiting })}>{t('portfolio.forYou', { n: waiting })}</span>
            : <span className="pf-quiet">{t('portfolio.nothingWaiting')}</span>}
          <span className="pf-last">{t('portfolio.lastActivity')} <time className="pf-mono" dateTime={p.lastActivityAt ?? undefined}>{ago(p.lastActivityAt ?? p.lastUpdatedAt, now) || '—'}</time></span>
        </div>
      </Link>
    </article>
  );
}

function SkeletonCards() {
  return (
    <div className="pf-grid" role="status" aria-label={t('portfolio.loading')}>
      {Array.from({ length: 6 }, (_, i) => (
        <div className="pf-card pf-skel" key={i} aria-hidden="true">
          <div className="pf-heat" />
          <div className="pf-body">
            <i style={{ width: 216, height: 20 }} /><i style={{ width: 108, height: 12 }} /><i className="pill" style={{ width: 140, height: 28 }} />
            <div className="pf-skel-lines"><i style={{ height: 8 }} /><i style={{ height: 8, width: '78%' }} /><i style={{ height: 8 }} /><i style={{ height: 8, width: '68%' }} /></div>
          </div>
          <div className="pf-foot"><i style={{ width: 96, height: 12 }} /></div>
        </div>
      ))}
    </div>
  );
}

const subject = (p: PortfolioProject, archived: boolean): StatusSubject => ({
  name: p.name, slug: p.slug, status: p.pmStatus, status_note: p.pmStatusNote, status_set_by: p.pmStatusSetBy, status_set_by_name: p.pmStatusSetByName,
  status_set_at: p.pmStatusSetAt, pm_agent_id: p.pmAgentId, pm_name: p.pmName, archived,
});

/** The card's "⋯" menu: the same status form as the board header (+ Archive), or Unarchive on an archived card. */
function CardMenu({ p, archived }: { p: PortfolioProject; archived: boolean }) {
  const mobile = useMedia(MOBILE);
  const [open, setOpen] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState<'idle' | 'busy' | 'err'>('idle');
  const btnRef = useRef<HTMLButtonElement>(null);
  const popRef = useRef<HTMLDivElement>(null);
  const close = useCallback(() => { setOpen(false); refocus(btnRef.current); }, []);
  useDismiss([btnRef, popRef], open && !mobile, close);
  const reload = () => router.reload({ only: ['projects'], async: true } as never);
  const run = async (action: 'archive' | 'unarchive') => {
    setBusy('busy');
    try {
      await send('POST', `/projects/${p.slug}/${action}`);
      setBusy('idle'); setConfirm(false); setOpen(false); reload();
    } catch {
      setBusy('err');
    }
  };
  const sub = subject(p, archived);
  const label = t(archived ? 'portfolio.menu.archivedTitle' : 'portfolio.menu.status');
  const body = archived ? (
    <div className="sform">
      <p className="hint">{t('portfolio.menu.archivedHint', { name: p.name })}</p>
      <div className="qact">
        <button className="btn" type="button" disabled={busy === 'busy'} onClick={() => run('unarchive')}>{busy === 'busy' ? <><span className="spin" />{t('portfolio.menu.unarchiving')}</> : t('proj.unarchive')}</button>
        <button className="abtn" type="button" onClick={close}>{t('portfolio.menu.cancel')}</button>
      </div>
      {busy === 'err' && <p className="err-line" role="alert">{t('portfolio.menu.unarchiveError')}</p>}
    </div>
  ) : (
    <StatusForm project={sub} onDone={() => { close(); reload(); }} onCancel={close} onArchive={() => { setOpen(false); setConfirm(true); }} />
  );
  return (
    <div className="pf-menu">
      <button ref={btnRef} type="button" className="pf-more" aria-haspopup="dialog" aria-expanded={open} aria-label={t('portfolio.menu.aria', { name: p.name })} onClick={() => (open ? close() : setOpen(true))}><IconMore /></button>
      {open && !mobile && <div ref={popRef} className="pf-pop" role="dialog" aria-label={label}>{body}</div>}
      {open && mobile && <Sheet label={label} onClose={() => setOpen(false)} restoreFocus={() => btnRef.current}><div className="sheetb">{body}</div></Sheet>}
      {confirm && (
        <ConfirmSheet message={archiveMessage(p.name)} confirmLabel={t('portfolio.archive.confirm')} busy={busy === 'busy'} error={busy === 'err' ? t('portfolio.archive.error') : null}
          onConfirm={() => run('archive')} onCancel={() => { setConfirm(false); setBusy('idle'); }} restoreFocus={() => btnRef.current} />
      )}
    </div>
  );
}

export default function Portfolio({ projects, now }: Props) {
  const signedIn = usePage<Shared>().props.auth?.user != null;
  const [q, setQ] = useState(() => readUrl().q);
  const [showArchived, setShowArchived] = useState(() => readUrl().archived);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const u = new URL(window.location.href);
    q ? u.searchParams.set('q', q) : u.searchParams.delete('q');
    showArchived ? u.searchParams.set('archived', '1') : u.searchParams.delete('archived');
    window.history.replaceState(window.history.state, '', u);
  }, [q, showArchived]);

  // Skeleton cards while a full (non-partial) visit to the Portfolio itself is in flight.
  useEffect(() => {
    const off = [
      router.on('start', (e) => { if (!e.detail.visit.only.length && e.detail.visit.url.pathname === '/') setLoading(true); }),
      router.on('finish', () => setLoading(false)),
    ];
    return () => off.forEach((f) => f());
  }, []);

  const query = q.trim();
  const { active, archived } = useMemo(() => {
    const n = query.toLowerCase();
    const hits = n
      ? projects.filter((p) => [p.name, p.pmName, p.pmStatusNote].some((s) => (s ?? '').toLowerCase().includes(n)))
      : projects;
    return {
      // Order comes from the server (PortfolioController::order, SPEC §2).
      active: hits.filter((p) => !isArch(p)),
      // Search always includes archived; otherwise only when toggled on.
      archived: n || showArchived ? hits.filter(isArch) : [],
    };
  }, [projects, query, showArchived]);

  const live = projects.filter((p) => !isArch(p));
  const waitingProjects = live.filter((p) => p.pmStatus === 'waiting').length;
  const forYou = live.reduce((s, p) => s + (p.waitingTilenCount ?? 0), 0);
  const total = active.length + archived.length;
  const plural = (n: number, one: StrKey, many: StrKey) => t(n === 1 ? one : many);

  return (
    <div className="kanban app">
      <Head title={t('dash.title')} />
      <AppTopBar />
      <main className="pf">
        <div className="pf-header">
          <div className="pf-titleblock">
            <h1>{t('dash.title')}</h1>
            <p className="pf-summary" aria-live="polite">
              {query && total ? t('dash.results', { n: total, q: query })
                : t('portfolio.summary', { a: live.length, projects: plural(live.length, 'portfolio.summary.project', 'portfolio.summary.projects'), w: waitingProjects, n: forYou, tickets: plural(forYou, 'portfolio.summary.ticket', 'portfolio.summary.tickets') })}
            </p>
          </div>
          <div className="pf-tools">
            <label className={`pf-search${q ? ' filled' : ''}`}>
              <IconSearch20 />
              <span className="sr-only">{t('portfolio.search.label')}</span>
              <input type="text" placeholder={t('dash.search.placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
              {q && <button type="button" className="pf-clr" aria-label={t('portfolio.search.clear')} onClick={() => setQ('')}><IconX /></button>}
            </label>
            <button type="button" role="switch" aria-checked={showArchived} className={`pf-switch${showArchived ? ' on' : ''}`} onClick={() => setShowArchived((v) => !v)}>
              <span className="pf-track"><span /></span><span>{t('dash.showArchived')}</span>
            </button>
          </div>
        </div>

        {loading ? <SkeletonCards /> : projects.length === 0 ? (
          <div className="pf-empty"><div className="pf-eico"><IconInbox24 /></div><h2>{t('dash.empty.title')}</h2><p>{t('dash.empty.body')}</p>{signedIn && <Link className="pf-btn" href="/welcome">{t('portfolio.empty.cta')}</Link>}</div>
        ) : query && total === 0 ? (
          <div className="pf-empty">
            <div className="pf-eico"><IconSearch24 /></div>
            <h2>{t('dash.noresults.title', { q: query })}</h2>
            <p>{t('dash.noresults.body')}</p>
            <button type="button" className="pf-btn" onClick={() => setQ('')}>{t('dash.noresults.clear')}</button>
          </div>
        ) : (
          <>
            {active.length > 0 && <div className="pf-grid">{active.map((p) => <Card key={p.id} p={p} q={query} now={now} />)}</div>}
            {archived.length > 0 && (
              <section className="pf-archived">
                <h2 className="pf-sect"><span>{t('dash.archived.heading')}</span><span className="pf-count pf-mono">{archived.length}</span></h2>
                <div className="pf-grid">{archived.map((p) => <Card key={p.id} p={p} q={query} now={now} />)}</div>
              </section>
            )}
          </>
        )}
      </main>
    </div>
  );
}
