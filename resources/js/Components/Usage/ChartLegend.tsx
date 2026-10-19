import { colorVar, type ColorKey, type Series } from '../../lib/usage';
import { Est } from './Est';

/** Toggle chips: pressing one isolates that series (others dim to 22% in the chart). */
export function ChartLegend({ series, isolated, onToggle }: { series: Series[]; isolated: ColorKey | null; onToggle: (k: ColorKey) => void }) {
  return (
    <ul className="legend" aria-label="Agents in the chart. Select one to isolate it.">
      {series.map((s) => (
        <li key={s.key}>
          <button type="button" aria-pressed={isolated === s.key} className={isolated && isolated !== s.key ? 'dim' : ''} onClick={() => onToggle(s.key)}>
            <i style={{ background: colorVar(s.key) }} aria-hidden="true" />{s.name} <Est value={s.total} />
          </button>
        </li>
      ))}
    </ul>
  );
}
