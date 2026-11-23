import type { AuthCopy } from '../../lib/authCopy';
import { ThemeToggle } from '../Shell/ThemeToggle';
import { Wordmark } from './Wordmark';

/**
 * Narrow screens only (≤ 960px, the stage is hidden there): the wordmark and the theme toggle. No "Sample data" chip: the sample panel
 * it labels is not shown at these widths (Constance QA S4).
 */
export function GuestTopBar({ copy }: { copy: AuthCopy }) {
  return (
    <header className="auth-topbar">
      <Wordmark size={28} label={copy.home} />
      <ThemeToggle />
    </header>
  );
}
