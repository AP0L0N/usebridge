import { forwardRef, useState, type InputHTMLAttributes, type ReactNode } from 'react';
import { strength } from '../../lib/account';
import { t, type AuthCopy } from '../../lib/authCopy';

type Toggle = { show: string; hide: string; showLabel: string; hideLabel: string };
type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
  id: string;
  label: ReactNode;
  /** The field's own error: red border + ONE line under the field (it replaces the hint). */
  error?: string;
  /** Red border without a line (log in: the wrong-credentials message is above the form). */
  invalid?: boolean;
  hint?: ReactNode;
  /** Under the message line (strength meter, "Passwords match", "Forgot password?"). */
  below?: ReactNode;
  /** Password field: Show / Hide toggle with these labels. */
  toggle?: Toggle;
};

/** Label + 44px field + one message line (Figma "Input field": default, filled, focus, error, disabled). */
export const TextField = forwardRef<HTMLInputElement, Props>(function TextField(
  { id, label, error, invalid, hint, below, toggle, type, className, ...rest }, ref,
) {
  const [shown, setShown] = useState(false);
  const message = error ? `${id}-err` : hint ? `${id}-hint` : '';
  const describedBy = [message, rest['aria-describedby'] ?? ''].filter(Boolean).join(' ') || undefined;
  const bad = Boolean(error) || Boolean(invalid);
  return (
    <div className={`auth-field ${className ?? ''}`.trim()}>
      <label className="auth-label" htmlFor={id}>{label}</label>
      <div className={`auth-input${bad ? ' is-error' : ''}${rest.disabled ? ' is-disabled' : ''}`}>
        <input {...rest} ref={ref} id={id} type={toggle ? (shown ? 'text' : 'password') : type} aria-invalid={bad || undefined} aria-describedby={describedBy} />
        {toggle && (
          <button type="button" className="auth-pwt" aria-pressed={shown} aria-label={shown ? toggle.hideLabel : toggle.showLabel}
            disabled={rest.disabled} onClick={() => setShown((v) => !v)}>
            {shown ? toggle.hide : toggle.show}
          </button>
        )}
      </div>
      {error
        ? <p className="auth-msg auth-msg--error" id={`${id}-err`}><span className="auth-dot" aria-hidden="true" />{error}</p>
        : hint && <p className="auth-msg" id={`${id}-hint`}>{hint}</p>}
      {below}
    </div>
  );
});

/** Show / Hide labels for a password field, from the copy ("Show password", "Hide new password"). */
export function pwToggle(copy: AuthCopy, what: string): Toggle {
  return {
    show: copy.common.show, hide: copy.common.hide,
    showLabel: t(copy.common.show_label, { what }), hideLabel: t(copy.common.hide_label, { what }),
  };
}

/** Four bars + "Strength: Good, longer is even better" (word and tip from the copy; never colour alone). */
export function StrengthMeter({ id, value, min, copy }: { id: string; value: string; min: number; copy: AuthCopy['strength'] }) {
  const score = strength(value, min).score;
  const tip = t(copy.tips[score] ?? '', { min });
  const line = value === '' ? t(copy.empty, { min }) : t(tip ? copy.line : copy.line_no_tip, { word: copy.words[score] ?? '', tip });
  return (
    <div className="auth-meter" id={id} data-score={value === '' ? 'empty' : score}>
      <span className="auth-meter-bars" aria-hidden="true"><i /><i /><i /><i /></span>
      <p className="auth-msg" aria-live="polite">{line}</p>
    </div>
  );
}

/** "• Passwords match" under the confirmation field. */
export function OkLine({ id, children }: { id: string; children: ReactNode }) {
  return <p className="auth-msg auth-msg--ok" id={id} aria-live="polite">{children && <><span className="auth-dot" aria-hidden="true" />{children}</>}</p>;
}

/** Banner above the form (risk / warn / ok / info): a coloured dot + text. */
export function Notice({ kind, role, children }: { kind: 'risk' | 'warn' | 'ok' | 'info'; role?: 'alert' | 'status'; children: ReactNode }) {
  return (
    <div className={`auth-notice auth-notice--${kind}`} role={role}>
      <span className="auth-dot" aria-hidden="true" />
      <div className="auth-notice-body">{children}</div>
    </div>
  );
}

/** Error summary after a failed submit (focusable, role=alert): one link per field error, in field order. */
export const ErrorSummary = forwardRef<HTMLDivElement, { errors: Record<string, string>; fieldIds: Record<string, string>; order: string[]; copy: AuthCopy['common'] }>(
  function ErrorSummary({ errors, fieldIds, order, copy }, ref) {
    const keys = Object.keys(errors).sort((a, b) => (order.indexOf(a) + 1 || 99) - (order.indexOf(b) + 1 || 99));
    if (keys.length === 0) return null;
    const focus = (e: React.MouseEvent, id: string) => {
      const el = document.getElementById(id);
      if (!el) return;
      e.preventDefault();
      el.focus();
    };
    return (
      <div className="auth-notice auth-notice--risk auth-summary" role="alert" tabIndex={-1} ref={ref} aria-labelledby="auth-summary-h">
        <span className="auth-dot" aria-hidden="true" />
        <div className="auth-notice-body">
          <h2 id="auth-summary-h">{keys.length === 1 ? copy.summary_one : t(copy.summary_many, { count: keys.length })}</h2>
          <ul>
            {keys.map((k) => {
              const target = fieldIds[k];
              return <li key={k}>{target ? <a href={`#${target}`} onClick={(e) => focus(e, target)}>{errors[k]}</a> : errors[k]}</li>;
            })}
          </ul>
        </div>
      </div>
    );
  },
);

/** The square with a dot above a heading (sent / expired / success). */
export function StateIcon({ kind }: { kind: 'ok' | 'warn' }) {
  return <div className={`auth-state-icon auth-state-icon--${kind}`} aria-hidden="true"><span className="auth-dot" /></div>;
}

/** The checkbox row ("Keep me logged in on this device", the terms checkbox). */
export function CheckRow({ id, name, required, checked, onChange, disabled, invalid, describedBy, children }: {
  id: string; name?: string; required?: boolean; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean; invalid?: boolean;
  describedBy?: string; children: ReactNode;
}) {
  return (
    <label className="auth-check" htmlFor={id}>
      <input type="checkbox" id={id} name={name} required={required} checked={checked} disabled={disabled} aria-invalid={invalid || undefined} aria-describedby={describedBy}
        onChange={(e) => onChange(e.target.checked)} />
      <span>{children}</span>
    </label>
  );
}
