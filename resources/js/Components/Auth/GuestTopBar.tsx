import type { AuthCopy } from '../../lib/authCopy';
import { Wordmark } from './Wordmark';

/**
 * Narrow screens only (≤ 960px, the stage is hidden there): just the wordmark. No "Sample data" chip: the sample panel
 * it labels is not shown at these widths (Constance QA S4).
 */
export function GuestTopBar({ copy }: { copy: AuthCopy }) {
  return (
    <header className="auth-topbar">
      <Wordmark size={28} label={copy.home} />
    </header>
  );
}
