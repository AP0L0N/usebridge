import { fmt, measuredSpoken } from '../../lib/usage';

/** A measured token figure (CLI logs): plain "427 M", no tilde; spoken "427 million tokens, measured". */
export function Measured({ value }: { value: number }) {
  if (value === 0) {
    return <span className="meas0" aria-label="0 tokens">0</span>;
  }
  return <span className="meas" title="Measured" aria-label={measuredSpoken(value)}>{fmt(value)}</span>;
}

/** The counterpart of the estimate "Est." badges: this figure is counted, not scaled. */
export function MeasuredBadge() {
  return <span className="kb meas-b" title="Counted from the CLI logs, not estimated">Measured</span>;
}
