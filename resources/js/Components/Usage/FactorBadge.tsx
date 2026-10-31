import { t } from '../../lib/strings-usage';
import type { Factor } from '../../lib/usage';

/** Factor chip: "calibrated" / "median K"; with `k` it reads "×1.21 calibrated" (agent page header). */
export function FactorBadge({ factor, k }: { factor: Factor; k?: number }) {
  if (k !== undefined) {
    const kk = String(k);
    return factor === 'calibrated'
      ? <span className="chip" title="Factor K calibrated on this agent's own transcripts"><i aria-hidden="true" />{t('agent.factor.calibrated', { k: kk })}</span>
      : <span className="chip median" title="No calibration of its own: median factor K, a rougher estimate"><i aria-hidden="true" />{t('agent.factor.median', { k: kk })}</span>;
  }
  return factor === 'calibrated'
    ? <span className="kb cal" title="Factor K calibrated on this agent's own transcripts">calibrated</span>
    : <span className="kb med" title="No calibration of its own: median factor K, a rougher estimate">median K</span>;
}
