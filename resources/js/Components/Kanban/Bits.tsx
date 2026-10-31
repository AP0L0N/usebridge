import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent, ReactNode, RefObject } from 'react';
import { IconArchive14, IconCal16, IconChev, IconClock14, IconFilter16, IconHist16, IconLeft16, IconMore16, IconMsg12, IconPlus16, IconSearch16, IconX16 } from '../AppIcons';
import { IconOk, IconWait } from '../Icons';
import { ageAria, t } from '../../lib/strings';
import { ago } from '../../lib/dates';
import { initial, viaLabel, type Label, type PmStatus, type Person, type WriteSource } from '../../lib/kanban';

// Icons from the mock (build.py `I`), geometry matched to the Figma icon components.
const svg16 = (d: ReactNode) => <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">{d}</svg>; // sized: unsized SVGs grow to 300px in flex rows
const st = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;
export const IcComment = IconMsg12;
export const IcCal = IconCal16;
export const IcFlag = () => <span className="pri-dot" aria-hidden="true" />;
export const IcPlus = IconPlus16;
export const IcFilter = IconFilter16;
export const IcChev = IconChev;
export const IcMove = () => svg16(<path d="M8.66667 10.6667L11.3333 13.3333L14 10.6667M11.3333 13.3333V2.66667M7.33333 5.33333L4.66667 2.66667L2 5.33333M4.66667 2.66667V13.3333" {...st} strokeWidth="1.33333" />);
export const IcHist = IconHist16;
export const IcSearch = IconSearch16;
export const IcBack = IconLeft16;
export const IcX = IconX16;
export const IcMore = IconMore16;
export const IcEdit = () => svg16(<path d="M8 13.3333H14M11 2.33333C11.2652 2.06812 11.6249 1.91912 12 1.91912C12.3751 1.91912 12.7348 2.06812 13 2.33333C13.2652 2.59855 13.4142 2.95826 13.4142 3.33333C13.4142 3.70841 13.2652 4.06812 13 4.33333L4.66667 12.6667L2 13.3333L2.66667 10.6667L11 2.33333Z" {...st} strokeWidth="1.33333" />);
export const IcClock = IconClock14;
export const IcApi = () => svg16(<path d="M5.5 4L2 8l3.5 4M10.5 4L14 8l-3.5 4" {...st} strokeWidth="1.7" />);
export const IcReply = () => svg16(<path d="M6.5 4L2.5 7.5 6.5 11M2.5 7.5H10a3.5 3.5 0 013.5 3.5v1" {...st} strokeWidth="1.7" />);
export { IconOk as IcOk, IconWait as IcWait, IconArchive14 as IcArch };

export function Pips({ level }: { level: number }) {
  return <span className="pips" aria-hidden="true">{[0, 1, 2].map((i) => <i key={i} className={i < level ? '' : 'off'} />)}</span>;
}

/** Waiting-age pill (cards, the Your input box): warn pill, dot + "<1d"/"2d" (text, so colour is never the only signal). */
export function WaitAge({ level, days, long = false }: { level: number; days: number; long?: boolean }) {
  const txt = long ? `Waiting ${days < 1 ? 'less than 1 day' : `${days} ${days === 1 ? 'day' : 'days'}`}` : days < 1 ? '<1d' : `${days}d`;
  return <span className={`wage w${level}`}><span className="dotk" aria-hidden="true" />{txt}{!long && <span className="sr-only"> waiting</span>}</span>;
}

export function LabelChip({ label }: { label: Label }) {
  return <span className="lb">{label.name}</span>;
}

/** 24px radius-12 avatar: agents violet with an initial, humans ink, unassigned a dashed circle. */
export function Avatar({ id, name, type, lg = false }: { id: string | null; name: string | null; type: 'agent' | 'human' | null; lg?: boolean }) {
  if (!id) return <i className={`av none${lg ? ' lg' : ''}`} title="Unassigned" aria-hidden="true">?</i>;
  return <i className={`av${type === 'human' ? ' h' : ''}${lg ? ' lg' : ''}`} title={name ?? id} aria-hidden="true">{initial(name ?? id)}</i>;
}

export const personType = (id: string | null, people: Person[]): 'agent' | 'human' | null =>
  id ? (people.find((p) => p.id === id)?.type ?? 'human') : null;

/** Project-status badge (the PM's status): waiting = warn tint with dot + pips + age; smooth = ok tint. */
export function PmBadge({ status, level, ageDays }: { status: PmStatus | null; level?: number | null; ageDays?: number | null }) {
  if (status === 'smooth') return <span className="badge b-ok"><span className="bdot" aria-hidden="true" /><span>{t('status.smooth')}</span></span>;
  if (status === 'archived') return <span className="badge b-arch"><span className="bdot" aria-hidden="true" /><IconArchive14 /><span>{t('status.archived')}</span></span>;
  if (!status) return <span className="badge b-arch"><span className="bdot" aria-hidden="true" /><span>{t('portfolio.status.none')}</span></span>;
  const days = ageDays ?? 0;
  const lvl = level ?? 1;
  return (
    <span className="badge" aria-label={ageAria(days)} title={ageAria(days)}>
      <span className="bdot" aria-hidden="true" />
      <span aria-hidden="true">{t('status.waiting')}</span>
      <Pips level={lvl} />
      <span className="bage" aria-hidden="true">{days < 1 ? t('portfolio.age.lt1') : t('status.waiting.age', { n: days })}</span>
    </span>
  );
}

/** History rows: the badge without the age (the age belongs to the current status only). */
export function PlainBadge({ status }: { status: PmStatus | null }) {
  if (status === 'smooth') return <span className="badge b-ok"><span className="bdot" aria-hidden="true" /><span>{t('status.smooth')}</span></span>;
  if (status === 'archived') return <span className="badge b-arch"><span className="bdot" aria-hidden="true" /><IconArchive14 /><span>{t('status.archived')}</span></span>;
  if (status === 'waiting') return <span className="badge"><span className="bdot" aria-hidden="true" /><span>{t('status.waiting')}</span></span>;
  return <span className="badge b-arch"><span className="bdot" aria-hidden="true" /><span>{t('portfolio.status.none')}</span></span>;
}

export function useMedia(query: string): boolean {
  const get = () => typeof window !== 'undefined' && window.matchMedia(query).matches;
  const [m, setM] = useState(get);
  useEffect(() => {
    const mq = window.matchMedia(query);
    const on = () => setM(mq.matches);
    on();
    mq.addEventListener('change', on);
    return () => mq.removeEventListener('change', on);
  }, [query]);
  return m;
}

export const MOBILE = '(max-width: 720px)';

/** Close on outside click / Escape (menus, popovers). `refs`: everything that counts as inside (e.g. the opener + the popover). */
export function useDismiss(refs: RefObject<HTMLElement | null> | RefObject<HTMLElement | null>[], open: boolean, onClose: (how: 'outside' | 'escape') => void) {
  const list = useRef(refs);
  list.current = refs;
  useEffect(() => {
    if (!open) return;
    const inside = (n: Node) => (Array.isArray(list.current) ? list.current : [list.current]).some((r) => r.current?.contains(n));
    const down = (e: PointerEvent) => { if (!inside(e.target as Node)) onClose('outside'); };
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') { e.stopPropagation(); onClose('escape'); } };
    document.addEventListener('pointerdown', down);
    document.addEventListener('keydown', key, true);
    return () => { document.removeEventListener('pointerdown', down); document.removeEventListener('keydown', key, true); };
  }, [open, onClose]);
}


/**
 * Focus `el` now, and again after the current pointer event if the click landed on something non-focusable
 * (the browser's mousedown default would otherwise drop focus to <body>). A click into another field keeps its focus.
 */
export function refocus(el: HTMLElement | null | undefined) {
  if (!el) return;
  el.focus({ preventScroll: true });
  window.setTimeout(() => {
    const a = document.activeElement;
    if ((!a || a === document.body) && document.contains(el)) el.focus({ preventScroll: true });
  }, 0);
}

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/** Open dialogs, outermost first. Esc and Tab belong to the top one only (a sheet over a ticket, a confirm over a sheet). */
const layers: symbol[] = [];

/**
 * Keep Tab inside `ref` while active; Escape calls onEscape. On unmount focus goes back to the opener, or to
 * `restore()` when given (e.g. the dragged card, which was not focused when the dialog opened).
 */
export function useFocusTrap(ref: RefObject<HTMLElement | null>, onEscape: () => void, initialFocus?: RefObject<HTMLElement | null>, restore?: () => HTMLElement | null | undefined) {
  const esc = useRef(onEscape);
  esc.current = onEscape;
  const back = useRef(restore);
  back.current = restore;
  useEffect(() => {
    const layer = Symbol('layer');
    layers.push(layer);
    const opener = document.activeElement as HTMLElement | null;
    (initialFocus?.current ?? ref.current?.querySelector<HTMLElement>(FOCUSABLE))?.focus({ preventScroll: true });
    const key = (e: KeyboardEvent) => {
      const root = ref.current;
      if (!root || layers[layers.length - 1] !== layer) return;
      if (e.key === 'Escape' && !e.defaultPrevented) { e.preventDefault(); esc.current(); return; }
      if (e.key !== 'Tab') return;
      const els = [...root.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => el.offsetParent !== null);
      if (!els.length) return;
      const first = els[0], last = els[els.length - 1];
      if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', key);
    return () => {
      document.removeEventListener('keydown', key);
      layers.splice(layers.indexOf(layer), 1);
      const to = back.current?.() ?? opener;
      if (to && document.contains(to)) refocus(to);
    };
  }, [ref, initialFocus]);
}

/**
 * Scrim dismiss. Fires only when the press went down AND came up on this overlay's own scrim, outside `panel`:
 * a text-selection drag that starts in the panel and ends on the scrim does not dismiss, and a press inside a
 * nested overlay (a sheet over a ticket) does not dismiss the ticket underneath.
 */
export function useScrimDismiss(panel: RefObject<HTMLElement | null>, onDismiss: () => void) {
  const downOnScrim = useRef(false);
  const onScrim = (e: ReactPointerEvent<HTMLElement>) => {
    const target = e.target as Element;
    return target.closest('.ovl') === e.currentTarget && !panel.current?.contains(target);
  };
  return {
    onPointerDown: (e: ReactPointerEvent<HTMLElement>) => { downOnScrim.current = onScrim(e); },
    onPointerUp: (e: ReactPointerEvent<HTMLElement>) => {
      const was = downOnScrim.current;
      downOnScrim.current = false;
      if (was && onScrim(e)) onDismiss();
    },
  };
}

type SheetProps = {
  label: string;
  onClose: () => void;
  children: ReactNode;
  className?: string;
  initialFocus?: RefObject<HTMLElement | null>;
  restoreFocus?: () => HTMLElement | null | undefined;
};

/** Bottom sheet (mobile) / centred panel: scrim, focus trap, Esc closes. */
export function Sheet({ label, onClose, children, className = '', initialFocus, restoreFocus }: SheetProps) {
  const ref = useRef<HTMLDivElement>(null);
  useFocusTrap(ref, onClose, initialFocus, restoreFocus);
  const scrim = useScrimDismiss(ref, onClose);
  return (
    <div className="ovl sheetov" {...scrim}>
      <div ref={ref} className={`sheet ${className}`} role="dialog" aria-modal="true" aria-label={label}>
        <div className="grab" aria-hidden="true" />
        {children}
      </div>
    </div>
  );
}

/** Confirm dialog on the existing Sheet (scrim, focus trap, Esc/scrim = cancel). `message` is the question, shown verbatim. */
export function ConfirmSheet({ message, confirmLabel, busy = false, error, onConfirm, onCancel, restoreFocus }: {
  message: string; confirmLabel: string; busy?: boolean; error?: string | null; onConfirm: () => void; onCancel: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const cancelRef = useRef<HTMLButtonElement>(null);
  return (
    <Sheet label={message} className="cfm" onClose={onCancel} initialFocus={cancelRef} restoreFocus={restoreFocus}>
      <h3>{message}</h3>
      <div className="qact sheetb">
        <button ref={cancelRef} className="btn" type="button" onClick={onCancel}>{t('cancel')}</button>
        <button className="abtn dng" type="button" disabled={busy} onClick={onConfirm}>{busy ? <><span className="spin" />{t('q.saving')}</> : confirmLabel}</button>
      </div>
      {error && <p className="err-line sheetb" role="alert">{error}</p>}
    </Sheet>
  );
}

// ---------------------------------------------------------------- toasts

export type Toast = { id: number; body: ReactNode; tone?: 'dark' | 'err'; action?: { label: string; run: () => void }; ms?: number; icon?: ReactNode };
type ToastApi = { push: (t: Omit<Toast, 'id'>) => number; dismiss: (id: number) => void };
const ToastCtx = createContext<ToastApi>({ push: () => 0, dismiss: () => {} });
export const useToasts = () => useContext(ToastCtx);

export function ToastHost({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Toast[]>([]);
  const seq = useRef(0);
  const dismiss = useCallback((id: number) => setItems((xs) => xs.filter((x) => x.id !== id)), []);
  const push = useCallback((t: Omit<Toast, 'id'>) => {
    const id = ++seq.current;
    setItems((xs) => [...xs.slice(-2), { ...t, id }]);
    if (t.ms !== 0) window.setTimeout(() => dismiss(id), t.ms ?? 5000);
    return id;
  }, [dismiss]);
  return (
    <ToastCtx.Provider value={{ push, dismiss }}>
      {children}
      <div className="toasts" aria-live="polite">
        {items.map((t) => (
          <div key={t.id} className={`toast${t.tone === 'err' ? ' err' : ''}`} role={t.tone === 'err' ? 'alert' : 'status'}>
            {t.icon}
            <div className="tbody">{t.body}</div>
            {t.action && <button type="button" className="tundo" onClick={() => { t.action!.run(); dismiss(t.id); }}>{t.action.label}</button>}
            <button type="button" className="tx" aria-label="Dismiss" onClick={() => dismiss(t.id)}><IconX16 /></button>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}

/** "via MCP · Builder · 12m" / "via API · …" card line (agent-authored latest comment). */
export function ViaLine({ name, at, now, source }: { name: string; at: string | null; now: string; source?: WriteSource | null }) {
  return <span className="via"><span className="agentdot" aria-hidden="true" />{viaLabel(source, true)} · {name}{at ? <> · {ago(at, now)}</> : null}</span>;
}
