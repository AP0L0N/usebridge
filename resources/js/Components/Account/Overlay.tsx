import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { IconCheck, IconClose } from './Fields';

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/**
 * Dialog (.mdl): aria-modal, labelled heading, focus trapped inside, Esc closes, the page behind is inert, and focus
 * returns to the element that opened it. Initial focus: [data-autofocus], else the first field, else the first button.
 */
export function Modal({ title, onClose, children, footer, alert = false, describedBy, closable = true }: {
  title: ReactNode; onClose: () => void; children: ReactNode; footer: ReactNode; alert?: boolean; describedBy?: string; closable?: boolean;
}) {
  const box = useRef<HTMLDivElement>(null);
  const hid = useId();
  const close = useRef(onClose);
  close.current = onClose;

  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    const behind = Array.from(document.querySelectorAll<HTMLElement>('header.top, main'));
    behind.forEach((el) => el.setAttribute('inert', ''));
    const el = box.current!;
    const first = el.querySelector<HTMLElement>('[data-autofocus]') ?? el.querySelector<HTMLElement>('input,select,textarea') ?? el.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? el).focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && closable) {
        e.preventDefault();
        close.current();
        return;
      }
      if (e.key !== 'Tab') return;
      const items = Array.from(el.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((x) => x.offsetParent !== null);
      if (items.length === 0) return;
      const a = items[0]!, z = items[items.length - 1]!;
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
      else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      behind.forEach((b) => b.removeAttribute('inert'));
      if (opener && document.contains(opener)) opener.focus();
    };
  }, [closable]);

  return (
    <div className="mdl" onMouseDown={(e) => { if (closable && e.target === e.currentTarget) onClose(); }}>
      <div className="mdlbox" role={alert ? 'alertdialog' : 'dialog'} aria-modal="true" aria-labelledby={hid} aria-describedby={describedBy} ref={box} tabIndex={-1}>
        <div className="mdlh">
          <h2 id={hid}>{title}</h2>
          {closable && <button className="ibtn" type="button" aria-label="Close" onClick={onClose}><IconClose /></button>}
        </div>
        <div className="mdlb">{children}</div>
        <div className="mdlf">{footer}</div>
      </div>
    </div>
  );
}

export type Toast = { id: number; text: string; sub?: string; error?: boolean };

/** Toasts in an always-present polite live region; each one leaves after 6 s or on Dismiss. */
export function useToasts() {
  const [items, setItems] = useState<Toast[]>([]);
  const seq = useRef(0);
  const dismiss = (id: number) => setItems((cur) => cur.filter((t) => t.id !== id));
  const push = (t: Omit<Toast, 'id'>) => {
    const id = ++seq.current;
    setItems((cur) => [...cur.slice(-2), { ...t, id }]);
    window.setTimeout(() => dismiss(id), 6000);
  };
  const region = (
    <div className="toasts" aria-live="polite">
      {items.map((t) => (
        <div key={t.id} className={`toast${t.error ? ' err' : ''}`}>
          <IconCheck />
          <div className="tbody"><p>{t.text}</p>{t.sub && <p className="s">{t.sub}</p>}</div>
          <button className="tx" type="button" aria-label="Dismiss" onClick={() => dismiss(t.id)}><IconClose /></button>
        </div>
      ))}
    </div>
  );
  return { push, region };
}

async function copyText(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  }
}

/** Copy button: says "Copied" (visible and in a polite live region) for 2 s. */
export function CopyButton({ text, label, className = 'abtn sm', autoFocus }: { text: string; label: string; className?: string; autoFocus?: boolean }) {
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');
  const onClick = async () => {
    setState((await copyText(text)) ? 'copied' : 'failed');
    window.setTimeout(() => setState('idle'), 2000);
  };
  return (
    <>
      <button type="button" className={className} aria-label={label} onClick={onClick} data-autofocus={autoFocus || undefined}>
        {state === 'copied' ? 'Copied' : state === 'failed' ? 'Select and copy' : 'Copy'}
      </button>
      <span className="sr-only" aria-live="polite">{state === 'copied' ? 'Copied to the clipboard' : state === 'failed' ? 'Copy failed; select the text and copy it' : ''}</span>
    </>
  );
}
