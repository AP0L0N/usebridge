import { Fragment, createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MarkdownBody } from '../MarkdownBody';
import {
  Avatar, IcBack, IcClock, IcEdit, IcMore, IcOk, IcX, LabelChip, Sheet, WaitAge,
  personType, useDismiss, useFocusTrap, useScrimDismiss, useToasts,
} from './Bits';
import { IconCal16, IconChev } from '../AppIcons';
import { ago, updateTime } from '../../lib/dates';
import { t as tr } from '../../lib/strings';
import {
  ApiError, PRIORITY_LABEL, WAITING, dueLabel, dueSoon, firstName, roleTag, send, ticketUrl, useMe, viaLabel,
  type BoardProject, type Column, type ColumnKey, type Comment, type Label, type Person, type Priority, type Ticket,
  type TicketDetail as Detail, type TicketEvent,
} from '../../lib/kanban';

type Props = {
  detail: Detail | null;
  fallback: Ticket;
  project: BoardProject;
  people: Person[];
  labels: Label[];
  columns: Column[];
  now: string;
  mobile: boolean;
  onClose: () => void;
  onStatus: (t: Ticket, to: ColumnKey) => void;
  onChanged: () => void;
  onArchived: (t: Ticket) => void;
};

const COLNAME = (cols: Column[], k: unknown) => cols.find((c) => c.key === k)?.label ?? String(k ?? '');

/** One-line description of a ticket event (side History and inline thread events). */
function eventText(e: TicketEvent, cols: Column[], people: Person[]): string | null {
  const to = e.to ?? {}, from = e.from ?? {};
  const who = (id: unknown) => (id ? people.find((p) => p.id === id)?.name ?? String(id) : 'nobody');
  switch (e.type) {
    case 'created': return 'Created';
    case 'moved': return from.status === to.status ? `Reordered in ${COLNAME(cols, to.status)}` : `Moved ${COLNAME(cols, from.status)} → ${COLNAME(cols, to.status)}`;
    case 'assigned': return to.id ? `Assigned to ${who(to.id)}` : 'Unassigned';
    case 'priority': return `Priority set to ${String(to.priority ?? 'p3').toUpperCase()}`;
    case 'due': return to.due_on ? `Due set to ${dueLabel(String(to.due_on))}` : 'Due date cleared';
    case 'labelled': return 'Labels changed';
    case 'blocked': return `Blocked · ${String(to.blocked_reason ?? '')}`;
    case 'unblocked': return 'Unblocked';
    case 'ask_set': return 'Ask updated';
    case 'edited': return 'title' in to ? 'Title edited' : 'Description edited';
    case 'input_acked': return 'Your input picked up';
    case 'archived': return 'Archived';
    case 'unarchived': return 'Unarchived';
    default: return null;
  }
}

/** "You" for the signed-in user's own actions, else the actor's first name. */
const actorShort = (e: { actor_id: string | null; actor_name: string | null }, me: string | null) => (e.actor_id !== null && e.actor_id === me ? tr('ticket.role.you') : firstName(e.actor_name) || e.actor_id || '');

/** Native <select> laid over the mock's select look (accessible, works on touch). */
function SelectField({ id, label, value, options, onChange, dot, lead }: {
  id: string; label: string; value: string; options: { value: string; label: string }[]; onChange: (v: string) => void; dot?: string; lead?: ReactNode;
}) {
  const cur = options.find((o) => o.value === value);
  return (
    <span className="selw">
      <span className="l">{lead}{dot && <span className={`dotk c-${dot}`} aria-hidden="true" />}{cur?.label ?? '—'}</span>
      <IconChev />
      <select id={id} aria-label={label} value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </span>
  );
}

/**
 * Unsaved text in the ticket's fields. Fields report their dirty state (`useDirty`); closing the drawer asks first
 * when any field is dirty (the discard dialog names the draft), and an Esc inside a dirty field asks before dropping
 * that field's edit.
 */
type Dirty = { report: (field: string, dirty: boolean) => void; askDiscard: (discard: () => void) => void };
const DirtyCtx = createContext<Dirty>({ report: () => {}, askDiscard: (discard) => discard() });

function useDirty(field: string, dirty: boolean) {
  const { report } = useContext(DirtyCtx);
  useEffect(() => {
    report(field, dirty);
    return () => report(field, false);
  }, [report, field, dirty]);
}

/** Which draft the discard dialog talks about. */
const DISCARD_COPY: Record<string, 'note' | 'comment' | 'desc'> = { reply: 'note', composer: 'comment', description: 'desc', labels: 'desc' };

export function TicketDetail({ detail, fallback, project, people, labels, columns, now, mobile, onClose, onStatus, onChanged, onArchived }: Props) {
  const t: Ticket = detail ?? fallback;
  const ref = useRef<HTMLDivElement>(null);
  const titleRef = useRef<HTMLHeadingElement>(null);
  const toasts = useToasts();
  const me = useMe();
  const [menu, setMenu] = useState(false);
  const [details, setDetails] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const closeMenu = useCallback(() => setMenu(false), []);
  useDismiss(menuRef, menu, closeMenu);

  const dirtyFields = useRef(new Map<string, boolean>());
  const report = useCallback((field: string, dirty: boolean) => { if (dirty) dirtyFields.current.set(field, true); else dirtyFields.current.delete(field); }, []);
  const [ask, setAsk] = useState<{ discard: () => void } | null>(null);
  const lastFocus = useRef<HTMLElement | null>(null); // last focused element inside the drawer: focus returns here after the dialog
  const keepRef = useRef<HTMLButtonElement>(null);
  const askDiscard = useCallback((discard: () => void) => setAsk({ discard }), []);
  const requestClose = useCallback(() => { if (dirtyFields.current.size) askDiscard(onClose); else onClose(); }, [askDiscard, onClose]);
  const dirtyApi = useMemo(() => ({ report, askDiscard }), [report, askDiscard]);
  const keepEditing = () => setAsk(null);
  const discard = () => { const run = ask?.discard; setAsk(null); run?.(); };
  const scrim = useScrimDismiss(ref, requestClose);
  useFocusTrap(ref, requestClose, titleRef);

  // "New" divider: frozen at open, so the seen POST and the 30 s refresh don't move it under your eyes.
  // (Opened from the board, the detail arrives after mount: take the value from the first payload.)
  const [seenAt, setSeenAt] = useState<string | null | undefined>(() => (detail ? detail.last_seen_at : undefined));
  useEffect(() => { if (seenAt === undefined && detail) setSeenAt(detail.last_seen_at); }, [detail, seenAt]);
  const [unreadAtOpen] = useState(() => fallback.unread_for_me);

  const pm = project.pm_name;
  const pmFirst = firstName(pm) || 'the PM';

  const patch = async (body: Record<string, unknown>) => {
    try {
      await send('PATCH', `/projects/${project.slug}/tickets/${t.key}`, body);
      onChanged();
    } catch (e) {
      toasts.push({ tone: 'err', body: <p>Couldn’t save {t.key}. {e instanceof ApiError ? e.message : 'Try again.'}</p> });
    }
  };

  const copyLink = async () => {
    setMenu(false);
    const url = window.location.origin + ticketUrl(project.slug, t.key);
    try { await navigator.clipboard.writeText(url); toasts.push({ body: <p>{tr('toast.link', { key: t.key })}</p>, ms: 3000 }); }
    catch { toasts.push({ body: <p>Copy this link: {url}</p> }); }
  };
  const archiveTicket = async () => {
    try {
      await send('POST', `/projects/${project.slug}/tickets/${t.key}/archive`);
      onArchived(t);
    } catch (e) {
      toasts.push({ tone: 'err', body: <p>Couldn’t archive {t.key}. {e instanceof ApiError ? e.message : 'Try again.'}</p> });
    }
  };
  // Archiving closes the ticket, so unsaved text asks first, like any other close.
  const archive = () => {
    setMenu(false);
    if (dirtyFields.current.size) askDiscard(archiveTicket); else archiveTicket();
  };

  const statusOptions = columns.map((c) => ({ value: c.key, label: c.label }));
  const assigneeOptions = [{ value: '', label: 'Unassigned' }, ...people.map((p) => ({ value: p.id, label: p.name }))];
  if (t.assignee_id && !people.some((p) => p.id === t.assignee_id)) assigneeOptions.push({ value: t.assignee_id, label: t.assignee_name ?? t.assignee_id });
  const prOptions = (['p1', 'p2', 'p3'] as Priority[]).map((p) => ({ value: p, label: PRIORITY_LABEL[p] }));

  const statusSelect = (id: string, hint: boolean) => (
    <>
      <SelectField id={id} label={`Status: ${columns.find((c) => c.key === t.status)?.label ?? t.status_label}. Move to another column`} value={t.status} options={statusOptions}
        dot={t.status} onChange={(v) => onStatus(t, v as ColumnKey)} />
      {hint && <p className="sm">{tr('ticket.status.hint')}</p>}
    </>
  );

  const events = (detail?.events ?? []).filter((e) => e.type !== 'commented');
  const sideFields = (inSheet: boolean) => (
    <>
      {inSheet && <div className="fld"><p className="k" id="k-st-m">{tr('ticket.status')}</p>{statusSelect('st-sheet', false)}</div>}
      <div className="fld">
        <p className="k"><label htmlFor={`as-${inSheet}`}>{tr('board.filter.assignee')}</label></p>
        <div className="v">
          <Avatar id={t.assignee_id} name={t.assignee_name} type={t.assignee_type ?? personType(t.assignee_id, people)} />
          <SelectField id={`as-${inSheet}`} label={tr('board.filter.assignee')} value={t.assignee_id ?? ''} options={assigneeOptions}
            onChange={(v) => patch(v ? { assignee: v } : { assignee_type: null, assignee_id: null })} />
        </div>
        {t.assignee_id && <p className="sm">{t.assignee_type === 'agent' ? `Agent · ${roleTag(t.assignee_id, project.pm_agent_id, people) || 'Agent'}` : t.assignee_id === me ? tr('ticket.role.you') : 'Person'}</p>}
      </div>
      <div className="fld">
        <p className="k"><label htmlFor={`pr-${inSheet}`}>{tr('board.filter.priority')}</label></p>
        <div className="v">
          <SelectField id={`pr-${inSheet}`} label={tr('board.filter.priority')} value={t.priority} options={prOptions}
            dot={t.priority === 'p3' ? undefined : `pri-${t.priority}`} lead={t.priority === 'p3' ? <span className="pri-dot p3" aria-hidden="true" /> : undefined}
            onChange={(v) => patch({ priority: v })} />
        </div>
      </div>
      <DueField t={t} now={now} id={`due-${inSheet}`} onSave={(v) => patch({ due_on: v })} />
      <LabelsField t={t} labels={labels} onSave={(names) => patch({ labels: names })} />
      <div className="fld">
        <p className="k">{tr('ticket.created')}</p>
        <p className="sm2">{t.created_by_name ?? 'Unknown'} · {viaLabel(detail?.created_source, t.created_by_type === 'agent')} · {updateTime(t.created_at, now)}</p>
        <p className="sm">Updated {ago(t.updated_at, now)}</p>
      </div>
      <HistoryField events={events} columns={columns} people={people} now={now} />
    </>
  );

  const dsum = mobile && (
    <button className="dsum" type="button" aria-haspopup="dialog" onClick={() => setDetails(true)}>
      {tr('ticket.details')}
      <span className={`dotk c-${t.status}`} aria-hidden="true" />
      <span className="sum">{columns.find((c) => c.key === t.status)?.label}{t.assignee_name ? ` · ${t.assignee_name}` : ''}{t.priority !== 'p3' ? ` · ${t.priority.toUpperCase()}` : ''}</span>
      <IconChev />
    </button>
  );

  return (
    <DirtyCtx.Provider value={dirtyApi}>
      <div className="ovl kb-ovl" {...scrim}>
        <div ref={ref} className="kb-drawer" role="dialog" aria-modal="true" aria-labelledby="dlg-t" onFocus={(e) => { lastFocus.current = e.target as HTMLElement; }}>
          <div className="kb-dh">
            <button className="gbtn only-m" type="button" onClick={requestClose}><IcBack />{tr('ticket.back')}</button>
            <span className="kb-crumb only-d">{project.name}&nbsp;/&nbsp;<b>{t.key}</b></span>
            <span className="spacer" />
            <div className="pwrap" ref={menuRef}>
              <button className="ibtn" type="button" aria-label={tr('ticket.menu.aria')} aria-haspopup="menu" aria-expanded={menu} onClick={() => setMenu((v) => !v)}><IcMore /></button>
              {menu && (
                <div className="pop amenu" role="menu">
                  <button type="button" role="menuitem" onClick={copyLink}>{tr('ticket.menu.copy')}</button>
                  <button type="button" role="menuitem" onClick={archive}>{tr('ticket.menu.archive')}</button>
                </div>
              )}
            </div>
            <button className="ibtn only-d" type="button" aria-label={tr('ticket.close')} onClick={requestClose}><IcX /></button>
          </div>
          <div className="kb-db">
            <div className="kb-main">
              {t.labels.length > 0 && <div className="kb-tl"><div className="lbs">{t.labels.map((l) => <LabelChip key={l.id} label={l} />)}</div></div>}
              <div className="kb-tl">
                <h1 id="dlg-t" ref={titleRef} tabIndex={-1}>{t.title}</h1>
              </div>
              {mobile && <div className="mf">{dsum}</div>}
              {t.blocked_reason && <p className="blk"><span className="pri-dot" aria-hidden="true" /><span>{tr('board.card.blocked', { reason: t.blocked_reason })}</span></p>}
              {t.status === WAITING && <InputBox t={t} detail={detail} pm={pm} slug={project.slug} now={now} onSent={onChanged} />}
              <Description t={t} onSave={(d) => patch({ description: d })} />
              <hr className="kb-div" />
              <section aria-labelledby="th-h">
                <h2 className="kb-sect" id="th-h">{tr('ticket.comments')} <span className="cnt">{t.comment_count}</span></h2>
                {detail
                  ? <Thread d={detail} project={project} people={people} columns={columns} now={now} seenAt={seenAt ?? null} unread={unreadAtOpen} />
                  : <p className="hint" role="status">{tr('ticket.comments.loading')}</p>}
                <Composer t={t} slug={project.slug} pmFirst={pmFirst} onSent={onChanged} />
              </section>
            </div>
            {!mobile && <aside className="kb-side" aria-label={tr('ticket.fields')}><div className="flds"><p className="fh">{tr('ticket.fields')}</p>{sideFields(false)}</div></aside>}
          </div>
        </div>
        {details && mobile && (
          <Sheet label={tr('ticket.details')} onClose={() => setDetails(false)} className="dsheet">
            <h3>{tr('ticket.details')}</h3>
            <div className="sheetb side">{sideFields(true)}</div>
          </Sheet>
        )}
        {ask && (
          <DiscardDialog kind={DISCARD_COPY[[...dirtyFields.current.keys()].find((f) => DISCARD_COPY[f]) ?? 'composer'] ?? 'comment'} pm={pmFirst}
            onKeep={keepEditing} onDiscard={discard} restoreFocus={() => lastFocus.current} />
        )}
      </div>
    </DirtyCtx.Provider>
  );
}

/** The discard unsaved-changes dialog (screen 2): names the draft (note / comment / edits). Esc/scrim = keep editing. */
function DiscardDialog({ kind, pm, onKeep, onDiscard, restoreFocus }: {
  kind: 'note' | 'comment' | 'desc'; pm: string; onKeep: () => void; onDiscard: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const keepRef = useRef<HTMLButtonElement>(null);
  const title = tr(kind === 'note' ? 'discard.note.title' : kind === 'comment' ? 'discard.comment.title' : 'discard.desc.title');
  const body = tr(kind === 'note' ? 'discard.note.body' : kind === 'comment' ? 'discard.comment.body' : 'discard.desc.body', { name: pm });
  return (
    <Sheet label={title} onClose={onKeep} initialFocus={keepRef} restoreFocus={restoreFocus}>
      <div className="discard">
        <h3>{title}</h3>
        <p>{body}</p>
        <div className="qact">
          <button ref={keepRef} className="abtn" type="button" onClick={onKeep}>{tr('discard.keep')}</button>
          <button className="dngbtn" type="button" onClick={onDiscard}>{tr('discard.discard')}</button>
        </div>
      </div>
    </Sheet>
  );
}

function DueField({ t, now, id, onSave }: { t: Ticket; now: string; id: string; onSave: (v: string | null) => void }) {
  return (
    <div className="fld">
      <p className="k"><label htmlFor={id}>{tr('ticket.due')}</label></p>
      <div className="v">
        <span className="selw">
          <span className="l"><IconCal16 /><span className={t.due_on ? '' : 'due-none'}>{t.due_on ? dueLabel(t.due_on) : tr('ticket.due.none')}</span></span>
          <IconChev />
          <input id={id} type="date" value={t.due_on ?? ''} aria-label={`Due date${t.due_on ? `: ${dueLabel(t.due_on)}` : ''}`}
            onClick={(e) => { try { e.currentTarget.showPicker?.(); } catch { /* not supported */ } }}
            onChange={(e) => onSave(e.target.value || null)} />
        </span>
        {t.due_on && <button type="button" className="gbtn ed" onClick={() => onSave(null)}>{tr('ticket.due.clear')}</button>}
      </div>
    </div>
  );
}

function LabelsField({ t, labels, onSave }: { t: Ticket; labels: Label[]; onSave: (names: string[]) => void }) {
  const { askDiscard } = useContext(DirtyCtx);
  const [edit, setEdit] = useState(false);
  const [val, setVal] = useState('');
  const [base, setBase] = useState('');
  const dirty = edit && val.trim() !== base.trim();
  useDirty('labels', dirty);
  // Esc leaves the editor; unsaved text asks first, and Discard drops it.
  const leave = () => (dirty ? askDiscard(() => setEdit(false)) : setEdit(false));
  const start = () => { const names = t.labels.map((l) => l.name).join(', '); setVal(names); setBase(names); setEdit(true); };
  const save = () => { onSave(val.split(',').map((s) => s.trim()).filter(Boolean)); setEdit(false); };
  return (
    <div className="fld">
      <p className="k fk"><span>{tr('board.filter.label')}</span>{!edit && <button type="button" className="gbtn ed" onClick={start}>{tr('ticket.labels.edit')}</button>}</p>
      {edit ? (
        <form onSubmit={(e) => { e.preventDefault(); save(); }}>
          <label className="sr-only" htmlFor="lb-in">Labels, separated by commas</label>
          <input id="lb-in" className="qin" list="lb-list" value={val} autoFocus onChange={(e) => setVal(e.target.value)} onKeyDown={(e) => { if (e.key === 'Escape') { e.stopPropagation(); leave(); } }} />
          <datalist id="lb-list">{labels.map((l) => <option key={l.id} value={l.name} />)}</datalist>
          <p className="sm">{tr('ticket.labels.hint')}</p>
          <div className="qact"><button className="btn" type="submit">{tr('ticket.labels.save')}</button><button className="gbtn" type="button" onClick={() => setEdit(false)}>{tr('cancel')}</button></div>
        </form>
      ) : (
        <div className="v">{t.labels.length ? t.labels.map((l) => <LabelChip key={l.id} label={l} />) : <span className="sm">{tr('ticket.labels.none')}</span>}</div>
      )}
    </div>
  );
}

function HistoryField({ events, columns, people, now }: { events: TicketEvent[]; columns: Column[]; people: Person[]; now: string }) {
  const me = useMe();
  const [all, setAll] = useState(false);
  const rows = events.map((e) => ({ e, txt: eventText(e, columns, people) })).filter((r) => r.txt);
  const shown = all ? rows : rows.slice(0, 3);
  return (
    <div className="fld">
      <p className="k">{tr('ticket.history')}</p>
      {rows.length === 0 ? <p className="sm">{tr('ticket.history.empty')}</p> : (
        <ul className="mini">
          {shown.map(({ e, txt }) => <li key={e.id}>{txt}<time dateTime={e.created_at ?? undefined}>{actorShort(e, me)} · {updateTime(e.created_at, now)}</time></li>)}
        </ul>
      )}
      {rows.length > 3 && <button className="gbtn" type="button" style={{ alignSelf: 'flex-start' }} onClick={() => setAll((v) => !v)}>{all ? tr('ticket.history.less') : tr('ticket.history.more', { n: rows.length })}</button>}
    </div>
  );
}

function Description({ t, onSave }: { t: Ticket; onSave: (d: string | null) => void }) {
  const { askDiscard } = useContext(DirtyCtx);
  const [edit, setEdit] = useState(false);
  const [val, setVal] = useState('');
  const [base, setBase] = useState('');
  const dirty = edit && val.trim() !== base.trim();
  useDirty('description', dirty);
  // Esc leaves the editor; unsaved text asks first, and Discard drops it.
  const leave = () => (dirty ? askDiscard(() => setEdit(false)) : setEdit(false));
  const start = () => { const text = t.description ?? ''; setVal(text); setBase(text); setEdit(true); };
  return (
    <section className="dsec" aria-labelledby="desc-h">
      <h2 className="kb-sect" id="desc-h">{tr('ticket.description')} {!edit && <button className="gbtn" type="button" onClick={start}><IcEdit />{tr('ticket.labels.edit')}</button>}</h2>
      {edit ? (
        <form onSubmit={(e) => { e.preventDefault(); onSave(val.trim() ? val : null); setEdit(false); }}>
          <label className="sr-only" htmlFor="desc-in">Description (Markdown)</label>
          <div className="qwrap">
            <textarea id="desc-in" className="qta" value={val} autoFocus onChange={(e) => setVal(e.target.value)} onKeyDown={(e) => { if (e.key === 'Escape') { e.stopPropagation(); leave(); } }} />
          </div>
          <div className="qact" style={{ marginTop: 8 }}><button className="btn" type="submit">{tr('ticket.description.save')}</button><button className="gbtn" type="button" onClick={() => setEdit(false)}>{tr('cancel')}</button></div>
        </form>
      ) : (
        <div className="desc">{t.description?.trim() ? <MarkdownBody content={t.description} /> : <p className="hint">{tr('ticket.description.empty')}</p>}</div>
      )}
    </section>
  );
}

/** "at 10:00" today, else "on Wed 28 Oct, 10:00" / "yesterday at 21:15" (QA M1 heading). */
const answeredAt = (iso: string | null, now: string): string => {
  const s = updateTime(iso, now);
  if (s.startsWith('Today, ')) return `at ${s.slice(7)}`;
  if (s.startsWith('Yesterday, ')) return `yesterday at ${s.slice(11)}`;
  return `on ${s}`;
};

/** The latest input comment of yours on this ticket (for the SENT state). */
const latestInput = (d: Detail | null): Comment | undefined => [...(d?.comments ?? [])].reverse().find((c) => c.is_owner_input && !c.retracted);

function InputBox({ t, detail, pm, slug, now, onSent }: { t: Ticket; detail: Detail | null; pm: string | null; slug: string; now: string; onSent: () => void }) {
  const pmFirst = firstName(pm) || 'the PM';
  const opts = t.ask_options ?? [];
  const last = latestInput(detail);
  const sent = !!last && t.needs_pm_pickup;
  const answered = !!last && !t.needs_pm_pickup && !!t.owner_answered_ask; // QA M1: acked, ask unchanged
  const [changing, setChanging] = useState(false);
  const [choice, setChoice] = useState<string | null>(null);
  const [note, setNote] = useState('');
  const [state, setState] = useState<'idle' | 'busy' | 'err'>('idle');
  const [err, setErr] = useState('');
  const noteRequired = !opts.length || choice === 'other';
  // The note's starting text: '' for a new answer, the sent note when changing an answer (dirty only once edited).
  const [base, setBase] = useState('');
  const showForm = !((sent || answered) && !changing);
  useDirty('reply', showForm && note.trim() !== base.trim());
  const startChange = () => {
    const prefill = last?.input_choice && last.body !== last.input_choice ? last.body ?? '' : '';
    setChanging(true); setChoice(last?.input_choice ?? null); setNote(prefill); setBase(prefill);
  };

  const submit = async () => {
    if (opts.length && !choice) { setErr(tr('ticket.input.pickErr')); setState('err'); return; }
    if (noteRequired && !note.trim()) { setErr(tr(choice === 'other' ? 'ticket.input.noteErrOther' : 'ticket.input.noteErr')); setState('err'); return; }
    setState('busy');
    try {
      await send('POST', `/projects/${slug}/tickets/${t.key}/comments`, { body: note.trim() || null, is_owner_input: true, input_choice: opts.length ? choice : null });
      setState('idle'); setChanging(false); setNote(''); setBase(''); setChoice(null);
      onSent();
    } catch (e) {
      setErr(e instanceof ApiError && e.status !== 0 ? e.message : tr('ticket.input.sendErr'));
      setState('err');
    }
  };

  if (sent && !changing && last) {
    const answer = last.input_choice && last.input_choice !== 'other' ? last.input_choice : (last.body ?? '').split('\n')[0];
    return (
      <section className="tin sent" aria-labelledby="tin-h">
        <div className="tih"><span className="tag">{tr('ticket.input.sent.tag')}</span><h2 id="tin-h">{tr('ticket.input.sent.title', { name: pmFirst })}</h2></div>
        <p className="askc" role="status">{tr('ticket.input.sent.body', { answer, when: updateTime(last.created_at, now), name: pmFirst })}</p>
        <div className="qact"><button className="gbtn" type="button" onClick={startChange}>{tr('ticket.input.change')}</button></div>
      </section>
    );
  }

  if (answered && !changing && last) {
    const answer = last.input_choice && last.input_choice !== 'other' ? last.input_choice : (last.body ?? '').split('\n')[0];
    return (
      <section className="tin sent" aria-labelledby="tin-h">
        <div className="tih"><span className="tag">{tr('ticket.input.answered.tag')}</span><h2 id="tin-h">{tr('ticket.input.answered.title', { when: answeredAt(last.created_at, now) })}</h2></div>
        <p className="askc" role="status">{tr('ticket.input.answered.body', { answer })}{t.owner_input_ack_at ? <> {tr('ticket.input.answered.ack', { name: pmFirst, when: updateTime(t.owner_input_ack_at, now) })}</> : null} </p>
        <div className="qact"><button className="gbtn" type="button" onClick={startChange}>{tr('ticket.input.again')}</button></div>
      </section>
    );
  }

  const days = t.waiting_age_days ?? 0;
  return (
    <section className="tin" aria-labelledby="tin-h">
      <div className="tih">
        <span className="tag">{tr('ticket.input.tag')}</span><h2 id="tin-h">{tr('ticket.input.needs', { name: pmFirst })}</h2>
        {t.waiting_level && <WaitAge level={t.waiting_level} days={days} />}
      </div>
      <p className="askq" id="tin-q">{t.ask}</p>
      {t.ask_context && <p className="askc" id="tin-c">{t.ask_context}</p>}
      <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
        {opts.length > 0 && (
          <fieldset className="sfs">
            <legend className="sr-only">{t.ask}</legend>
            <div className="opts">
              {[...opts, 'other'].map((o) => (
                <label key={o} className="optc">
                  <input type="radio" name={`tin-${t.key}`} checked={choice === o} onChange={() => { setChoice(o); if (state === 'err') setState('idle'); }} />
                  {o === 'other' ? tr('ticket.input.other') : o}
                </label>
              ))}
            </div>
          </fieldset>
        )}
        <div className="other">
          <label className="flabel" htmlFor="tin-t">{noteRequired ? tr('ticket.input.noteRequired', { name: pmFirst }) : tr('ticket.input.noteOptional', { name: pmFirst })}</label>
          <div className="qwrap">
            <textarea className="qta" id="tin-t" placeholder={tr('ticket.input.placeholder')} aria-describedby={t.ask_context ? 'tin-q tin-c' : 'tin-q'} aria-required={noteRequired}
              value={note} onChange={(e) => { setNote(e.target.value); if (state === 'err') setState('idle'); }} />
          </div>
        </div>
        <div className="qact">
          <button className="btn" type="submit" disabled={state === 'busy'}>{state === 'busy' ? <><span className="spin" />{tr('ticket.input.sending')}</> : tr('ticket.input.send', { name: pmFirst })}</button>
          {changing && <button className="gbtn" type="button" onClick={() => setChanging(false)}>{tr('cancel')}</button>}
          <p className="hint">{tr('ticket.input.hint')}</p>
        </div>
        {state === 'err' && <p className="err-line" role="alert">✕ {err}</p>}
      </form>
    </section>
  );
}

function Thread({ d, project, people, columns, now, seenAt, unread }: {
  d: Detail; project: BoardProject; people: Person[]; columns: Column[]; now: string; seenAt: string | null; unread: boolean;
}) {
  const pmFirst = firstName(project.pm_name) || 'the PM';
  const me = useMe();
  type Item = { at: number; node: (isNew: boolean) => ReactNode; key: string; agent?: boolean };
  const items = useMemo(() => {
    const out: Item[] = d.comments.map((c) => ({
      at: Date.parse(c.created_at ?? ''), key: `c${c.id}`, agent: c.author_type === 'agent',
      node: (isNew: boolean) => <CommentItem c={c} pm={project.pm_agent_id} pmFirst={pmFirst} people={people} now={now} isNew={isNew} />,
    }));
    for (const e of d.events) {
      if (e.type !== 'moved' && e.type !== 'assigned') continue;
      if (e.type === 'moved' && (e.from?.status === e.to?.status || !e.from)) continue;
      const who = actorShort(e, me);
      const what = e.type === 'moved'
        ? <>moved this <span className="st">{COLNAME(columns, e.from?.status)} → {COLNAME(columns, e.to?.status)}</span></>
        : e.to?.id ? <>assigned this to <b>{people.find((p) => p.id === e.to?.id)?.name ?? String(e.to.id)}</b></> : <>unassigned this</>;
      out.push({ at: Date.parse(e.created_at ?? ''), key: `e${e.id}`, node: () => <li className="ev"><span className="agentdot" aria-hidden="true" /><span><b>{who}</b> {what}</span><time>{updateTime(e.created_at, now)}</time></li> });
    }
    return out.sort((a, b) => a.at - b.at);
  }, [d, project.pm_agent_id, pmFirst, people, columns, now, me]);

  const seen = seenAt ? Date.parse(seenAt) : 0;
  const isNew = (i: Item) => unread && !!i.agent && i.at > seen;
  const firstNew = items.findIndex(isNew);
  if (!items.length) return <p className="hint">{tr('ticket.comments.empty')}</p>;
  return (
    <ol className="thread">
      {items.map((i, n) => (
        <Fragment key={i.key}>
          {n === firstNew && <li className="newsep" role="separator" aria-label={tr('ticket.newSince')}>{tr('ticket.newSince')}</li>}
          {i.node(isNew(i))}
        </Fragment>
      ))}
    </ol>
  );
}

function CommentItem({ c, pm, pmFirst, people, now, isNew }: { c: Comment; pm: string | null; pmFirst: string; people: Person[]; now: string; isNew: boolean }) {
  const tl = c.is_owner_input;
  const role = tl ? tr('ticket.input.tag') : roleTag(c.author_id, pm, people);
  const choice = c.input_choice && c.input_choice !== 'other' ? c.input_choice : null;
  const rest = choice && c.body === choice ? '' : c.body ?? '';
  return (
    <li className={`cm${tl ? ' tl' : ''}${isNew ? ' new' : ''}`}>
      <Avatar id={c.author_id} name={c.author_name} type={c.author_type} lg />
      <div className="bub">
        <header>
          <b>{c.author_name ?? c.author_id}</b>
          {role && <span className="role">{role}</span>}
          {c.author_type === 'agent' && <span className="via"><span className="agentdot" aria-hidden="true" />{viaLabel(c.source, true)}</span>}
          <time dateTime={c.created_at ?? undefined}>{updateTime(c.created_at, now)}</time>
        </header>
        {c.retracted ? <p className="hint">{tr('ticket.removed')}</p> : (
          <>
            {choice && <p className="choice"><b>{choice}.</b></p>}
            {rest && <MarkdownBody content={rest} />}
          </>
        )}
        {tl && (c.picked_up_at
          ? <p className="ack ok"><IcOk />{tr('ticket.input.pickedUp', { name: pmFirst, when: updateTime(c.picked_up_at, now) })}</p>
          : <p className="ack"><IcClock />{tr('ticket.input.pending', { name: pmFirst })}</p>)}
      </div>
    </li>
  );
}

function Composer({ t, slug, pmFirst, onSent }: { t: Ticket; slug: string; pmFirst: string; onSent: () => void }) {
  const [body, setBody] = useState('');
  const [asInput, setAsInput] = useState(false);
  const [state, setState] = useState<'idle' | 'busy' | 'ok' | 'err'>('idle');
  useDirty('composer', body.trim() !== '');
  useEffect(() => { if (state !== 'ok') return; const id = window.setTimeout(() => setState('idle'), 6000); return () => window.clearTimeout(id); }, [state]);
  const submit = async () => {
    if (!body.trim() || state === 'busy') return;
    setState('busy');
    try {
      await send('POST', `/projects/${slug}/tickets/${t.key}/comments`, { body, is_owner_input: asInput });
      setBody(''); setAsInput(false); setState('ok');
      onSent();
    } catch {
      setState('err'); // the text is never cleared on error
    }
  };
  return (
    <form className="compose" onSubmit={(e) => { e.preventDefault(); submit(); }}>
      <div className="qwrap">
        <label className="sr-only" htmlFor="cmp">{tr('ticket.composer.placeholder')}</label>
        <textarea id="cmp" className="qta" placeholder={tr('ticket.composer.placeholder')} value={body} onChange={(e) => setBody(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); submit(); } }} />
      </div>
      <div className="row">
        <label className="chkl"><input type="checkbox" checked={asInput} onChange={(e) => setAsInput(e.target.checked)} />{tr('ticket.composer.markInput')}</label>
        <button className="btn" type="submit" disabled={state === 'busy' || !body.trim()}>{state === 'busy' ? <><span className="spin" />{tr('ticket.composer.sending')}</> : tr('ticket.composer.send')}</button>
      </div>
      <div className="cstat">
        {state === 'ok' && <p className="ok-line" role="status"><IcOk />{tr('ticket.composer.sent', { name: pmFirst })}</p>}
        {state === 'err' && <p className="err-line" role="alert">{tr('ticket.composer.err')} <button className="lnk" type="button" onClick={submit}>{tr('ticket.composer.retry')}</button></p>}
      </div>
    </form>
  );
}
