import { useRef, useState, type KeyboardEvent } from 'react';
import { axisLabel, estSpoken, estText, niceStep, type OverviewDay, type Series } from '../../lib/usage';
import { ChartTooltip, tipHeight, tipWidth } from './ChartTooltip';
import { ChartPatterns, MissingStub } from './MissingStub';
import { Est } from './Est';

const W = 880, H = 380, X0 = 58, BASE = 350, TOP = 26, GAP = 1.5;

type Props = {
  days: OverviewDay[];
  series: Series[];
  /** agentId of the isolated series */
  isolated: string | null;
  fromLabel: string;
  toLabel: string;
  onOpenDay: (date: string) => void;
};

/**
 * Stacked daily bars (one segment per agent with usage in the range, bottom→top = rank). The SVG is the picture (role="img" + title);
 * an HTML overlay of buttons over each day slot carries hover / focus / keyboard
 * (roving tabindex, ←/→ between days, Enter opens the day, Esc closes the tooltip).
 */
export function StackedDailyChart({ days, series, isolated, fromLabel, toLabel, onOpenDay }: Props) {
  const wrap = useRef<HTMLDivElement>(null);
  const btns = useRef<(HTMLButtonElement | null)[]>([]);
  const n = days.length;
  const todayIdx = days.findIndex((d) => d.today);
  const [focusIdx, setFocusIdx] = useState(todayIdx >= 0 ? todayIdx : n - 1);
  const [active, setActive] = useState<number | null>(null);
  const [tipOpen, setTipOpen] = useState(false);

  const max = Math.max(0, ...days.map((d) => d.total ?? 0));
  const step = niceStep(max);
  const top = max > 0 ? Math.ceil(max / step) * step : step;
  const y = (v: number) => BASE - (v / top) * (BASE - TOP);
  const ticks: number[] = [];
  for (let v = 0; v <= top + 1; v += step) ticks.push(v);

  const slot = (W - X0) / n;
  const bw = Math.min(56, slot * 0.62);
  const bx = (i: number) => X0 + i * slot + (slot - bw) / 2;
  const wide = n <= 7;
  const showX = (i: number) => wide || i % 3 === 0 || days[i]!.today;
  const isoIdx = isolated ? series.findIndex((s) => s.agentId === isolated) : -1;

  const show = (i: number) => { setActive(i); setTipOpen(true); };
  const hide = () => { setTipOpen(false); setActive(null); };

  const onKey = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    let next = i;
    if (e.key === 'ArrowRight') next = Math.min(n - 1, i + 1);
    else if (e.key === 'ArrowLeft') next = Math.max(0, i - 1);
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = n - 1;
    else if (e.key === 'Escape') { setTipOpen(false); return; }
    else return;
    e.preventDefault();
    setFocusIdx(next);
    btns.current[next]?.focus();
  };

  // Tooltip position in px inside the wrapper: right of the bar, or left when there is no room.
  // Many agents: the tooltip goes two columns (tipWidth) and is kept inside the chart's height, scrolling if it still does not fit.
  let tip: { left: number; top: number; maxHeight: number } | null = null;
  if (tipOpen && active !== null && wrap.current) {
    const w = wrap.current.getBoundingClientRect().width, k = w / W, d = days[active]!;
    const tw = Math.min(w, tipWidth(d, series)), hgt = H * k;
    const right = (bx(active) + bw + 6) * k + 10;
    const left = right + tw > w ? Math.max(0, (bx(active) - 6) * k - tw - 10) : right;
    const barTop = d.total ? y(d.total) : BASE - 14;
    const top = Math.max(0, Math.min(barTop * k - 40, (BASE - 200) * k, hgt - tipHeight(d, series)));
    tip = { left, top, maxHeight: hgt - top };
  }

  return (
    <div ref={wrap} style={{ position: 'relative' }}>
      <svg className="chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby="u-chart-t">
        <title id="u-chart-t">{`Daily estimated tokens by agent, ${fromLabel} to ${toLabel}. Table view has every value.`}</title>
        <ChartPatterns />
        {ticks.map((v) => (
          <g key={v}>
            <line className={v === 0 ? 'base' : 'grid'} x1={X0} x2={W} y1={y(v)} y2={y(v)} />
            <text x={X0 - 8} y={y(v) + 4} textAnchor="end">{axisLabel(v)}</text>
          </g>
        ))}
        {days.map((d, i) => {
          const x = bx(i), cx = x + bw / 2;
          const xl = showX(i) ? (
            <text className={`xl${d.today ? ' b' : ''}`} x={cx} y={H - 8} textAnchor="middle">{d.label}{d.today && wide ? ' · so far' : ''}</text>
          ) : null;
          const ring = active === i ? (
            <rect x={x - 4} y={d.present ? 20 : BASE - 22} width={bw + 8} height={d.present ? BASE - 20 : 26} fill="none" stroke="var(--ink)" strokeWidth="1.5" rx="4" strokeDasharray="4 3" />
          ) : null;
          if (!d.present || !d.segments) {
            return <g key={d.date}><MissingStub x={x} width={bw} baseline={BASE} />{ring}{xl}</g>;
          }
          let acc = 0;
          let isoTop = BASE;
          const rects = d.segments.map((v, si) => {
            const y0 = y(acc), y1 = y(acc + v);
            acc += v;
            if (v <= 0) return null;
            const h = Math.max(0.8, y0 - y1 - GAP);
            if (si === isoIdx) isoTop = y0 - h;
            return (
              <rect key={si} className="seg" x={x} y={y0 - h} width={bw} height={h} fill={series[si]!.color}
                opacity={isolated && si !== isoIdx ? 0.22 : 1} />
            );
          });
          const total = d.total ?? 0;
          const labelVal = isoIdx >= 0 ? d.segments[isoIdx] ?? 0 : total;
          const labelY = (isoIdx >= 0 ? isoTop : y(total)) - 8;
          const showVal = wide || active === i;
          return (
            <g key={d.date}>
              {rects}
              {d.today && total > 0 && <rect x={x} y={y(total)} width={bw} height={BASE - y(total)} fill="url(#u-today)" />}
              {showVal && <text className="vl" x={cx} y={labelY} textAnchor="middle">{estText(labelVal)}</text>}
              {ring}
              {xl}
            </g>
          );
        })}
      </svg>
      <div className="bars-hit">
        {days.map((d, i) => (
          <button
            key={d.date}
            ref={(el) => { btns.current[i] = el; }}
            type="button"
            tabIndex={i === focusIdx ? 0 : -1}
            style={{ left: `${((X0 + i * slot) / W) * 100}%`, width: `${(slot / W) * 100}%`, top: `${(18 / H) * 100}%`, height: `${((BASE - 18) / H) * 100}%` }}
            aria-label={`${d.longLabel}${d.today ? ', so far' : ''}: ${d.present ? estSpoken(d.total ?? 0) : 'no file, not counted in any total'}. Enter opens that day in the table.`}
            aria-describedby={tipOpen && active === i ? 'u-chart-tip' : undefined}
            onMouseEnter={() => show(i)}
            onMouseLeave={hide}
            onFocus={() => { setFocusIdx(i); show(i); }}
            onBlur={hide}
            onKeyDown={(e) => onKey(e, i)}
            onClick={() => onOpenDay(d.date)}
          />
        ))}
      </div>
      {tip && active !== null && <ChartTooltip id="u-chart-tip" day={days[active]!} series={series} left={tip.left} top={tip.top} maxHeight={tip.maxHeight} />}
    </div>
  );
}

/** "Show as table": Date × (Total, one column per agent). */
export function DailyTable({ days, series, caption }: { days: OverviewDay[]; series: Series[]; caption: string }) {
  return (
    <div className="ttbl">
      <table className="tbl">
        <caption>{caption}</caption>
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Total (est.)</th>
            {series.map((s) => <th key={s.agentId} scope="col">{s.name}</th>)}
          </tr>
        </thead>
        <tbody>
          {days.map((d) => (
            <tr key={d.date}>
              <th scope="row" className={d.today ? 'b' : ''}>
                {d.longLabel}{d.today ? ' · so far' : ''}
              </th>
              {d.present && d.segments ? (
                <>
                  <td><Est value={d.total ?? 0} /></td>
                  {d.segments.map((v, i) => <td key={i}><Est value={v} /></td>)}
                </>
              ) : (
                <td className="num-miss" colSpan={series.length + 1}>No file. Not counted in any total.</td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
