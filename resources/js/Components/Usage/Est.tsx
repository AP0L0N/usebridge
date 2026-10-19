import { estSpoken, fmt } from '../../lib/usage';

/** Every token figure goes through here: "~427 M" (tilde via CSS), spoken "about 427 million tokens, estimated". */
export function Est({ value }: { value: number }) {
  if (value === 0) {
    // A true zero from a present file: no tilde.
    return <span className="est0" aria-label="0 tokens">0</span>;
  }
  return <span className="est" title="Estimate" aria-label={estSpoken(value)}>{fmt(value)}</span>;
}
