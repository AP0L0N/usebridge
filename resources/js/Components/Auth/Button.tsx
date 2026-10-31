import type { ReactNode } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost';

/**
 * 44px button (Figma "Button"). busy: spinner + busyLabel, disabled; busyNote is the line under it while busy, in a
 * polite live region that is always there (so screen readers hear it when it fills).
 */
export function Button({ variant = 'primary', type = 'submit', busy = false, busyLabel, busyNote, disabled, onClick, children }: {
  variant?: Variant; type?: 'submit' | 'button'; busy?: boolean; busyLabel?: string; busyNote?: string; disabled?: boolean;
  onClick?: () => void; children: ReactNode;
}) {
  const button = (
    <button className={`auth-btn auth-btn--${variant}`} type={type} disabled={busy || disabled} aria-busy={busy || undefined} onClick={onClick}>
      {busy ? <><span className="auth-spin" aria-hidden="true" />{busyLabel ?? children}</> : children}
    </button>
  );
  if (busyNote === undefined) return button;
  return (
    <div className="auth-submit">
      {button}
      <p className="auth-busy-note" aria-live="polite">{busy ? busyNote : ''}</p>
    </div>
  );
}

/** A link styled as the button (expired link -> "Send a new link", success -> "Log in"); a full page load. */
export function ButtonLink({ href, variant = 'primary', children }: { href: string; variant?: Variant; children: ReactNode }) {
  return <a className={`auth-btn auth-btn--${variant}`} href={href}>{children}</a>;
}
