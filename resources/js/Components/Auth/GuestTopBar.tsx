import type { AuthCopy } from '../../lib/authCopy';
import { Badge } from './Badge';
import { Wordmark } from './Wordmark';

/** Narrow screens only (the stage is hidden there): wordmark + "Sample data" chip. */
export function GuestTopBar({ copy }: { copy: AuthCopy }) {
  return (
    <header className="auth-topbar">
      <Wordmark size={28} label={copy.home} />
      <Badge kind="sample" onDark>{copy.topbar.sample}</Badge>
    </header>
  );
}
