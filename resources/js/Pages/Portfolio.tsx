import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { TopBar } from '../Components/TopBar';
import { StatusBadge, rowClass } from '../Components/StatusBadge';
import { Highlight } from '../Components/Highlight';
import { IconInbox, IconReopen, IconSearch } from '../Components/Icons';
import { lastUpdated } from '../lib/dates';
import { t } from '../lib/strings';
import type { ProjectSummary } from '../lib/types';

type Props = { projects: ProjectSummary[]; now: string };

const severity = (p: ProjectSummary) => (p.status === 'waiting' ? 3 - (p.level ?? 1) : 3); // L3=0, L2=1, L1=2, smooth=3
const ts = (p: ProjectSummary) => (p.lastUpdatedAt ? Date.parse(p.lastUpdatedAt) : 0);
const byOrder = (a: ProjectSummary, b: ProjectSummary) => severity(a) - severity(b) || ts(b) - ts(a);

function readUrl() {
  const u = new URLSearchParams(window.location.search);
  return { q: u.get('q') ?? '', archived: u.get('archived') === '1' };
}

function Row({ p, q, now }: { p: ProjectSummary; q: string; now: string }) {
  return (
    <Link className={`prow ${rowClass(p.status, p.level)}`} href={`/projects/${p.slug}`}>
      <span className="spine" />
      <div className="pmain">
        <div className="pname">
          <span><Highlight text={p.name} q={q} /></span>
          {p.reopened && (
            <span className="reopen" title={t('dash.reopened.tooltip')}><IconReopen /><span>{t('dash.reopened')}</span></span>
          )}
        </div>
        {p.latestLine && <p className="pline"><Highlight text={p.latestLine} q={q} /></p>}
      </div>
      <div className="pbadge"><StatusBadge status={p.status} level={p.level} ageDays={p.ageDays} /></div>
      <div className="pupd"><span className="lbl">{t('dash.updated')}</span> <time dateTime={p.lastUpdatedAt ?? undefined}>{lastUpdated(p.lastUpdatedAt, now)}</time></div>
    </Link>
  );
}

export default function Portfolio({ projects, now }: Props) {
  const [q, setQ] = useState(() => readUrl().q);
  const [showArchived, setShowArchived] = useState(() => readUrl().archived);

  useEffect(() => {
    const u = new URL(window.location.href);
    q ? u.searchParams.set('q', q) : u.searchParams.delete('q');
    showArchived ? u.searchParams.set('archived', '1') : u.searchParams.delete('archived');
    window.history.replaceState(window.history.state, '', u);
  }, [q, showArchived]);

  const query = q.trim();
  const { active, archived } = useMemo(() => {
    const n = query.toLowerCase();
    const hits = n
      ? projects.filter((p) => p.name.toLowerCase().includes(n) || (p.searchText ?? '').toLowerCase().includes(n))
      : projects;
    return {
      active: hits.filter((p) => !p.archived).sort(byOrder),
      // Search always includes archived; otherwise only when toggled on.
      archived: n || showArchived ? hits.filter((p) => p.archived).sort((a, b) => ts(b) - ts(a)) : [],
    };
  }, [projects, query, showArchived]);

  const nonArchived = projects.filter((p) => !p.archived);
  const w = nonArchived.filter((p) => p.status === 'waiting').length;
  const o = nonArchived.filter((p) => p.status === 'smooth').length;
  const total = active.length + archived.length;
  const [, mid, tail] = t('dash.summary').split(/\{w\}|\{o\}/);

  return (
    <>
      <Head title={t('dash.title')} />
      <TopBar />
      <main className="wrap">
        <div className="dhead">
          <div>
            <h1>{t('dash.title')}</h1>
            <p className="summary" aria-live="polite">
              {query ? (total ? t('dash.results', { n: total, q: query }) : '\u00a0')
                : projects.length ? <><b>{w}</b>{mid}<b>{o}</b>{tail}</> : '\u00a0'}
            </p>
          </div>
          <div className="tools">
            <label className={`search${q ? ' filled' : ''}`}>
              <IconSearch />
              <span className="sr-only">{t('dash.search.placeholder')}</span>
              <input type="text" placeholder={t('dash.search.placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
              {q && <button type="button" className="clr" aria-label={t('dash.noresults.clear')} onClick={() => setQ('')}>×</button>}
            </label>
            <button type="button" role="switch" aria-checked={showArchived} className={`toggle${showArchived ? ' on' : ''}`} onClick={() => setShowArchived((v) => !v)}>
              <span className="sw"><span /></span><span>{t('dash.showArchived')}</span>
            </button>
          </div>
        </div>

        {projects.length === 0 ? (
          <div className="empty"><div className="eico big"><IconInbox /></div><h2>{t('dash.empty.title')}</h2><p>{t('dash.empty.body')}</p></div>
        ) : query && total === 0 ? (
          <div className="empty">
            <div className="eico"><IconSearch /></div>
            <h2>{t('dash.noresults.title', { q: query })}</h2>
            <p>{t('dash.noresults.body')}</p>
            <button type="button" className="btn" onClick={() => setQ('')}>{t('dash.noresults.clear')}</button>
          </div>
        ) : (
          <>
            {active.length > 0 && <div className="plist">{active.map((p) => <Row key={p.id} p={p} q={query} now={now} />)}</div>}
            {archived.length > 0 && (
              <>
                <h3 className="sect"><span>{t('dash.archived.heading')}</span> <span className="cnt">{archived.length}</span></h3>
                <div className="plist arch">{archived.map((p) => <Row key={p.id} p={p} q={query} now={now} />)}</div>
              </>
            )}
          </>
        )}
      </main>
    </>
  );
}
