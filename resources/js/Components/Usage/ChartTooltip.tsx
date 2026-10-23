import { estText, type OverviewDay, type Series } from '../../lib/usage';
import { Est } from './Est';
import { MISSING_TEXT } from './MissingStub';

/** Rows above this go into two columns (19+ agents would otherwise run past the chart). */
const ONE_COLUMN_MAX = 10;

const rowsOf = (day: OverviewDay, series: Series[]) =>
  day.present && day.segments ? series.map((s, i) => ({ s, v: day.segments![i] ?? 0 })).filter((r) => r.v > 0) : [];

/** Tooltip width in px, shared with the chart's positioning. */
export function tipWidth(day: OverviewDay, series: Series[]): number {
  if (!day.present) return 270;
  return rowsOf(day, series).length > ONE_COLUMN_MAX ? 470 : 250;
}

/** Rough rendered height in px (header + rows + hint), so the chart can keep the tooltip inside itself. */
export function tipHeight(day: OverviewDay, series: Series[]): number {
  if (!day.present) return 120;
  const n = rowsOf(day, series).length;
  return 100 + 25 * (n > ONE_COLUMN_MAX ? Math.ceil(n / 2) : n);
}

/** Dark tooltip for one day: date, total, that day's nonzero segments, hint. Positioned by the chart. */
export function ChartTooltip({ day, series, left, top, maxHeight, id }: { day: OverviewDay; series: Series[]; left: number; top: number; maxHeight: number; id: string }) {
  const rows = rowsOf(day, series);
  const two = rows.length > ONE_COLUMN_MAX;
  return (
    <div className="tip" id={id} role="tooltip" style={{ left, top, width: tipWidth(day, series), maxHeight }}>
      {day.present && day.segments ? (
        <>
          <h4>{day.longLabel}{day.today ? ' · so far' : ''} · <Est value={day.total ?? 0} /></h4>
          {/* Two columns read top-to-bottom, then the second column, in legend (rank) order. */}
          <ul className={two ? 'two' : undefined} style={two ? { gridTemplateRows: `repeat(${Math.ceil(rows.length / 2)}, auto)` } : undefined}>
            {rows.map(({ s, v }) => (
              <li key={s.agentId}>
                <i style={{ background: s.color }} aria-hidden="true" />
                <span>{s.name}</span>
                <span>{estText(v)}</span>
              </li>
            ))}
          </ul>
          <p className="tn">Estimate · click a bar to open that day</p>
        </>
      ) : (
        <>
          <h4>{day.longLabel} · no file</h4>
          <p className="miss-t">{MISSING_TEXT}</p>
        </>
      )}
    </div>
  );
}
