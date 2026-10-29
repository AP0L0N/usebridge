import type { Factor } from '../../lib/usage';

export function FactorBadge({ factor }: { factor: Factor }) {
  return factor === 'calibrated'
    ? <span className="kb cal" title="Factor K calibrated on this agent's own transcripts">calibrated</span>
    : <span className="kb med" title="No calibration of its own: median factor K, a rougher estimate">median K</span>;
}
