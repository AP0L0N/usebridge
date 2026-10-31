import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { IcBack, Sheet, useFocusTrap, useScrimDismiss } from './Bits';
import { IconChev } from '../AppIcons';
import { WAITING, type Column, type ColumnKey, type Ticket } from '../../lib/kanban';
import { t } from '../../lib/strings';

const ASK_MAX = 280;

/**
 * Mobile "Move to" sheet (screen 2): one row per column (the current one marked), a chevron hint on the rest, and a
 * Cancel button. Moving into Waiting on you without an ask turns the sheet into the ask step, with a back arrow to
 * the column list (Tommy review c1). Column labels come from the board (per-project names win).
 */
export function MoveSheet({ ticket, columns, onPick, onClose }: { ticket: Ticket; columns: Column[]; onPick: (to: ColumnKey, ask?: string) => void; onClose: () => void }) {
  const [step, setStep] = useState<'list' | 'ask'>('list');
  const listRef = useRef<HTMLUListElement>(null);
  const askRef = useRef<HTMLTextAreaElement>(null);
  const first = useRef(true);
  const waitingLabel = columns.find((c) => c.key === WAITING)?.label ?? 'Waiting on you';
  useEffect(() => {
    if (first.current) { first.current = false; return; }
    if (step === 'ask') askRef.current?.focus({ preventScroll: true });
    else listRef.current?.querySelector<HTMLElement>(`[data-col="${WAITING}"]`)?.focus({ preventScroll: true });
  }, [step]);
  const pick = (to: ColumnKey) => {
    if (to === ticket.status) return onClose();
    if (to === WAITING && !ticket.ask) return setStep('ask');
    onPick(to);
  };
  return (
    <Sheet label={step === 'ask' ? t('move.ask.title', { key: ticket.key, column: waitingLabel }) : t('move.title', { key: ticket.key })} onClose={onClose} className={step === 'ask' ? 'askstep' : ''}>
      {step === 'list' ? (
        <>
          <h3>Move <span className="mono">{ticket.key}</span></h3>
          <p className="sub2">{ticket.title}</p>
          <ul className="mvl" ref={listRef}>
            {columns.map((c) => {
              const cur = c.key === ticket.status;
              return (
                <li key={c.key} aria-current={cur ? 'true' : undefined}>
                  <button type="button" data-col={c.key} onClick={() => pick(c.key)}>
                    <span className={`dotk c-${c.key}`} aria-hidden="true" />{c.label}
                    {cur ? <span className="cur">{t('move.current')}</span> : <IconChev />}
                  </button>
                </li>
              );
            })}
          </ul>
          <div className="sheetb mtop"><button type="button" className="abtn wfull" onClick={onClose}>{t('move.cancel')}</button></div>
        </>
      ) : (
        <div className="askw">
          <div className="askh">
            <button type="button" className="ibtn" aria-label={t('move.ask.back')} onClick={() => setStep('list')}><IcBack /></button>
            <div>
              <h3 id="ask-h">{t('move.ask.title', { key: ticket.key, column: waitingLabel })}</h3>
              <p className="asub">{ticket.title}</p>
            </div>
          </div>
          <AskForm input={askRef} onSubmit={(ask) => onPick(WAITING, ask)} onCancel={onClose} stacked />
        </div>
      )}
    </Sheet>
  );
}

/** The ask field shared by the desktop dialog and the mobile sheet step (Tommy review c2, c3, c5). */
export function AskForm({ input, onSubmit, onCancel, stacked = false, columnLabel }: { input: RefObject<HTMLTextAreaElement | null>; onSubmit: (ask: string) => void; onCancel: () => void; stacked?: boolean; columnLabel?: string }) {
  const [ask, setAsk] = useState('');
  const [err, setErr] = useState(false);
  const label = columnLabel ?? 'Waiting on you';
  const submit = () => {
    if (ask.trim()) return onSubmit(ask.trim());
    setErr(true);
    input.current?.focus();
  };
  return (
    <form className="askf" onSubmit={(e) => { e.preventDefault(); submit(); }} noValidate>
      <div className="other">
        <label className="flabel" htmlFor="ask-in">{t('move.ask.label')}</label>
        <p className="hint" id="ask-hint">{t('move.ask.hint')}</p>
        <div className="qwrap">
          <textarea ref={input} id="ask-in" className={`qta${err ? ' bad' : ''}`} maxLength={ASK_MAX} required aria-required="true" aria-invalid={err}
            aria-describedby={err ? 'ask-err ask-hint' : 'ask-hint ask-keys'}
            value={ask} onChange={(e) => { setAsk(e.target.value); setErr(false); }}
            onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); } }} />
        </div>
        <p className="hint count" id="ask-keys">{t('move.ask.count', { n: ask.length, max: ASK_MAX })}</p>
        {err && <p className="err-line" id="ask-err" role="alert">{t('move.ask.err', { column: label })}</p>}
      </div>
      <div className={`qact${stacked ? ' stack1' : ''}`}>
        <button className="btn" type="submit">{t('move.ask.move')}</button>
        <button className="abtn" type="button" onClick={onCancel}>{t('cancel')}</button>
      </div>
    </form>
  );
}

/**
 * Moving into Waiting on you from the UI needs an ask (SPEC §4). Desktop: a centred dialog. Mobile (the ticket
 * drawer's status select; the Move sheet has its own step): a bottom sheet. `restoreFocus`: where focus goes on close
 * (the dragged card after a desktop drag, c4).
 */
export function AskPrompt({ ticket, mobile, columns, onSubmit, onCancel, restoreFocus }: {
  ticket: Ticket; mobile: boolean; columns: Column[]; onSubmit: (ask: string) => void; onCancel: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const input = useRef<HTMLTextAreaElement>(null);
  const waitingLabel = columns.find((c) => c.key === WAITING)?.label ?? 'Waiting on you';
  if (mobile) {
    return (
      <Sheet label={t('move.ask.title', { key: ticket.key, column: waitingLabel })} onClose={onCancel} initialFocus={input} restoreFocus={restoreFocus} className="askstep">
        <div className="askw">
          <h3 id="ask-h">{t('move.ask.title', { key: ticket.key, column: waitingLabel })}</h3>
          <p className="asub">{ticket.title}</p>
          <AskForm input={input} onSubmit={onSubmit} onCancel={onCancel} stacked columnLabel={waitingLabel} />
        </div>
      </Sheet>
    );
  }
  return <AskDialog ticket={ticket} input={input} columns={columns} onSubmit={onSubmit} onCancel={onCancel} restoreFocus={restoreFocus} />;
}

function AskDialog({ ticket, input, columns, onSubmit, onCancel, restoreFocus }: {
  ticket: Ticket; input: RefObject<HTMLTextAreaElement | null>; columns: Column[]; onSubmit: (ask: string) => void; onCancel: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useFocusTrap(ref, onCancel, input, restoreFocus);
  const scrim = useScrimDismiss(ref, onCancel);
  const waitingLabel = columns.find((c) => c.key === WAITING)?.label ?? 'Waiting on you';
  return (
    <div className="ovl askov" {...scrim}>
      <div ref={ref} className="askbox" role="dialog" aria-modal="true" aria-labelledby="ask-h">
        <h3 id="ask-h">{t('move.ask.title', { key: ticket.key, column: waitingLabel })}</h3>
        <p className="asub">{ticket.title}</p>
        <AskForm input={input} onSubmit={onSubmit} onCancel={onCancel} columnLabel={waitingLabel} />
      </div>
    </div>
  );
}
