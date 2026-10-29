import { useCallback, useRef, useState } from 'react';
import { IcHist, PlainBadge, PmBadge, Sheet, useDismiss } from './Bits';
import { updateTime } from '../../lib/dates';
import { ApiError, STATUS_LABEL, firstName, send, type BoardProject, type PmStatus, type StatusEvent } from '../../lib/kanban';

/** "Janice (PM)", "Tilen", "Captain William Diego (CEO)". */
export function setByLabel(by: string | null, name: string | null, pmId: string | null): string {
  if (!by) return '';
  if (by === 'tilen') return 'Tilen';
  return by === pmId ? `${firstName(name)} (PM)` : name ?? by;
}

/** The PM status waiting level counts from status_set_at (SPEC §2): <3 d L1, 3–6 L2, >6 L3. */
export function pmAge(p: BoardProject, now: string): { level: number; days: number } | null {
  if (p.status !== 'waiting' || !p.status_set_at) return null;
  const days = Math.max(0, Math.floor((Date.parse(now) - Date.parse(p.status_set_at)) / 86400000));
  return { level: days > 6 ? 3 : days >= 3 ? 2 : 1, days };
}

export function HistoryList({ events, pmId, now }: { events: StatusEvent[]; pmId: string | null; now: string }) {
  if (!events.length) return <p className="hint">No status changes yet.</p>;
  return (
    <ol className="shist">
      {events.map((e) => (
        <li key={e.id}>
          <PlainBadge status={e.to_status} />
          <p className="n">{e.note ? `“${e.note}”` : <span className="dim2">No note</span>}</p>
          <p>{setByLabel(e.set_by, e.set_by_name, pmId)} · {updateTime(e.created_at, now)} · {e.source === 'api' ? 'via API' : 'via Bridge'}</p>
        </li>
      ))}
    </ol>
  );
}

type Props = {
  project: BoardProject;
  history: StatusEvent[];
  now: string;
  mobile: boolean;
  historyOpen: boolean;
  setHistoryOpen: (v: boolean) => void;
  onChanged: () => void;
};

export function StatusBlock({ project: p, history, now, mobile, historyOpen, setHistoryOpen, onChanged }: Props) {
  const age = pmAge(p, now);
  const [menu, setMenu] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const histRef = useRef<HTMLDivElement>(null);
  const closeMenu = useCallback(() => setMenu(false), []);
  const closeHist = useCallback(() => setHistoryOpen(false), [setHistoryOpen]);
  useDismiss(menuRef, menu && !mobile, closeMenu);
  useDismiss(histRef, historyOpen && !mobile, closeHist);

  const badge = (
    <button type="button" className="bbtn" aria-haspopup="dialog" aria-expanded={menu}
      aria-label={`Project status: ${p.status ? STATUS_LABEL[p.status] : 'Status not set'}. Change status`} onClick={() => setMenu((v) => !v)}>
      <PmBadge status={p.status} level={age?.level} ageDays={age?.days} />
    </button>
  );

  return (
    <div className="pstat">
      <div className="pwrap" ref={menuRef}>
        {badge}
        {menu && !mobile && <div className="pop menu smenu"><StatusForm project={p} onDone={() => { setMenu(false); onChanged(); }} /></div>}
      </div>
      {menu && mobile && <Sheet label="Set project status" onClose={closeMenu}><div className="sheetb"><StatusForm project={p} onDone={() => { setMenu(false); onChanged(); }} /></div></Sheet>}
      <div className="txt">
        {p.status && p.status_set_at ? (
          <>
            <p className="sset">Set by <b>{p.status_set_by === p.pm_agent_id ? p.status_set_by_name : setByLabel(p.status_set_by, p.status_set_by_name, p.pm_agent_id)}</b>{p.status_set_by === p.pm_agent_id ? ' (PM)' : ''} · <time dateTime={p.status_set_at}>{updateTime(p.status_set_at, now)}</time></p>
            {p.status_note && <p className="snote">{p.status_note}</p>}
          </>
        ) : <p className="sset">The PM hasn’t set a status yet</p>}
      </div>
      <div className="pwrap hist" ref={histRef}>
        <button className="lnk" type="button" aria-haspopup="dialog" aria-expanded={historyOpen} onClick={() => setHistoryOpen(!historyOpen)}><IcHist />History</button>
        {historyOpen && !mobile && (
          <div className="pop hpop" role="dialog" aria-label="Status history">
            <h3>Status history</h3>
            <HistoryList events={history} pmId={p.pm_agent_id} now={now} />
          </div>
        )}
      </div>
      {historyOpen && mobile && (
        <Sheet label="Status history" onClose={closeHist}><h3>Status history</h3><div className="sheetb"><HistoryList events={history} pmId={p.pm_agent_id} now={now} /></div></Sheet>
      )}
    </div>
  );
}

/** Tilen's override: logged as set_by=tilen, source=ui (PATCH /projects/{slug}/status). */
function StatusForm({ project: p, onDone }: { project: BoardProject; onDone: () => void }) {
  const [status, setStatus] = useState<PmStatus>(p.status ?? 'smooth');
  const [note, setNote] = useState(p.status_note ?? '');
  const [state, setState] = useState<'idle' | 'busy' | 'err'>('idle');
  const [err, setErr] = useState('');
  const save = async () => {
    setState('busy');
    try {
      await send('PATCH', `/projects/${p.slug}/status`, { status, note: note.trim() || null });
      onDone();
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Couldn’t save.');
      setState('err');
    }
  };
  return (
    <form onSubmit={(e) => { e.preventDefault(); save(); }}>
      <fieldset className="sfs">
        <legend>Set project status</legend>
        <div className="opts col1">
          {(['smooth', 'waiting', 'archived'] as PmStatus[]).map((s) => (
            <label key={s} className="opt"><input type="radio" name="pst" checked={status === s} onChange={() => setStatus(s)} />{STATUS_LABEL[s]}</label>
          ))}
        </div>
      </fieldset>
      <div className="other">
        <label className="flabel" htmlFor="pst-note">Note (optional)</label>
        <textarea id="pst-note" className="qta sm" maxLength={280} value={note} onChange={(e) => setNote(e.target.value)} />
      </div>
      <div className="qact">
        <button className="btn" type="submit" disabled={state === 'busy'}>{state === 'busy' ? <><span className="spin" />Saving…</> : 'Save status'}</button>
        <p className="hint">Logged as set by you. The PM sees it.</p>
      </div>
      {state === 'err' && <p className="err-line" role="alert">✕ {err} Try again.</p>}
    </form>
  );
}
