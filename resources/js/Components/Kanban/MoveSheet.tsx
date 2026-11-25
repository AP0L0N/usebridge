import { Sheet } from './Bits';
import { IconChev } from '../AppIcons';
import { type Column, type ColumnKey, type Ticket } from '../../lib/kanban';
import { t } from '../../lib/strings';

/**
 * Mobile "Move to" sheet (screen 2): one row per column (the current one marked), a chevron hint on the rest, and a
 * Cancel button. Column labels come from the board (the defaults or the project's own label), never hard-coded.
 */
export function MoveSheet({ ticket, columns, onPick, onClose }: { ticket: Ticket; columns: Column[]; onPick: (to: ColumnKey) => void; onClose: () => void }) {
  const pick = (to: ColumnKey) => {
    if (to === ticket.status) return onClose();
    onPick(to);
  };
  return (
    <Sheet label={t('move.title', { key: ticket.key })} onClose={onClose}>
      <h3>Move <span className="mono">{ticket.key}</span></h3>
      <p className="sub2">{ticket.title}</p>
      <ul className="mvl">
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
    </Sheet>
  );
}
