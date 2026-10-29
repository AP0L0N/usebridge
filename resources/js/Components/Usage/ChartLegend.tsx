import type { Series, ValueKind } from '../../lib/usage';
import { Est } from './Est';
import { Measured } from './Measured';

/** Toggle chips: pressing one isolates that series (others dim to 22% in the chart). */
export function ChartLegend({ series, isolated, onToggle, kind = 'est', label }: { series: Series[]; isolated: string | null; onToggle: (agentId: string) => void; kind?: ValueKind; label?: string }) {
  const Val = kind === 'measured' ? Measured : Est;
  return (
    <ul className="legend" aria-label={label ?? 'Agents in the chart. Select one to isolate it.'}>
      {series.map((s) => (
        <li key={s.agentId}>
          <button type="button" aria-pressed={isolated === s.agentId} className={isolated && isolated !== s.agentId ? 'dim' : ''} onClick={() => onToggle(s.agentId)}>
            <i style={{ background: s.color }} aria-hidden="true" />{s.name} <Val value={s.total} />
          </button>
        </li>
      ))}
    </ul>
  );
}
