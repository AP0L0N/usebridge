import { useCallback, useEffect, useRef, useState } from 'react';
import { ConfirmSheet, IcChev, IcHist, PlainBadge, PmBadge, Sheet, refocus, useDismiss } from './Bits';
import { updateTime } from '../../lib/dates';
import { STATUS_LABEL, archiveMessage, firstName, send, type PmStatus, type StatusEvent, type StatusSubject } from '../../lib/kanban';

/** "Janice (PM)", "Tilen", "Captain William Diego (CEO)". */
export function setByLabel(by: string | null, name: string | null, pmId: string | null): string {
  if (!by) return '';
  if (by === 'tilen') return 'Tilen';
  return by === pmId ? `${firstName(name)} (PM)` : name ?? by;
}

/** The PM status waiting level counts from status_set_at (SPEC §2): <3 d L1, 3–6 L2, >6 L3. */
export function pmAge(p: Pick<StatusSubject, 'status' | 'status_set_at'>, now: string): { level: number; days: number } | null {
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
  project: StatusSubject;
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
  const btnRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const histRef = useRef<HTMLDivElement>(null);
  // Esc, outside click and Cancel all hand focus back to the badge button (Tommy review a1).
  const closeMenu = useCallback(() => { setMenu(false); refocus(btnRef.current); }, []);
  const closeHist = useCallback(() => setHistoryOpen(false), [setHistoryOpen]);
  useDismiss([btnRef, menuRef], menu && !mobile, closeMenu);
  useDismiss(histRef, historyOpen && !mobile, closeHist);
  const saved = () => { closeMenu(); onChanged(); };
  const archived = p.archived || p.status === 'archived';
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState<'idle' | 'busy' | 'err'>('idle');
  const run = async (action: 'archive' | 'unarchive') => {
    setBusy('busy');
    try {
      await send('POST', `/projects/${p.slug}/${action}`);
      setBusy('idle'); setConfirm(false); onChanged();
    } catch {
      setBusy('err');
    }
  };

  return (
    <div className="pstat">
      {archived ? (
        <>
          <div className="pwrap"><PmBadge status="archived" /></div>
          <button type="button" className="btn" disabled={busy === 'busy'} onClick={() => run('unarchive')}>{busy === 'busy' ? <><span className="spin" />Unarchiving…</> : 'Unarchive'}</button>
          {busy === 'err' && <p className="err-line" role="alert">✕ Couldn’t unarchive. Try again.</p>}
        </>
      ) : (
      <div className="pwrap">
        <button ref={btnRef} type="button" className="bbtn" aria-haspopup="dialog" aria-expanded={menu}
          aria-label={`Project status: ${p.status ? STATUS_LABEL[p.status] : 'Status not set'}. Change status`} onClick={() => (menu ? closeMenu() : setMenu(true))}>
          <PmBadge status={p.status} level={age?.level} ageDays={age?.days} /><IcChev />
        </button>
      </div>
      )}
      {menu && !mobile && (
        <div ref={menuRef} className="pop menu smenu" role="dialog" aria-label="Set project status">
          <StatusForm project={p} onDone={saved} onCancel={closeMenu} onArchive={() => { closeMenu(); setConfirm(true); }} />
        </div>
      )}
      {menu && mobile && (
        <Sheet label="Set project status" onClose={() => setMenu(false)} initialFocus={checkedRadio} restoreFocus={() => btnRef.current}>
          <div className="sheetb"><StatusForm project={p} onDone={saved} onCancel={closeMenu} onArchive={() => { setMenu(false); setConfirm(true); }} /></div>
        </Sheet>
      )}
      {confirm && (
        <ConfirmSheet message={archiveMessage(p.name)} confirmLabel="Archive" busy={busy === 'busy'} error={busy === 'err' ? 'Couldn’t archive. Try again.' : null}
          onConfirm={() => run('archive')} onCancel={() => { setConfirm(false); setBusy('idle'); }} restoreFocus={() => btnRef.current} />
      )}
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

/** The sheet's initial focus: the checked radio (a1). The desktop form focuses it itself. */
const checkedRadio = { get current() { return firstRadio(document.querySelector('.sheet')); } };
const firstRadio = (root: ParentNode | null) => root?.querySelector<HTMLElement>('input[name="pst"]:checked') ?? root?.querySelector<HTMLElement>('input[name="pst"]') ?? null;

const NOTE_MAX = 280;

/** "Janice's note", "Your note" (Tilen set it), "William's note". */
function noteOwner(p: StatusSubject): string {
  if (p.status_set_by === 'tilen') return 'Your note';
  const who = firstName(p.status_set_by === p.pm_agent_id ? (p.pm_name ?? p.status_set_by_name) : p.status_set_by_name);
  return who ? `${who}’s note` : 'Current note';
}

/** Tilen's override: logged as set_by=tilen, source=ui (PATCH /projects/{slug}/status). */
export function StatusForm({ project: p, onDone, onCancel, onArchive }: { project: StatusSubject; onDone: () => void; onCancel: () => void; onArchive: () => void }) {
  const [status, setStatus] = useState<PmStatus | null>(p.status);
  const [note, setNote] = useState('');
  const [state, setState] = useState<'idle' | 'busy' | 'err'>('idle');
  const formRef = useRef<HTMLFormElement>(null);
  const dirty = status !== null && (status !== p.status || note.trim() !== '');
  const pm = firstName(p.pm_name);
  useEffect(() => {
    // Desktop popover: focus the checked radio (or the first one when no status is set yet). The sheet does it via initialFocus.
    if (formRef.current?.closest('.sheet')) return;
    firstRadio(formRef.current)?.focus({ preventScroll: true });
  }, []);
  const save = async () => {
    if (!dirty || !status) return;
    setState('busy');
    try {
      await send('PATCH', `/projects/${p.slug}/status`, { status, note: note.trim() || null });
      onDone();
    } catch {
      setState('err');
    }
  };
  return (
    <form ref={formRef} className="sform" onSubmit={(e) => { e.preventDefault(); save(); }}>
      <fieldset className="sfs">
        <legend>Set project status</legend>
        <div className="opts col1">
          {(['smooth', 'waiting'] as PmStatus[]).map((s) => (
            <label key={s} className="opt">
              <input type="radio" name="pst" checked={status === s} onChange={() => setStatus(s)} />
              {STATUS_LABEL[s]}
            </label>
          ))}
        </div>
      </fieldset>
      <div className="other">
        <label className="flabel" htmlFor="pst-note">Note (optional)</label>
        {p.status_note && <p className="hint cur">{noteOwner(p)}: “{p.status_note}”</p>}
        <textarea id="pst-note" className="qta sm" maxLength={NOTE_MAX} placeholder="Why? (optional)" value={note}
          aria-describedby="pst-count" onChange={(e) => setNote(e.target.value)} />
        <p className="hint count" id="pst-count">{note.length}/{NOTE_MAX}</p>
      </div>
      <div className="qact">
        <button className="btn" type="submit" disabled={!dirty || state === 'busy'}>{state === 'busy' ? <><span className="spin" />Saving…</> : 'Save status'}</button>
        <button className="abtn" type="button" onClick={onCancel}>Cancel</button>
      </div>
      <div className="arow"><button className="lnk dng" type="button" onClick={onArchive}>Archive project</button></div>
      <p className="hint">Saved as set by you. {pm ? `${pm} sees` : 'The PM sees'} it in History.</p>
      {state === 'err' && <p className="err-line" role="alert">✕ Couldn’t save the status. Your note is kept.</p>}
    </form>
  );
}
