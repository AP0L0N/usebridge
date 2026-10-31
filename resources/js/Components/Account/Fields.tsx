import { forwardRef, useId, useState, type InputHTMLAttributes, type ReactNode } from 'react';
import { strength } from '../../lib/account';

/** Label + control + hint + inline error (icon + text), tied with aria-describedby (DESIGN.md "Accessibility"). */
export function Field({ id, label, error, hint, labelRow, className = '', children }: {
  id: string; label: ReactNode; error?: string; hint?: ReactNode; labelRow?: ReactNode; className?: string; children: ReactNode;
}) {
  return (
    <div className={`fld2 ${className}`.trim()}>
      {labelRow ? <div className="lrow"><label className="flabel" htmlFor={id}>{label}</label>{labelRow}</div> : <label className="flabel" htmlFor={id}>{label}</label>}
      {children}
      {error && <p className="ferr" id={`${id}-err`}>{error}</p>}
      {hint && <p className="fhint" id={`${id}-hint`}>{hint}</p>}
    </div>
  );
}

/** aria-describedby / aria-invalid for an input inside <Field>. */
export function described(id: string, error?: string, hint?: boolean, extra?: string) {
  const ids = [error ? `${id}-err` : '', hint ? `${id}-hint` : '', extra ?? ''].filter(Boolean).join(' ');
  return { 'aria-describedby': ids || undefined, 'aria-invalid': error ? (true as const) : undefined };
}

type PwProps = InputHTMLAttributes<HTMLInputElement> & { id: string; showLabel?: string };

/** Password input with a Show/Hide toggle (aria-pressed); the label names what it shows. */
export const PasswordInput = forwardRef<HTMLInputElement, PwProps>(function PasswordInput({ id, showLabel = 'password', ...rest }, ref) {
  const [shown, setShown] = useState(false);
  return (
    <div className="pw">
      <input ref={ref} className="qin" id={id} type={shown ? 'text' : 'password'} {...rest} />
      <button type="button" className="pwt" aria-pressed={shown} aria-label={`${shown ? 'Hide' : 'Show'} ${showLabel}`} onClick={() => setShown((v) => !v)}>
        {shown ? 'Hide' : 'Show'}
      </button>
    </div>
  );
});

/** Strength meter: segments are decoration; word + tip are text in a polite live line. */
export function StrengthMeter({ id, value, min }: { id: string; value: string; min: number }) {
  const s = strength(value, min);
  return (
    <p className="meter" id={id} data-score={value === '' ? 0 : s.score} aria-live="polite">
      <span className="segs" aria-hidden="true"><i /><i /><i /><i /></span>
      {value === ''
        ? <span>At least {min} characters. Longer is stronger.</span>
        : <span>Strength: <b>{s.word}</b>{s.tip && <>, {s.tip}</>}</span>}
    </p>
  );
}

const FIELD_ORDER = ['name', 'email', 'current_password', 'password', 'password_confirmation', 'token', 'form', 'turnstile'];

/** Error summary (role=alert, focusable): one link per field error, focused by the page after a failed submit. */
export const ErrorSummary = forwardRef<HTMLDivElement, { errors: Record<string, string>; fieldIds: Record<string, string>; what: string; level?: 2 | 3 }>(
  function ErrorSummary({ errors, fieldIds, what, level = 2 }, ref) {
    const hid = useId();
    const keys = Object.keys(errors).sort((a, b) => FIELD_ORDER.indexOf(a) - FIELD_ORDER.indexOf(b));
    if (keys.length === 0) return null;
    const H = level === 2 ? 'h2' : 'h3';
    const focusField = (e: React.MouseEvent, id: string) => {
      const el = document.getElementById(id);
      if (!el) return;
      e.preventDefault();
      el.focus();
    };
    return (
      <div className="errsum" role="alert" tabIndex={-1} aria-labelledby={hid} ref={ref}>
        <H id={hid}>{keys.length === 1 ? `Fix 1 thing to ${what}` : `Fix ${keys.length} things to ${what}`}</H>
        <ul>
          {keys.map((k) => (
            <li key={k}>{fieldIds[k] ? <a href={`#${fieldIds[k]}`} onClick={(e) => focusField(e, fieldIds[k]!)}>{errors[k]}</a> : errors[k]}</li>
          ))}
        </ul>
      </div>
    );
  },
);

const ICONS = {
  bad: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5M12 16.5h.01" /></svg>,
  lock: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>,
  mail: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg>,
  warn: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 3l10 18H2z" /><path d="M12 10v4M12 17h.01" /></svg>,
  ok: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 5-6" /></svg>,
  info: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>,
};

/** Inline banner (.callout.bad / .warn / .ok / .info); alert=true for errors that appear after an action. */
export function Callout({ kind, icon, alert, status, children, style }: {
  kind: 'bad' | 'warn' | 'ok' | 'info'; icon?: keyof typeof ICONS; alert?: boolean; status?: boolean; children: ReactNode; style?: React.CSSProperties;
}) {
  return (
    <div className={`callout ${kind}`} role={alert ? 'alert' : status ? 'status' : undefined} style={style}>
      {ICONS[icon ?? kind]}
      <p>{children}</p>
    </div>
  );
}

export const IconCheck = () => <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>;
export const IconClose = () => <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>;
export const IconPlus = () => <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>;

/** Button with the label kept while busy (spinner + "…ing" text) and a polite announcement. */
export function BusyButton({ busy, busyLabel, children, className = 'btn wide', type = 'submit', disabled, onClick }: {
  busy: boolean; busyLabel: string; children: ReactNode; className?: string; type?: 'submit' | 'button'; disabled?: boolean; onClick?: () => void;
}) {
  return (
    <>
      <button className={className} type={type} disabled={busy || disabled} aria-disabled={busy || disabled || undefined} onClick={onClick}>
        {busy ? <><span className="spin" aria-hidden="true" />{busyLabel}</> : children}
      </button>
      {busy && <p className="sr-only" aria-live="polite">{busyLabel.replace(/…$/, '')}, please wait.</p>}
    </>
  );
}
