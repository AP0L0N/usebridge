import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { TopBar } from '../Components/TopBar';
import { Highlight } from '../Components/Highlight';
import { IconInbox, IconSearch } from '../Components/Icons';
import { ConfirmSheet, IcMore, IcWait, MOBILE, PmBadge, Sheet, refocus, useDismiss, useMedia } from '../Components/Kanban/Bits';
import { StatusForm } from '../Components/Kanban/StatusBlock';
import type { Shared } from '../lib/account';
import { updateTime } from '../lib/dates';
import { archiveMessage, firstName, send, type ColumnKey, type PortfolioProject, type StatusSubject } from '../lib/kanban';

type Props = { projects: PortfolioProject[]; now: string };

const COLS: [ColumnKey, string][] = [
  ['backlog', 'Backlog'], ['todo', 'To do'], ['in_progress', 'In progress'], ['waiting_tilen', 'Waiting on Tilen'], ['review', 'Review / QA'], ['done', 'Done (7 days)'],
];

function rule(p: PortfolioProject): string {
  if (p.archived || p.pmStatus === 'archived') return 's-arch';
  if (p.pmStatus === 'smooth') return 's-ok';
  if (p.pmStatus === 'waiting') return `s-w${p.pmLevel ?? 1}`;
  return 's-none';
}

function readUrl() {
  const u = new URLSearchParams(window.location.search);
  return { q: u.get('q') ?? '', archived: u.get('archived') === '1' };
}

function Card({ p, q, now }: { p: PortfolioProject; q: string; now: string }) {
  const counts = COLS.map(([k, label]) => ({ k, label, n: p.ticketCounts?.[k] ?? 0 })).filter((c) => c.n > 0);
  const total = counts.reduce((s, c) => s + c.n, 0);
  const archived = p.archived || p.pmStatus === 'archived';
  return (
    <div className="pcw">
    <Link className={`pc ${rule(p)}`} href={`/projects/${p.slug}`}>
      <div className="pcb">
        <div className="pctop">
          <div>
            <h2><Highlight text={p.name} q={q} /></h2>
            {p.pmName && <p className="pm">PM <Highlight text={p.pmName} q={q} /></p>}
          </div>
          <PmBadge status={archived ? 'archived' : p.pmStatus} level={p.pmLevel} ageDays={p.pmAgeDays} />
        </div>
        {p.pmStatus && p.pmStatusSetAt ? (
          <>
            <p className="sset">Set by <b>{p.pmStatusSetBy === p.pmAgentId ? firstName(p.pmStatusSetByName) : p.pmStatusSetByName}</b> · <time dateTime={p.pmStatusSetAt}>{updateTime(p.pmStatusSetAt, now)}</time></p>
            {p.pmStatusNote && <p className="snote"><Highlight text={p.pmStatusNote} q={q} /></p>}
          </>
        ) : (
          <p className="sset">The PM hasn’t set a status yet</p>
        )}
        <div className="cbar" role="img" aria-label={`${total} tickets by column`}>
          {counts.map((c) => <i key={c.k} className={`c-${c.k}`} style={{ flex: c.n }} />)}
        </div>
        {counts.length > 0 && (
          <ul className="ccount">
            {counts.map((c) => <li key={c.k}><i className={`c-${c.k}`} aria-hidden="true" />{c.label} <b>{c.n}</b></li>)}
          </ul>
        )}
      </div>
      <div className="pcf">
        {p.waitingTilenCount > 0
          ? <span className="wchip" aria-label={`${p.waitingTilenCount} ${p.waitingTilenCount === 1 ? 'ticket' : 'tickets'} waiting on you`}><IcWait />{p.waitingTilenCount} for you</span>
          : <span />}
        <span>Last activity <time dateTime={p.lastActivityAt ?? undefined}>{updateTime(p.lastActivityAt ?? p.lastUpdatedAt, now) || '—'}</time></span>
      </div>
    </Link>
    <CardMenu p={p} archived={archived} />
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
  const body = archived ? (
    <div className="sform">
      <p className="hint">{p.name} is archived. Nothing is deleted.</p>
      <div className="qact">
        <button className="btn" type="button" disabled={busy === 'busy'} onClick={() => run('unarchive')}>{busy === 'busy' ? <><span className="spin" />Unarchiving…</> : 'Unarchive'}</button>
        <button className="abtn" type="button" onClick={close}>Cancel</button>
      </div>
      {busy === 'err' && <p className="err-line" role="alert">✕ Couldn’t unarchive. Try again.</p>}
    </div>
  ) : (
    <StatusForm project={sub} onDone={() => { close(); reload(); }} onCancel={close} onArchive={() => { setOpen(false); setConfirm(true); }} />
  );
  return (
    <>
      <div className="pcm">
        <button ref={btnRef} type="button" className="pcmb" aria-haspopup="dialog" aria-expanded={open} aria-label={`${p.name}: project actions`} onClick={() => (open ? close() : setOpen(true))}><IcMore /></button>
      </div>
      {open && !mobile && <div ref={popRef} className="pop menu smenu pcpop" role="dialog" aria-label={archived ? 'Archived project' : 'Set project status'}>{body}</div>}
      {open && mobile && <Sheet label={archived ? 'Archived project' : 'Set project status'} onClose={() => setOpen(false)} restoreFocus={() => btnRef.current}><div className="sheetb">{body}</div></Sheet>}
      {confirm && (
        <ConfirmSheet message={archiveMessage(p.name)} confirmLabel="Archive" busy={busy === 'busy'} error={busy === 'err' ? 'Couldn’t archive. Try again.' : null}
          onConfirm={() => run('archive')} onCancel={() => { setConfirm(false); setBusy('idle'); }} restoreFocus={() => btnRef.current} />
      )}
    </>
  );
}

export default function Portfolio({ projects, now }: Props) {
  const signedIn = usePage<Shared>().props.auth?.user != null;
  const [q, setQ] = useState(() => readUrl().q);
  const [showArchived, setShowArchived] = useState(() => readUrl().archived);

  useEffect(() => {
    const u = new URL(window.location.href);
    q ? u.searchParams.set('q', q) : u.searchParams.delete('q');
    showArchived ? u.searchParams.set('archived', '1') : u.searchParams.delete('archived');
    window.history.replaceState(window.history.state, '', u);
  }, [q, showArchived]);

  const query = q.trim();
  const isArch = (p: PortfolioProject) => p.archived || p.pmStatus === 'archived';
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

  return (
    <div className="kanban">
      <Head title="Portfolio" />
      <TopBar />
      <main className="wrap">
        <div className="dhead">
          <div>
            <h1>Portfolio</h1>
            <p className="summary" aria-live="polite">
              {query ? (total ? `${total} ${total === 1 ? 'result' : 'results'} for “${query}”, incl. archived` : ' ')
                : projects.length ? <><b>{live.length}</b> active {live.length === 1 ? 'project' : 'projects'} · <b>{waitingProjects}</b> waiting input · <b>{forYou}</b> {forYou === 1 ? 'ticket' : 'tickets'} waiting on you</> : ' '}
            </p>
          </div>
          <div className="tools">
            <label className={`search${q ? ' filled' : ''}`}>
              <IconSearch />
              <span className="sr-only">Search projects</span>
              <input type="text" placeholder="Search projects" value={q} onChange={(e) => setQ(e.target.value)} />
              {q && <button type="button" className="clr" aria-label="Clear search" onClick={() => setQ('')}>×</button>}
            </label>
            <button type="button" role="switch" aria-checked={showArchived} className={`toggle${showArchived ? ' on' : ''}`} onClick={() => setShowArchived((v) => !v)}>
              <span className="sw"><span /></span><span>Show archived</span>
            </button>
          </div>
        </div>

        {projects.length === 0 ? (
          signedIn ? (
            <div className="empty"><div className="eico big"><IconInbox /></div><h2>No projects yet</h2><p>Your portfolio is empty. Three steps and your first board is ready.</p><Link className="btn" href="/welcome">Get started</Link></div>
          ) : (
            <div className="empty"><div className="eico big"><IconInbox /></div><h2>No projects yet</h2><p>Projects show up here once Captain creates them.</p></div>
          )
        ) : query && total === 0 ? (
          <div className="empty">
            <div className="eico"><IconSearch /></div>
            <h2>No projects match “{query}”</h2>
            <p>Search covers project names, PMs and status notes. Try a shorter word.</p>
            <button type="button" className="btn" onClick={() => setQ('')}>Clear search</button>
          </div>
        ) : (
          <>
            {active.length > 0 && <div className="pgrid2">{active.map((p) => <Card key={p.id} p={p} q={query} now={now} />)}</div>}
            {archived.length > 0 && (
              <>
                <h2 className="sect"><span>Archived</span> <span className="cnt">{archived.length}</span></h2>
                <div className="pgrid2">{archived.map((p) => <Card key={p.id} p={p} q={query} now={now} />)}</div>
              </>
            )}
          </>
        )}
      </main>
    </div>
  );
}
