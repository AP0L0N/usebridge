import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { IcBack, Sheet, useFocusTrap, useScrimDismiss } from './Bits';
import { WAITING, type Column, type ColumnKey, type Ticket } from '../../lib/kanban';

const ASK_MAX = 280;

/**
 * Mobile "Move to" sheet (SPEC §4): six 52px rows, the current one marked, the ticket goes to the top of the target
 * (the waiting column: the end, oldest first). Moving into the waiting column without an ask turns the sheet into the
 * ask step, with a back arrow to the column list (Tommy review c1). Its name comes from the board's columns (workspace
 * default or the project's own label), never hard-coded.
 */
export function MoveSheet({ t, columns, onPick, onClose }: { t: Ticket; columns: Column[]; onPick: (to: ColumnKey, ask?: string) => void; onClose: () => void }) {
  const [step, setStep] = useState<'list' | 'ask'>('list');
  const listRef = useRef<HTMLUListElement>(null);
  const askRef = useRef<HTMLTextAreaElement>(null);
  const first = useRef(true);
  useEffect(() => {
    if (first.current) { first.current = false; return; }
    if (step === 'ask') askRef.current?.focus({ preventScroll: true });
    else listRef.current?.querySelector<HTMLElement>(`[data-col="${WAITING}"]`)?.focus({ preventScroll: true });
  }, [step]);
  const waiting = columns.find((c) => c.key === WAITING)?.label ?? '';
  const pick = (to: ColumnKey) => {
    if (to === t.status) return onClose();
    if (to === WAITING && !t.ask) return setStep('ask');
    onPick(to);
  };
  return (
    <Sheet label={step === 'ask' ? `Move ${t.key} to ${waiting}` : `Move ${t.key}`} onClose={onClose} className={step === 'ask' ? 'askstep' : ''}>
      {step === 'list' ? (
        <>
          <h3>Move {t.key} to…</h3>
          <p className="sub2">{t.title}</p>
          <ul className="mvl" ref={listRef}>
            {columns.map((c) => {
              const cur = c.key === t.status;
              return (
                <li key={c.key} aria-current={cur ? 'true' : undefined}>
                  <button type="button" data-col={c.key} onClick={() => pick(c.key)}>
                    <span className={`dot c-${c.key}`} aria-hidden="true" />{c.label}
                    {cur ? <span className="cur">Current</span> : <span className="pos">{c.key === WAITING ? 'Goes to the end, sorted oldest first' : 'Top of column'}</span>}
                  </button>
                </li>
              );
            })}
          </ul>
        </>
      ) : (
        <div className="askw">
          <div className="askh">
            <button type="button" className="ibtn" aria-label="Back to the column list" onClick={() => setStep('list')}><IcBack /></button>
            <div>
              <h3 id="ask-h">Move {t.key} to {waiting}</h3>
              <p className="asub">{t.title}</p>
            </div>
          </div>
          <AskForm column={waiting} input={askRef} onSubmit={(ask) => onPick(WAITING, ask)} onCancel={onClose} stacked />
        </div>
      )}
    </Sheet>
  );
}

/** The ask field shared by the desktop dialog and the mobile sheet step (Tommy review c2, c3, c5). */
function AskForm({ column, input, onSubmit, onCancel, stacked = false }: { column: string; input: RefObject<HTMLTextAreaElement | null>; onSubmit: (ask: string) => void; onCancel: () => void; stacked?: boolean }) {
  const [ask, setAsk] = useState('');
  const [err, setErr] = useState(false);
  const submit = () => {
    if (ask.trim()) return onSubmit(ask.trim());
    setErr(true);
    input.current?.focus();
  };
  return (
    <form className="askf" onSubmit={(e) => { e.preventDefault(); submit(); }} noValidate>
      <div className="other">
        <label className="flabel" htmlFor="ask-in">What’s the ask?</label>
        <p className="hint" id="ask-hint">Shown on the card and in Your input.</p>
        <textarea ref={input} id="ask-in" className={`qta sm${err ? ' bad' : ''}`} maxLength={ASK_MAX} required aria-required="true" aria-invalid={err}
          aria-describedby={err ? 'ask-err ask-hint' : 'ask-hint ask-keys'}
          value={ask} onChange={(e) => { setAsk(e.target.value); setErr(false); }}
          onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); } }} />
        <p className="hint count" id="ask-keys">Enter moves it · Shift+Enter for a new line · {ask.length}/{ASK_MAX}</p>
        {err && <p className="err-line" id="ask-err" role="alert">✕ Add the ask. Every ticket in {column} needs one.</p>}
      </div>
      <div className={`qact${stacked ? ' stack1' : ''}`}>
        <button className="btn" type="submit">Move</button>
        <button className="abtn" type="button" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

/**
 * Moving into the waiting column (`column`: its label) from the UI needs an ask (SPEC §4). Desktop: a centred dialog. Mobile (the ticket
 * detail's status select; the Move sheet has its own step): a bottom sheet. `restoreFocus`: where focus goes on close
 * (the dragged card after a desktop drag, c4).
 */
export function AskPrompt({ t, column, mobile, onSubmit, onCancel, restoreFocus }: {
  t: Ticket; column: string; mobile: boolean; onSubmit: (ask: string) => void; onCancel: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const input = useRef<HTMLTextAreaElement>(null);
  if (mobile) {
    return (
      <Sheet label={`Move ${t.key} to ${column}`} onClose={onCancel} initialFocus={input} restoreFocus={restoreFocus} className="askstep">
        <div className="askw">
          <h3 id="ask-h">Move {t.key} to {column}</h3>
          <p className="asub">{t.title}</p>
          <AskForm column={column} input={input} onSubmit={onSubmit} onCancel={onCancel} stacked />
        </div>
      </Sheet>
    );
  }
  return <AskDialog t={t} column={column} input={input} onSubmit={onSubmit} onCancel={onCancel} restoreFocus={restoreFocus} />;
}

function AskDialog({ t, column, input, onSubmit, onCancel, restoreFocus }: {
  t: Ticket; column: string; input: RefObject<HTMLTextAreaElement | null>; onSubmit: (ask: string) => void; onCancel: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useFocusTrap(ref, onCancel, input, restoreFocus);
  const scrim = useScrimDismiss(ref, onCancel);
  return (
    <div className="ovl askov" {...scrim}>
      <div ref={ref} className="pop askbox" role="dialog" aria-modal="true" aria-labelledby="ask-h">
        <h3 id="ask-h">Move {t.key} to {column}</h3>
        <p className="asub">{t.title}</p>
        <AskForm column={column} input={input} onSubmit={onSubmit} onCancel={onCancel} />
      </div>
    </div>
  );
}
