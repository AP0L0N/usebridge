import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { AppTopBar } from '../Components/Shell/AppTopBar';
import { IconSearch20, IconX } from '../Components/AppIcons';
import { Highlight } from '../Components/Highlight';
import { Sheet, ToastHost, useMedia, useToasts } from '../Components/Kanban/Bits';
import { StatusForm } from '../Components/Kanban/StatusBlock';
import { ConnectBotCard, ReadyBanner, type Welcome } from '../Components/Onboarding/ReadyBanner';
import { DeleteWorkstreamDialog, MoveAllDialog, NameDialog, errorText, takenNames } from '../Components/Workstreams/Dialogs';
import {
  ActionMenu, CountBadge, IcArchive, IcArrowRight, IcEdit, IcFolder, IcHistory, IcLeft, IcLines, IcPlus, IcRight, IcStar, IcTrash, IcUp, IcDown, UnreadDot, type MenuItem,
} from '../Components/Workstreams/Ui';
import { ago, longDate } from '../lib/dates';
import { firstName, send, type ColumnKey, type StatusSubject } from '../lib/kanban';
import { t as tk, type StrKey } from '../lib/strings';
import { t, tn } from '../lib/strings-workstreams';
import {
  boardUrl, filterProjects, filterWorkstreams, finderKey, finderUrl, isArchived, nameError,
  type Change, type FinderFocus, type FinderProject, type Preview, type WorkstreamRow,
} from '../lib/workstreams';

type Props = {
  projects: FinderProject[];
  selected: string | null;
  preview: Preview | null;
  now: string;
  welcome?: Welcome;
  columns?: { key: ColumnKey; label: string }[];
};

/** Desktop Finder from 1024px (README §1.1); below that the columns become drill-in screens (§1.2). */
const DESKTOP = '(min-width: 1024px)';

const COLS: [ColumnKey, StrKey][] = [
  ['backlog', 'portfolio.col.backlog'], ['todo', 'portfolio.col.todo'], ['in_progress', 'portfolio.col.in_progress'],
  ['waiting_tilen', 'portfolio.col.waiting'], ['review', 'portfolio.col.review'], ['done', 'portfolio.col.done'],
];

type Dialog =
  | { kind: 'newProject' }
  | { kind: 'renameProject'; p: FinderProject }
  | { kind: 'status'; p: FinderProject }
  | { kind: 'newWs'; p: FinderProject }
  | { kind: 'renameWs'; p: FinderProject; ws: WorkstreamRow }
  | { kind: 'deleteWs'; p: FinderProject; ws: WorkstreamRow }
  | { kind: 'moveAll'; p: FinderProject; ws: WorkstreamRow }
  | { kind: 'wsActions'; p: FinderProject; ws: WorkstreamRow };

type Inline = { kind: 'addWs'; projectId: string } | { kind: 'renameWs'; wsId: string } | { kind: 'renameProject'; projectId: string } | null;

function readUrl() {
  const u = new URLSearchParams(window.location.search);
  return { q: u.get('q') ?? '', archived: u.get('archived') === '1' };
}

/** "3 Oct" this year, "3 Oct 2025" otherwise. */
function dayMonth(iso: string | null | undefined, nowIso: string): string {
  if (!iso) return '';
  const s = longDate(iso);
  return new Date(iso).getFullYear() === new Date(nowIso).getFullYear() ? s.replace(/ \d{4}$/, '') : s;
}

function statusText(p: FinderProject): string {
  if (p.pmStatus === 'smooth') return tk('status.smooth');
  if (p.pmStatus === 'waiting') return p.pmAgeDays ? `${tk('status.waiting')} ${tk('status.waiting.age', { n: p.pmAgeDays })}` : tk('status.waiting');
  return tk('portfolio.status.none');
}

/** Row meta: "PM Ava · Waiting input 2d" · archived: "PM Iris · Archived 3 Oct". */
function projectMeta(p: FinderProject, now: string): string {
  const pm = p.pmName ? t('fd.project.pm', { name: firstName(p.pmName) }) : null;
  const rest = isArchived(p) ? t('fd.project.archivedOn', { date: dayMonth(p.archivedAt ?? p.lastActivityAt, now) }) : statusText(p);
  return [pm, rest].filter(Boolean).join(' · ');
}

/** Stream meta: "9 tickets · 1 for you" · "11 tickets · all done". */
function streamMeta(w: WorkstreamRow): string {
  const parts = [tn(w.tickets, 'fd.ws.tickets.one', 'fd.ws.tickets.many')];
  if (w.allDone) parts.push(t('fd.ws.allDone'));
  else if (w.forYou > 0) parts.push(t('fd.ws.forYou', { n: w.forYou }));
  return parts.join(' · ');
}

const subject = (p: FinderProject): StatusSubject => ({
  name: p.name, slug: p.slug, status: p.pmStatus, status_note: p.pmStatusNote, status_set_by: p.pmStatusSetBy, status_set_by_name: p.pmStatusSetByName,
  status_set_by_human: !!p.pmStatusSetByHuman, status_set_at: p.pmStatusSetAt, pm_agent_id: p.pmAgentId, pm_name: p.pmName, archived: isArchived(p),
});

export default function PortfolioPage(props: Props) {
  return <div className="kanban app"><ToastHost><Portfolio {...props} /></ToastHost></div>;
}

function Portfolio({ projects, selected, preview, now, welcome, columns }: Props) {
  const toasts = useToasts();
  const desktop = useMedia(DESKTOP);
  const [q, setQ] = useState(() => readUrl().q);
  const [showArchived, setShowArchived] = useState(() => readUrl().archived);
  const [sel, setSel] = useState<string | null>(selected);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [inline, setInline] = useState<Inline>(null);

  // ------------------------------------------------------------ optimistic copy of the projects
  const [rows, setRows] = useState<FinderProject[]>(projects);
  const pending = useRef(0);
  useEffect(() => { if (pending.current === 0) setRows(projects); }, [projects]);
  useEffect(() => { setSel(selected); }, [selected]);

  const reload = useCallback(() => new Promise<void>((resolve) => {
    router.reload({ only: ['projects', 'preview'], onFinish: () => resolve() } as never);
  }), []);

  /**
   * Optimistic write: apply `change` now, save in the background, then reload. On failure the change rolls back and an
   * error toast shows; `rethrow` lets a dialog keep its own error line.
   */
  const mutate = useCallback(async (change: (r: FinderProject[]) => FinderProject[], save: () => Promise<unknown>, done?: string, rethrow = false) => {
    let before: FinderProject[] = [];
    setRows((r) => { before = r; return change(r); });
    pending.current++;
    try {
      await save();
      pending.current--;
      if (done) toasts.push({ body: done });
      await reload();
    } catch (e) {
      pending.current--;
      setRows(before);
      if (rethrow) throw e;
      toasts.push({ body: errorText(e), tone: 'err' });
    }
  }, [reload, toasts]);

  // ------------------------------------------------------------ URL state (/portfolio?project=…&q=…&archived=1)
  useEffect(() => {
    const u = new URL(window.location.href);
    q ? u.searchParams.set('q', q) : u.searchParams.delete('q');
    showArchived ? u.searchParams.set('archived', '1') : u.searchParams.delete('archived');
    window.history.replaceState(window.history.state, '', u);
  }, [q, showArchived]);

  const select = useCallback((slug: string | null, push = false) => {
    setSel(slug);
    setInline(null);
    // Desktop replaces the entry (Back from a board returns to this selection); phone drill-in pushes one.
    router.visit(finderUrl(slug, readUrl().q, readUrl().archived), { only: ['preview', 'selected'], preserveState: true, preserveScroll: true, replace: !push });
  }, []);

  // ------------------------------------------------------------ lists
  const query = q.trim();
  const { active, archived } = useMemo(() => filterProjects(rows, query, showArchived), [rows, query, showArchived]);
  const visible = useMemo(() => [...active, ...archived], [active, archived]);
  const current = rows.find((p) => p.slug === sel) ?? null;
  const streams = current ? filterWorkstreams(current, query) : [];
  const live = rows.filter((p) => !isArchived(p));
  const archivedCount = rows.length - live.length;
  const summary = showArchived
    ? [t('fd.summary.active', { n: live.length, projects: t(live.length === 1 ? 'fd.summary.project' : 'fd.summary.projects') }), t('fd.summary.archived', { n: archivedCount })]
    : [
      t('fd.summary.active', { n: live.length, projects: t(live.length === 1 ? 'fd.summary.project' : 'fd.summary.projects') }),
      t('fd.summary.waiting', { n: live.filter((p) => p.pmStatus === 'waiting').length }),
      t('fd.summary.forYou', { n: live.reduce((s, p) => s + (p.waitingOwnerCount ?? 0), 0), tickets: t(live.reduce((s, p) => s + (p.waitingOwnerCount ?? 0), 0) === 1 ? 'fd.summary.ticket' : 'fd.summary.tickets') }),
    ];

  // ------------------------------------------------------------ writes
  const patchProject = (id: string, f: (p: FinderProject) => FinderProject) => (r: FinderProject[]) => r.map((p) => (p.id === id ? f(p) : p));
  const patchStreams = (id: string, f: (w: WorkstreamRow[]) => WorkstreamRow[]) => patchProject(id, (p) => ({ ...p, workstreams: f(p.workstreams) }));

  const createProject = async (name: string) => {
    const r = await send<{ data: { slug: string } }>('POST', '/projects', { name });
    toasts.push({ body: t('toast.created', { name }) });
    setDialog(null);
    router.visit(finderUrl(r.data.slug, q, showArchived), { preserveScroll: true, replace: true });
  };
  const renameProject = (p: FinderProject, name: string, rethrow = false) => mutate(
    patchProject(p.id, (x) => ({ ...x, name })), () => send('PATCH', `/projects/${p.slug}`, { name }), t('toast.renamedProject', { name }), rethrow);
  const addWorkstream = (p: FinderProject, name: string, rethrow = false) => mutate(
    patchStreams(p.id, (ws) => {
      const d = ws.findIndex((w) => w.isDefault);
      const row: WorkstreamRow = { id: `tmp-${Date.now()}`, name, isDefault: false, tickets: 0, forYou: 0, open: 0, allDone: false, badge: 0 };
      return d < 0 ? [...ws, row] : [...ws.slice(0, d), row, ...ws.slice(d)];
    }),
    () => send('POST', `/projects/${p.slug}/workstreams`, { name }), undefined, rethrow);
  const renameWorkstream = (p: FinderProject, ws: WorkstreamRow, name: string, rethrow = false) => mutate(
    patchStreams(p.id, (list) => list.map((w) => (w.id === ws.id ? { ...w, name } : w))),
    () => send('PATCH', `/projects/${p.slug}/workstreams/${ws.id}`, { name }), t('toast.renamedWs', { name }), rethrow);
  const deleteWorkstream = (p: FinderProject, ws: WorkstreamRow, body: { target_id?: string; new_name?: string }) => mutate(
    patchStreams(p.id, (list) => list.filter((w) => w.id !== ws.id).map((w) => (w.id === body.target_id ? { ...w, tickets: w.tickets + ws.tickets, isDefault: w.isDefault || ws.isDefault } : w))),
    () => send('DELETE', `/projects/${p.slug}/workstreams/${ws.id}`, body), t('toast.deletedWs', { name: ws.name }), true);
  const moveAll = (p: FinderProject, ws: WorkstreamRow, targetId: string, targetName: string) => mutate(
    patchStreams(p.id, (list) => list.map((w) => (w.id === ws.id ? { ...w, tickets: 0, forYou: 0, open: 0, allDone: false } : w.id === targetId ? { ...w, tickets: w.tickets + ws.tickets } : w))),
    () => send('POST', `/projects/${p.slug}/workstreams/${ws.id}/move-tickets`, { target_id: targetId }),
    t('toast.movedAll', { tickets: tn(ws.tickets, 'dlg.delWs.tickets.one', 'dlg.delWs.tickets.many'), name: targetName }), true);
  const reorder = (p: FinderProject, ws: WorkstreamRow, direction: 'up' | 'down') => mutate(
    patchStreams(p.id, (list) => {
      const i = list.findIndex((w) => w.id === ws.id), j = direction === 'up' ? i - 1 : i + 1;
      if (j < 0 || j >= list.length || list[j].isDefault) return list;
      const next = [...list];
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    }),
    () => send('POST', `/projects/${p.slug}/workstreams/${ws.id}/reorder`, { direction }));
  const makeDefault = (p: FinderProject, ws: WorkstreamRow) => mutate(
    patchStreams(p.id, (list) => list.map((w) => ({ ...w, isDefault: w.id === ws.id }))),
    () => send('POST', `/projects/${p.slug}/workstreams/${ws.id}/default`));
  const setArchived = (p: FinderProject, archive: boolean, rethrow = false) => mutate(
    patchProject(p.id, (x) => ({ ...x, archived: archive, pmStatus: archive ? 'archived' : x.pmStatus === 'archived' ? null : x.pmStatus })),
    () => send('POST', `/projects/${p.slug}/${archive ? 'archive' : 'unarchive'}`), t(archive ? 'toast.archived' : 'toast.unarchived', { name: p.name }), rethrow);

  // ------------------------------------------------------------ menus
  const projectItems = (p: FinderProject): MenuItem[] => isArchived(p)
    ? [
      { label: t('fd.menu.unarchive'), icon: <IcArchive />, onSelect: () => setArchived(p, false) },
    ]
    : [
      { label: t('fd.menu.renameProject'), icon: <IcEdit />, onSelect: () => (desktop ? setInline({ kind: 'renameProject', projectId: p.id }) : setDialog({ kind: 'renameProject', p })) },
      { label: t('fd.menu.setStatus'), icon: <IcHistory />, onSelect: () => setDialog({ kind: 'status', p }) },
      { label: t('fd.menu.newWorkstream'), icon: <IcPlus />, onSelect: () => (desktop ? setInline({ kind: 'addWs', projectId: p.id }) : setDialog({ kind: 'newWs', p })) },
      'sep',
      { label: t('fd.menu.archive'), icon: <IcArchive />, onSelect: () => setArchived(p, true) },
    ];
  const wsItems = (p: FinderProject, ws: WorkstreamRow): MenuItem[] => {
    const custom = p.workstreams.filter((w) => !w.isDefault);
    const i = custom.findIndex((w) => w.id === ws.id);
    return [
      { label: t('fd.menu.openBoard'), icon: <IcArrowRight />, onSelect: () => router.visit(boardUrl(p.slug, ws.id)) },
      { label: t('fd.menu.rename'), icon: <IcEdit />, onSelect: () => (desktop ? setInline({ kind: 'renameWs', wsId: ws.id }) : setDialog({ kind: 'renameWs', p, ws })) },
      { label: t('fd.menu.moveAll'), icon: <IcLines />, disabled: ws.tickets === 0 || p.workstreams.length < 2, onSelect: () => setDialog({ kind: 'moveAll', p, ws }) },
      ...(!ws.isDefault ? [
        { label: t('fd.menu.makeDefault'), icon: <IcStar />, onSelect: () => makeDefault(p, ws) },
        { label: t('fd.menu.moveUp'), icon: <IcUp />, disabled: i <= 0, onSelect: () => reorder(p, ws, 'up') },
        { label: t('fd.menu.moveDown'), icon: <IcDown />, disabled: i < 0 || i >= custom.length - 1, onSelect: () => reorder(p, ws, 'down') },
      ] : []),
      'sep',
      { label: t('fd.menu.deleteWs'), icon: <IcTrash />, danger: true, onSelect: () => setDialog({ kind: 'deleteWs', p, ws }) },
    ];
  };

  // ------------------------------------------------------------ keyboard (desktop)
  const projRefs = useRef<(HTMLButtonElement | null)[]>([]);
  const wsRefs = useRef<(HTMLAnchorElement | null)[]>([]);
  const onKey = (col: 0 | 1, index: number) => (e: KeyboardEvent) => {
    if (inline || e.altKey || e.metaKey || e.ctrlKey) return;
    if (col === 1 && e.key === 'Enter') return; // the link itself opens the board
    const r = finderKey(e.key, { col, index } as FinderFocus, [visible.length, current ? streams.length : 0]);
    if (!r) return;
    e.preventDefault();
    if (r.focus.col === 0) {
      const i = r.focus.index < 0 ? Math.max(0, visible.findIndex((p) => p.slug === sel)) : r.focus.index;
      projRefs.current[i]?.focus();
      if (r.focus.index >= 0 && visible[i] && visible[i].slug !== sel) select(visible[i].slug);
    } else {
      wsRefs.current[r.focus.index]?.focus();
    }
  };

  // ------------------------------------------------------------ render pieces
  const header = (
    <div className="fd-head">
      <div className="fd-titleblock">
        <h1>{t('fd.title')}</h1>
        <p className="fd-summary" aria-live="polite">{summary.join(' · ')}</p>
      </div>
      <div className="fd-tools">
        <label className={`pf-search fd-search${q ? ' filled' : ''}`}>
          <IconSearch20 />
          <span className="sr-only">{t('fd.search.label')}</span>
          <input type="text" placeholder={t('fd.search.placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
          {q && <button type="button" className="pf-clr" aria-label={t('fd.search.clear')} onClick={() => setQ('')}><IconX /></button>}
        </label>
        <button type="button" role="switch" aria-checked={showArchived} className={`pf-switch${showArchived ? ' on' : ''}`} onClick={() => setShowArchived((v) => !v)}>
          <span className="pf-track"><span /></span><span>{t('fd.showArchived')}</span>
        </button>
        <button type="button" className="btn fd-new" onClick={() => setDialog({ kind: 'newProject' })}>{t('fd.newProject')}</button>
      </div>
    </div>
  );

  const projectRow = (p: FinderProject, i: number, archivedSection: boolean) => {
    const on = p.slug === sel;
    if (inline?.kind === 'renameProject' && inline.projectId === p.id) {
      return (
        <li key={p.id}>
          <InlineName initial={p.name} label={t('dlg.newProject.label')} taken={rows.filter((x) => x.id !== p.id && !isArchived(x)).map((x) => x.name)}
            onCancel={() => setInline(null)} onSave={(name) => { setInline(null); if (name !== p.name) renameProject(p, name); }} />
        </li>
      );
    }
    return (
      <li key={p.id} className="fd-li">
        <button ref={(el) => { projRefs.current[i] = el; }} type="button" className={`fd-prow${on ? ' on' : ''}${archivedSection ? ' arch' : ''}`} aria-current={on ? 'true' : undefined}
          onClick={() => select(p.slug, !desktop)} onKeyDown={onKey(0, i)}
          onDoubleClick={() => { if (desktop && !isArchived(p)) setInline({ kind: 'renameProject', projectId: p.id }); }}>
          {archivedSection && <span className="fd-aico"><IcArchive /></span>}
          <span className="fd-rtext">
            <span className="fd-rname"><Highlight text={p.name} q={query} /></span>
            <span className="fd-rmeta">{projectMeta(p, now)}</span>
          </span>
          {p.hasChanges && !isArchived(p) && <UnreadDot on={on && desktop} />}
          <span className="fd-chev"><IcRight /></span>
        </button>
      </li>
    );
  };

  const streamRow = (p: FinderProject, w: WorkstreamRow, i: number) => {
    const readOnly = isArchived(p);
    if (inline?.kind === 'renameWs' && inline.wsId === w.id) {
      return (
        <li key={w.id}>
          <InlineName initial={w.name} label={t('dlg.newWs.label')} taken={takenNames(p.workstreams, w.id)}
            onCancel={() => setInline(null)} onSave={(name) => { setInline(null); if (name !== w.name) renameWorkstream(p, w, name); }} />
        </li>
      );
    }
    return (
      <li key={w.id} className="fd-li fd-wli" onContextMenu={(e) => { if (!desktop && !readOnly) { e.preventDefault(); setDialog({ kind: 'wsActions', p, ws: w }); } }}>
        <Link ref={(el: HTMLAnchorElement | null) => { wsRefs.current[i] = el; }} href={boardUrl(p.slug, w.id)} className="fd-wrow" onKeyDown={onKey(1, i)}
          onDoubleClick={(e) => { if (desktop && !readOnly) { e.preventDefault(); setInline({ kind: 'renameWs', wsId: w.id }); } }}>
          <span className="fd-rtext">
            <span className="fd-rname"><Highlight text={w.name} q={query} />{w.isDefault && <span className="fd-chip">{t('fd.ws.default')}</span>}</span>
            <span className="fd-rmeta">{streamMeta(w)}</span>
          </span>
          {!readOnly && <CountBadge n={w.badge} />}
          <span className="fd-chev"><IcRight /></span>
        </Link>
        {desktop && !readOnly && <ActionMenu className="fd-wmenu" label={t('fd.menu.ws', { name: w.name })} items={wsItems(p, w)} />}
      </li>
    );
  };

  const projectsColumn = (
    <section className="fd-col fd-c1" aria-label={t('fd.projects.aria')}>
      <div className="fd-ch">
        <h2 className="fd-ov">{t('fd.projects')} <span className="fd-n">{live.length}</span></h2>
        <button type="button" className="fd-sq" aria-label={t('fd.project.newAria')} onClick={() => setDialog({ kind: 'newProject' })}><IcPlus /></button>
      </div>
      <div className="fd-scroll">
        {visible.length === 0 && query && <p className="fd-none">{t('fd.projects.none', { q: query })}</p>}
        <ul className="fd-list">{active.map((p, i) => projectRow(p, i, false))}</ul>
        {archived.length > 0 && (
          <>
            <h2 className="fd-ov fd-archh"><IcArchive />{t('fd.archived')} <span className="fd-n">{archived.length}</span></h2>
            <ul className="fd-list">{archived.map((p, i) => projectRow(p, active.length + i, true))}</ul>
          </>
        )}
      </div>
      <p className="fd-foot">{t('fd.projects.hint')}</p>
    </section>
  );

  const addRow = current && !isArchived(current) && (
    inline?.kind === 'addWs' && inline.projectId === current.id ? (
      <InlineName initial="" label={t('dlg.newWs.label')} taken={takenNames(current.workstreams)} placeholder={t('fd.ws.namePlaceholder')}
        onCancel={() => setInline(null)} onSave={(name) => { setInline(null); addWorkstream(current, name); }} />
    ) : (
      <button type="button" className="fd-addws" onClick={() => (desktop ? setInline({ kind: 'addWs', projectId: current.id }) : setDialog({ kind: 'newWs', p: current }))}><IcPlus />{t('fd.ws.add')}</button>
    )
  );

  const streamsColumn = (
    <section className="fd-col fd-c2" aria-label={current ? t('fd.workstreams.aria', { name: current.name }) : t('fd.workstreams')}>
      {current ? (
        <>
          <div className="fd-ch fd-ptitle">
            <h2 className="fd-pname">{current.name}</h2>
            {isArchived(current)
              ? <span className="fd-archchip"><IcArchive />{t('fd.archived')}</span>
              : <ActionMenu label={t('fd.menu.project', { name: current.name })} items={projectItems(current)} className="fd-pmenu" />}
          </div>
          <div className="fd-ch fd-wh">
            <h3 className="fd-ov">{t('fd.workstreams')} <span className="fd-n">{current.workstreams.length}</span></h3>
            {!isArchived(current) && <button type="button" className="fd-sq" aria-label={t('fd.ws.addAria')} onClick={() => setInline({ kind: 'addWs', projectId: current.id })}><IcPlus /></button>}
          </div>
          <div className="fd-scroll">
            {current.workstreams.length === 0 && !(inline?.kind === 'addWs') ? (
              <div className="fd-empty">
                <span className="fd-eico"><IcLines /></span>
                <b>{t('fd.ws.empty.title')}</b>
                <p>{t('fd.ws.empty.body')}</p>
                {!isArchived(current) && <button type="button" className="abtn" onClick={() => setInline({ kind: 'addWs', projectId: current.id })}>{t('fd.ws.add')}</button>}
              </div>
            ) : (
              <ul className="fd-list">{streams.map((w, i) => streamRow(current, w, i))}</ul>
            )}
            {current.workstreams.length > 0 && addRow}
            {isArchived(current) && <p className="fd-archnote"><IcArchive />{t('fd.ws.archivedNote')}</p>}
          </div>
          <p className="fd-foot">{t('fd.ws.hint')}</p>
        </>
      ) : <p className="fd-pick">{t('fd.select')}</p>}
    </section>
  );

  const dialogs = (
    <>
      {dialog?.kind === 'newProject' && (
        <NameDialog title={t('dlg.newProject.title')} label={t('dlg.newProject.label')} hint={t('dlg.newProject.hint')} submitLabel={t('dlg.newProject.submit')}
          onSubmit={createProject} onClose={() => setDialog(null)} />
      )}
      {dialog?.kind === 'renameProject' && (
        <NameDialog title={t('dlg.renameProject.title')} label={t('dlg.newProject.label')} initial={dialog.p.name}
          note={(name) => (name && name !== dialog.p.name ? t('dlg.renameProject.note', { from: dialog.p.name, to: name }) : null)}
          onSubmit={async (name) => { await renameProject(dialog.p, name, true); setDialog(null); }} onClose={() => setDialog(null)} />
      )}
      {dialog?.kind === 'newWs' && (
        <NameDialog title={t('dlg.newWs.title')} label={t('dlg.newWs.label')} submitLabel={t('dlg.newWs.submit')}
          onSubmit={async (name) => { await addWorkstream(dialog.p, name, true); setDialog(null); }} onClose={() => setDialog(null)} />
      )}
      {dialog?.kind === 'renameWs' && (
        <NameDialog title={t('dlg.renameWs.title')} label={t('dlg.newWs.label')} initial={dialog.ws.name}
          onSubmit={async (name) => { await renameWorkstream(dialog.p, dialog.ws, name, true); setDialog(null); }} onClose={() => setDialog(null)} />
      )}
      {dialog?.kind === 'deleteWs' && (
        <DeleteWorkstreamDialog ws={dialog.ws} others={dialog.p.workstreams.filter((w) => w.id !== dialog.ws.id)} projectName={dialog.p.name}
          onSubmit={async (body) => { await deleteWorkstream(dialog.p, dialog.ws, body); setDialog(null); }} onClose={() => setDialog(null)} />
      )}
      {dialog?.kind === 'moveAll' && (
        <MoveAllDialog ws={dialog.ws} others={dialog.p.workstreams.filter((w) => w.id !== dialog.ws.id)}
          onSubmit={async (id, name) => { await moveAll(dialog.p, dialog.ws, id, name); setDialog(null); }} onClose={() => setDialog(null)} />
      )}
      {dialog?.kind === 'status' && (
        <Sheet label={t('fd.menu.setStatus')} onClose={() => setDialog(null)}>
          <h3>{t('fd.menu.setStatus')}</h3>
          <div className="sheetb">
            <StatusForm project={subject(dialog.p)} onDone={() => { setDialog(null); reload(); }} onCancel={() => setDialog(null)}
              onArchive={() => { const p = dialog.p; setDialog(null); setArchived(p, true); }} />
          </div>
        </Sheet>
      )}
      {dialog?.kind === 'wsActions' && (
        <Sheet label={t('fd.menu.ws', { name: dialog.ws.name })} onClose={() => setDialog(null)}>
          <h3>{dialog.ws.name}</h3>
          <ul className="mvl">
            {wsItems(dialog.p, dialog.ws).filter((it): it is Exclude<MenuItem, 'sep'> => it !== 'sep').map((it) => (
              <li key={it.label}><button type="button" disabled={it.disabled} className={it.danger ? 'dng' : ''} onClick={() => { setDialog(null); it.onSelect(); }}>{it.icon}{it.label}</button></li>
            ))}
          </ul>
        </Sheet>
      )}
    </>
  );

  // ------------------------------------------------------------ layouts
  if (!desktop) {
    return (
      <>
        <Head title={current ? current.name : t('fd.title')} />
        <AppTopBar />
        <main className="fd fd-phone">
          {current ? (
            <PhoneStreams p={current} streams={streams} now={now} onBack={() => select(null)}
              menu={<ActionMenu label={t('fd.menu.project', { name: current.name })} items={projectItems(current)} className="fd-pmenu" />}
              renderRow={(w, i) => streamRow(current, w, i)} onAdd={() => setDialog({ kind: 'newWs', p: current })} />
          ) : (
            <>
              <div className="fd-head">
                <div className="fd-titleblock"><h1>{t('fd.title')}</h1><p className="fd-summary">{summary.join(' · ')}</p></div>
              </div>
              <label className={`pf-search fd-search${q ? ' filled' : ''}`}>
                <IconSearch20 /><span className="sr-only">{t('fd.search.label')}</span>
                <input type="text" placeholder={t('fd.search.placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
                {q && <button type="button" className="pf-clr" aria-label={t('fd.search.clear')} onClick={() => setQ('')}><IconX /></button>}
              </label>
              <div className="fd-prow2">
                <button type="button" role="switch" aria-checked={showArchived} className={`pf-switch${showArchived ? ' on' : ''}`} onClick={() => setShowArchived((v) => !v)}>
                  <span className="pf-track"><span /></span><span>{t('fd.showArchived')}</span>
                </button>
                <button type="button" className="btn fd-new" onClick={() => setDialog({ kind: 'newProject' })}>{t('fd.newProject')}</button>
              </div>
              <ReadyBanner welcome={welcome} />
              {rows.length === 0 ? <EmptyPortfolio onNew={() => setDialog({ kind: 'newProject' })} /> : (
                <>
                  <h2 className="fd-ov">{t('fd.projects')} <span className="fd-n">{live.length}</span></h2>
                  {visible.length === 0 && query && <p className="fd-none">{t('fd.projects.none', { q: query })}</p>}
                  {active.length > 0 && <ul className="fd-list fd-card">{active.map((p, i) => projectRow(p, i, false))}</ul>}
                  {archived.length > 0 && (
                    <>
                      <h2 className="fd-ov fd-archh"><IcArchive />{t('fd.archived')} <span className="fd-n">{archived.length}</span></h2>
                      <ul className="fd-list fd-card">{archived.map((p, i) => projectRow(p, active.length + i, true))}</ul>
                    </>
                  )}
                  <p className="fd-foot">{t('fd.projects.hint')}</p>
                </>
              )}
              <ConnectBotCard welcome={welcome} />
            </>
          )}
        </main>
        {dialogs}
      </>
    );
  }

  return (
    <>
      <Head title={t('fd.title')} />
      <AppTopBar />
      <main className="fd">
        {header}
        <ReadyBanner welcome={welcome} />
        {rows.length === 0 ? <div className="fd-frame fd-frame-empty"><EmptyPortfolio onNew={() => setDialog({ kind: 'newProject' })} /></div> : (
          <div className="fd-frame">
            {projectsColumn}
            {streamsColumn}
            <PreviewColumn p={current} preview={preview && current && preview.slug === current.slug ? preview : null} now={now} columns={columns}
              onUnarchive={() => current && setArchived(current, false)} />
          </div>
        )}
        <div className="fd-bottom">
          <nav className="fd-crumbs" aria-label="Breadcrumb">
            <IcFolder /><Link href={finderUrl(null, q, showArchived)} onClick={(e) => { e.preventDefault(); select(null); }}>{t('fd.crumb.portfolio')}</Link>
            {current && isArchived(current) && <><IcRight /><span>{t('fd.crumb.archived')}</span></>}
            {current && <><IcRight /><span aria-current="page">{current.name}</span></>}
          </nav>
          <p className="fd-keys">{t('fd.keys')}</p>
        </div>
        <ConnectBotCard welcome={welcome} />
      </main>
      {dialogs}
    </>
  );
}

/** Inline name input (desktop add / rename): Enter saves, Esc cancels, blur saves when valid. */
function InlineName({ initial, label, taken, placeholder, onSave, onCancel }: {
  initial: string; label: string; taken: string[]; placeholder?: string; onSave: (name: string) => void; onCancel: () => void;
}) {
  const [v, setV] = useState(initial);
  const [err, setErr] = useState<string | null>(null);
  const done = useRef(false);
  const commit = () => {
    if (done.current) return;
    const name = v.trim().replace(/\s+/g, ' ');
    if (!name || name === initial) { done.current = true; onCancel(); return; }
    const e = nameError(name, taken);
    if (e === 'max') { setErr(t('dlg.name.max', { n: 60 })); return; }
    if (e === 'taken') { setErr(t('dlg.name.taken', { name })); return; }
    done.current = true;
    onSave(name);
  };
  return (
    <div className={`fd-inline${err ? ' bad' : ''}`}>
      <input aria-label={label} value={v} autoFocus placeholder={placeholder} onFocus={(e) => e.currentTarget.select()}
        onChange={(e) => { setV(e.target.value); setErr(null); }} onBlur={commit}
        onKeyDown={(e) => {
          e.stopPropagation();
          if (e.key === 'Enter') { e.preventDefault(); commit(); }
          if (e.key === 'Escape') { e.preventDefault(); done.current = true; onCancel(); }
        }} />
      <span className="fd-ikeys" aria-hidden="true">{t('fd.ws.inputHint')}</span>
      {err && <span className="sr-only" role="alert">{err}</span>}
    </div>
  );
}

function EmptyPortfolio({ onNew }: { onNew: () => void }) {
  return (
    <div className="fd-empty fd-empty-all">
      <span className="fd-eico"><IcFolder /></span>
      <b>{t('fd.empty.title')}</b>
      <p>{t('fd.empty.body')}</p>
      <button type="button" className="btn" onClick={onNew}>{t('fd.newProject')}</button>
    </div>
  );
}

function StatusPill({ p }: { p: FinderProject }) {
  const tone = p.pmStatus === 'waiting' ? 'w' : p.pmStatus === 'smooth' ? 'ok' : 'none';
  const text = p.pmStatus === 'waiting' && p.pmAgeDays ? `${tk('status.waiting')} · ${tk('status.waiting.age', { n: p.pmAgeDays })}` : p.pmStatus === 'smooth' ? tk('status.smooth') : p.pmStatus === 'waiting' ? tk('status.waiting') : tk('portfolio.status.none');
  return <span className={`fd-pill ${tone}`}><i aria-hidden="true" />{text}</span>;
}

function StatusCard({ p, now }: { p: FinderProject; now: string }) {
  if (!p.pmStatus || !p.pmStatusSetAt) return <div className="fd-note muted">{t('fd.preview.noStatus')}</div>;
  const who = p.pmStatusSetBy === p.pmAgentId ? firstName(p.pmStatusSetByName) : p.pmStatusSetByName;
  return (
    <div className="fd-note">
      <p className="fd-by"><i className={p.pmStatusSource === 'ui' ? 'h' : ''} aria-hidden="true" />{t('fd.preview.setBy', { name: who ?? '', when: ago(p.pmStatusSetAt, now) })}
        {p.pmStatusSource && <> {t('fd.preview.via', { source: tk(`portfolio.source.${p.pmStatusSource}` as StrKey) })}</>}</p>
      {p.pmStatusNote && <p className="fd-q">“{p.pmStatusNote}”</p>}
    </div>
  );
}

function changeText(c: Change): string {
  const v = { actor: c.actor, key: c.key ?? '', title: c.title ?? '' };
  switch (c.type) {
    case 'created': return t('fd.change.created', v);
    case 'moved': return t('fd.change.moved', { ...v, column: c.column ?? '' });
    case 'commented': return t('fd.change.commented', v);
    case 'assigned': return c.assignee ? t('fd.change.assigned', { ...v, name: c.assignee }) : t('fd.change.unassigned', v);
    case 'workstream': return t('fd.change.workstream', { ...v, from: c.fromWorkstream ?? '' });
  }
}

/** Column 3 (README §1.1): status, ticket counts by column, unseen changes. Reading it clears nothing. */
function PreviewColumn({ p, preview, now, columns, onUnarchive }: { p: FinderProject | null; preview: Preview | null; now: string; columns?: Props['columns']; onUnarchive: () => void }) {
  if (!p) return <section className="fd-col fd-c3" aria-label={t('fd.title')} />;
  const archived = isArchived(p);
  const total = p.workstreams.reduce((s, w) => s + w.tickets, 0);
  const ws = tn(p.workstreams.length, 'fd.preview.ws.one', 'fd.preview.ws.many');
  const tickets = tn(total, 'fd.ws.tickets.one', 'fd.ws.tickets.many');
  const pm = firstName(p.pmName);
  const meta = archived
    ? t('fd.preview.metaArchived', { pm: pm || '—', ws, tickets, date: dayMonth(p.archivedAt, now) })
    : total === 0 && p.createdAt
      ? [pm && t('fd.project.pm', { name: pm }), ws, tickets, t('fd.preview.created', { when: ago(p.createdAt, now) })].filter(Boolean).join(' · ')
      : (pm ? t('fd.preview.meta', { pm, ws, tickets, when: ago(p.lastActivityAt ?? p.lastUpdatedAt, now) || '—' }) : t('fd.preview.metaNoPm', { ws, tickets, when: ago(p.lastActivityAt ?? p.lastUpdatedAt, now) || '—' }));
  const cols = COLS.map(([k, key]) => {
    const own = p.columnLabels?.[k];
    const wsLabel = k === 'waiting_tilen' ? columns?.find((c) => c.key === k)?.label : undefined;
    return { k, label: own ? `${own}${k === 'done' ? ' (7 days)' : ''}` : wsLabel ?? tk(key), n: p.ticketCounts?.[k] ?? 0 };
  });
  const sum = cols.reduce((s, c) => s + c.n, 0);
  return (
    <section className="fd-col fd-c3" aria-label={p.name}>
      <div className="fd-phead">
        <div>
          <h2>{p.name}</h2>
          <p className="fd-pmeta">{meta}</p>
        </div>
        {archived ? <button type="button" className="abtn" onClick={onUnarchive}>{t('fd.preview.unarchive')}</button> : <StatusPill p={p} />}
      </div>
      {archived ? <div className="fd-note">{t('fd.preview.archived', { name: p.name })}</div> : (
        <>
          <StatusCard p={p} now={now} />
          {total === 0 ? (
            <div className="fd-empty fd-empty-tk">
              <span className="fd-eico"><IcLines /></span>
              <b>{t('fd.preview.noTickets.title')}</b>
              <p>{t('fd.preview.noTickets.body')}</p>
            </div>
          ) : (
            <>
              <div className="fd-cols">
                <div className="pf-bar fd-bar" role="img" aria-label={tk('portfolio.tickets.aria', { n: sum })}>
                  {cols.filter((c) => c.n > 0).map((c) => <i key={c.k} className={`pf-c-${c.k}`} style={{ flex: c.n }} />)}
                </div>
                <ul className="pf-legend">
                  {cols.map((c) => <li key={c.k} className={c.n === 0 ? 'pf-zero' : ''}><i className={`pf-c-${c.k}`} aria-hidden="true" /><span>{c.label}</span> <b className="pf-mono">{c.n}</b></li>)}
                </ul>
              </div>
              <div className="fd-changes">
                <h3 className="fd-ov">{t('fd.preview.changes')}
                  {preview && preview.total > 0 && <span className="fd-n pf-mono">{t('fd.preview.changesCount', { n: preview.total, w: preview.workstreams, workstreams: t(preview.workstreams === 1 ? 'fd.preview.workstream' : 'fd.preview.workstreams') })}</span>}
                </h3>
                {preview === null ? <div className="fd-skel" aria-hidden="true"><i /><i /><i /></div>
                  : preview.changes.length === 0 ? <p className="fd-quiet">{t('fd.preview.noChanges')}</p> : (
                    <ul className="fd-clist">
                      {preview.changes.map((c) => (
                        <li key={c.id}>
                          <i className="fd-cdot" aria-hidden="true" />
                          <span className="lb">{c.workstream}</span>
                          <span className="fd-ctext">{changeText(c)}</span>
                          <time className="pf-mono" dateTime={c.at ?? undefined}>{ago(c.at, now)}</time>
                        </li>
                      ))}
                    </ul>
                  )}
              </div>
              <p className="fd-hint"><IcArrowRight />{t('fd.preview.footer')}</p>
            </>
          )}
        </>
      )}
    </section>
  );
}

/** Phone drill-in: the workstreams of one project (frame 74:1033). */
function PhoneStreams({ p, streams, now, onBack, menu, renderRow, onAdd }: {
  p: FinderProject; streams: WorkstreamRow[]; now: string; onBack: () => void; menu: ReactNode; renderRow: (w: WorkstreamRow, i: number) => ReactNode; onAdd: () => void;
}) {
  const archived = isArchived(p);
  const total = p.workstreams.reduce((s, w) => s + w.tickets, 0);
  const pm = firstName(p.pmName);
  return (
    <>
      <button type="button" className="fd-back" onClick={onBack}><IcLeft />{t('fd.projects')}</button>
      <div className="fd-phhead">
        <div>
          <h1>{p.name}</h1>
          <p className="fd-pmeta">{[pm && t('fd.project.pm', { name: pm }), tn(total, 'fd.ws.tickets.one', 'fd.ws.tickets.many'), ago(p.lastActivityAt ?? p.lastUpdatedAt, now)].filter(Boolean).join(' · ')}</p>
        </div>
        {!archived && menu}
      </div>
      {!archived && (
        <div className="fd-phstatus">
          <StatusPill p={p} />
          {p.pmStatusNote && <p className="fd-q">“{p.pmStatusNote}”</p>}
          {p.pmStatusSetAt && <p className="fd-by pf-mono">{t('fd.preview.setBy', { name: (p.pmStatusSetBy === p.pmAgentId ? firstName(p.pmStatusSetByName) : p.pmStatusSetByName) ?? '', when: ago(p.pmStatusSetAt, now) })}</p>}
        </div>
      )}
      <div className="fd-ch fd-wh">
        <h2 className="fd-ov">{t('fd.workstreams')} <span className="fd-n">{p.workstreams.length}</span></h2>
        {!archived && <button type="button" className="fd-sq" aria-label={t('fd.ws.addAria')} onClick={onAdd}><IcPlus /></button>}
      </div>
      {p.workstreams.length === 0 ? (
        <div className="fd-empty fd-card">
          <span className="fd-eico"><IcLines /></span>
          <b>{t('fd.ws.empty.title')}</b>
          <p>{t('fd.ws.empty.body')}</p>
          {!archived && <button type="button" className="abtn" onClick={onAdd}>{t('fd.ws.add')}</button>}
        </div>
      ) : (
        <div className="fd-card">
          <ul className="fd-list">{streams.map(renderRow)}</ul>
          {!archived && <button type="button" className="fd-addws" onClick={onAdd}><IcPlus />{t('fd.ws.add')}</button>}
        </div>
      )}
      {archived ? <p className="fd-archnote"><IcArchive />{t('fd.ws.archivedNote')}</p> : <p className="fd-foot">{t('fd.ws.phoneHint')}</p>}
    </>
  );
}
