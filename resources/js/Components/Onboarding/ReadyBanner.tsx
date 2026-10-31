import { Link } from '@inertiajs/react';
import { Notice } from '../Auth/TextField';
import { t } from '../../lib/authCopy';
import type { OnboardingCopy } from '../../lib/onboardingCopy';

/** PortfolioController's `welcome` prop (ReadyBanner::props): set on GET /?ready={slug} after onboarding. */
export type Welcome = { project: string; slug: string; connect: boolean; tokensUrl: string; copy: OnboardingCopy['done'] } | null | undefined;

/** Above the cards: "<project> is ready. Open it to add your first tickets." */
export function ReadyBanner({ welcome }: { welcome: Welcome }) {
  if (!welcome) return null;
  return (
    <div className="onb-done">
      <Notice kind="ok" role="status">{t(welcome.copy.ready, { project: welcome.project })}</Notice>
    </div>
  );
}

/** Below the cards, only while the user has no API token: the dashed violet "Connect your bot" card. */
export function ConnectBotCard({ welcome }: { welcome: Welcome }) {
  if (!welcome?.connect) return null;
  return (
    <div className="onb-done onb-connect">
      <span className="onb-connect-dot" aria-hidden="true" />
      <p className="onb-connect-text">{welcome.copy.connect}</p>
      <Link href={welcome.tokensUrl}>{welcome.copy.connect_link}</Link>
    </div>
  );
}
