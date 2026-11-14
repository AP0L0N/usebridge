import { router, usePage } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, useSyncExternalStore } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Avatar, MOBILE, useMedia, useToasts } from './Kanban/Bits';
import { AskPrompt } from './Kanban/MoveSheet';
import { TicketDetail } from './Kanban/TicketDetail';
import { requestJson } from '../lib/http';
import { ApiError, WAITING, send, ticketUrl, type BoardProject, type Column, type ColumnKey, type Label, type Person, type Ticket, type TicketDetail as Detail } from '../lib/kanban';
import { plainRefs, splitRefs } from '../lib/ticketRefs';
import { t as tr } from '../lib/strings';

/**
 * Ticket references in text (`[[ticket:<id>|<key>]]`, lib/ticketRefs.ts; README §11): a chip (REF / Ticket chip 84:1097)
 * with the ticket's current key and a status dot, a popover on hover / focus (84:1106), and a click that opens the ticket
 * in a modal on the current page (TicketRefHost), no navigation. Keys, titles and statuses are resolved in one batch per
 * render (GET /ticket-refs?ids=…) and cached for the page session. A ticket that is gone renders as its stored key.
 */
type Ref = {
  key: string; title: string; project_slug: string | null; archived: boolean;
  status: ColumnKey; status_label: string; assignee_id: string | null; assignee_name: string | null;
};

// ------------------------------------------------------------ resolution store (module scope: shared by every chip)
/** id → the ticket, or null when it no longer exists. Missing = not asked yet / still loading / lookup failed. */
const known = new Map<string, Ref | null>();
const queued = new Set<string>();
const asked = new Set<string>();
const listeners = new Set<() => void>();
let version = 0;
let timer: number | null = null;

const emit = () => { version++; listeners.forEach((l) => l()); };
const subscribe = (l: () => void) => { listeners.add(l); return () => { listeners.delete(l); }; };

async function flush() {
  timer = null;
  const ids = [...queued];
  queued.clear();
  for (let i = 0; i < ids.length; i += 200) {
    const chunk = ids.slice(i, i + 200);
    const r = await requestJson<{ data: Record<string, Ref> }>('GET', `/ticket-refs?ids=${chunk.map(encodeURIComponent).join(',')}`);
    // A failed lookup leaves the ids unknown: the chips keep the key stored in the token.
    if (r.ok) chunk.forEach((id) => known.set(id, r.data.data?.[id] ?? null));
  }
  emit();
}

function want(ids: string[]) {
  for (const id of ids) {
    if (asked.has(id)) continue;
    asked.add(id);
    queued.add(id);
  }
  if (queued.size && timer === null) timer = window.setTimeout(flush, 0);
}

/** A ticket changed in a modal: ask for its chips' key / status / assignee again. */
function forget(id: string) {
  asked.delete(id);
  want([id]);
}

function useResolvedRef(id: string): Ref | null | undefined {
  useSyncExternalStore(subscribe, () => version, () => version);
  useEffect(() => { want([id]); }, [id]);
  return known.get(id);
}

/** Tokens → the tickets' current keys (stored key while unknown): edit forms, aria labels, plain strings. */
export const refsToPlain = (text: string | null) => plainRefs(text, (id) => known.get(id)?.key);

// ------------------------------------------------------------ contexts
/** Where a chip was clicked: inside an open ticket modal, a chip swaps that modal's content (no stacking, §11.3). */
export type RefSource = {
  id: string;
  key: string;
  /** The modal is TicketRefHost's own; otherwise a page's (the board's) modal, which the host takes over. */
  host: boolean;
  /** The page's modal pushed its history entry (Back / close pop it). */
  pushed?: boolean;
  /** The page forgets its modal (the host now owns the ticket param). */
  release?: () => void;
  /** The page's URL without its modal (the full-page board's own modal lives at /projects/{slug}/tickets/{key}). */
  base?: string;
};
export const RefSourceCtx = createContext<RefSource | null>(null);
const OpenCtx = createContext<((id: string, key: string | null, from: RefSource | null) => void) | null>(null);

// ------------------------------------------------------------ chip + popover
const HOVER_IN = 250;
const HOVER_OUT = 120;

/**
 * One reference: a button chip (Geist Mono key, status dot; README §11.2). `inert`: inside another link (a card title
 * stretches over its card), a non-focusable chip that still opens the ticket on click; the card keeps the keyboard.
 */
export function TicketRef({ id, fallback, inert = false }: { id: string; fallback: string | null; inert?: boolean }) {
  const ref = useResolvedRef(id);
  const open = useContext(OpenCtx);
  const from = useContext(RefSourceCtx);
  const popId = useId();
  const anchor = useRef<HTMLElement | null>(null);
  const [pop, setPop] = useState(false);
  const timerRef = useRef<number | null>(null);
  const key = ref?.key ?? fallback;
  useEffect(() => () => { if (timerRef.current) window.clearTimeout(timerRef.current); }, []);

  if (ref === null) return key ? <>{key}</> : null; // unresolved: plain text, no chip, no affordance
  if (!key) return null;

  const later = (show: boolean, ms: number) => {
    if (timerRef.current) window.clearTimeout(timerRef.current);
    timerRef.current = window.setTimeout(() => setPop(show), ms);
  };
  const go = (e: MouseEvent) => {
    if (!open || e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return; // a new tab keeps working (link chips)
    e.preventDefault();
    e.stopPropagation();
    setPop(false);
    if (from?.id !== id) open(id, key, from);
  };
  const label = ref ? tr('ticketRef.aria', { key, title: ref.title }) : tr('ticketRef.ariaKey', { key });
  const body = <><span className={`tdot${ref ? ` c-${ref.status}` : ''}`} aria-hidden="true" />{key}</>;
  const hover = {
    onMouseEnter: () => later(true, HOVER_IN),
    onMouseLeave: () => later(false, HOVER_OUT),
  };
  const popover = pop && ref && anchor.current && (
    <RefPopover id={popId} anchor={anchor.current} r={ref} onEnter={() => later(true, 0)} onLeave={() => later(false, HOVER_OUT)} />
  );

  if (inert) {
    return (
      <>
        <span ref={(el) => { anchor.current = el; }} className="tref" data-ticket-ref={id} onClick={go} {...hover}>{body}</span>
        {popover}
      </>
    );
  }
  if (!open) {
    // A page without a modal host: an ordinary link to the ticket page.
    return <a ref={(el) => { anchor.current = el; }} className="tref" href={ref?.project_slug ? ticketUrl(ref.project_slug, ref.key) : undefined} aria-label={label}>{body}</a>;
  }
  return (
    <>
      <button ref={(el) => { anchor.current = el; }} type="button" className={`tref${pop ? ' hov' : ''}`} aria-label={label} aria-describedby={pop ? popId : undefined}
        onClick={go} onFocus={() => later(true, 0)} onBlur={() => later(false, 0)} {...hover}
        onKeyDown={(e) => { if (e.key === 'Escape' && pop) { e.stopPropagation(); setPop(false); } }}>{body}</button>
      {popover}
    </>
  );
}

/** REF / Ticket popover (84:1106): key, status, title, assignee. Not a dialog: a tooltip next to the chip. */
function RefPopover({ id, anchor, r, onEnter, onLeave }: { id: string; anchor: HTMLElement; r: Ref; onEnter: () => void; onLeave: () => void }) {
  const [pos, setPos] = useState<{ left: number; top: number } | null>(null);
  const el = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const a = anchor.getBoundingClientRect();
    const w = el.current?.offsetWidth ?? 280;
    const h = el.current?.offsetHeight ?? 140;
    const left = Math.max(8, Math.min(a.left, window.innerWidth - w - 8));
    const below = a.bottom + 8 + h <= window.innerHeight;
    setPos({ left, top: below ? a.bottom + 8 : Math.max(8, a.top - 8 - h) });
  }, [anchor]);
  const host = anchor.closest('.kanban') ?? document.body;
  return createPortal(
    <div ref={el} id={id} role="tooltip" className="trefpop" style={pos ? { left: pos.left, top: pos.top } : { visibility: 'hidden', left: 0, top: 0 }}
      onMouseEnter={onEnter} onMouseLeave={onLeave}>
      <div className="trp-h">
        <span className="tref"><span className={`tdot c-${r.status}`} aria-hidden="true" />{r.key}</span>
        <span className={`trp-st s-${r.status}`}>{r.status_label}</span>
      </div>
      <p className="trp-t">{r.title}</p>
      <p className="trp-a">
        {r.assignee_id ? <><Avatar id={r.assignee_id} name={r.assignee_name} type={null} />{r.assignee_name ?? r.assignee_id}</> : tr('ticketRef.unassigned')}
      </p>
      <p className="trp-hint">{tr('ticketRef.hint')}</p>
    </div>,
    host,
  );
}

/** Plain stored text with its references as chips (`inert`: inside a link, e.g. a card title). */
export function RefText({ text, inert = false }: { text: string | null | undefined; inert?: boolean }) {
  if (!text) return null;
  if (!text.includes('[[ticket:')) return <>{text}</>;
  return <>{splitRefs(text).map((p, i) => (typeof p === 'string' ? p : <TicketRef key={i} id={p.id} fallback={p.key} inert={inert} />))}</>;
}

/** A translated template with one slot filled by stored text: refTemplate(tr('board.card.blocked', {reason: SLOT}), reason). */
export const SLOT = '\u0000';
export function RefTemplate({ template, text, inert = false }: { template: string; text: string | null | undefined; inert?: boolean }) {
  const [before, after = ''] = template.split(SLOT);
  return <>{before}<RefText text={text} inert={inert} />{after}</>;
}

// ------------------------------------------------------------ modal host
type Payload = { ticket: Detail; project: BoardProject; columns: Column[]; people: Person[]; labels: Label[] };
/** A ticket in the modal: `ref` = the id from a token, or the key from a ?ticket= deep link. */
type Entry = { ref: string; key: string | null };

/** The current URL (or `base`) with ?ticket= set to `key` (or removed). */
function withTicket(key: string | null, base?: string): string {
  const u = new URL(base ?? window.location.href, window.location.origin);
  if (key) u.searchParams.set('ticket', key); else u.searchParams.delete('ticket');
  return u.pathname + u.search + u.hash;
}

const ticketParam = (url: string) => new URL(url, window.location.origin).searchParams.get('ticket');

/**
 * Opens a referenced ticket in the existing TicketDetail (centred modal on desktop, full-height sheet on phones; §11.3)
 * on whatever page this wraps. The URL carries `?ticket=KEY`: opening pushes it, a chip inside the modal swaps the
 * content in place and replaces it (the back arrow returns to the previous ticket), and Esc / close / browser Back pop
 * it. `claimed`: the page itself shows the URL's ticket (the board's own modal), so a deep link is not opened twice.
 * `reloadOnly`: the page's Inertia props to refresh after a change made in the modal.
 */
export function TicketRefHost({ children, reloadOnly, claimed = false }: { children: ReactNode; reloadOnly?: string[]; claimed?: boolean }) {
  const toasts = useToasts();
  const mobile = useMedia(MOBILE);
  const page = usePage();
  const [stack, setStack] = useState<Entry[]>(() => {
    const deep = claimed ? null : ticketParam(window.location.href);
    return deep ? [{ ref: deep, key: deep }] : [];
  });
  const [data, setData] = useState<{ ref: string; payload: Payload } | null>(null);
  const [askFor, setAskFor] = useState<{ t: Ticket; to: ColumnKey } | null>(null);
  const pushed = useRef(false);
  const only = reloadOnly?.join(',');
  const top = stack.length ? stack[stack.length - 1] : null;

  const load = useCallback(async (e: Entry, first = false) => {
    const r = await requestJson<{ data: Payload }>('GET', `/ticket-refs/${encodeURIComponent(e.ref)}`);
    if (!r.ok) {
      if (first) { setStack((s) => s.filter((x) => x !== e)); toasts.push({ tone: 'err', body: <p>{tr('ticketRef.loadErr', { key: e.key ?? '' })}</p> }); }
      return;
    }
    setData({ ref: e.ref, payload: r.data.data });
    if (first) send('POST', `/projects/${r.data.data.project.slug}/tickets/${r.data.data.ticket.key}/seen`).catch(() => {});
  }, [toasts]);

  useEffect(() => { if (top) load(top, true); }, [top, load]);

  // Browser Back (or any visit) that drops ?ticket= closes the modal.
  useEffect(() => {
    if (stack.length && !ticketParam(page.url)) { pushed.current = false; setStack([]); setData(null); setAskFor(null); }
  }, [page.url]); // eslint-disable-line react-hooks/exhaustive-deps

  // Scroll lock; re-applied after a page's own modal let go of it (a takeover).
  useEffect(() => {
    if (!stack.length) return;
    document.documentElement.classList.add('kmodal');
    return () => document.documentElement.classList.remove('kmodal');
  }, [stack.length, page.url]);

  const visit = { preserveState: true, preserveScroll: true } as const;
  const open = useCallback((id: string, key: string | null, from: RefSource | null) => {
    const entry: Entry = { ref: id, key };
    if (!from) {
      pushed.current = true;
      setStack([entry]);
      router.push({ url: withTicket(key ?? id), ...visit });
    } else if (from.host) {
      setStack((s) => [...s, entry]);
      router.replace({ url: withTicket(key ?? id), ...visit });
    } else {
      // A chip inside the page's own ticket modal: the host takes it over (same place, same history entry).
      pushed.current = !!from.pushed;
      from.release?.();
      setStack([{ ref: from.id, key: from.key }, entry]);
      router.replace({ url: withTicket(key ?? id, from.base), props: (p: Record<string, unknown>) => ({ ...p, ticket: null }), ...visit });
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const back = useCallback(() => {
    const prev = stack[stack.length - 2];
    if (!prev) return;
    setStack((s) => s.slice(0, -1));
    router.replace({ url: withTicket(prev.key ?? prev.ref), ...visit });
  }, [stack]); // eslint-disable-line react-hooks/exhaustive-deps

  const close = useCallback(() => {
    setStack([]); setData(null); setAskFor(null);
    if (pushed.current) { pushed.current = false; window.history.back(); }
    else router.replace({ url: withTicket(null), ...visit });
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const changed = useCallback(() => {
    if (top) load(top);
    if (data) forget(data.payload.ticket.id);
    if (only) router.reload({ only: only.split(','), async: true } as never);
  }, [top, data, load, only]);

  const payload = top && data && data.ref === top.ref ? data.payload : null;
  const move = async (t: Ticket, to: ColumnKey, ask?: string) => {
    try {
      await send('POST', `/projects/${payload?.project.slug}/tickets/${t.key}/move`, { status: to, ...(to === WAITING ? {} : { position: 0 }), ...(ask ? { ask } : {}) });
      changed();
    } catch (e) {
      toasts.push({ tone: 'err', body: <p>{e instanceof ApiError && e.status === 422 ? e.message : tr('toast.moveErrTry', { key: t.key })}</p> });
    }
  };
  const prev = stack.length > 1 ? stack[stack.length - 2] : null;

  return (
    <OpenCtx.Provider value={open}>
      {children}
      {payload && (
        <RefSourceCtx.Provider value={{ id: payload.ticket.id, key: payload.ticket.key, host: true }}>
          <TicketDetail key={payload.ticket.id} detail={payload.ticket} fallback={payload.ticket} project={payload.project} people={payload.people} labels={payload.labels}
            columns={payload.columns} now={new Date().toISOString()} mobile={mobile} onClose={close}
            back={prev ? { key: prev.key ?? prev.ref, onBack: back } : undefined}
            onStatus={(t, to) => {
              if (to === t.status) return;
              if (to === WAITING && !t.ask) setAskFor({ t, to });
              else move(t, to);
            }}
            onChanged={changed}
            onArchived={(t) => {
              toasts.push({ body: <p>{tr('toast.archived', { key: t.key })}</p>, ms: 6000 });
              close();
              if (only) router.reload({ only: only.split(','), async: true } as never);
            }} />
        </RefSourceCtx.Provider>
      )}
      {askFor && payload && (
        <AskPrompt ticket={askFor.t} column={payload.columns.find((c) => c.key === WAITING)?.label ?? WAITING} mobile={mobile} onCancel={() => setAskFor(null)}
          onSubmit={(ask) => { const a = askFor; setAskFor(null); move(a.t, a.to, ask); }} />
      )}
    </OpenCtx.Provider>
  );
}
