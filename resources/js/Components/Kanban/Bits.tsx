import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import { StatusBadge } from '../StatusBadge';
import { IconArch, IconOk, IconWait } from '../Icons';
import { initial, type Label, type PmStatus, type Person } from '../../lib/kanban';

// Icons from the mock (build.py `I`).
const svg16 = (d: ReactNode) => <svg viewBox="0 0 16 16" aria-hidden="true">{d}</svg>;
const st = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;
export const IcComment = () => svg16(<path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z" {...st} strokeWidth="1.6" />);
export const IcCal = () => svg16(<><rect x="2" y="3" width="12" height="11" rx="2" fill="none" stroke="currentColor" strokeWidth="1.6" /><path d="M2 6.5h12M5.5 1.5v3M10.5 1.5v3" {...st} strokeWidth="1.6" /></>);
export const IcFlag = () => svg16(<path d="M3.5 14V2.5M3.5 2.5h8l-1.8 3 1.8 3h-8" {...st} strokeWidth="1.7" />);
export const IcPlus = () => svg16(<path d="M8 3v10M3 8h10" {...st} strokeWidth="2" />);
export const IcFilter = () => svg16(<path d="M2 3.5h12M4.5 8h7M7 12.5h2" {...st} strokeWidth="1.8" />);
export const IcChev = () => svg16(<path d="M4 6l4 4 4-4" {...st} strokeWidth="1.8" />);
export const IcMove = () => svg16(<path d="M2.5 8h10M9 4.5L12.5 8 9 11.5" {...st} strokeWidth="1.8" />);
export const IcHist = () => svg16(<path d="M2.5 8a5.5 5.5 0 101.6-3.9M2.5 2.5v2.6h2.6M8 5v3.2l2.2 1.4" {...st} strokeWidth="1.6" />);
export const IcBlock = () => svg16(<><circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" strokeWidth="1.7" /><path d="M3.8 12.2l8.4-8.4" stroke="currentColor" strokeWidth="1.7" /></>);
export const IcClock = () => svg16(<><circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" strokeWidth="1.7" /><path d="M8 4.6V8l2.3 1.5" {...st} strokeWidth="1.7" /></>);
export const IcApi = () => svg16(<path d="M5.5 4L2 8l3.5 4M10.5 4L14 8l-3.5 4" {...st} strokeWidth="1.7" />);
export const IcReply = () => svg16(<path d="M6.5 4L2.5 7.5 6.5 11M2.5 7.5H10a3.5 3.5 0 013.5 3.5v1" {...st} strokeWidth="1.7" />);
export const IcX = () => <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>;
export const IcBack = () => <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 4.5L7 10l5.5 5.5" {...st} strokeWidth="2" /></svg>;
export const IcMore = () => <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="4.5" cy="10" r="1.6" fill="currentColor" /><circle cx="10" cy="10" r="1.6" fill="currentColor" /><circle cx="15.5" cy="10" r="1.6" fill="currentColor" /></svg>;
export { IconOk as IcOk, IconWait as IcWait };

export function Pips({ level }: { level: number }) {
  return <span className="pips" aria-hidden="true">{[0, 1, 2].map((i) => <i key={i} className={i < level ? 'on' : ''} />)}</span>;
}

/** Waiting-age pill on cards: pips + "1d" (text, so colour is never the only signal). */
export function WaitAge({ level, days, long = false }: { level: number; days: number; long?: boolean }) {
  const txt = long ? `Waiting ${days < 1 ? 'less than 1 day' : `${days} ${days === 1 ? 'day' : 'days'}`}` : days < 1 ? '<1d' : `${days}d`;
  return <span className={`wage w${level}`}><Pips level={level} />{txt}{!long && <span className="sr-only"> waiting</span>}</span>;
}

export function LabelChip({ label }: { label: Label }) {
  return <span className={`lb l-${label.color}`}>{label.name}</span>;
}

/** 22px avatar: agents navy with an initial, humans (Tilen) ink, unassigned a dashed circle. */
export function Avatar({ id, name, type, lg = false }: { id: string | null; name: string | null; type: 'agent' | 'human' | null; lg?: boolean }) {
  if (!id) return <i className={`av none${lg ? ' lg' : ''}`} title="Unassigned" aria-hidden="true">?</i>;
  return <i className={`av${type === 'human' ? ' h' : ''}${lg ? ' lg' : ''}`} title={name ?? id} aria-hidden="true">{initial(name ?? id)}</i>;
}

export const personType = (id: string | null, people: Person[]): 'agent' | 'human' | null =>
  id ? (people.find((p) => p.id === id)?.type ?? 'human') : null;

/** PM-set project status badge: the existing StatusBadge, plus grey "Status not set" for null. */
export function PmBadge({ status, level, ageDays }: { status: PmStatus | null; level?: number | null; ageDays?: number | null }) {
  if (!status) return <span className="badge b-arch" aria-label="Status not set"><span>Status not set</span></span>;
  return <StatusBadge status={status} level={level} ageDays={ageDays} />;
}

/** History rows: badge without the age (the age belongs to the current status only). */
export function PlainBadge({ status }: { status: PmStatus | null }) {
  if (status === 'smooth') return <span className="badge b-ok"><IconOk /><span>Smooth sailing</span></span>;
  if (status === 'archived') return <span className="badge b-arch"><IconArch /><span>Archived</span></span>;
  if (status === 'waiting') return <span className="badge b-w1"><IconWait /><span>Waiting input</span></span>;
  return <span className="badge b-arch"><span>Status not set</span></span>;
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
    const opener = document.activeElement as HTMLElement | null;
    (initialFocus?.current ?? ref.current?.querySelector<HTMLElement>(FOCUSABLE))?.focus({ preventScroll: true });
    const key = (e: KeyboardEvent) => {
      const root = ref.current;
      if (!root) return;
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
      const to = back.current?.() ?? opener;
      if (to && document.contains(to)) refocus(to);
    };
  }, [ref, initialFocus]);
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
  return (
    <div className="ovl sheetov" onPointerDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div ref={ref} className={`sheet ${className}`} role="dialog" aria-modal="true" aria-label={label}>
        <div className="grab" aria-hidden="true" />
        {children}
      </div>
    </div>
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
            {t.action && <button type="button" className="lnk" onClick={() => { t.action!.run(); dismiss(t.id); }}>{t.action.label}</button>}
            <button type="button" className="tx" aria-label="Dismiss" onClick={() => dismiss(t.id)}><IcX /></button>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}
