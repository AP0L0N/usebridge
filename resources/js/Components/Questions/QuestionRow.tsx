import { forwardRef } from 'react';
import { longDate, shortDay, updateTime } from '../../lib/dates';
import { firstName } from '../../lib/kanban';
import { answerReadBack, type Question } from '../../lib/questions';
import { t } from '../../lib/strings-questions';

export type RowKind = 'awaiting' | 'closed' | 'dismissed';

const ICON: Record<RowKind, string> = { awaiting: '↗', closed: '✓', dismissed: '–' };

/**
 * r4 QuestionRow (compact): awaiting PM pickup (answer read back, "✓ Sent …", Change answer), closed (read-only)
 * or dismissed (Restore). Focusable (tabIndex -1) so the panel can move focus here after a send.
 */
export const QuestionRow = forwardRef<HTMLDivElement, {
  q: Question;
  kind: RowKind;
  now: string;
  /** Announce the "✓ Sent" line (the row the user just sent). */
  announce?: boolean;
  busy?: boolean;
  onDetails: (key: string) => void;
  onChange?: () => void;
  onRestore?: () => void;
}>(function QuestionRow({ q, kind, now, announce = false, busy = false, onDetails, onChange, onRestore }, ref) {
  const asker = firstName(q.asked_by.name) || q.asked_by.role || '';
  const answer = answerReadBack(q);
  const day = (iso: string | null) => (iso ? shortDay(iso, now) || longDate(iso) : '');
  let sub: string;
  if (kind === 'awaiting') sub = t('q.row.answered', { x: answer });
  else if (kind === 'dismissed') sub = t('q.row.dismissed', { date: day(q.dismissed_at) });
  else if (!answer) sub = t('q.row.closedOnly', { date: day(q.closed_at) });
  else {
    sub = [
      t('q.row.answered', { x: answer }),
      q.picked_up_at ? t('q.row.pickedUp', { agent: asker }) : null,
      q.closed_at ? t('q.row.closed', { date: day(q.closed_at) }) : null,
    ].filter(Boolean).join(' · ');
  }

  return (
    <div ref={ref} tabIndex={-1} className={`qp-row ${kind}`} aria-labelledby={`qr-${q.id}`}>
      <span className="qp-ico" aria-hidden="true">{ICON[kind]}</span>
      <div className="qp-rtxt">
        <p className="qp-rq" id={`qr-${q.id}`}>{q.text}</p>
        <p className="qp-rsub">{sub}</p>
        {kind === 'awaiting' && (
          <p className="qp-sent" role={announce ? 'status' : undefined}>{t('q.sent', { time: updateTime(q.answered_at, now), asker })}</p>
        )}
      </div>
      <div className="qp-racts">
        <span className="lb qp-key">{q.ticket_key}</span>
        {kind === 'dismissed'
          ? <button type="button" className="gbtn" onClick={onRestore} disabled={busy}>{t('q.restore')}</button>
          : <button type="button" className="gbtn" onClick={() => onDetails(q.ticket_key)}>{t('q.details')}</button>}
        {kind === 'awaiting' && <button type="button" className="abtn" onClick={onChange}>{t('q.change')}</button>}
      </div>
    </div>
  );
});
