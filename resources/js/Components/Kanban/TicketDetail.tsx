import { Fragment, createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MarkdownBody } from '../MarkdownBody';
import {
  Avatar, IcApi, IcBack, IcCal, IcChev, IcClock, IcFlag, IcMore, IcMove, IcOk, IcX, LabelChip, Sheet, WaitAge,
  personType, useDismiss, useFocusTrap, useScrimDismiss, useToasts,
} from './Bits';
import { updateTime } from '../../lib/dates';
import {
  ApiError, PRIORITY_LABEL, WAITING, dueLabel, dueSoon, firstName, roleTag, send, ticketUrl,
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

const actorShort = (e: { actor_id: string | null; actor_name: string | null }) => (e.actor_id === 'tilen' ? 'You' : firstName(e.actor_name) || e.actor_id || '');

/** Native <select> laid over the mock's .sel button look (accessible, works on touch). */
function SelectField({ id, label, value, options, onChange, wt = false, dot, plain = '' }: {
  id: string; label: string; value: string; options: { value: string; label: string }[]; onChange: (v: string) => void; wt?: boolean; dot?: string; plain?: string;
}) {
  const cur = options.find((o) => o.value === value);
  return (
    <div className={`${plain ? `plain ${plain}` : 'sel'} selw${wt ? ' wt' : ''}`}>
      <span className="l">{dot && <span className={`dot c-${dot}`} aria-hidden="true" />}{cur?.label ?? '—'}</span><IcChev />
      <select id={id} aria-label={label} value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </div>
  );
}

/**
 * Unsaved text in the ticket's fields. Fields report their dirty state (`useDirty`); closing the ticket asks first
 * when any field is dirty, and an Esc inside a dirty field asks before dropping that field's edit.
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

export function TicketDetail({ detail, fallback, project, people, labels, columns, now, mobile, onClose, onStatus, onChanged, onArchived }: Props) {
  const t: Ticket = detail ?? fallback;
  const ref = useRef<HTMLDivElement>(null);
  const titleRef = useRef<HTMLHeadingElement>(null);
  const toasts = useToasts();
  const [menu, setMenu] = useState(false);
  const [details, setDetails] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const closeMenu = useCallback(() => setMenu(false), []);
  useDismiss(menuRef, menu, closeMenu);

  const dirtyFields = useRef(new Map<string, boolean>());
  const report = useCallback((field: string, dirty: boolean) => { if (dirty) dirtyFields.current.set(field, true); else dirtyFields.current.delete(field); }, []);
  const [ask, setAsk] = useState<{ discard: () => void } | null>(null);
  const lastFocus = useRef<HTMLElement | null>(null); // last focused element inside the panel: focus returns here after the confirm
  const keepRef = useRef<HTMLButtonElement>(null);
  const askDiscard = useCallback((discard: () => void) => setAsk({ discard }), []);
  const requestClose = useCallback(() => { if (dirtyFields.current.size) askDiscard(onClose); else onClose(); }, [askDiscard, onClose]);
  const dirtyApi = useMemo(() => ({ report, askDiscard }), [report, askDiscard]);
  const keepEditing = () => setAsk(null);
  const discard = () => { const run = ask?.discard; setAsk(null); run?.(); };
  const scrim = useScrimDismiss(ref, requestClose);
  useFocusTrap(ref, requestClose, titleRef);

  // "New" divider: frozen at open, so the seen POST and the 30 s refresh don't move it under Tilen's eyes.
  // (Opened from the board, the detail arrives after mount: take the value from the first payload.)
  const [seenAt, setSeenAt] = useState<string | null | undefined>(() => (detail ? detail.tilen_last_seen_at : undefined));
  useEffect(() => { if (seenAt === undefined && detail) setSeenAt(detail.tilen_last_seen_at); }, [detail, seenAt]);
  const [unreadAtOpen] = useState(() => fallback.unread_for_tilen);

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
    try { await navigator.clipboard.writeText(url); toasts.push({ body: <p>Link to {t.key} copied</p>, ms: 3000 }); }
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
      <SelectField id={id} label={`Status: ${t.status_label}. Move to another column`} value={t.status} options={statusOptions}
        wt={t.status === WAITING} dot={t.status} onChange={(v) => onStatus(t, v as ColumnKey)} />
      {hint && <p className="sm only-d">Drag the card, or pick a column here.</p>}
    </>
  );

  const events = (detail?.events ?? []).filter((e) => e.type !== 'commented');
  const sideFields = (inSheet: boolean) => (
    <>
      {inSheet && <div className="fld"><p className="k" id="k-st-m">Status</p>{statusSelect('st-sheet', false)}</div>}
      <div className="fld">
        <p className="k"><label htmlFor={`as-${inSheet}`}>Assignee</label></p>
        <div className="v fsel">
          <Avatar id={t.assignee_id} name={t.assignee_name} type={t.assignee_type ?? personType(t.assignee_id, people)} />
          <SelectField id={`as-${inSheet}`} label="Assignee" plain="pv" value={t.assignee_id ?? ''} options={assigneeOptions}
            onChange={(v) => patch(v ? { assignee: v } : { assignee_type: null, assignee_id: null })} />
        </div>
        {t.assignee_id && <p className="sm">{t.assignee_type === 'agent' ? `Agent · ${roleTag(t.assignee_id, project.pm_agent_id, people) || 'Agent'}` : t.assignee_id === 'tilen' ? 'You' : 'Person'}</p>}
      </div>
      <div className="fld">
        <p className="k"><label htmlFor={`pr-${inSheet}`}>Priority</label></p>
        <div className="v fsel">
          {t.priority !== 'p3' && <span className={`pri ${t.priority}`} aria-hidden="true"><IcFlag /></span>}
          <SelectField id={`pr-${inSheet}`} label="Priority" plain={`pri ${t.priority}`} value={t.priority} options={prOptions} onChange={(v) => patch({ priority: v })} />
        </div>
      </div>
      <DueField t={t} now={now} id={`due-${inSheet}`} onSave={(v) => patch({ due_on: v })} />
      <LabelsField t={t} labels={labels} onSave={(names) => patch({ labels: names })} />
      <div className="fld">
        <p className="k">Created</p>
        <div className="v small">{t.created_by_name ?? 'Unknown'} · {updateTime(t.created_at, now)}</div>
        <p className="apistamp">{t.created_by_type === 'agent' ? <><IcApi />via API</> : 'via Bridge'} · updated {updateTime(t.updated_at, now)}</p>
      </div>
      <HistoryField events={events} columns={columns} people={people} now={now} />
    </>
  );

  return (
    <DirtyCtx.Provider value={dirtyApi}>
      <div className="ovl dlgov" {...scrim}>
        <div className="dlgw">
          <div ref={ref} className="dlg" role="dialog" aria-modal="true" aria-labelledby="dlg-t" onFocus={(e) => { lastFocus.current = e.target as HTMLElement; }}>
            <div className="dlgh">
              <button className="ibtn only-m" type="button" aria-label="Back to board" onClick={requestClose}><IcBack /></button>
              <span className="crumb">{project.name} / <b>{t.key}</b></span>
              <div className="pwrap mright" ref={menuRef}>
                <button className="ibtn" type="button" aria-label="More actions" aria-haspopup="menu" aria-expanded={menu} onClick={() => setMenu((v) => !v)}><IcMore /></button>
                {menu && (
                  <div className="pop menu amenu" role="menu">
                    <button type="button" role="menuitem" onClick={copyLink}>Copy link</button>
                    <button type="button" role="menuitem" onClick={archive}>Archive ticket</button>
                  </div>
                )}
              </div>
              <button className="ibtn only-d" type="button" aria-label="Close" onClick={requestClose}><IcX /></button>
            </div>
            <div className="dlgb">
              <div className="main">
                {t.labels.length > 0 && <div className="tlbs tl0">{t.labels.map((l) => <LabelChip key={l.id} label={l} />)}</div>}
                <h1 id="dlg-t" ref={titleRef} tabIndex={-1}>{t.title}</h1>
                {mobile && (
                  <div className="mf">
                    {statusSelect('st-m', false)}
                    <p className="mfr">
                      <Avatar id={t.assignee_id} name={t.assignee_name} type={t.assignee_type ?? personType(t.assignee_id, people)} />
                      <span>{t.assignee_name ? `${t.assignee_name}${t.assignee_id === project.pm_agent_id ? ' · PM' : ''}` : 'Unassigned'}</span>
                      {t.priority !== 'p3' && <span className={`pri ${t.priority}`}><IcFlag />{t.priority.toUpperCase()}</span>}
                      {t.due_on && <span className={`due${dueSoon(t.due_on, now) ? ' soon' : ''}`}><IcCal />{dueLabel(t.due_on)}</span>}
                    </p>
                    <button className="lnk sm" type="button" aria-haspopup="dialog" onClick={() => setDetails(true)}>Details &amp; history</button>
                  </div>
                )}
                {t.blocked_reason && <p className="blk dblk"><span>Blocked · {t.blocked_reason}</span></p>}
                {t.status === WAITING && <InputBox t={t} detail={detail} pm={pm} slug={project.slug} now={now} onSent={onChanged} />}
                <Description t={t} onSave={(d) => patch({ description: d })} />
                <section className="dsec" aria-labelledby="th-h">
                  <h2 id="th-h">Comments &amp; activity <span className="cnt">{t.comment_count}</span></h2>
                  {detail
                    ? <Thread d={detail} project={project} people={people} columns={columns} now={now} seenAt={seenAt ?? null} unread={unreadAtOpen} />
                    : <p className="hint" role="status">Loading comments…</p>}
                  <Composer t={t} slug={project.slug} pmFirst={pmFirst} onSent={onChanged} />
                </section>
              </div>
              {!mobile && <aside className="side" aria-label="Ticket fields"><div className="fld"><p className="k" id="k-st">Status</p>{statusSelect('st-d', true)}</div>{sideFields(false)}</aside>}
            </div>
          </div>
        </div>
        {details && mobile && (
          <Sheet label="Details & history" onClose={() => setDetails(false)} className="dsheet">
            <h3>Details &amp; history</h3>
            <div className="sheetb side">{sideFields(true)}</div>
          </Sheet>
        )}
        {ask && (
          <Sheet label="Discard unsaved changes?" onClose={keepEditing} initialFocus={keepRef} restoreFocus={() => lastFocus.current} className="confirm">
            <h3>Discard unsaved changes?</h3>
            <div className="qact">
              <button ref={keepRef} className="btn" type="button" onClick={keepEditing}>Keep editing</button>
              <button className="abtn dng" type="button" onClick={discard}>Discard</button>
            </div>
          </Sheet>
        )}
      </div>
    </DirtyCtx.Provider>
  );
}

function DueField({ t, now, id, onSave }: { t: Ticket; now: string; id: string; onSave: (v: string | null) => void }) {
  return (
    <div className="fld">
      <p className="k"><label htmlFor={id}>Due</label></p>
      <div className="v">
        <span className={`plain selw due${t.due_on && dueSoon(t.due_on, now) ? ' soon' : ''}`}>
          <span className="l"><IcCal />{t.due_on ? dueLabel(t.due_on) : 'No due date'}</span>
          <input id={id} type="date" value={t.due_on ?? ''} aria-label={`Due date${t.due_on ? `: ${dueLabel(t.due_on)}` : ''}`}
            onClick={(e) => { try { e.currentTarget.showPicker?.(); } catch { /* not supported */ } }}
            onChange={(e) => onSave(e.target.value || null)} />
        </span>
        {t.due_on && <button type="button" className="edit" onClick={() => onSave(null)}>Clear</button>}
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
      <p className="k fk">Labels {!edit && <button type="button" className="edit" onClick={start}>Edit</button>}</p>
      {edit ? (
        <form onSubmit={(e) => { e.preventDefault(); save(); }}>
          <label className="sr-only" htmlFor="lb-in">Labels, separated by commas</label>
          <input id="lb-in" className="qin" list="lb-list" value={val} autoFocus onChange={(e) => setVal(e.target.value)} onKeyDown={(e) => { if (e.key === 'Escape') { e.stopPropagation(); leave(); } }} />
          <datalist id="lb-list">{labels.map((l) => <option key={l.id} value={l.name} />)}</datalist>
          <p className="sm">Separate with commas. New names are created in grey.</p>
          <div className="qact"><button className="btn" type="submit">Save</button><button className="abtn" type="button" onClick={() => setEdit(false)}>Cancel</button></div>
        </form>
      ) : (
        <div className="v">{t.labels.length ? t.labels.map((l) => <LabelChip key={l.id} label={l} />) : <span className="sm">None</span>}</div>
      )}
    </div>
  );
}

function HistoryField({ events, columns, people, now }: { events: TicketEvent[]; columns: Column[]; people: Person[]; now: string }) {
  const [all, setAll] = useState(false);
  const rows = events.map((e) => ({ e, txt: eventText(e, columns, people) })).filter((r) => r.txt);
  const shown = all ? rows : rows.slice(0, 3);
  return (
    <div className="fld">
      <p className="k">History</p>
      {rows.length === 0 ? <p className="sm">Nothing yet.</p> : (
        <ul className="mini">
          {shown.map(({ e, txt }) => <li key={e.id}>{txt}<time dateTime={e.created_at ?? undefined}>{actorShort(e)} · {updateTime(e.created_at, now)}</time></li>)}
        </ul>
      )}
      {rows.length > 3 && <button className="lnk sm" type="button" onClick={() => setAll((v) => !v)}>{all ? 'Show fewer' : `Show all ${rows.length}`}</button>}
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
      <h2 id="desc-h">Description {!edit && <button className="edit" type="button" onClick={start}>Edit</button>}</h2>
      {edit ? (
        <form className="desc" onSubmit={(e) => { e.preventDefault(); onSave(val.trim() ? val : null); setEdit(false); }}>
          <label className="sr-only" htmlFor="desc-in">Description (Markdown)</label>
          <textarea id="desc-in" className="qta" value={val} autoFocus onChange={(e) => setVal(e.target.value)} onKeyDown={(e) => { if (e.key === 'Escape') { e.stopPropagation(); leave(); } }} />
          <div className="qact"><button className="btn" type="submit">Save</button><button className="abtn" type="button" onClick={() => setEdit(false)}>Cancel</button></div>
        </form>
      ) : (
        <div className="desc">{t.description?.trim() ? <MarkdownBody content={t.description} /> : <p className="hint">No description yet.</p>}</div>
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

/** The latest input comment of Tilen's on this ticket (for the SENT state). */
const latestInput = (d: Detail | null): Comment | undefined => [...(d?.comments ?? [])].reverse().find((c) => c.is_tilen_input && !c.retracted);

function InputBox({ t, detail, pm, slug, now, onSent }: { t: Ticket; detail: Detail | null; pm: string | null; slug: string; now: string; onSent: () => void }) {
  const pmFirst = firstName(pm) || 'the PM';
  const opts = t.ask_options ?? [];
  const last = latestInput(detail);
  const sent = !!last && t.needs_pm_pickup;
  const answered = !!last && !t.needs_pm_pickup && !!t.tilen_answered_ask; // QA M1: acked, ask unchanged
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
    if (opts.length && !choice) { setErr('Pick an answer first.'); setState('err'); return; }
    if (noteRequired && !note.trim()) { setErr(choice === 'other' ? 'Add a note for “Something else”.' : 'Write your answer.'); setState('err'); return; }
    setState('busy');
    try {
      await send('POST', `/projects/${slug}/tickets/${t.key}/comments`, { body: note.trim() || null, is_tilen_input: true, input_choice: opts.length ? choice : null });
      setState('idle'); setChanging(false); setNote(''); setBase(''); setChoice(null);
      onSent();
    } catch (e) {
      setErr(e instanceof ApiError && e.status !== 0 ? e.message : 'Couldn’t send. Your text is kept. Try again.');
      setState('err');
    }
  };

  if (sent && !changing && last) {
    const answer = last.input_choice && last.input_choice !== 'other' ? last.input_choice : (last.body ?? '').split('\n')[0];
    return (
      <section className="tin sent" aria-labelledby="tin-h">
        <div className="tih"><span className="tag">Sent</span><h2 id="tin-h">Your input is with {pmFirst}</h2></div>
        <p className="askc sentl" role="status">You answered <b>{answer}</b> · {updateTime(last.created_at, now)}. It stays here until {pmFirst} picks it up.</p>
        <div className="qact"><button className="abtn" type="button" onClick={startChange}>Change answer</button></div>
      </section>
    );
  }

  if (answered && !changing && last) {
    const answer = last.input_choice && last.input_choice !== 'other' ? last.input_choice : (last.body ?? '').split('\n')[0];
    return (
      <section className="tin sent" aria-labelledby="tin-h">
        <div className="tih"><span className="tag">Answered</span><h2 id="tin-h">You answered {answeredAt(last.created_at, now)}</h2></div>
        <p className="askc sentl" role="status">Your answer: <b>{answer}</b>.{t.tilen_input_ack_at ? <> Picked up by {pmFirst} · {updateTime(t.tilen_input_ack_at, now)}.</> : null} You don’t need to answer again unless {pmFirst} asks something new.</p>
        <div className="qact"><button className="abtn" type="button" onClick={startChange}>Answer again</button></div>
      </section>
    );
  }

  const days = t.waiting_age_days ?? 0;
  return (
    <section className="tin" aria-labelledby="tin-h">
      <div className="tih">
        <span className="tag">Your input</span><h2 id="tin-h">{pmFirst} needs your input</h2>
        {t.waiting_level && <WaitAge level={t.waiting_level} days={days} long />}
      </div>
      <p className="askq" id="tin-q">{t.ask}</p>
      {t.ask_context && <p className="askc" id="tin-c">{t.ask_context}</p>}
      <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
        {opts.length > 0 && (
          <fieldset className="sfs">
            <legend className="sr-only">{t.ask}</legend>
            <div className="opts">
              {[...opts, 'other'].map((o) => (
                <label key={o} className="opt">
                  <input type="radio" name={`tin-${t.key}`} checked={choice === o} onChange={() => { setChoice(o); if (state === 'err') setState('idle'); }} />
                  {o === 'other' ? 'Something else' : o}
                </label>
              ))}
            </div>
          </fieldset>
        )}
        <div className="other">
          <label className="flabel" htmlFor="tin-t">{noteRequired ? `Note for ${pmFirst}` : `Note for ${pmFirst} (optional)`}</label>
          <textarea className="qta" id="tin-t" aria-describedby={t.ask_context ? 'tin-q tin-c' : 'tin-q'} aria-required={noteRequired}
            value={note} onChange={(e) => { setNote(e.target.value); if (state === 'err') setState('idle'); }} />
        </div>
        <div className="qact">
          <button className="btn" type="submit" disabled={state === 'busy'}>{state === 'busy' ? <><span className="spin" />Sending…</> : `Send to ${pmFirst}`}</button>
          {changing && <button className="abtn" type="button" onClick={() => setChanging(false)}>Cancel</button>}
          <p className="hint">Posts as a comment marked “Tilen’s input”. {pmFirst} moves the ticket on.</p>
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
  type Item = { at: number; node: (isNew: boolean) => ReactNode; key: string; agent?: boolean };
  const items = useMemo(() => {
    const out: Item[] = d.comments.map((c) => ({
      at: Date.parse(c.created_at ?? ''), key: `c${c.id}`, agent: c.author_type === 'agent',
      node: (isNew: boolean) => <CommentItem c={c} pm={project.pm_agent_id} pmFirst={pmFirst} people={people} now={now} isNew={isNew} />,
    }));
    for (const e of d.events) {
      if (e.type !== 'moved' && e.type !== 'assigned') continue;
      if (e.type === 'moved' && (e.from?.status === e.to?.status || !e.from)) continue;
      const who = e.actor_id === 'tilen' ? 'You' : firstName(e.actor_name) || e.actor_id;
      const what = e.type === 'moved'
        ? <>moved this <span className="st">{COLNAME(columns, e.from?.status)} → {COLNAME(columns, e.to?.status)}</span></>
        : e.to?.id ? <>assigned this to <b>{people.find((p) => p.id === e.to?.id)?.name ?? String(e.to.id)}</b></> : <>unassigned this</>;
      out.push({ at: Date.parse(e.created_at ?? ''), key: `e${e.id}`, node: () => <li className="ev"><IcMove /><span><b>{who}</b> {what} · {updateTime(e.created_at, now)}</span></li> });
    }
    return out.sort((a, b) => a.at - b.at);
  }, [d, project.pm_agent_id, pmFirst, people, columns, now]);

  const seen = seenAt ? Date.parse(seenAt) : 0;
  const isNew = (i: Item) => unread && !!i.agent && i.at > seen;
  const firstNew = items.findIndex(isNew);
  if (!items.length) return <p className="hint">No comments yet.</p>;
  return (
    <ol className="thread">
      {items.map((i, n) => (
        <Fragment key={i.key}>
          {n === firstNew && <li className="newsep" role="separator" aria-label="New since you last opened this ticket">New</li>}
          {i.node(isNew(i))}
        </Fragment>
      ))}
    </ol>
  );
}

function CommentItem({ c, pm, pmFirst, people, now, isNew }: { c: Comment; pm: string | null; pmFirst: string; people: Person[]; now: string; isNew: boolean }) {
  const tl = c.is_tilen_input;
  const role = tl ? 'Tilen’s input' : roleTag(c.author_id, pm, people);
  const choice = c.input_choice && c.input_choice !== 'other' ? c.input_choice : null;
  const rest = choice && c.body === choice ? '' : c.body ?? '';
  return (
    <li className={`cm${tl ? ' tl' : ''}${isNew ? ' new' : ''}`}>
      <Avatar id={c.author_id} name={c.author_name} type={c.author_type} lg />
      <div className="bub">
        <header><b>{c.author_name ?? c.author_id}</b>{role && <span className="role">{role}</span>}<time dateTime={c.created_at ?? undefined}>{updateTime(c.created_at, now)}</time></header>
        {c.retracted ? <p className="hint">Comment removed</p> : (
          <>
            {choice && <p className="choice"><b>{choice}.</b></p>}
            {rest && <MarkdownBody content={rest} />}
          </>
        )}
        {tl && (c.picked_up_at
          ? <p className="ack ok"><IcOk />Picked up by {pmFirst} · {updateTime(c.picked_up_at, now)}</p>
          : <p className="ack pend"><IcClock />Sent to {pmFirst} · not picked up yet</p>)}
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
      await send('POST', `/projects/${slug}/tickets/${t.key}/comments`, { body, is_tilen_input: asInput });
      setBody(''); setAsInput(false); setState('ok');
      onSent();
    } catch {
      setState('err'); // the text is never cleared on error
    }
  };
  return (
    <form className="compose" onSubmit={(e) => { e.preventDefault(); submit(); }}>
      <Avatar id="tilen" name="Tilen" type="human" lg />
      <div className="box">
        <label className="sr-only" htmlFor="cmp">Write a comment</label>
        <textarea id="cmp" placeholder="Write a comment… (Markdown)" value={body} onChange={(e) => setBody(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); submit(); } }} />
        <div className="row">
          <label className="chkl"><input type="checkbox" checked={asInput} onChange={(e) => setAsInput(e.target.checked)} />Mark as my input for the PM</label>
          <button className="btn" type="submit" disabled={state === 'busy'}>{state === 'busy' ? <><span className="spin" />Sending…</> : 'Comment'}</button>
        </div>
      </div>
      <div className="cstat">
        {state === 'ok' && <p className="ok-line" role="status"><IcOk />Sent · {pmFirst} gets it on their next run</p>}
        {state === 'err' && <p className="err-line" role="alert">✕ Couldn’t send. Your text is kept. <button className="lnk" type="button" onClick={submit}>Try again</button></p>}
      </div>
    </form>
  );
}
