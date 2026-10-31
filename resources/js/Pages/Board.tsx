import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, MouseEvent, PointerEvent as RPointerEvent } from 'react';
import { AppTopBar } from '../Components/Shell/AppTopBar';
import { IconSearch } from '../Components/Icons';
import { IcFilter, IcHist, IcOk, IcPlus, MOBILE, Sheet, ToastHost, refocus, useDismiss, useMedia, useToasts } from '../Components/Kanban/Bits';
import { TicketCard } from '../Components/Kanban/TicketCard';
import { StatusBlock, setByLabel } from '../Components/Kanban/StatusBlock';
import { AskPrompt, MoveSheet } from '../Components/Kanban/MoveSheet';
import { TicketDetail } from '../Components/Kanban/TicketDetail';
import { ColumnLabelsSheet, type ColumnDef } from '../Components/Kanban/ColumnLabels';
import { updateTime } from '../lib/dates';
import {
  ApiError, QUICK_ADD, STATUS_LABEL, WAITING, firstName, prefersReducedMotion, send, ticketUrl,
  type Board as BoardData, type Column, type ColumnKey, type Label, type Person, type StatusEvent, type Ticket, type TicketDetail as Detail,
} from '../lib/kanban';

type Props = {
  board: BoardData;
  statusHistory: StatusEvent[];
  people: Person[];
  labels: Label[];
  doneAll: boolean;
  ticket: Detail | null;
  now: string;
  /** PUT /projects/{slug}/column-labels for a logged-in member; null for the gate session (no editor). */
  columnLabelsUrl: string | null;
  defaultColumns: ColumnDef[];
};

export default function BoardPage(props: Props) {
  return <div className="kanban"><ToastHost><Board {...props} /></ToastHost></div>;
}

/** Board filters (local state, like search). `replies` / `pickup` match the card chips "New reply from …" / "Your input · not picked up yet". */
type Filters = { assignee: string; label: string; priority: string; blocked: boolean; replies: boolean; pickup: boolean };
const NO_FILTERS: Filters = { assignee: '', label: '', priority: '', blocked: false, replies: false, pickup: false };
const POLL_MS = 30_000;
/** Card to refocus once the ticket dialog closes (module scope: survives Inertia's remount on history restore). */
let returnTo: string | null = null;

/** Where a card lands: before a ticket, after one, or the top of the column (API before_id/after_id/position). */
type Placement = { before_id?: string; after_id?: string; position?: number };
type Pending = { t: Ticket; to: ColumnKey; index: number; via: 'drag' | 'key' | 'sheet' | 'detail' };
type Drag = { key: string; from: ColumnKey; x: number; y: number; offX: number; offY: number; w: number; over: ColumnKey | null; index: number };
type Pick = { key: string; from: ColumnKey; col: ColumnKey; index: number };

function Board({ board, statusHistory, people, labels, doneAll, ticket, now, columnLabelsUrl, defaultColumns }: Props) {
  const p = board.project;
  const toasts = useToasts();
  const mobile = useMedia(MOBILE);

  // ------------------------------------------------------------ columns (optimistic copy of the props)
  const [cols, setCols] = useState<Column[]>(board.columns);
  const busy = useRef(0);
  const own = useRef(new Set<string>());
  const prevStamp = useRef<Map<string, string>>(new Map());
  const [flash, setFlash] = useState<Set<string>>(new Set());
  useEffect(() => {
    if (busy.current === 0) setCols(board.columns);
    // Live updates: cards whose updated_at/comment count changed since the last payload fade in (not our own writes).
    const next = new Map<string, string>();
    board.columns.forEach((c) => c.tickets.forEach((t) => next.set(t.key, `${t.updated_at}|${t.comment_count}|${t.status}`)));
    const changed = prevStamp.current.size ? [...next].filter(([k, v]) => prevStamp.current.get(k) !== v && !own.current.has(k)).map(([k]) => k) : [];
    prevStamp.current = next;
    own.current.clear();
    if (changed.length && !prefersReducedMotion()) {
      setFlash(new Set(changed));
      const id = window.setTimeout(() => setFlash(new Set()), 1100);
      return () => window.clearTimeout(id);
    }
  }, [board.columns]);

  const all = useMemo(() => cols.flatMap((c) => c.tickets), [cols]);
  const find = useCallback((key: string) => all.find((t) => t.key === key), [all]);

  // ------------------------------------------------------------ search + filters
  const [q, setQ] = useState('');
  const [filters, setFilters] = useState<Filters>(NO_FILTERS);
  const nFilters = Object.values(filters).filter(Boolean).length;
  const matches = useCallback((t: Ticket) => {
    const n = q.trim().toLowerCase();
    if (n && ![t.title, t.description ?? '', t.key, ...t.labels.map((l) => l.name)].some((s) => s.toLowerCase().includes(n))) return false;
    if (filters.assignee && (filters.assignee === 'none' ? t.assignee_id !== null : t.assignee_id !== filters.assignee)) return false;
    if (filters.label && !t.labels.some((l) => l.name === filters.label)) return false;
    if (filters.priority && t.priority !== filters.priority) return false;
    if (filters.blocked && !t.blocked) return false;
    if (filters.replies && !t.unread_for_tilen) return false;
    if (filters.pickup && !t.needs_pm_pickup) return false;
    return true;
  }, [q, filters]);
  const visible = useMemo(() => cols.map((c) => ({ ...c, tickets: c.tickets.filter(matches) })), [cols, matches]);
  const filtering = q.trim() !== '' || nFilters > 0;

  // ------------------------------------------------------------ reloads + poll
  const reload = useCallback((only: string[] = ['board', 'statusHistory']) => {
    router.reload({ only, async: true } as never);
  }, []);
  const ticketOpen = ticket !== null;
  const dragging = useRef(false);
  useEffect(() => {
    const id = window.setInterval(() => {
      if (document.visibilityState !== 'visible' || dragging.current || busy.current) return;
      reload(ticketOpen ? ['board', 'statusHistory', 'ticket'] : ['board', 'statusHistory']);
    }, POLL_MS);
    return () => window.clearInterval(id);
  }, [reload, ticketOpen]);

  // ------------------------------------------------------------ status-change toast, once per change (project_read_states)
  const [historyOpen, setHistoryOpen] = useState(false);
  const seenPosted = useRef(false);
  const statusToast = useRef(0);
  useEffect(() => { if (historyOpen && statusToast.current) { toasts.dismiss(statusToast.current); statusToast.current = 0; } }, [historyOpen, toasts]);
  useEffect(() => {
    if (seenPosted.current) return;
    seenPosted.current = true;
    const ev = statusHistory[0];
    if (p.status_unseen_by_tilen && ev) {
      const who = ev.set_by === p.pm_agent_id ? firstName(ev.set_by_name) : setByLabel(ev.set_by, ev.set_by_name, p.pm_agent_id);
      statusToast.current = toasts.push({
        ms: 15000,
        icon: <IcHist />,
        body: (
          <>
            <p><b>{who} changed the status</b> {ev.from_status ? STATUS_LABEL[ev.from_status] : 'Status not set'} → {STATUS_LABEL[ev.to_status]}</p>
            <p className="s">{updateTime(ev.created_at, now)}{ev.note ? ` · “${ev.note}”` : ''}</p>
          </>
        ),
        action: { label: 'See history', run: () => setHistoryOpen(true) },
      });
    }
    send('POST', `/projects/${p.slug}/seen`).catch(() => {});
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // ------------------------------------------------------------ moving
  const [live, setLive] = useState('');
  const [askFor, setAskFor] = useState<Pending | null>(null);
  const [focusKey, setFocusKey] = useState<string | null>(null);
  useLayoutEffect(() => {
    if (!focusKey) return;
    document.querySelector<HTMLElement>(`.board [data-key="${focusKey}"] .tkl`)?.focus({ preventScroll: true });
    setFocusKey(null);
  }, [focusKey, cols]);

  /** Placement from an index in the *visible* list of the target column (so filters never misplace a card). */
  const placementFor = (to: ColumnKey, index: number, key: string): Placement => {
    if (to === WAITING) return {};
    const list = (visible.find((c) => c.key === to)?.tickets ?? []).filter((t) => t.key !== key);
    if (index <= 0) return list.length ? { before_id: list[0].key } : { position: 0 };
    if (index >= list.length) return { after_id: list[list.length - 1].key };
    return { before_id: list[index].key };
  };

  const applyLocal = (key: string, to: ColumnKey, pl: Placement, patch: Partial<Ticket> = {}) => {
    setCols((cs) => {
      const t = cs.flatMap((c) => c.tickets).find((x) => x.key === key);
      if (!t) return cs;
      const moved: Ticket = { ...t, ...patch, status: to, status_label: cs.find((c) => c.key === to)?.label ?? to };
      if (to === WAITING && t.status !== WAITING) Object.assign(moved, { waiting_since: new Date().toISOString(), waiting_level: 1, waiting_age_days: 0 });
      if (to !== WAITING) Object.assign(moved, { waiting_level: null, waiting_age_days: null });
      return cs.map((c) => {
        let list = c.tickets.filter((x) => x.key !== key);
        if (c.key === to) {
          let i = list.length;
          if (pl.position === 0) i = 0;
          else if (pl.before_id) i = Math.max(0, list.findIndex((x) => x.key === pl.before_id));
          else if (pl.after_id) i = list.findIndex((x) => x.key === pl.after_id) + 1;
          list = [...list.slice(0, i), moved, ...list.slice(i)];
        }
        return { ...c, tickets: list, count: list.length };
      });
    });
  };

  const doMove = async (t: Ticket, to: ColumnKey, pl: Placement, ask?: string, opts: { undo?: boolean; silent?: boolean } = {}) => {
    const snapshot = cols;
    const fromIdx = cols.find((c) => c.key === t.status)?.tickets.findIndex((x) => x.key === t.key) ?? 0;
    const fromList = cols.find((c) => c.key === t.status)?.tickets ?? [];
    const back: Placement = t.status === WAITING ? {} : fromIdx > 0 ? { after_id: fromList[fromIdx - 1].key } : { position: 0 };
    own.current.add(t.key);
    applyLocal(t.key, to, pl, ask ? { ask } : {});
    busy.current++;
    try {
      await send('POST', `/projects/${p.slug}/tickets/${t.key}/move`, { status: to, ...pl, ...(ask ? { ask } : {}) });
      busy.current--;
      if (opts.undo && t.status !== to) {
        toasts.push({
          body: <p>Moved {t.key} to {cols.find((c) => c.key === to)?.label}</p>,
          action: { label: 'Undo', run: () => doMove({ ...t, status: to }, t.status, back, undefined, { silent: true }) },
          ms: 5000,
        });
      }
      reload(ticketOpen ? ['board', 'ticket'] : ['board']);
    } catch (e) {
      busy.current--;
      setCols(snapshot);
      const why = e instanceof ApiError && e.status === 422 ? ` ${e.message}` : ' Try again.';
      toasts.push({ tone: 'err', body: <p>Couldn’t move {t.key}.{why}</p> });
      setLive(`Couldn’t move ${t.key}.`);
    }
  };

  /** Every UI move goes through here: entering Waiting on Tilen without an ask asks first. */
  const requestMove = (pending: Pending) => {
    const { t, to, index, via } = pending;
    if (to === t.status && via !== 'drag' && via !== 'key') return;
    if (to === WAITING && t.status !== WAITING && !t.ask) { setAskFor(pending); return; }
    const pl = via === 'sheet' || via === 'detail' ? (to === WAITING ? {} : { position: 0 }) : placementFor(to, index, t.key);
    doMove(t, to, pl, undefined, { undo: via === 'sheet' });
  };

  // ------------------------------------------------------------ desktop drag (pointer events, mouse only)
  const [drag, setDragState] = useState<Drag | null>(null);
  const dragRef = useRef<Drag | null>(null);
  const setDrag = (d: Drag | null) => { dragRef.current = d; setDragState(d); };
  const start = useRef<{ t: Ticket; x: number; y: number; el: HTMLElement } | null>(null);
  const boardRef = useRef<HTMLDivElement>(null);
  // Desktop mouse panning of the board slider (background/headers only; tickets keep their own drag).
  useEffect(() => {
    const el = boardRef.current;
    if (!el) return;
    let st: { x: number; sl: number; id: number; on: boolean } | null = null;
    const down = (e: PointerEvent) => {
      if (e.pointerType !== 'mouse' || e.button !== 0 || window.innerWidth <= 720) return;
      if ((e.target as HTMLElement).closest('.tk, .ph, a, button, input, textarea, select, label, [draggable="true"], [contenteditable]')) return;
      st = { x: e.clientX, sl: el.scrollLeft, id: e.pointerId, on: false };
    };
    const move = (e: PointerEvent) => {
      if (!st || e.pointerId !== st.id) return;
      const dx = e.clientX - st.x;
      if (!st.on && Math.abs(dx) < 5) return;
      if (!st.on) { st.on = true; el.setPointerCapture(st.id); el.classList.add('panning'); }
      el.scrollLeft = st.sl - dx;
      e.preventDefault();
    };
    const up = (e: PointerEvent) => {
      if (!st || e.pointerId !== st.id) return;
      if (st.on) {
        el.classList.remove('panning');
        const stop = (c: Event) => { c.stopPropagation(); c.preventDefault(); };
        el.addEventListener('click', stop, { capture: true, once: true });
        setTimeout(() => el.removeEventListener('click', stop, true), 0);
      }
      st = null;
    };
    el.addEventListener('pointerdown', down);
    el.addEventListener('pointermove', move);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointercancel', up);
    return () => { el.removeEventListener('pointerdown', down); el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up); };
  }, []);
  const suppressClick = useRef(false);

  const hit = (x: number, y: number, key: string): { over: ColumnKey | null; index: number } => {
    const col = document.elementsFromPoint(x, y).map((el) => el.closest<HTMLElement>('.board .col[data-col]')).find(Boolean);
    if (!col) return { over: null, index: 0 };
    const over = col.dataset.col as ColumnKey;
    const cards = [...col.querySelectorAll<HTMLElement>('.tk[data-key]')].filter((el) => el.dataset.key !== key);
    const index = cards.findIndex((el) => { const r = el.getBoundingClientRect(); return y < r.top + r.height / 2; });
    return { over, index: index < 0 ? cards.length : index };
  };

  const onCardPointerDown = (e: RPointerEvent<HTMLDivElement>, t: Ticket) => {
    if (mobile || e.pointerType !== 'mouse' || e.button !== 0 || (e.target as HTMLElement).closest('button')) return;
    start.current = { t, x: e.clientX, y: e.clientY, el: e.currentTarget };
  };

  useEffect(() => {
    const move = (e: PointerEvent) => {
      const s = start.current;
      if (!s) return;
      if (!dragging.current) {
        if (Math.hypot(e.clientX - s.x, e.clientY - s.y) < 6) return;
        dragging.current = true;
        const r = s.el.getBoundingClientRect();
        setDrag({ key: s.t.key, from: s.t.status, x: e.clientX, y: e.clientY, offX: s.x - r.left, offY: s.y - r.top, w: r.width, over: s.t.status, index: 0 });
        document.body.classList.add('kdragging');
      }
      const b = boardRef.current;
      if (b) {
        const r = b.getBoundingClientRect();
        if (e.clientX > r.right - 48) b.scrollLeft += 14;
        else if (e.clientX < r.left + 48) b.scrollLeft -= 14;
      }
      const h = hit(e.clientX, e.clientY, s.t.key);
      if (dragRef.current) setDrag({ ...dragRef.current, x: e.clientX, y: e.clientY, ...h });
    };
    const end = () => {
      const s = start.current;
      start.current = null;
      if (!s || !dragging.current) return;
      dragging.current = false;
      suppressClick.current = true;
      window.setTimeout(() => { suppressClick.current = false; }, 0);
      document.body.classList.remove('kdragging');
      const d = dragRef.current;
      setDrag(null);
      if (d?.over) {
        const t = s.t;
        const sameSpot = d.over === t.status && (t.status === WAITING || placementSame(d.over, d.index, t.key));
        if (!sameSpot) requestMove({ t, to: d.over, index: d.index, via: 'drag' });
      }
    };
    const key = (e: globalThis.KeyboardEvent) => {
      if (e.key === 'Escape' && dragging.current) {
        e.preventDefault();
        const dk = start.current?.t.key;
        start.current = null; dragging.current = false; setDrag(null);
        document.body.classList.remove('kdragging');
        if (dk) setFocusKey(dk);
        setLive('Move cancelled.');
      }
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
    window.addEventListener('keydown', key);
    return () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', end); window.removeEventListener('pointercancel', end); window.removeEventListener('keydown', key); };
  });

  const placementSame = (col: ColumnKey, index: number, key: string) => {
    const list = visible.find((c) => c.key === col)?.tickets ?? [];
    return list.findIndex((t) => t.key === key) === index;
  };

  // ------------------------------------------------------------ keyboard model (SPEC §4)
  const [pick, setPick] = useState<Pick | null>(null);
  const colLabel = (k: ColumnKey) => cols.find((c) => c.key === k)?.label ?? k;
  const len = (k: ColumnKey, key: string) => (visible.find((c) => c.key === k)?.tickets ?? []).filter((t) => t.key !== key).length;
  const say = (pk: Pick) => {
    const n = len(pk.col, pk.key) + 1;
    setLive(pk.col === WAITING ? `${pk.key}, ${colLabel(pk.col)}, ordered oldest first` : `${pk.key}, ${colLabel(pk.col)}, position ${pk.index + 1} of ${n}`);
  };
  const keys = cols.map((c) => c.key);
  const onCardKey = (e: KeyboardEvent<HTMLAnchorElement>, t: Ticket) => {
    if (mobile) return;
    if (!pick) {
      if (e.key === ' ') {
        e.preventDefault();
        const index = (visible.find((c) => c.key === t.status)?.tickets ?? []).findIndex((x) => x.key === t.key);
        const pk = { key: t.key, from: t.status, col: t.status, index: Math.max(0, index) };
        setPick(pk);
        setFocusKey(t.key);
        const n = len(t.status, t.key) + 1;
        setLive(`Picked up ${t.key}. ${colLabel(t.status)}, position ${pk.index + 1} of ${n}. Arrow keys move it, Space drops, Escape cancels.`);
      }
      return;
    }
    if (pick.key !== t.key) return;
    const k = e.key;
    if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); setPick(null); setFocusKey(t.key); setLive(`Move cancelled. ${t.key} stays in ${colLabel(pick.from)}.`); return; }
    if (k === ' ' || k === 'Enter') {
      e.preventDefault();
      const pk = pick;
      setPick(null);
      if (pk.col === pk.from && (pk.col === WAITING || placementSame(pk.col, pk.index, pk.key))) { setLive(`${t.key} dropped where it was.`); setFocusKey(t.key); return; }
      setLive(`${t.key} dropped in ${colLabel(pk.col)}.`);
      setFocusKey(t.key);
      requestMove({ t, to: pk.col, index: pk.index, via: 'key' });
      return;
    }
    let nx: Pick | null = null;
    if (k === 'ArrowLeft' || k === 'ArrowRight') {
      const ci = keys.indexOf(pick.col) + (k === 'ArrowLeft' ? -1 : 1);
      if (ci >= 0 && ci < keys.length) { const col = keys[ci]; nx = { ...pick, col, index: Math.min(pick.index, len(col, t.key)) }; }
    } else if ((k === 'ArrowUp' || k === 'ArrowDown') && pick.col !== WAITING) {
      const i = pick.index + (k === 'ArrowUp' ? -1 : 1);
      if (i >= 0 && i <= len(pick.col, t.key)) nx = { ...pick, index: i };
    }
    if (k.startsWith('Arrow')) { e.preventDefault(); if (nx) { setPick(nx); say(nx); setFocusKey(t.key); } }
  };

  // ------------------------------------------------------------ open / close the ticket
  const openedHere = useRef(false);
  const [opening, setOpening] = useState<string | null>(null);
  const openTicket = (t: Ticket, e?: MouseEvent) => {
    if (e && (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1)) return; // new tab keeps working
    e?.preventDefault();
    if (suppressClick.current || pick) return;
    returnTo = t.key;
    openedHere.current = true;
    setOpening(t.key);
    router.visit(ticketUrl(p.slug, t.key), { only: ['ticket'], preserveState: true, preserveScroll: true, onFinish: () => setOpening(null) });
  };
  const closeTicket = () => {
    const key = ticket?.key ?? opening;
    setOpening(null);
    returnTo = key ?? null;
    if (openedHere.current) { openedHere.current = false; window.history.back(); }
    else router.visit(`/projects/${p.slug}`, { preserveState: true, preserveScroll: true });
  };
  // Opening marks the ticket seen (clears its unread strip on the next board payload).
  const seenKey = useRef<string | null>(null);
  useEffect(() => {
    if (!ticket) { seenKey.current = null; return; }
    if (seenKey.current === ticket.key) return;
    seenKey.current = ticket.key;
    send('POST', `/projects/${p.slug}/tickets/${ticket.key}/seen`).then(() => reload(['board'])).catch(() => {});
  }, [ticket?.key]); // eslint-disable-line react-hooks/exhaustive-deps
  // Browser Back (or Esc → history.back) closes the dialog. Inertia restores the page from history (a remount with the
  // props from before the dialog opened), so refresh them and return focus to the card the dialog was opened from.
  useEffect(() => {
    if (ticket !== null || !returnTo) return;
    const key = returnTo;
    returnTo = null;
    setFocusKey(key);
    reload(['board', 'statusHistory']);
  }, [ticket]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    document.documentElement.classList.toggle('kmodal', ticket !== null || opening !== null);
    return () => document.documentElement.classList.remove('kmodal');
  }, [ticket, opening]);

  const shownKey = ticket?.key ?? opening;
  const fallback = shownKey ? (ticket && ticket.key === shownKey ? ticket : find(shownKey)) : undefined;

  // ------------------------------------------------------------ mobile tabs
  const waitingCount = cols.find((c) => c.key === WAITING)?.count ?? 0;
  // Default tab: Waiting on Tilen when it has tickets, else In progress; after the dialog closes, the card's own column.
  const [tab, setTab] = useState<ColumnKey>(() => board.columns.find((c) => returnTo && c.tickets.some((t) => t.key === returnTo))?.key
    ?? (waitingCount > 0 ? WAITING : 'in_progress'));
  // A filter change on mobile lands on a tab with matches: stay if the current one has some, else the first that does (b4).
  const lastFilters = useRef(filters);
  useEffect(() => {
    if (lastFilters.current === filters) return;
    lastFilters.current = filters;
    if (!mobile || nFilters === 0 || (visible.find((c) => c.key === tab)?.tickets.length ?? 0) > 0) return;
    const hit = visible.find((c) => c.tickets.length > 0);
    if (hit) setTab(hit.key);
  }, [filters, mobile, nFilters, visible, tab]);
  const tabsRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const strip = tabsRef.current, btn = strip?.querySelector<HTMLElement>('[aria-selected="true"]');
    if (strip && btn) strip.scrollTo({ left: btn.offsetLeft - (strip.clientWidth - btn.offsetWidth) / 2, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
  }, [tab, mobile]);
  const tabKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    const i = keys.indexOf(tab);
    const n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? keys.length - 1 : null;
    if (n === null || n < 0 || n >= keys.length) return;
    e.preventDefault();
    setTab(keys[n]);
    tabsRef.current?.querySelectorAll<HTMLElement>('[role=tab]')[n]?.focus();
  };
  const touch = useRef<{ x: number; y: number } | null>(null);
  // QA L1: the board swipes between tabs, so the page must not chain an overscroll into the browser's Back gesture.
  useEffect(() => {
    const root = document.documentElement, prev = root.style.overscrollBehaviorX;
    root.style.overscrollBehaviorX = 'none';
    return () => { root.style.overscrollBehaviorX = prev; };
  }, []);
  const swipeEnd = (x: number, y: number) => {
    const s = touch.current;
    touch.current = null;
    if (!s || Math.abs(x - s.x) < 60 || Math.abs(x - s.x) < Math.abs(y - s.y) * 1.5) return;
    const i = keys.indexOf(tab) + (x < s.x ? 1 : -1);
    if (i >= 0 && i < keys.length) setTab(keys[i]);
  };
  const [moveFor, setMoveFor] = useState<Ticket | null>(null);
  const [colLabelsOpen, setColLabelsOpen] = useState(false);
  const colLabelsBtn = useRef<HTMLButtonElement>(null);

  // ------------------------------------------------------------ quick add
  const [adding, setAdding] = useState<ColumnKey | null>(null);
  const addTop = () => {
    if (mobile && !QUICK_ADD.includes(tab)) setTab('backlog');
    setAdding(mobile && QUICK_ADD.includes(tab) ? tab : 'backlog');
  };

  // ------------------------------------------------------------ summary
  const open = all.filter((t) => t.status !== 'done').length;
  const blocked = all.filter((t) => t.blocked && t.status !== 'done').length;

  const dragged = drag ? find(drag.key) : undefined;

  return (
    <>
      <Head title={p.name} />
      <AppTopBar />
      <main className="wrap">
        <Link className="back" href="/">← All projects</Link>
        <div className="bhead">
          <div className="bhl">
            <div className="t"><h1>{p.name}</h1></div>
            {p.description && <p className="bdesc">{p.description}</p>}
            <StatusBlock project={p} history={statusHistory} now={now} mobile={mobile} historyOpen={historyOpen} setHistoryOpen={setHistoryOpen}
              onChanged={() => reload(['board', 'statusHistory'])} />
          </div>
          <div className="btools">
            <label className={`search${q ? ' filled' : ''}`}>
              <IconSearch />
              <span className="sr-only">Search tickets</span>
              <input type="text" placeholder="Search tickets" value={q} onChange={(e) => setQ(e.target.value)} />
              {q && <button type="button" className="clr" aria-label="Clear search" onClick={() => setQ('')}>×</button>}
            </label>
            <FilterMenu filters={filters} setFilters={setFilters} n={nFilters} tickets={all} people={people} labels={labels} />
            {columnLabelsUrl && (
              <button ref={colLabelsBtn} type="button" className="fbtn bset" aria-haspopup="dialog" aria-label={mobile ? 'Column labels' : undefined}
                onClick={() => setColLabelsOpen(true)}><IcColumns /><span className="t">Column labels</span></button>
            )}
            <button className="btn add" type="button" aria-label={mobile ? 'Add ticket' : undefined} onClick={addTop}><IcPlus /><span className="t">Add ticket</span></button>
          </div>
        </div>
        <p className="bsum">
          <span><b>{open}</b> open tickets</span>
          <span><b>{waitingCount}</b> waiting on you</span>
          <span>{blocked} blocked</span>
          {p.pm_name && <span>PM <b>{p.pm_name}</b></span>}
          <span>Last activity <b>{updateTime(p.last_activity_at, now) || '—'}</b></span>
          {filtering && <span role="status"><b>{visible.reduce((s, c) => s + c.tickets.length, 0)}</b> shown · <button type="button" className="lnk inl" onClick={() => { setQ(''); setFilters(NO_FILTERS); }}>Clear</button></span>}
        </p>

        {mobile && (
          <div className="ctabs" role="tablist" aria-label="Columns" ref={tabsRef}>
            {visible.map((c) => (
              <button key={c.key} id={`tab-${c.key}`} role="tab" type="button" className={c.key === WAITING ? 'wt' : ''} aria-selected={c.key === tab}
                aria-controls={`col-${c.key}`} tabIndex={c.key === tab ? 0 : -1} onClick={() => setTab(c.key)} onKeyDown={tabKey}>
                {c.label}<span className={`cnt${c.tickets.length === 0 ? ' is0' : ''}`}>{c.tickets.length}</span>
              </button>
            ))}
          </div>
        )}

        <div className="board" ref={boardRef}
          onTouchStart={(e) => { touch.current = { x: e.touches[0].clientX, y: e.touches[0].clientY }; }}
          onTouchEnd={(e) => swipeEnd(e.changedTouches[0].clientX, e.changedTouches[0].clientY)}>
          {visible.map((c) => {
            // The dragged / keyboard-picked card leaves its column; a placeholder (drag) or the picked card (keyboard) shows where it lands.
            const items = c.tickets.filter((t) => t.key !== drag?.key && t.key !== pick?.key).map((t) => (
              <TicketCard key={t.key} t={t} slug={p.slug} people={people} now={now} flash={flash.has(t.key)}
                onOpen={openTicket} onKeyDown={onCardKey} onPointerDown={onCardPointerDown} onMove={mobile ? setMoveFor : undefined} />
            ));
            const at = (i: number) => (c.key === WAITING ? items.length : Math.min(i, items.length));
            if (drag && drag.over === c.key) items.splice(at(drag.index), 0, <div key="ph" className="ph" aria-hidden="true" />);
            const picked = pick && pick.col === c.key ? find(pick.key) : undefined;
            if (pick && picked) {
              items.splice(at(pick.index), 0, <TicketCard key={picked.key} t={picked} slug={p.slug} people={people} now={now} picked onOpen={openTicket} onKeyDown={onCardKey} />);
            }
            const over = (drag && drag.over === c.key) || (pick && pick.col === c.key);
            const cls = ['col', c.key === WAITING && 'wt', c.key === tab && 'on', over && 'over'].filter(Boolean).join(' ');
            return (
              <section key={c.key} id={`col-${c.key}`} data-col={c.key} className={cls} aria-label={`${c.label}, ${c.tickets.length} tickets`}
                role={mobile ? 'tabpanel' : undefined} aria-labelledby={mobile ? `tab-${c.key}` : undefined}>
                <h2 className="colh"><span className={`dot c-${c.key}`} aria-hidden="true" />{c.label}<span className="cnt">{c.tickets.length}</span></h2>
                {c.key === WAITING && <p className="colsub">Oldest first · your answers go to the PM</p>}
                {c.key === 'done' && <p className="colsub">{doneAll ? 'All done tickets' : 'Last 7 days'}</p>}
                {items.length === 0 && !filtering && <EmptyColumn k={c.key} />}
                {items.length === 0 && filtering && <div className="cempty">No matching tickets</div>}
                {items}
                {c.key === 'done' && !doneAll && board.done_older_count > 0 && (
                  <button className="colmore" type="button" onClick={() => router.reload({ only: ['board', 'doneAll'], data: { done: 'all' } })}>Show {board.done_older_count} older</button>
                )}
                {c.key === 'done' && doneAll && (
                  <button className="colmore" type="button" onClick={() => router.visit(`/projects/${p.slug}`, { only: ['board', 'doneAll'], preserveState: true, preserveScroll: true })}>Show last 7 days only</button>
                )}
                {QUICK_ADD.includes(c.key) && <QuickAdd col={c.key} slug={p.slug} open={adding === c.key} setOpen={(v) => setAdding(v ? c.key : null)} onAdded={(key) => { own.current.add(key); reload(['board']); }} />}
              </section>
            );
          })}
        </div>
        {mobile && (
          <div className="swipe" aria-hidden="true">{keys.map((k) => <i key={k} className={k === tab ? 'on' : ''} />)}</div>
        )}
        <p className="sr-only" aria-live="assertive">{live}</p>
      </main>

      {drag && dragged && (
        <div className="ghost" style={{ left: drag.x - drag.offX, top: drag.y - drag.offY, width: drag.w }} aria-hidden="true">
          <TicketCard t={dragged} slug={p.slug} people={people} now={now} lift />
        </div>
      )}
      {colLabelsOpen && columnLabelsUrl && (
        <ColumnLabelsSheet url={columnLabelsUrl} columns={cols} defaults={defaultColumns} restoreFocus={() => colLabelsBtn.current}
          onClose={() => setColLabelsOpen(false)}
          onSaved={() => {
            setColLabelsOpen(false);
            toasts.push({ body: <p>Column labels saved</p>, icon: <IcOk />, ms: 4000 });
            reload(['board']);
          }} />
      )}
      {moveFor && (
        <MoveSheet t={moveFor} columns={cols} onClose={() => setMoveFor(null)}
          onPick={(to, ask) => {
            const t = moveFor;
            setMoveFor(null);
            if (ask) doMove(t, to, {}, ask, { undo: true });
            else requestMove({ t, to, index: 0, via: 'sheet' });
          }} />
      )}
      {askFor && (
        <AskPrompt t={askFor.t} mobile={mobile} onCancel={() => { setAskFor(null); setLive('Move cancelled.'); }}
          restoreFocus={askFor.via === 'drag' || askFor.via === 'key' ? () => document.querySelector<HTMLElement>(`.board [data-key="${askFor.t.key}"] .tkl`) : undefined}
          onSubmit={(ask) => {
            const a = askFor;
            setAskFor(null);
            doMove(a.t, a.to, {}, ask, { undo: a.via === 'sheet' });
          }} />
      )}
      {shownKey && fallback && (
        <TicketDetail key={shownKey} detail={ticket && ticket.key === shownKey ? ticket : null} fallback={fallback} project={p} people={people} labels={labels}
          columns={cols} now={now} mobile={mobile} onClose={closeTicket}
          onStatus={(t, to) => requestMove({ t, to, index: 0, via: 'detail' })}
          onChanged={() => reload(['board', 'ticket'])}
          onArchived={(t) => {
            toasts.push({
              body: <p>{t.key} archived</p>, ms: 6000,
              action: { label: 'Undo', run: () => { send('POST', `/projects/${p.slug}/tickets/${t.key}/unarchive`).then(() => reload(['board'])).catch(() => {}); } },
            });
            closeTicket();
            reload(['board']);
          }} />
      )}
    </>
  );
}

const IcColumns = () => (
  <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 3h3v10h-3zM6.5 3h3v10h-3zM10.5 3h3v10h-3z" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" /></svg>
);

function EmptyColumn({ k }: { k: ColumnKey }) {
  if (k === WAITING) {
    return <div className="cempty ok"><IcOk /><div><b>Nothing waiting on you</b><span>The PM moves tickets here when they need your call.</span></div></div>;
  }
  const names: Record<ColumnKey, string> = { backlog: 'Nothing in the backlog', todo: 'Nothing to do', in_progress: 'Nothing in progress', waiting_tilen: '', review: 'Nothing in review', done: 'Nothing done in the last 7 days' };
  return <div className="cempty"><b>{names[k]}</b>{k === 'done' ? '' : 'Drag a ticket here to start it.'}</div>;
}

function QuickAdd({ col, slug, open, setOpen, onAdded }: { col: ColumnKey; slug: string; open: boolean; setOpen: (v: boolean) => void; onAdded: (key: string) => void }) {
  const [title, setTitle] = useState('');
  const [state, setState] = useState<'idle' | 'busy' | 'err'>('idle');
  const input = useRef<HTMLTextAreaElement>(null);
  useEffect(() => { if (open) { input.current?.focus(); input.current?.scrollIntoView({ block: 'nearest' }); } }, [open]);
  const submit = async () => {
    if (!title.trim()) return;
    setState('busy');
    try {
      const r = await send<{ data: Ticket }>('POST', `/projects/${slug}/tickets`, { title: title.trim(), status: col });
      setTitle(''); setState('idle');
      onAdded(r.data.key);
    } catch {
      setState('err');
    }
  };
  if (!open) return <button className="qadd" type="button" onClick={() => setOpen(true)}><IcPlus />Add a ticket</button>;
  return (
    <form className="qform" onSubmit={(e) => { e.preventDefault(); submit(); }}>
      <label className="sr-only" htmlFor={`qa-${col}`}>New ticket title</label>
      <textarea ref={input} id={`qa-${col}`} className="qta" rows={2} maxLength={140} placeholder="Ticket title" value={title}
        onChange={(e) => setTitle(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
          if (e.key === 'Escape') { e.stopPropagation(); setOpen(false); }
        }} />
      <div className="qact">
        <button className="btn" type="submit" disabled={state === 'busy' || !title.trim()}>{state === 'busy' ? 'Adding…' : 'Add'}</button>
        <button className="abtn" type="button" onClick={() => setOpen(false)}>Cancel</button>
      </div>
      {state === 'err' ? <p className="err-line" role="alert">✕ Couldn’t add it. Your text is kept. Try again.</p> : <p className="hint">Enter adds it. The PM refines it later.</p>}
    </form>
  );
}

function FilterMenu({ filters, setFilters, n, tickets, people, labels }: {
  filters: Filters; setFilters: (f: Filters) => void; n: number; tickets: Ticket[]; people: Person[]; labels: Label[];
}) {
  // Two render paths (Tommy review b1): a bottom Sheet at the one mobile breakpoint (MOBILE, <=720px), a popover under the button above that.
  const asSheet = useMedia(MOBILE);
  const [open, setOpen] = useState(false);
  const btnRef = useRef<HTMLButtonElement>(null);
  const popRef = useRef<HTMLDivElement>(null);
  const firstRef = useRef<HTMLSelectElement>(null);
  // Esc, Done and outside click hand focus back to the Filter button (b2). The sheet's focus trap does the same on close.
  const close = useCallback(() => { setOpen(false); refocus(btnRef.current); }, []);
  useDismiss([btnRef, popRef], open && !asSheet, close);
  useEffect(() => { if (open && !asSheet) firstRef.current?.focus({ preventScroll: true }); }, [open, asSheet]);
  const assignees = [...new Set(tickets.map((t) => t.assignee_id).filter(Boolean) as string[])]
    .map((id) => ({ id, name: people.find((p) => p.id === id)?.name ?? tickets.find((t) => t.assignee_id === id)?.assignee_name ?? id }));
  const usedLabels = labels.filter((l) => tickets.some((t) => t.labels.some((x) => x.id === l.id)));
  const set = (patch: Partial<Filters>) => setFilters({ ...filters, ...patch });
  const fields = (
    <>
      <label className="flabel" htmlFor="f-as">Assignee</label>
      <select ref={firstRef} id="f-as" className="qin" value={filters.assignee} onChange={(e) => set({ assignee: e.target.value })}>
        <option value="">Anyone</option><option value="none">Unassigned</option>
        {assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
      </select>
      <label className="flabel" htmlFor="f-lb">Label</label>
      <select id="f-lb" className="qin" value={filters.label} onChange={(e) => set({ label: e.target.value })}>
        <option value="">Any label</option>
        {usedLabels.map((l) => <option key={l.id} value={l.name}>{l.name}</option>)}
      </select>
      <label className="flabel" htmlFor="f-pr">Priority</label>
      <select id="f-pr" className="qin" value={filters.priority} onChange={(e) => set({ priority: e.target.value })}>
        <option value="">Any priority</option><option value="p1">P1 · High</option><option value="p2">P2 · Medium</option><option value="p3">P3 · Normal</option>
      </select>
      <label className="chkl"><input type="checkbox" checked={filters.blocked} onChange={(e) => set({ blocked: e.target.checked })} />Blocked only</label>
      <label className="chkl"><input type="checkbox" checked={filters.replies} onChange={(e) => set({ replies: e.target.checked })} />New replies for you</label>
      <label className="chkl"><input type="checkbox" checked={filters.pickup} onChange={(e) => set({ pickup: e.target.checked })} />Your input not picked up</label>
      <div className="qact ffoot">
        <button className="abtn" type="button" onClick={() => setFilters(NO_FILTERS)} disabled={!n}>Clear filters</button>
        <button className="btn" type="button" onClick={close}>Done</button>
      </div>
    </>
  );
  return (
    <div className="pwrap">
      <button ref={btnRef} className={`fbtn${n ? ' on' : ''}`} type="button" aria-haspopup="dialog" aria-expanded={open}
        aria-label={`Filter${n ? `, ${n} active` : ''}`} onClick={() => (open ? close() : setOpen(true))}>
        <IcFilter /><span className="t" aria-hidden="true">Filter{n ? ` · ${n}` : ''}</span>
        {n > 0 && <span className="cnt" aria-hidden="true">{n}</span>}
      </button>
      {open && !asSheet && <div ref={popRef} className="pop menu fmenu" role="dialog" aria-label="Filter tickets">{fields}</div>}
      {open && asSheet && (
        <Sheet label="Filter tickets" onClose={() => setOpen(false)} initialFocus={firstRef} restoreFocus={() => btnRef.current} className="fsheet">
          <h3>Filter tickets</h3>
          <div className="sheetb fform">{fields}</div>
        </Sheet>
      )}
    </div>
  );
}
