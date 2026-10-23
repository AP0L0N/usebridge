import type { Series } from '../../lib/usage';
import { Est } from './Est';

/** Toggle chips: pressing one isolates that series (others dim to 22% in the chart). */
export function ChartLegend({ series, isolated, onToggle }: { series: Series[]; isolated: string | null; onToggle: (agentId: string) => void }) {
  return (
    <ul className="legend" aria-label="Agents in the chart. Select one to isolate it.">
      {series.map((s) => (
        <li key={s.agentId}>
          <button type="button" aria-pressed={isolated === s.agentId} className={isolated && isolated !== s.agentId ? 'dim' : ''} onClick={() => onToggle(s.agentId)}>
            <i style={{ background: s.color }} aria-hidden="true" />{s.name} <Est value={s.total} />
          </button>
        </li>
      ))}
    </ul>
  );
}
