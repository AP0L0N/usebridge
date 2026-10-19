import { colorVar, estText, type OverviewDay, type Series } from '../../lib/usage';
import { Est } from './Est';
import { MISSING_TEXT } from './MissingStub';

/** Dark tooltip for one day: date, total, the segment values, hint. Positioned by the chart. */
export function ChartTooltip({ day, series, left, top, id }: { day: OverviewDay; series: Series[]; left: number; top: number; id: string }) {
  return (
    <div className="tip" id={id} role="tooltip" style={{ left, top, width: day.present ? 250 : 270 }}>
      {day.present && day.segments ? (
        <>
          <h4>{day.longLabel}{day.today ? ' · so far' : ''} · <Est value={day.total ?? 0} /></h4>
          <ul>
            {series.map((s, i) => (
              <li key={s.key}>
                <i style={{ background: colorVar(s.key) }} aria-hidden="true" />
                <span>{s.agentId ? s.name : 'Other agents'}</span>
                <span>{estText(day.segments![i] ?? 0)}</span>
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
