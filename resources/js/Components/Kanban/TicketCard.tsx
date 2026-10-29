import type { KeyboardEvent, MouseEvent, PointerEvent, ReactNode } from 'react';
import { Avatar, IcBlock, IcCal, IcComment, IcFlag, IcMove, IcReply, LabelChip, WaitAge, personType } from './Bits';
import { dueLabel, dueSoon, firstName, ticketUrl, type Person, type Ticket } from '../../lib/kanban';

type Props = {
  t: Ticket;
  slug: string;
  people: Person[];
  now: string;
  lift?: boolean;
  picked?: boolean;
  flash?: boolean;
  onOpen?: (t: Ticket, e: MouseEvent) => void;
  onMove?: (t: Ticket) => void;
  onKeyDown?: (e: KeyboardEvent<HTMLAnchorElement>, t: Ticket) => void;
  onPointerDown?: (e: PointerEvent<HTMLDivElement>, t: Ticket) => void;
};

/** Ticket card (SPEC §2). The title link stretches over the whole card; the mobile "→ Move" button sits above it. */
export function TicketCard({ t, slug, people, now, lift, picked, flash, onOpen, onMove, onKeyDown, onPointerDown }: Props) {
  const cls = ['tk', t.status === 'done' && 'done', lift && 'lift', picked && 'picked', flash && 'flash'].filter(Boolean).join(' ');
  const strips: ReactNode[] = [];
  if (t.status === 'waiting_tilen' && t.ask) strips.push(<p key="ask" className="ask"><b>Ask:</b> {t.ask}</p>);
  if (t.blocked_reason) strips.push(<p key="blk" className="blk"><IcBlock /><span>Blocked · {t.blocked_reason}</span></p>);
  if (t.unread_for_tilen) {
    strips.push(<p key="new" className="unr new"><i aria-hidden="true" />New reply from {firstName(t.latest_unread_author?.name) || 'the PM'}</p>);
  } else if (t.needs_pm_pickup) {
    strips.push(<p key="pend" className="unr pend"><IcReply />Your input · not picked up yet</p>);
  }

  return (
    <div className={cls} data-key={t.key} onPointerDown={onPointerDown ? (e) => onPointerDown(e, t) : undefined}>
      {t.labels.length > 0 && <div className="lbs">{t.labels.map((l) => <LabelChip key={l.id} label={l} />)}</div>}
      <h3>
        <a
          className="tkl"
          href={ticketUrl(slug, t.key)}
          draggable={false}
          aria-roledescription="draggable ticket"
          aria-label={`${t.key}: ${t.title}`}
          tabIndex={lift ? -1 : undefined}
          onClick={onOpen ? (e) => onOpen(t, e) : undefined}
          onKeyDown={onKeyDown ? (e) => onKeyDown(e, t) : undefined}
        >{t.title}</a>
      </h3>
      {strips}
      <div className="tmeta">
        <span className="id">{t.key}</span>
        {t.waiting_level && <WaitAge level={t.waiting_level} days={t.waiting_age_days ?? 0} />}
        {(t.priority === 'p1' || t.priority === 'p2') && <span className={`pri ${t.priority}`}><IcFlag />{t.priority.toUpperCase()}</span>}
        {t.due_on && (
          <span className={`due${t.status !== 'done' && dueSoon(t.due_on, now) ? ' soon' : ''}`}><IcCal /><span className="sr-only">Due </span>{dueLabel(t.due_on)}</span>
        )}
        {t.comment_count > 0 && <span className="ic"><IcComment />{t.comment_count}<span className="sr-only"> comments</span></span>}
        <Avatar id={t.assignee_id} name={t.assignee_name} type={t.assignee_type ?? personType(t.assignee_id, people)} />
        {onMove && (
          <button className="tkmv" type="button" aria-label={`Move ${t.key} to another column`} onClick={() => onMove(t)}><IcMove />Move</button>
        )}
      </div>
    </div>
  );
}
