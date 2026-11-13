/**
 * Workstream building blocks (Figma components): WS / Count badge, WS / Unread dot, the "⋯" action menu, and small icons.
 *
 *   <CountBadge n={3} inverse={selected} />   // hidden at 0, "99+" over the cap
 *   <UnreadDot on={selected} />               // agent violet; signal lime on a selected (ink) row
 *   <ActionMenu label="Frontend actions" items={[{ label: 'Rename', onSelect }]} />
 */
import { useCallback, useId, useRef, useState, type ReactNode } from 'react';
import { refocus, useDismiss } from '../Kanban/Bits';
import { badgeText } from '../../lib/workstreams';
import { t } from '../../lib/strings-workstreams';

export function CountBadge({ n, inverse = false }: { n: number; inverse?: boolean }) {
  const text = badgeText(n);
  if (!text) return null;
  return <span className={`ws-badge${inverse ? ' inv' : ''}`} aria-label={t('fd.ws.badge', { n: text })}>{text}</span>;
}

export function UnreadDot({ on = false }: { on?: boolean }) {
  return <span className={`ws-dot${on ? ' on' : ''}`} role="img" aria-label={t('fd.project.unread')} />;
}

const svg = (d: string, size = 16) => (
  <svg width={size} height={size} viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d={d} stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" /></svg>
);
export const IcRight = () => svg('M6 4l4 4-4 4');
export const IcLeft = () => svg('M10 4L6 8l4 4');
export const IcArrowRight = () => svg('M3 8h10M9 4l4 4-4 4');
export const IcArrowLeft = () => svg('M13 8H3M7 4L3 8l4 4');
export const IcPlus = () => svg('M8 3v10M3 8h10');
export const IcEdit = () => svg('M8 13.3h6M11 2.3a1.4 1.4 0 012 2L4.7 12.7 2 13.3l.7-2.6L11 2.3z');
export const IcTrash = () => svg('M2.7 4h10.6M6 4V2.7h4V4m2 0l-.6 9.3H4.6L4 4');
export const IcArchive = () => svg('M2 3h12v3H2zM3 6v7h10V6M6.5 9h3');
export const IcHistory = () => svg('M2.5 8a5.5 5.5 0 105.5-5.5A5.6 5.6 0 004 4.3L2.5 6M2.5 3v3h3M8 5v3l2 1.5');
export const IcLines = () => svg('M3 5h10M3 8h10M3 11h7');
export const IcUp = () => svg('M8 13V3M4 7l4-4 4 4');
export const IcDown = () => svg('M8 3v10M4 9l4 4 4-4');
export const IcStar = () => svg('M8 2.5l1.7 3.4 3.8.5-2.7 2.7.6 3.8L8 11.1l-3.4 1.8.6-3.8L2.5 6.4l3.8-.5L8 2.5z');
export const IcFolder = () => svg('M2 4.5h4.5l1.5 1.5H14v6.5H2z');
export const IcMore = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="3.5" cy="8" r="1.2" fill="currentColor" /><circle cx="8" cy="8" r="1.2" fill="currentColor" /><circle cx="12.5" cy="8" r="1.2" fill="currentColor" /></svg>
);

export type MenuItem = { label: string; icon?: ReactNode; danger?: boolean; disabled?: boolean; onSelect: () => void } | 'sep';

/** "⋯" button with a popover menu (roving arrows, Esc / outside click close, focus back on the button). */
export function ActionMenu({ label, items, className = '', buttonText, onOpenChange }: {
  label: string; items: MenuItem[]; className?: string; buttonText?: string; onOpenChange?: (open: boolean) => void;
}) {
  const [open, setOpenState] = useState(false);
  const [pos, setPos] = useState<{ top?: number; bottom?: number; right: number }>({ right: 0 });
  const btn = useRef<HTMLButtonElement>(null);
  const pop = useRef<HTMLDivElement>(null);
  const id = useId();
  // Fixed to the viewport so a scrolling column never clips it; opens upwards when there is no room below.
  const setOpen = useCallback((v: boolean) => {
    const r = btn.current?.getBoundingClientRect();
    if (v && r) {
      const right = window.innerWidth - r.right;
      setPos(window.innerHeight - r.bottom < 360 && r.top > 360 ? { bottom: window.innerHeight - r.top + 4, right } : { top: r.bottom + 4, right });
    }
    setOpenState(v);
    onOpenChange?.(v);
  }, [onOpenChange]);
  const close = useCallback(() => { setOpen(false); refocus(btn.current); }, [setOpen]);
  useDismiss([btn, pop], open, close);
  const focusItem = (i: number) => {
    const els = [...(pop.current?.querySelectorAll<HTMLButtonElement>('button:not([disabled])') ?? [])];
    els[(i + els.length) % els.length]?.focus();
  };
  return (
    <div className={`ws-menu ${className}`} onClick={(e) => e.stopPropagation()} onDoubleClick={(e) => e.stopPropagation()}>
      <button ref={btn} type="button" className={buttonText ? 'ws-actbtn' : 'ws-more'} aria-haspopup="menu" aria-expanded={open} aria-controls={open ? id : undefined}
        aria-label={buttonText ? undefined : label} onClick={() => (open ? close() : (setOpen(true), window.setTimeout(() => focusItem(0), 0)))}
        onKeyDown={(e) => { if (e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); setOpen(true); window.setTimeout(() => focusItem(0), 0); } }}>
        <IcMore />{buttonText && <span>{buttonText}</span>}
      </button>
      {open && (
        <div ref={pop} id={id} className="ws-pop" role="menu" aria-label={label} style={pos}
          onKeyDown={(e) => {
            const els = [...(pop.current?.querySelectorAll<HTMLButtonElement>('button:not([disabled])') ?? [])];
            const i = els.indexOf(document.activeElement as HTMLButtonElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); focusItem(i + 1); }
            if (e.key === 'ArrowUp') { e.preventDefault(); e.stopPropagation(); focusItem(i - 1); }
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') e.stopPropagation();
            if (e.key === 'Tab') setOpen(false);
          }}>
          {items.map((it, i) => it === 'sep'
            ? <div key={`s${i}`} className="ws-sep" role="separator" />
            : (
              <button key={it.label} type="button" role="menuitem" className={it.danger ? 'dng' : ''} disabled={it.disabled}
                onClick={() => { setOpen(false); it.onSelect(); }}>
                {it.icon}<span>{it.label}</span>
              </button>
            ))}
        </div>
      )}
    </div>
  );
}
