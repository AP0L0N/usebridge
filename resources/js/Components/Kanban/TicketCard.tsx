import type { KeyboardEvent, MouseEvent, PointerEvent, ReactNode } from 'react';
import { Avatar, IcComment, IcMove, LabelChip, ViaLine, WaitAge, personType } from './Bits';
import { IconCal12 } from '../AppIcons';
import { dueLabel, dueSoon, firstName, ticketUrl, type Person, type Ticket } from '../../lib/kanban';
import { t as str } from '../../lib/strings';
import { cardDot } from '../../lib/kanbanCards';
import { RefTemplate, RefText, SLOT } from '../TicketRefs';

type Props = {
  t: Ticket;
  slug: string;
  people: Person[];
  now: string;
  lift?: boolean;
  picked?: boolean;
  /** Simple card: only the status dot and the title (the board header's "Simple" | "Detailed" control). */
  simple?: boolean;
  flash?: boolean;
  onOpen?: (t: Ticket, e: MouseEvent) => void;
  onMove?: (t: Ticket) => void;
  onKeyDown?: (e: KeyboardEvent<HTMLAnchorElement>, t: Ticket) => void;
  onPointerDown?: (e: PointerEvent<HTMLDivElement>, t: Ticket) => void;
};

/** Ticket card (screen 2). The title link stretches over the whole card; the mobile "Move" button sits above it. */
export function TicketCard({ t, slug, people, now, lift, picked, simple, flash, onOpen, onMove, onKeyDown, onPointerDown }: Props) {
  const cls = ['tk', simple && 'simple', t.status === 'done' && 'done', lift && 'lift', picked && 'picked', flash && 'flash'].filter(Boolean).join(' ');
  const link = (label: string) => (
    <a
      className="tkl"
      href={ticketUrl(slug, t.key)}
      draggable={false}
      aria-roledescription="draggable ticket"
      aria-label={label}
      tabIndex={lift ? -1 : undefined}
      onClick={onOpen ? (e) => onOpen(t, e) : undefined}
      onKeyDown={onKeyDown ? (e) => onKeyDown(e, t) : undefined}
    ><RefText text={t.title_refs ?? t.title} inert /></a>
  );
  if (simple) {
    const dot = cardDot(t);
    // Blocked / new activity are announced; the column colour is decorative (the column already says it).
    const state = dot === 'blocked' || dot === 'new' ? str(`board.card.dot.${dot}`) : null;
    return (
      <div className={cls} data-key={t.key} onPointerDown={onPointerDown ? (e) => onPointerDown(e, t) : undefined}>
        <span className={`sdot ${state ? dot : `c-${dot}`}`} role={state ? 'img' : undefined} aria-label={state ?? undefined} aria-hidden={state ? undefined : true} />
        <h3>{link(state ? `${t.key}: ${t.title} (${state})` : `${t.key}: ${t.title}`)}</h3>
        {onMove && (
          <button className="tkmv" type="button" aria-label={str('board.move.aria', { key: t.key })} onClick={() => onMove(t)}><IcMove />{str('board.move')}</button>
        )}
      </div>
    );
  }
  const strips: ReactNode[] = [];
  if (t.status === 'waiting_tilen' && t.ask) {
    strips.push(<div key="ask" className="askb"><span className="ov">{str('board.card.ask')}</span><p><RefText text={t.ask} inert /></p></div>);
  }
  if (t.blocked_reason) strips.push(<p key="blk" className="blk"><span className="pri-dot" aria-hidden="true" /><span><RefTemplate template={str('board.card.blocked', { reason: SLOT })} text={t.blocked_reason} inert /></span></p>);
  if (t.unread_for_me) {
    strips.push(<p key="new" className="unr new"><span className="dotk" aria-hidden="true" />{str('board.card.newReply', { name: firstName(t.latest_unread_author?.name) || 'the PM' })}</p>);
  } else if (t.needs_pm_pickup) {
    strips.push(<p key="pend" className="unr pend">{str('board.card.pickup')}</p>);
  }
  const via = t.latest_comment?.author_type === 'agent' ? t.latest_comment : null;

  return (
    <div className={cls} data-key={t.key} onPointerDown={onPointerDown ? (e) => onPointerDown(e, t) : undefined}>
      {t.labels.length > 0 && <div className="lbs">{t.labels.map((l) => <LabelChip key={l.id} label={l} />)}</div>}
      <h3>{link(`${t.key}: ${t.title}`)}</h3>
      {via && <ViaLine name={firstName(via.author_name) || via.author_id} at={via.created_at} now={now} source={via.source} />}
      {strips}
      <div className="tmeta">
        <span className="id">{t.key}</span>
        {t.waiting_level && <WaitAge level={t.waiting_level} days={t.waiting_age_days ?? 0} />}
        {(t.priority === 'p1' || t.priority === 'p2') && <span className="pri"><span className={`pri-dot ${t.priority}`} aria-hidden="true" />{t.priority.toUpperCase()}</span>}
        {t.due_on && (
          <span className={`due${t.status !== 'done' && dueSoon(t.due_on, now) ? ' soon' : ''}`}><IconCal12 /><span className="sr-only">Due </span>{dueLabel(t.due_on)}</span>
        )}
        {t.comment_count > 0 && <span className="mic"><IcComment />{t.comment_count}<span className="sr-only"> comments</span></span>}
        <Avatar id={t.assignee_id} name={t.assignee_name} type={t.assignee_type ?? personType(t.assignee_id, people)} />
        {onMove && (
          <button className="tkmv" type="button" aria-label={str('board.move.aria', { key: t.key })} onClick={() => onMove(t)}><IcMove />{str('board.move')}</button>
        )}
      </div>
    </div>
  );
}
