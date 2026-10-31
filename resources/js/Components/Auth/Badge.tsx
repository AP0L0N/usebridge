import type { ReactNode } from 'react';

export type BadgeKind = 'neutral' | 'ok' | 'warn' | 'risk' | 'agent' | 'sample';

/** Pill badge (Figma "Badge"); sample = dashed outline, no dot. onDark: the variant used in the dark top bar. */
export function Badge({ kind = 'neutral', onDark = false, children }: { kind?: BadgeKind; onDark?: boolean; children: ReactNode }) {
  return (
    <span className={`auth-badge auth-badge--${kind}${onDark ? ' on-dark' : ''}`}>
      {kind !== 'sample' && <span className="auth-badge-dot" aria-hidden="true" />}
      {children}
    </span>
  );
}
