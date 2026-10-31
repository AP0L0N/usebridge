import type { ReactNode } from 'react';

export type BadgeKind = 'neutral' | 'ok' | 'warn' | 'risk' | 'agent' | 'sample' | 'signal';

/**
 * Pill badge (Figma "Badge"); sample = dashed outline, no dot; signal = lime fill, ink text, no dot ("Core column").
 * onDark: the variant used in the dark top bar.
 */
export function Badge({ kind = 'neutral', onDark = false, children }: { kind?: BadgeKind; onDark?: boolean; children: ReactNode }) {
  return (
    <span className={`auth-badge auth-badge--${kind}${onDark ? ' on-dark' : ''}`}>
      {kind !== 'sample' && kind !== 'signal' && <span className="auth-badge-dot" aria-hidden="true" />}
      {children}
    </span>
  );
}
