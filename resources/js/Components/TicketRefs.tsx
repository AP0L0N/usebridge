import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useState, useSyncExternalStore } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import { MOBILE, useMedia, useToasts } from './Kanban/Bits';
import { AskPrompt } from './Kanban/MoveSheet';
import { TicketDetail } from './Kanban/TicketDetail';
import { requestJson } from '../lib/http';
import { ApiError, WAITING, send, ticketUrl, type BoardProject, type Column, type ColumnKey, type Label, type Person, type Ticket, type TicketDetail as Detail } from '../lib/kanban';
import { plainRefs, splitRefs } from '../lib/ticketRefs';
import { t as tr } from '../lib/strings';

/**
 * Ticket references in text (`[[ticket:<id>|<key>]]`, lib/ticketRefs.ts): a chip with the ticket's current key that
 * opens the ticket in a modal on the current page (TicketRefHost), no navigation. Keys and titles are resolved in one
 * batch per render (GET /ticket-refs?ids=…) and cached for the page session.
 */
type Ref = { key: string; title: string; project_slug: string | null; archived: boolean };

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

function useResolvedRef(id: string): Ref | null | undefined {
  useSyncExternalStore(subscribe, () => version, () => version);
  useEffect(() => { want([id]); }, [id]);
  return known.get(id);
}

/** Tokens → the tickets' current keys (stored key while unknown): edit forms, aria labels, plain strings. */
export const refsToPlain = (text: string | null) => plainRefs(text, (id) => known.get(id)?.key);

// ------------------------------------------------------------ rendering
const OpenCtx = createContext<((id: string, key: string | null) => void) | null>(null);

/** One reference: a link-styled chip (current key, title on hover); plain text when the ticket is gone. */
export function TicketRef({ id, fallback, plain = false }: { id: string; fallback: string | null; plain?: boolean }) {
  const ref = useResolvedRef(id);
  const open = useContext(OpenCtx);
  const key = ref?.key ?? fallback;
  if (ref === null || plain) return key ? <>{key}</> : null;
  if (!key) return null;
  const label = ref ? tr('ticketRef.aria', { key, title: ref.title }) : tr('ticketRef.ariaKey', { key });
  const onClick = (e: MouseEvent<HTMLAnchorElement>) => {
    if (!open || e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return; // a new tab keeps working
    e.preventDefault();
    e.stopPropagation();
    open(id, key);
  };
  return (
    <a className="tref" href={ref?.project_slug ? ticketUrl(ref.project_slug, ref.key) : undefined} role={ref?.project_slug ? undefined : 'button'} tabIndex={0}
      title={ref?.title} aria-label={label} onClick={onClick}
      onKeyDown={(e) => { if (!ref?.project_slug && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open?.(id, key); } }}>{key}</a>
  );
}

/** Plain stored text with its references as chips (`plain`: as their current keys, e.g. inside a card link). */
export function RefText({ text, plain = false }: { text: string | null | undefined; plain?: boolean }) {
  if (!text) return null;
  if (!text.includes('[[ticket:')) return <>{text}</>;
  return <>{splitRefs(text).map((p, i) => (typeof p === 'string' ? p : <TicketRef key={i} id={p.id} fallback={p.key} plain={plain} />))}</>;
}

/** A translated template with one slot filled by stored text: refTemplate(tr('board.card.blocked', {reason: SLOT}), reason). */
export const SLOT = '\u0000';
export function RefTemplate({ template, text, plain = false }: { template: string; text: string | null | undefined; plain?: boolean }) {
  const [before, after = ''] = template.split(SLOT);
  return <>{before}<RefText text={text} plain={plain} />{after}</>;
}

// ------------------------------------------------------------ modal host
type Payload = { ticket: Detail; project: BoardProject; columns: Column[]; people: Person[]; labels: Label[] };

/**
 * Opens a referenced ticket in the existing TicketDetail modal on whatever page this wraps (Esc / backdrop close it,
 * like on the board). `reloadOnly`: the page's Inertia props to refresh after a change made in the modal.
 */
export function TicketRefHost({ children, reloadOnly }: { children: ReactNode; reloadOnly?: string[] }) {
  const toasts = useToasts();
  const mobile = useMedia(MOBILE);
  const [openId, setOpenId] = useState<string | null>(null);
  const [data, setData] = useState<Payload | null>(null);
  const [askFor, setAskFor] = useState<{ t: Ticket; to: ColumnKey } | null>(null);
  const only = reloadOnly?.join(',');

  const load = useCallback(async (id: string, key: string | null, first = false) => {
    const r = await requestJson<{ data: Payload }>('GET', `/ticket-refs/${encodeURIComponent(id)}`);
    if (!r.ok) {
      if (first) { setOpenId(null); toasts.push({ tone: 'err', body: <p>{tr('ticketRef.loadErr', { key: key ?? '' })}</p> }); }
      return;
    }
    setData(r.data.data);
    if (first) send('POST', `/projects/${r.data.data.project.slug}/tickets/${r.data.data.ticket.key}/seen`).catch(() => {});
  }, [toasts]);

  const open = useCallback((id: string, key: string | null) => {
    setOpenId(id);
    setData((d) => (d?.ticket.id === id ? d : null));
    load(id, key, true);
  }, [load]);
  const close = useCallback(() => { setOpenId(null); setData(null); setAskFor(null); }, []);
  const changed = useCallback(() => {
    if (openId) load(openId, null);
    if (only) router.reload({ only: only.split(','), async: true } as never);
  }, [openId, load, only]);

  useEffect(() => {
    if (!openId) return;
    document.documentElement.classList.add('kmodal');
    return () => document.documentElement.classList.remove('kmodal');
  }, [openId]);

  const move = async (t: Ticket, to: ColumnKey, ask?: string) => {
    try {
      await send('POST', `/projects/${data?.project.slug}/tickets/${t.key}/move`, { status: to, ...(to === WAITING ? {} : { position: 0 }), ...(ask ? { ask } : {}) });
      changed();
    } catch (e) {
      toasts.push({ tone: 'err', body: <p>{e instanceof ApiError && e.status === 422 ? e.message : tr('toast.moveErrTry', { key: t.key })}</p> });
    }
  };

  return (
    <OpenCtx.Provider value={open}>
      {children}
      {openId && data && data.ticket.id === openId && (
        <TicketDetail key={openId} detail={data.ticket} fallback={data.ticket} project={data.project} people={data.people} labels={data.labels}
          columns={data.columns} now={new Date().toISOString()} mobile={mobile} onClose={close}
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
      )}
      {askFor && data && (
        <AskPrompt ticket={askFor.t} column={data.columns.find((c) => c.key === WAITING)?.label ?? WAITING} mobile={mobile} onCancel={() => setAskFor(null)}
          onSubmit={(ask) => { const a = askFor; setAskFor(null); move(a.t, a.to, ask); }} />
      )}
    </OpenCtx.Provider>
  );
}
