import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { TopBar } from '../Components/TopBar';
import { StatusBadge } from '../Components/StatusBadge';
import { MarkdownBody } from '../Components/MarkdownBody';
import { IconAllClear, IconArch, IconReopen } from '../Components/Icons';
import { shortDay, updateTime } from '../lib/dates';
import { t } from '../lib/strings';
import type { InputView, NextStepView, ProjectSummary, TimelineWindow, UpdateView } from '../lib/types';

type Props = {
  project: ProjectSummary;
  statusUpdates: UpdateView[];
  openInputs: InputView[];
  doneInputs: InputView[];
  nextSteps: { inProgress: NextStepView[]; blockers: NextStepView[] };
  timeline: TimelineWindow;
  now: string;
};

const DAY = 86400000;
// r3: <3 days L1 yellow, 3-6 days L2 orange, >6 days L3 red (mirrors ProjectStatus::level).
const levelOf = (d: number) => (d > 6 ? 3 : d >= 3 ? 2 : 1) as 1 | 2 | 3;

function StepList({ id, title, items, kind }: { id: string; title: string; items: NextStepView[]; kind: string }) {
  return (
    <div className={`nscol ns-${kind}`} aria-labelledby={id}>
      <h3 id={id}>{title} <span className="cnt">{items.length}</span></h3>
      {items.length === 0 ? <p className="nsnone">{t('proj.next.none')}</p> : (
        <ul>
          {items.map((s) => (
            <li key={s.id}><span className="nst">{s.text}</span>{s.owner && <span className="nso">{s.owner}</span>}</li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default function Project({ project, statusUpdates, openInputs, doneInputs, nextSteps, timeline, now }: Props) {
  const [busy, setBusy] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const showMore = () => {
    setLoadingMore(true);
    router.get(`/projects/${project.slug}`, { days: timeline.days + timeline.step }, {
      only: ['statusUpdates', 'timeline'],
      preserveScroll: true,
      preserveState: true,
      replace: true,
      onFinish: () => setLoadingMore(false),
    });
  };
  // Optimistic overrides: input id -> resolved?
  const [pending, setPending] = useState<Record<string, boolean>>({});
  const [saveError, setSaveError] = useState(false);

  const { open, done, status } = useMemo(() => {
    const nowMs = Date.parse(now);
    const all = [...openInputs, ...doneInputs].map((i): InputView => {
      const want = pending[i.id];
      if (want === undefined || want === !i.open) return i;
      if (want) return { ...i, open: false, resolvedAt: now, resolvedBy: 'tilen', resolvedByLabel: 'Tilen', level: null, ageDays: null };
      const d = Math.max(0, Math.floor((nowMs - Date.parse(i.since)) / DAY));
      return { ...i, open: true, resolvedAt: null, resolvedBy: null, resolvedByLabel: null, ageDays: d, level: levelOf(d) };
    });
    const o = all.filter((i) => i.open).sort((a, b) => Date.parse(a.since) - Date.parse(b.since));
    const dn = all.filter((i) => !i.open).sort((a, b) => Date.parse(b.resolvedAt ?? '') - Date.parse(a.resolvedAt ?? ''));
    const st = project.archived
      ? { status: 'archived' as const, level: null, ageDays: null }
      : o.length ? { status: 'waiting' as const, level: o[0].level, ageDays: o[0].ageDays } : { status: 'smooth' as const, level: null, ageDays: null };
    return { open: o, done: dn, status: st };
  }, [openInputs, doneInputs, pending, now, project.archived]);

  const toggle = (i: InputView, resolved: boolean) => {
    setSaveError(false);
    setPending((p) => ({ ...p, [i.id]: resolved }));
    let ok = false;
    router.post(`/projects/${project.slug}/inputs/${encodeURIComponent(i.id)}/resolve`, { resolved }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => { ok = true; },
      onFinish: () => {
        // Server props are authoritative now; on failure this reverts the tick.
        setPending((p) => { const n = { ...p }; delete n[i.id]; return n; });
        if (!ok) setSaveError(true);
      },
    });
  };
  const toggleArchive = () => {
    setBusy(true);
    router.post(`/projects/${project.slug}/${project.archived ? 'unarchive' : 'archive'}`, {}, {
      preserveScroll: true,
      onFinish: () => setBusy(false),
    });
  };

  return (
    <>
      <Head title={project.name} />
      <TopBar />
      <main className="wrap">
        <Link className="back" href="/"><span>{t('proj.back')}</span></Link>
        <div className="phead">
          <h1>{project.name}</h1>
          <StatusBadge status={status.status} level={status.level} ageDays={status.ageDays} />
          {project.reopened && (
            <span className="reopen" title={t('dash.reopened.tooltip')}><IconReopen /><span>{t('dash.reopened')}</span></span>
          )}
          <div className="pact">
            <button type="button" className="abtn" onClick={toggleArchive} disabled={busy}>
              {project.archived ? <IconReopen /> : <IconArch />}
              <span>{project.archived ? t('proj.unarchive') : t('proj.archive')}</span>
            </button>
          </div>
        </div>
        <section className="nsteps" aria-labelledby="h-next">
          <h2 id="h-next">{t('proj.next.heading')}</h2>
          <div className="nsgrid">
            <StepList id="h-next-ip" title={t('proj.next.inProgress')} items={nextSteps.inProgress} kind="ip" />
            <StepList id="h-next-bl" title={t('proj.next.blockers')} items={nextSteps.blockers} kind="bl" />
          </div>
        </section>
        <div className="pgrid">
          <section className="feed" aria-labelledby="h-updates">
            <h2 id="h-updates">{t('proj.updates.heading')}</h2>
            <p className="sub">{t('proj.updates.order')} · {t('proj.updates.window', { n: timeline.days })}</p>
            {statusUpdates.length === 0 && <p className="empty-feed">{t('proj.updates.emptyWindow', { n: timeline.days })}</p>}
            {statusUpdates.map((u) => (
              <article className="upd" key={u.id}>
                <div className="dot" />
                <header><b>{u.agentName}</b><time dateTime={u.createdAt ?? undefined}>{updateTime(u.createdAt, now)}</time></header>
                <MarkdownBody content={u.body} />
              </article>
            ))}
            {timeline.hasMore && (
              <button type="button" className="abtn more" onClick={showMore} disabled={loadingMore}>{t('proj.updates.more')}</button>
            )}
          </section>
          <aside className="inputs" aria-labelledby="h-inputs">
            <h2 id="h-inputs">{t('proj.inputs.heading')}</h2>
            <p className="sub"><b>{open.length}</b> <span>{t('proj.inputs.openCount')}</span> · {done.length} <span>{t('proj.inputs.doneCount')}</span></p>
            {saveError && <p className="inerr" role="alert">{t('inputs.saveError')}</p>}
            <ul>
              {open.length === 0 ? (
                <li className="allclear"><IconAllClear /><div><b>{t('inputs.none.title')}</b><p>{t('inputs.none.body')}</p></div></li>
              ) : open.map((i) => (
                <li className={`inp open l${i.level}`} key={i.id}>
                  <input type="checkbox" className="chk" checked={false} onChange={() => toggle(i, true)}
                    aria-label={`${t('inputs.markDone')}: ${i.text}`} title={t('inputs.markDone')} />
                  <div>
                    <p className="it">{i.text}</p>
                    <p className="meta">
                      <span>{t('inputs.waitingOn')}</span> <b>{i.waitingOn}</b> · <span>{t('inputs.since')}</span> {shortDay(i.since, now)}{' '}
                      <StatusBadge status="waiting" level={i.level} ageDays={i.ageDays} />
                    </p>
                  </div>
                </li>
              ))}
            </ul>
            {done.length > 0 && (
              <ul className="donel">
                {done.map((i) => (
                  <li className="inp done" key={i.id}>
                    <input type="checkbox" className="chk on" checked onChange={() => toggle(i, false)}
                      aria-label={`${t('inputs.reopen')}: ${i.text}`} title={t('inputs.reopen')} />
                    <div>
                      <p className="it"><s>{i.text}</s></p>
                      <p className="meta"><span>{t('inputs.doneBy')}</span> {i.resolvedByLabel ?? i.waitingOn} · {shortDay(i.resolvedAt, now)}</p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </aside>
        </div>
      </main>
    </>
  );
}
