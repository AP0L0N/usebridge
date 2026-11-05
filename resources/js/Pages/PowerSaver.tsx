import { Head, router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import '../../css/power-saver.css';
import { AppTopBar } from '../Components/Shell/AppTopBar';
import { requestJson } from '../lib/http';
import { parsePercent, saveFailureKind, showStopSlider, type PercentParse } from '../lib/powerSaverField';
import { t } from '../lib/strings-power';

type Semantics = {
  min: number; max: number; summary: string; comparisonSummary: string; locked: boolean;
};
type StopOption = { id: string; status: 'proposed' | 'not_built' | 'rejected' | string; summary: string };
type Gaps = { summary: string; chosenStopOption: string | null; stopOptions: StopOption[] };
type ProjectRow = {
  id: string; name: string; slug: string; status: string | null; statusLabel: string; archived: boolean;
  agentId: string | null; agentIds: string[]; agentName: string | null; projectIdsForAgent: string[];
  stopPercent: number | null; stoppableInBridge: boolean;
};
type RoutineRow = {
  agentId: string; agentName: string; routineId: string; name: string; seenInUsage: boolean;
  stopPercent: number | null; stoppableInBridge: boolean;
};
type Props = { semantics: Semantics; gaps: Gaps; projects: ProjectRow[]; routines: RoutineRow[] };
type SavedRow = {
  subject_type: 'project' | 'routine'; project_id?: string; agent_id?: string; routine_id?: string; stop_percent: number | null;
};
type SaveFailure = { kind: 'validation' | 'network'; message: string };

function firstError(errors: Record<string, string[]> | undefined, fallback: string): string {
  for (const messages of Object.values(errors ?? {})) if (messages[0]) return messages[0];
  return fallback;
}

function ThresholdControl({ name, value, min, max, onCommit }: {
  name: string; value: number | null; min: number; max: number;
  onCommit: (next: number | null) => Promise<SaveFailure | null>;
}) {
  const numberId = useId();
  const errorId = useId();
  const numberRef = useRef<HTMLInputElement>(null);
  const [text, setText] = useState(value === null ? '' : String(value));
  const [saved, setSaved] = useState(value);
  const [error, setError] = useState<string | null>(null);
  const [errorKind, setErrorKind] = useState<'validation' | 'network' | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const inflight = useRef<number | null | undefined>(undefined);
  const seq = useRef(0);

  useEffect(() => {
    setText(value === null ? '' : String(value));
    setSaved(value);
  }, [value]);

  const invalid = t('ps.error.percent');
  const parsed: PercentParse = parsePercent(text, min, max, invalid);
  const showSlider = showStopSlider(parsed);
  const sliderValue = showSlider && parsed.ok ? parsed.value ?? min : min;
  const stateLabel = !showSlider ? t('ps.unset') : t('ps.percent', { n: sliderValue });
  const live = busy ? t('ps.saving') : error ? '' : note === t('ps.saved') ? `${t('ps.saved')} · ${stateLabel}` : (note ?? stateLabel);
  const describedBy = error ? errorId : undefined;

  const fail = (kind: 'validation' | 'network', message: string) => {
    setError(message);
    setErrorKind(kind);
    setNote(null);
  };

  const commit = async (raw: string) => {
    const next = parsePercent(raw, min, max, invalid);
    if (!next.ok) { fail('validation', next.message); return; }
    if (next.value === saved || inflight.current === next.value) { setError(null); setErrorKind(null); return; }
    const id = ++seq.current;
    inflight.current = next.value;
    setBusy(true);
    setError(null);
    setErrorKind(null);
    setNote(t('ps.saving'));
    const failure = await onCommit(next.value);
    if (id !== seq.current) return;
    inflight.current = undefined;
    setBusy(false);
    if (failure) { fail(failure.kind, failure.message); return; }
    setSaved(next.value);
    setText(next.value === null ? '' : String(next.value));
    setNote(t('ps.saved'));
  };

  const retry = () => { void commit(numberRef.current?.value ?? text); };

  return (
    <div className="ps-field">
      <div className="ps-control">
        <label className="sr-only" htmlFor={numberId}>{t('ps.number', { name })}</label>
        <input ref={numberRef} id={numberId} type="number" inputMode="numeric" min={min} max={max} step={1} value={text}
          aria-invalid={error ? true : undefined} aria-describedby={describedBy} disabled={busy}
          onChange={(e) => { setText(e.target.value); setNote(null); }}
          onBlur={(e) => { void commit(e.currentTarget.value); }}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); void commit(e.currentTarget.value); } }} />
        {showSlider && (
          <input type="range" min={min} max={max} step={1} value={sliderValue} disabled={busy}
            aria-label={t('ps.slider', { name })}
            aria-invalid={error ? true : undefined} aria-describedby={describedBy}
            aria-valuemin={min} aria-valuemax={max} aria-valuenow={sliderValue}
            aria-valuetext={t('ps.percent', { n: sliderValue })}
            onChange={(e) => setText(e.target.value)}
            onPointerUp={(e) => { void commit((e.target as HTMLInputElement).value); }}
            onKeyUp={(e) => { if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key)) void commit((e.currentTarget as HTMLInputElement).value); }}
            onBlur={(e) => { void commit(e.target.value); }} />
        )}
        <button type="button" className="ps-clear" disabled={busy || (!showSlider && saved === null)} onClick={() => { setText(''); void commit(''); }}>
          {t('ps.clear')}
        </button>
      </div>
      <p className="ps-live" aria-live="polite">{live}</p>
      {error && (
        <p className="ps-err">
          <span id={errorId} role="alert">{error}</span>
          <button type="button" className="ps-retry" onClick={retry}>{errorKind === 'validation' ? t('ps.correct') : t('ps.retry')}</button>
        </p>
      )}
    </div>
  );
}

function optionLabel(status: string): string {
  if (status === 'proposed' || status === 'not_built' || status === 'rejected' || status === 'locked' || status === 'archive_only') return t(`ps.stop.${status}`);
  return status;
}

function projectAgentLine(project: ProjectRow): string {
  const who = project.agentName ? t('ps.project.agent', { name: project.agentName }) : t('ps.project.noAgent');
  return project.projectIdsForAgent.length > 1 ? `${who} · ${t('ps.project.shared')}` : who;
}

export default function PowerSaver({ semantics, gaps, projects, routines }: Props) {
  const [pageLoading, setPageLoading] = useState(false);
  const min = semantics.min;
  const max = semantics.max;
  // The server already leaves archived projects out; this only guards against a stale payload.
  const activeProjects = projects.filter((project) => !project.archived);

  useEffect(() => {
    const offStart = router.on('start', (event) => {
      const url = event.detail.visit.url;
      const path = typeof url === 'string' ? new URL(url, window.location.origin).pathname : url.pathname;
      if (path === '/power-saver') setPageLoading(true);
    });
    const offFinish = router.on('finish', () => setPageLoading(false));
    return () => { offStart(); offFinish(); };
  }, []);

  const save = async (body: Record<string, unknown>): Promise<SaveFailure | null> => {
    const res = await requestJson<{ data?: SavedRow[] }>('PATCH', '/power-saver', { thresholds: [body] });
    if (res.ok) return null;
    const kind = saveFailureKind(res.status, res.data.errors !== undefined);
    const message = kind === 'validation'
      ? firstError(res.data.errors, t('ps.error.percent'))
      : (res.data.message ?? t('ps.error.save'));
    return { kind, message };
  };

  return (
    <div className="app">
      <Head title={t('ps.title')} />
      <AppTopBar />
      <main className="ps">
        <header className="ps-header">
          <h1>{t('ps.title')}</h1>
          <p className="ps-summary">{t('ps.summary')}</p>
        </header>

        <section className="ps-note" aria-label={t('ps.stop.details')}>
          <p>{semantics.summary}</p>
          <p>{gaps.summary}</p>
          <details>
            <summary>{t('ps.stop.details')}</summary>
            <p className="ps-sub">{semantics.comparisonSummary}</p>
            {gaps.chosenStopOption === null && <p className="ps-sub">{t('ps.stop.undecided')}</p>}
            <ul className="ps-options">
              {gaps.stopOptions.map((option) => (
                <li key={option.id}><span className="ps-tag">{optionLabel(option.status)}</span><span>{option.summary}</span></li>
              ))}
            </ul>
          </details>
        </section>

        <section className="ps-card" aria-labelledby="ps-projects" aria-busy={pageLoading}>
          <h2 id="ps-projects">{t('ps.projects.title')}</h2>
          <p className="ps-sub">{t('ps.projects.sub')}</p>
          {pageLoading ? <Skeleton /> : activeProjects.length === 0 ? (
            <div className="ps-empty">
              <h3>{t('ps.projects.empty.title')}</h3>
              <p>{t('ps.projects.empty.body')}</p>
            </div>
          ) : activeProjects.map((project) => (
            <div className="ps-row" key={project.id}>
              <div>
                <h3 id={`ps-p-${project.id}`}>{project.name}</h3>
                <p className="ps-meta">{project.slug} · {projectAgentLine(project)}</p>
              </div>
              <p className="ps-meta">{project.statusLabel}</p>
              <ThresholdControl name={project.name} value={project.stopPercent} min={min} max={max}
                onCommit={(stop_percent) => save({ subject_type: 'project', project_id: project.id, stop_percent })} />
            </div>
          ))}
        </section>

        <section className="ps-card" aria-labelledby="ps-routines" aria-busy={pageLoading}>
          <h2 id="ps-routines">{t('ps.routines.title')}</h2>
          <p className="ps-sub">{t('ps.routines.sub')}</p>
          {pageLoading ? <Skeleton /> : routines.length === 0 ? (
            <div className="ps-empty">
              <h3>{t('ps.routines.empty.title')}</h3>
              <p>{t('ps.routines.empty.body')}</p>
            </div>
          ) : routines.map((routine) => (
            <div className="ps-row" key={`${routine.agentId}:${routine.routineId}`}>
              <div>
                <h3>{routine.name}</h3>
                <p className="ps-meta">{routine.agentName} · {routine.seenInUsage ? t('ps.routine.seen') : t('ps.routine.unseen')}</p>
              </div>
              <p className="ps-meta">{routine.routineId}</p>
              <ThresholdControl name={`${routine.agentName} ${routine.name}`} value={routine.stopPercent} min={min} max={max}
                onCommit={(stop_percent) => save({ subject_type: 'routine', agent_id: routine.agentId, routine_id: routine.routineId, stop_percent })} />
            </div>
          ))}
        </section>
      </main>
    </div>
  );
}

function Skeleton() {
  return (
    <div aria-live="polite">
      <p className="sr-only">{t('ps.loading')}</p>
      <div className="ps-skel" />
      <div className="ps-skel" />
    </div>
  );
}
