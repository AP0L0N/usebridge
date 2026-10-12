import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { TopBar } from '../Components/TopBar';
import { StatusBadge } from '../Components/StatusBadge';
import { MarkdownBody } from '../Components/MarkdownBody';
import { IconAllClear, IconArch, IconReopen } from '../Components/Icons';
import { shortDay, updateTime } from '../lib/dates';
import { t } from '../lib/strings';
import type { InputView, ProjectSummary, UpdateView } from '../lib/types';

type Props = {
  project: ProjectSummary;
  statusUpdates: UpdateView[];
  openInputs: InputView[];
  doneInputs: InputView[];
  now: string;
};

export default function Project({ project, statusUpdates, openInputs, doneInputs, now }: Props) {
  const [busy, setBusy] = useState(false);
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
          <StatusBadge status={project.status} level={project.level} ageDays={project.ageDays} />
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
        <div className="pgrid">
          <section className="feed" aria-labelledby="h-updates">
            <h2 id="h-updates">{t('proj.updates.heading')}</h2>
            <p className="sub">{t('proj.updates.order')}</p>
            {statusUpdates.map((u) => (
              <article className="upd" key={u.id}>
                <div className="dot" />
                <header><b>{u.agentName}</b><time dateTime={u.createdAt ?? undefined}>{updateTime(u.createdAt, now)}</time></header>
                <MarkdownBody content={u.body} />
              </article>
            ))}
          </section>
          <aside className="inputs" aria-labelledby="h-inputs">
            <h2 id="h-inputs">{t('proj.inputs.heading')}</h2>
            <p className="sub"><b>{openInputs.length}</b> <span>{t('proj.inputs.openCount')}</span> · {doneInputs.length} <span>{t('proj.inputs.doneCount')}</span></p>
            <ul>
              {openInputs.length === 0 ? (
                <li className="allclear"><IconAllClear /><div><b>{t('inputs.none.title')}</b><p>{t('inputs.none.body')}</p></div></li>
              ) : openInputs.map((i) => (
                <li className={`inp open l${i.level}`} key={i.id}>
                  <span className="chk" aria-hidden="true" />
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
            {doneInputs.length > 0 && (
              <ul className="donel">
                {doneInputs.map((i) => (
                  <li className="inp done" key={i.id}>
                    <span className="chk on" aria-hidden="true">✓</span>
                    <div>
                      <p className="it"><s>{i.text}</s></p>
                      <p className="meta"><span>{t('inputs.doneBy')}</span> {i.doneBy ?? i.waitingOn} · {shortDay(i.doneAt, now)}</p>
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
