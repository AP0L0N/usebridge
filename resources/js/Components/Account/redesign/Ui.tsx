import { forwardRef, useEffect, useId, useRef, useState, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { strength } from '../../../lib/account';
import { t, type AcctKey } from '../../../lib/strings-account';

/*
 * Small pieces for the redesigned Account + API tokens screens (Figma Foundations: Button, Input, Badge, Card, Table
 * row). Styles: resources/css/account-redesign.css, everything under .acx. The shared Fields / Overlay components stay
 * as they are for the auth, onboarding and admin screens.
 */

type Kind = 'primary' | 'secondary' | 'ghost' | 'danger' | 'signal';

export function Button({ kind = 'primary', busy, busyLabel, block, className = '', children, type = 'button', disabled, ...rest }:
  ButtonHTMLAttributes<HTMLButtonElement> & { kind?: Kind; busy?: boolean; busyLabel?: string; block?: boolean }) {
  return (
    <button type={type} className={`acx-btn acx-btn--${kind}${block ? ' acx-btn--block' : ''} ${className}`.trim()} disabled={disabled || busy} aria-busy={busy || undefined} {...rest}>
      {busy ? <><span className="acx-spin" aria-hidden="true" />{busyLabel ?? children}</> : children}
    </button>
  );
}

export function Card({ id, title, sub, eyebrow, danger, action, children }: {
  id?: string; title: ReactNode; sub?: ReactNode; eyebrow?: ReactNode; danger?: boolean; action?: ReactNode; children: ReactNode;
}) {
  const hid = useId();
  return (
    <section className={`acx-card${danger ? ' acx-card--danger' : ''}`} id={id} aria-labelledby={hid}>
      <div className="acx-card__head">
        <div className="acx-card__title">
          {eyebrow && <p className="acx-eyebrow">{eyebrow}</p>}
          <h2 id={hid}>{title}</h2>
          {sub && <p className="acx-card__sub">{sub}</p>}
        </div>
        {action && <div className="acx-card__action">{action}</div>}
      </div>
      {children}
    </section>
  );
}

/** aria-describedby for a field: its hint (unless an error replaces it), its error, plus any extra ids. */
export function describedBy(id: string, error?: string, hint?: boolean, extra?: string) {
  const ids = [hint && !error ? `${id}-hint` : '', error ? `${id}-err` : '', extra ?? ''].filter(Boolean);
  return { 'aria-describedby': ids.length ? ids.join(' ') : undefined, 'aria-invalid': error ? (true as const) : undefined };
}

/** Label + control + (hint or its own error line). Only this field's error shows here. */
export function Field({ id, label, error, hint, className = '', children }: {
  id: string; label: ReactNode; error?: string; hint?: ReactNode; className?: string; children: ReactNode;
}) {
  return (
    <div className={`acx-field${error ? ' is-error' : ''} ${className}`.trim()}>
      <label className="acx-label" htmlFor={id}>{label}</label>
      {children}
      {error ? <p className="acx-err" id={`${id}-err`}>{error}</p> : hint ? <p className="acx-hint" id={`${id}-hint`}>{hint}</p> : null}
    </div>
  );
}

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input({ className = '', ...rest }, ref) {
  return <input ref={ref} className={`acx-input ${className}`.trim()} {...rest} />;
});

export function Select({ className = '', children, ...rest }: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <span className="acx-select">
      <select className={`acx-input ${className}`.trim()} {...rest}>{children}</select>
      <svg viewBox="0 0 12 12" aria-hidden="true"><path d="M3 4.5 6 7.5 9 4.5" fill="none" stroke="currentColor" strokeWidth="1.5" /></svg>
    </span>
  );
}

export const PasswordInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { what: string }>(function PasswordInput({ what, ...rest }, ref) {
  const [show, setShow] = useState(false);
  return (
    <span className="acx-pw">
      <Input ref={ref} type={show ? 'text' : 'password'} spellCheck={false} {...rest} />
      <button type="button" className="acx-pw__toggle" aria-pressed={show} aria-label={t(show ? 'acct.pw.hideLabel' : 'acct.pw.showLabel', { what })}
        onClick={() => setShow((s) => !s)}>{t(show ? 'acct.pw.hide' : 'acct.pw.show')}</button>
    </span>
  );
});

/** 4 segments + a word (never colour alone). */
export function StrengthMeter({ id, value, min }: { id: string; value: string; min: number }) {
  const s = strength(value, min);
  const text = value === '' || s.score === 0 ? t('acct.pw.tooShort', { n: min })
    : t('acct.pw.strengthTip', { word: t(`acct.pw.word.${s.score}` as AcctKey), tip: t(`acct.pw.tip.${s.score}` as AcctKey) });
  return (
    <div className="acx-meter" data-score={value === '' ? 0 : s.score}>
      <div className="acx-meter__bars" aria-hidden="true"><i /><i /><i /><i /></div>
      <p className="acx-hint" id={id} aria-live="polite">{text}</p>
    </div>
  );
}

/** Red summary at the top of a card: "Fix 2 things to …" + a link to each field. Focus moves here on submit. */
export const ErrorSummary = forwardRef<HTMLDivElement, { errors: Record<string, string>; fieldIds: Record<string, string>; what: string }>(
  function ErrorSummary({ errors, fieldIds, what }, ref) {
    const entries = Object.entries(errors);
    if (entries.length === 0) return null;
    const linked = entries.filter(([k]) => fieldIds[k]);
    const form = errors.form;
    return (
      <div className="acx-notice acx-notice--risk" role="alert" tabIndex={-1} ref={ref}>
        <span className="acx-notice__dot" aria-hidden="true" />
        <div className="acx-notice__text">
          <h3 className="acx-notice__title">{form && linked.length === 0 ? form : t(linked.length === 1 ? 'acct.errors.one' : 'acct.errors.many', { n: linked.length, what })}</h3>
          {linked.length > 0 && (
            <ul className="acx-notice__list">
              {linked.map(([k, v]) => (
                <li key={k}><a href={`#${fieldIds[k]}`} onClick={(e) => { e.preventDefault(); document.getElementById(fieldIds[k]!)?.focus(); }}>· {v}</a></li>
              ))}
            </ul>
          )}
        </div>
      </div>
    );
  },
);

export function Notice({ kind, children, role }: { kind: 'violet' | 'risk' | 'warn'; children: ReactNode; role?: 'status' | 'alert' }) {
  return (
    <div className={`acx-notice acx-notice--${kind}`} role={role}>
      <span className="acx-notice__dot" aria-hidden="true" />
      <div className="acx-notice__text">{children}</div>
    </div>
  );
}

export function Chip({ kind = 'read', children }: { kind?: 'read' | 'write' | 'new'; children: ReactNode }) {
  return <span className={`acx-chip acx-chip--${kind}`}>{children}</span>;
}

export function Badge({ tone, children }: { tone: 'warn' | 'risk' | 'ok'; children: ReactNode }) {
  return <span className={`acx-badge acx-badge--${tone}`}><span className="acx-badge__dot" aria-hidden="true" />{children}</span>;
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

/** "Copy" → "Copied" for 2 s, announced in a polite live region. */
export function CopyButton({ text, what, kind = 'ghost', autoFocus }: { text: string; what: string; kind?: Kind; autoFocus?: boolean }) {
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');
  const onClick = async () => {
    setState((await copyText(text)) ? 'copied' : 'failed');
    window.setTimeout(() => setState('idle'), 2000);
  };
  return (
    <>
      <Button kind={kind} aria-label={t('mcp.copyLabel', { what })} onClick={onClick} data-autofocus={autoFocus || undefined}>
        {state === 'copied' ? t('mcp.copied') : state === 'failed' ? t('mcp.copyFailed') : t('mcp.copy')}
      </Button>
      <span className="sr-only" aria-live="polite">{state === 'copied' ? t('mcp.copiedLive') : state === 'failed' ? t('mcp.copyFailedLive') : ''}</span>
    </>
  );
}

/** Code box: white head (label + Copy), paper body in mono. */
export function CodeBox({ label, copy, children, multiline }: { label: string; copy: string; children: ReactNode; multiline?: boolean }) {
  const lid = useId();
  return (
    <div className="acx-code">
      <div className="acx-code__head"><span id={lid}>{label}</span><CopyButton text={copy} what={label.toLowerCase()} /></div>
      <pre className={`acx-code__body${multiline ? ' is-multi' : ''}`} aria-labelledby={lid} tabIndex={multiline ? 0 : undefined}>{children}</pre>
    </div>
  );
}

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/**
 * Dialog rendered into <body> inside its own .acx root (so the scoped styles apply): aria-modal, focus trapped, Esc
 * closes (when closable), everything else in <body> inert, focus back to the opener. Initial focus: [data-autofocus],
 * else the first field, else the first button.
 */
export function Dialog({ title, onClose, children, footer, alert = false, describedBy: desc, closable = true }: {
  title: ReactNode; onClose: () => void; children: ReactNode; footer: ReactNode; alert?: boolean; describedBy?: string; closable?: boolean;
}) {
  const box = useRef<HTMLDivElement>(null);
  const root = useRef<HTMLDivElement>(null);
  const hid = useId();
  const close = useRef(onClose);
  close.current = onClose;

  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    const behind = Array.from(document.body.children).filter((el): el is HTMLElement => el instanceof HTMLElement && el !== root.current && !el.hasAttribute('inert'));
    behind.forEach((el) => el.setAttribute('inert', ''));
    const el = box.current!;
    const first = el.querySelector<HTMLElement>('[data-autofocus]') ?? el.querySelector<HTMLElement>('input,select,textarea') ?? el.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? el).focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && closable) { e.preventDefault(); close.current(); return; }
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

  return createPortal(
    <div className="acx acx-dialog" ref={root} onMouseDown={(e) => { if (closable && e.target === e.currentTarget) onClose(); }}>
      <div className="acx-dialog__box" role={alert ? 'alertdialog' : 'dialog'} aria-modal="true" aria-labelledby={hid} aria-describedby={desc} ref={box} tabIndex={-1}>
        <div className="acx-dialog__head">
          <h2 id={hid}>{title}</h2>
          {closable && (
            <button className="acx-iconbtn" type="button" aria-label={t('acct.close')} onClick={onClose}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
            </button>
          )}
        </div>
        <div className="acx-dialog__body">{children}</div>
        <div className="acx-dialog__foot">{footer}</div>
      </div>
    </div>,
    document.body,
  );
}

export type Toast = { id: number; text: string; sub?: string; error?: boolean };

/** Toasts in an always-present polite live region (inside .acx); each leaves after 6 s or on Dismiss. */
export function useToasts() {
  const [items, setItems] = useState<Toast[]>([]);
  const seq = useRef(0);
  const dismiss = (id: number) => setItems((cur) => cur.filter((x) => x.id !== id));
  const push = (x: Omit<Toast, 'id'>) => {
    const id = ++seq.current;
    setItems((cur) => [...cur.slice(-2), { ...x, id }]);
    window.setTimeout(() => dismiss(id), 6000);
  };
  const region = (
    <div className="acx-toasts" aria-live="polite">
      {items.map((x) => (
        <div key={x.id} className={`acx-toast${x.error ? ' is-error' : ''}`}>
          <div className="acx-toast__body"><p>{x.text}</p>{x.sub && <p className="acx-toast__sub">{x.sub}</p>}</div>
          <button type="button" className="acx-toast__x" aria-label={t('acct.dismiss')} onClick={() => dismiss(x.id)}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
          </button>
        </div>
      ))}
    </div>
  );
  return { push, region };
}
