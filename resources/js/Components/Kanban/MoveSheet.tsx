import { useRef, useState } from 'react';
import { Sheet, useFocusTrap } from './Bits';
import { WAITING, type Column, type ColumnKey, type Ticket } from '../../lib/kanban';

/** Mobile "Move to" sheet (SPEC §4): six 52px rows, the current one marked, the ticket goes to the top of the target (Waiting on Tilen: the end, oldest first). */
export function MoveSheet({ t, columns, onPick, onClose }: { t: Ticket; columns: Column[]; onPick: (to: ColumnKey) => void; onClose: () => void }) {
  return (
    <Sheet label={`Move ${t.key}`} onClose={onClose}>
      <h3>Move {t.key} to…</h3>
      <p className="sub2">{t.title}</p>
      <ul className="mvl">
        {columns.map((c) => {
          const cur = c.key === t.status;
          return (
            <li key={c.key} aria-current={cur ? 'true' : undefined}>
              <button type="button" onClick={() => (cur ? onClose() : onPick(c.key))}>
                <span className={`dot c-${c.key}`} aria-hidden="true" />{c.label}
                {cur ? <span className="cur">Current</span> : <span className="pos">{c.key === WAITING ? 'Goes to the end, sorted oldest first' : 'Top of column'}</span>}
              </button>
            </li>
          );
        })}
      </ul>
    </Sheet>
  );
}

/** Moving into Waiting on Tilen from the UI needs an ask (SPEC §4). */
export function AskPrompt({ t, onSubmit, onCancel }: { t: Ticket; onSubmit: (ask: string) => void; onCancel: () => void }) {
  const ref = useRef<HTMLDivElement>(null);
  const input = useRef<HTMLTextAreaElement>(null);
  const [ask, setAsk] = useState('');
  const [err, setErr] = useState(false);
  useFocusTrap(ref, onCancel, input);
  const submit = () => (ask.trim() ? onSubmit(ask.trim()) : setErr(true));
  return (
    <div className="ovl askov" onPointerDown={(e) => { if (e.target === e.currentTarget) onCancel(); }}>
      <div ref={ref} className="pop askbox" role="dialog" aria-modal="true" aria-labelledby="ask-h">
        <h3 id="ask-h">Move {t.key} to Waiting on Tilen</h3>
        <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
          <div className="other">
            <label className="flabel" htmlFor="ask-in">What do you need from Tilen?</label>
            <textarea ref={input} id="ask-in" className="qta sm" maxLength={280} aria-required="true" aria-invalid={err} aria-describedby={err ? 'ask-err' : undefined}
              value={ask} onChange={(e) => { setAsk(e.target.value); setErr(false); }}
              onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); } }} />
            {err && <p className="err-line" id="ask-err" role="alert">Write the ask: a ticket in Waiting on Tilen always has one.</p>}
          </div>
          <div className="qact">
            <button className="btn" type="submit">Move</button>
            <button className="abtn" type="button" onClick={onCancel}>Cancel</button>
          </div>
        </form>
      </div>
    </div>
  );
}
