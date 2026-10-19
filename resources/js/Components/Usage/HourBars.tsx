import { estText } from '../../lib/usage';

const H = 120, BASE = 96;

/** 24 hour-total bars (sum of by_hour over the last 7 days), peak hour labelled. Narrow: smaller viewBox so text stays legible. */
export function HourBars({ totals, title, narrow = false }: { totals: number[]; title: string; narrow?: boolean }) {
  const W = narrow ? 360 : 600, X0 = narrow ? 52 : 64;
  const max = Math.max(0, ...totals);
  const slot = (W - X0) / 24;
  const bw = slot * 0.82;
  const peak = max > 0 ? totals.indexOf(max) : -1;
  const h = (v: number) => (max > 0 ? (v / max) * BASE : 0);
  return (
    <svg className="chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby="u-hours-t">
      <title id="u-hours-t">{title}</title>
      <line className="base" x1={X0} x2={W} y1={BASE} y2={BASE} />
      {max > 0 && <text x={X0 - 8} y={12} textAnchor="end">{estText(max)}</text>}
      <text x={X0 - 8} y={BASE} textAnchor="end">0</text>
      {totals.map((v, i) => (
        <rect key={i} x={X0 + 2 + i * slot} y={BASE - h(v)} width={bw} height={h(v)} rx="2" fill="var(--accent)">
          <title>{`${String(i).padStart(2, '0')}:00 · ${estText(v)}`}</title>
        </rect>
      ))}
      {peak >= 0 && (
        <text x={X0 + 2 + peak * slot + bw / 2} y={H - 4} textAnchor="middle" className="xl b">{String(peak).padStart(2, '0')}:00 peak</text>
      )}
    </svg>
  );
}
