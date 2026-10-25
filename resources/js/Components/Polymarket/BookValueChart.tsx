import { useRef, useState, type KeyboardEvent } from 'react';
import { niceStep } from '../../lib/usage';
import { signedUsd, usd, usdAxis, type ChartData, type ChartRange } from '../../lib/polymarket';

// DATA-SPEC §4: viewBox 1312×452, plot x 64→1150 (150 px right gutter for end labels), y 22→362, swept track at 424.
// ≤720px: a 480-wide viewBox without the gutter (end labels go into the legend), so text stays readable.
const H = 452, X0 = 64, TOP = 22, BASE = 362, TRACK = 424;
const WIDE = { W: 1312, X1: 1150 }, NARROW = { W: 480, X1: 468 };
/** Wall-clock window of a day slot: 06:00–24:00 maps across the slot, nights collapse to its edge. */
const DAY_START = 360, DAY_LEN = 1080;
const NAVY = '#1F3A5F';
const TIP_W = 236;

type Hit = { x: number; y: number; label: string; book: number; cash: number | null; prev: number | null; hw: number; src: string; live: boolean };

const RANGES: { range: ChartRange; label: (since: string) => string }[] = [
  { range: '7', label: () => '7 days' },
  { range: '30', label: () => '30 days' },
  { range: 'all', label: (since) => `All · since ${since}` },
];

/** 7 days | 30 days | All · since 3 Oct (client-side: every point is already on the page). */
export function ChartRangeSeg({ range, since, onChange }: { range: ChartRange; since: string; onChange: (r: ChartRange) => void }) {
  return (
    <div className="seg" role="group" aria-label="Range">
      {RANGES.map(({ range: r, label }) => (
        <button key={r} type="button" aria-pressed={r === range} onClick={() => onChange(r)}>{label(since)}</button>
      ))}
    </div>
  );
}

/** Days and runs shown for a range: the last N calendar days up to today, never before the first run. */
export function visibleDays(chart: ChartData, range: ChartRange) {
  return range === 'all' ? chart.days : chart.days.slice(Math.max(0, chart.days.length - Number(range)));
}

export function LegendLine({ dash, color, width }: { dash?: string; color: string; width: number }) {
  return <svg width="26" height="12" aria-hidden="true"><line x1="1" x2="25" y1="6" y2="6" stroke={color} strokeWidth={width} strokeDasharray={dash} /></svg>;
}

/**
 * Book value per desk run on a session axis (one slot per Ljubljana day), with high-water, funded and
 * (old) stop-loss layers, the live mark, and a swept-to-Bitstamp track. The SVG is the picture
 * (role="img" + title/desc); an HTML overlay of buttons, one per point, carries hover / focus / keyboard
 * (roving tabindex, ←/→ between runs, Home/End, Esc closes the tooltip), like StackedDailyChart.
 */
export function BookValueChart({ chart, range, narrow }: { chart: ChartData; range: ChartRange; narrow: boolean }) {
  const wrap = useRef<HTMLDivElement>(null);
  const btns = useRef<(HTMLButtonElement | null)[]>([]);
  const { W, X1 } = narrow ? NARROW : WIDE;
  const days = visibleDays(chart, range);
  const index = new Map(days.map((d, i) => [d.date, i]));
  const slot = (X1 - X0) / Math.max(1, days.length);
  const xOf = (day: string, min: number) => X0 + (index.get(day) ?? 0) * slot + Math.min(1, Math.max(0, (min - DAY_START) / DAY_LEN)) * slot;

  // Running high-water over every run (so a short range still starts at the old peak), then the live mark.
  let run = -Infinity;
  const all = chart.points.map((p) => { run = Math.max(run, p.book); return { p, hw: run }; });
  const pts = all.filter(({ p }) => index.has(p.day));
  const live = chart.live;
  const liveHw = Math.max(run, live.book);
  const showLive = live.separate && index.has(live.day);

  const [focusIdx, setFocusIdx] = useState<number | null>(null);
  const [active, setActive] = useState<number | null>(null);

  const runCount = pts.length;
  if (runCount + (showLive ? 1 : 0) < 2) return null;

  // Y domain: min(book, stop, funded) → max(high-water), on a $1/2/2.5/5 × 10^k step.
  const vals = [...pts.flatMap(({ p, hw }) => [p.book, hw, ...(p.stop !== null ? [p.stop] : [])]), ...(showLive ? [live.book, liveHw] : [])];
  if (chart.funded !== null) vals.push(chart.funded);
  const lo = Math.min(...vals), hi = Math.max(...vals);
  const step = niceStep(Math.max(1, hi - lo), 8);
  const y0 = Math.floor(lo / step) * step;
  const y1 = Math.max(y0 + step, Math.ceil(hi / step) * step);
  const y = (v: number) => BASE - ((v - y0) / (y1 - y0)) * (BASE - TOP);
  const ticks: number[] = [];
  for (let v = y0; v <= y1 + step / 2; v += step) ticks.push(v);

  const xy = pts.map(({ p, hw }) => ({ x: xOf(p.day, p.min), y: y(p.book), hwY: y(hw) }));
  const liveXY = { x: xOf(live.day, live.min), y: y(live.book) };
  const line = xy.map((q, i) => `${i ? 'L' : 'M'}${q.x.toFixed(1)},${q.y.toFixed(1)}`).join(' ');
  const area = xy.length ? `M${xy[0]!.x.toFixed(1)},${BASE} ${line.replace(/^M/, 'L')} L${xy[xy.length - 1]!.x.toFixed(1)},${BASE} Z` : '';

  // High-water: step line through the runs (+ live), extended to the right edge.
  const hwPts = [...xy.map((q) => ({ x: q.x, y: q.hwY })), ...(showLive ? [{ x: liveXY.x, y: y(liveHw) }] : [])];
  let hwPath = '';
  hwPts.forEach((q, i) => {
    hwPath += i === 0 ? `M${q.x.toFixed(1)},${q.y.toFixed(1)}` : ` L${q.x.toFixed(1)},${hwPts[i - 1]!.y.toFixed(1)} L${q.x.toFixed(1)},${q.y.toFixed(1)}`;
  });
  const hwEndY = hwPts.length ? hwPts[hwPts.length - 1]!.y : y(liveHw);
  if (hwPts.length) hwPath += ` L${X1},${hwEndY.toFixed(1)}`;
  const hwValue = chart.highWater ?? liveHw;

  // Peak: the first run (or the live mark) at the high-water value.
  const peakBook = chart.peak?.book ?? null;
  const peakIdx = peakBook === null ? -1 : pts.findIndex(({ p }) => Math.abs(p.book - peakBook) < 0.005);
  const peakXY = peakIdx >= 0 ? xy[peakIdx]! : showLive && peakBook !== null && Math.abs(live.book - peakBook) < 0.005 ? liveXY : null;

  // Stop: only across runs where stop_usd is set; never extended.
  const stopIdx = pts.map(({ p }, i) => (p.stop !== null ? i : -1)).filter((i) => i >= 0);
  const stopPath = stopIdx.map((i, k) => `${k ? 'L' : 'M'}${xy[i]!.x.toFixed(1)},${y(pts[i]!.p.stop!).toFixed(1)}`).join(' ');
  const stopFirst = stopIdx.length ? { x: xy[stopIdx[0]!]!.x, y: y(pts[stopIdx[0]!]!.p.stop!), v: pts[stopIdx[0]!]!.p.stop! } : null;
  const stopLast = stopIdx.length ? { x: xy[stopIdx[stopIdx.length - 1]!]!.x, y: y(pts[stopIdx[stopIdx.length - 1]!]!.p.stop!) } : null;

  // End labels (right gutter): keep High-water and Funded 34 px apart.
  const fundedY = chart.funded !== null ? y(chart.funded) : null;
  let fundedLabelY = fundedY;
  if (fundedY !== null && Math.abs(fundedY - hwEndY) < 34) fundedLabelY = fundedY >= hwEndY ? hwEndY + 34 : hwEndY - 34;

  // Swept track: ▼ per day with sweeps; labels that would sit < 64 px apart drop to a second row.
  let lastRow1 = -Infinity;
  const track = chart.sweeps.filter((s) => index.has(s.day)).map((s) => {
    const x = xOf(s.day, s.min);
    const row2 = x - lastRow1 < 64;
    if (!row2) lastRow1 = x;
    return { ...s, x, ly: row2 ? 457 : 443 };
  });

  // Hover / keyboard targets: every run, then the live mark.
  const hits: Hit[] = pts.map(({ p, hw }, i) => ({
    x: xy[i]!.x, y: xy[i]!.y, label: p.label, book: p.book, cash: p.cash,
    prev: i > 0 ? pts[i - 1]!.p.book : all.indexOf(pts[0]!) > 0 ? all[all.indexOf(pts[0]!) - 1]!.p.book : null,
    hw, src: p.prevTime ? `Desk run · previous ${p.prevTime}` : 'Desk run · first in the feed', live: false,
  }));
  if (showLive) {
    const last = all[all.length - 1];
    hits.push({ ...liveXY, label: live.label, book: live.book, cash: live.cash, prev: last ? last.p.book : null, hw: liveHw,
      src: live.lastRunTime ? `Live mark · last run ${live.lastRunTime}` : 'Live mark', live: true });
  }
  const n = hits.length;
  const focusAt = focusIdx !== null && focusIdx < n ? focusIdx : n - 1;
  const cur = active !== null && active < n ? hits[active]! : null;

  const onKey = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    let next = i;
    if (e.key === 'ArrowRight') next = Math.min(n - 1, i + 1);
    else if (e.key === 'ArrowLeft') next = Math.max(0, i - 1);
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = n - 1;
    else if (e.key === 'Escape') { setActive(null); return; }
    else return;
    e.preventDefault();
    setFocusIdx(next);
    btns.current[next]?.focus();
  };

  let tip: { left: number; top: number } | null = null;
  if (cur && wrap.current) {
    const w = wrap.current.getBoundingClientRect().width, k = w / W;
    const right = cur.x * k + 16;
    const left = right + TIP_W > w ? Math.max(0, cur.x * k - 16 - TIP_W) : right;
    tip = { left, top: Math.max(0, Math.min(cur.y * k - 40, BASE * k - 150)) };
  }

  const first = days[0]!, lastDay = days[days.length - 1]!;
  const labelEvery = Math.max(1, Math.ceil(26 / slot));
  const nowLabel = `Now ${usd(live.book)}`;
  const nowXY = showLive ? liveXY : xy[xy.length - 1]!;

  return (
    <div className="chart-wrap" ref={wrap}>
      <svg className={`pmchart${narrow ? ' narrow' : ''}`} viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby="pm-ct pm-cd">
        <title id="pm-ct">{`Book value, every desk run, ${first.d} ${first.mon} to ${lastDay.d} ${lastDay.mon}`}</title>
        <desc id="pm-cd">
          {`Book value ${usd(live.book)} now; high-water ${usd(hwValue)}${chart.peak ? ` on ${chart.peak.dayLabel}` : ''}${chart.funded !== null ? `; funded ${usd(chart.funded)}` : ''}. Use the buttons over the chart to read each run.`}
        </desc>
        <defs>
          <linearGradient id="pmfill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stopColor={NAVY} stopOpacity=".16" />
            <stop offset="1" stopColor={NAVY} stopOpacity=".02" />
          </linearGradient>
        </defs>
        {days.map((d, i) => d.weekend && <rect key={d.date} x={X0 + i * slot} y={TOP} width={slot} height={BASE - TOP} fill="#F7F5F0" />)}
        {ticks.map((v) => (
          <g key={v}>
            <line className="grid" x1={X0} x2={X1} y1={y(v)} y2={y(v)} />
            <text x={X0 - 10} y={y(v) + 4} textAnchor="end">{usdAxis(v)}</text>
          </g>
        ))}
        {slot >= 8 && days.map((d, i) => <line key={d.date} className="vgrid" x1={X0 + i * slot} x2={X0 + i * slot} y1={TOP} y2={BASE} />)}
        {slot >= 8 && <line className="vgrid" x1={X1} x2={X1} y1={TOP} y2={BASE} />}

        {fundedY !== null && (
          <>
            <line className="fund" x1={X0} x2={X1} y1={fundedY} y2={fundedY} />
            {!narrow && <><text className="lbl" x={X1 + 12} y={fundedLabelY! + 4}>Funded</text><text x={X1 + 12} y={fundedLabelY! + 20}>{usd(chart.funded!)}</text></>}
          </>
        )}
        {hwPath && <path className="hw" d={hwPath} />}
        {!narrow && <><text className="lbl" x={X1 + 12} y={hwEndY + 4}>High-water</text><text x={X1 + 12} y={hwEndY + 20}>{usd(hwValue)}</text></>}

        {stopFirst && stopLast && (
          <>
            {stopIdx.length > 1 ? <path className="stop" d={stopPath} fill="none" /> : <circle cx={stopFirst.x} cy={stopFirst.y} r="2.5" fill="var(--w2)" />}
            <text className="ann" x={stopFirst.x} y={stopFirst.y - 12}>Stop {usd(stopFirst.v)}</text>
            {chart.stop?.removed && stopIdx[stopIdx.length - 1] !== pts.length - 1 && (
              <>
                <line stroke="#B4661C" strokeWidth="2" x1={stopLast.x} x2={stopLast.x} y1={stopLast.y - 7} y2={stopLast.y + 7} />
                <text className="ann" x={stopLast.x + 8} y={stopLast.y + 4}>Stop-loss removed {chart.stop.toLabel}</text>
              </>
            )}
          </>
        )}

        {area && <path d={area} fill="url(#pmfill)" />}
        {line && <path className="line" d={line} />}
        {showLive && xy.length > 0 && (
          <path className="line" strokeDasharray="3 4" d={`M${xy[xy.length - 1]!.x.toFixed(1)},${xy[xy.length - 1]!.y.toFixed(1)} L${liveXY.x.toFixed(1)},${liveXY.y.toFixed(1)}`} />
        )}
        {xy.length <= 400 && xy.map((q, i) => <circle key={i} cx={q.x} cy={q.y} r="2" fill={NAVY} />)}
        <circle cx={nowXY.x} cy={nowXY.y} r="9" fill={NAVY} opacity=".14" />
        <circle cx={nowXY.x} cy={nowXY.y} r="5" fill="#fff" stroke={NAVY} strokeWidth="2.5" />
        <text className="lv" x={nowXY.x - 10} y={nowXY.y - 16} textAnchor="end">{nowLabel}</text>
        {peakXY && <circle cx={peakXY.x} cy={peakXY.y} r="4.5" fill="#fff" stroke="#4A4F5A" strokeWidth="2" />}

        {cur && (
          <>
            <line x1={cur.x} x2={cur.x} y1={TOP} y2={BASE} stroke="#17191F" strokeOpacity=".35" strokeWidth="1" />
            <circle cx={cur.x} cy={cur.y} r="5.5" fill={NAVY} stroke="#fff" strokeWidth="2" />
          </>
        )}

        <line className="base" x1={X0} x2={X1} y1={BASE} y2={BASE} />
        {days.map((d, i) => {
          const show = d.today || (i % labelEvery === 0 && (days.length - 1 - i >= labelEvery || i === days.length - 1));
          if (!show) return null;
          const cx = X0 + (i + 0.5) * slot;
          return (
            <g key={d.date}>
              <text className={`xl${d.today ? ' b' : ''}`} x={cx} y="382" textAnchor="middle">{d.d === '1' && i > 0 ? `1 ${d.mon}` : d.d}</text>
              {slot >= 30 && <text className="xm" x={cx} y="397" textAnchor="middle">{d.wd}</text>}
            </g>
          );
        })}
        <text className="xm" x={X0 - 10} y="397" textAnchor="end">{first.mon}</text>

        {track.length > 0 && (
          <>
            <text className="xm" x={X0 - 10} y="428" textAnchor="end">Swept</text>
            <line className="swtrack" x1={X0} x2={X1} y1={TRACK} y2={TRACK} />
            {track.map((s) => (
              <g key={s.day}>
                <path className="sw" d={`M${(s.x - 6).toFixed(1)},418 L${(s.x + 6).toFixed(1)},418 L${s.x.toFixed(1)},428 Z`} />
                <text className="swl" x={s.x} y={s.ly} textAnchor="middle">{usd(s.total)}{s.count > 1 ? ` ×${s.count}` : ''}</text>
              </g>
            ))}
          </>
        )}
      </svg>
      <div className="bars-hit">
        {hits.map((h, i) => {
          const left = i === 0 ? Math.max(X0, h.x - 8) : (hits[i - 1]!.x + h.x) / 2;
          const right = i === n - 1 ? Math.min(X1 + 8, h.x + 8) : (h.x + hits[i + 1]!.x) / 2;
          return (
            <button
              key={i}
              ref={(el) => { btns.current[i] = el; }}
              type="button"
              tabIndex={i === focusAt ? 0 : -1}
              style={{ left: `${(left / W) * 100}%`, width: `${(Math.max(2, right - left) / W) * 100}%`, top: `${(TOP / H) * 100}%`, height: `${((BASE - TOP) / H) * 100}%` }}
              aria-label={`${h.live ? 'Live mark' : 'Desk run'} ${h.label}: book value ${usd(h.book)}, cash ${h.cash === null ? 'not recorded' : usd(h.cash)}.`}
              aria-describedby={active === i ? 'pm-tip' : undefined}
              onMouseEnter={() => setActive(i)}
              onMouseLeave={() => setActive(null)}
              onFocus={() => { setFocusIdx(i); setActive(i); }}
              onBlur={() => setActive(null)}
              onKeyDown={(e) => onKey(e, i)}
            />
          );
        })}
      </div>
      {cur && tip && (
        <div className="tip pmtip" id="pm-tip" role="tooltip" style={{ left: tip.left, top: tip.top }}>
          <h4>{cur.label}</h4>
          <dl>
            <dt>Book value</dt><dd>{usd(cur.book)}</dd>
            <dt>Cash</dt><dd>{cur.cash === null ? '—' : usd(cur.cash)}</dd>
            <dt>vs previous run</dt><dd>{cur.prev === null ? '—' : signedUsd(cur.book - cur.prev)}</dd>
            <dt>Below high-water</dt><dd>{cur.hw > 0 ? `${(((cur.hw - cur.book) / cur.hw) * 100).toFixed(1)}%` : '—'}</dd>
          </dl>
          <p className="src">{cur.src}</p>
        </div>
      )}
    </div>
  );
}

/** Line under the chart: runs + span + live mark, peak and low in the range. */
export function chartFoot(chart: ChartData, range: ChartRange): { left: string; right: string } | null {
  const days = visibleDays(chart, range);
  const set = new Set(days.map((d) => d.date));
  const pts = chart.points.filter((p) => set.has(p.day));
  if (!pts.length) return null;
  const dayOf = (date: string) => days.find((d) => d.date === date)!;
  const a = dayOf(pts[0]!.day), b = days[days.length - 1]!;
  const live = chart.live.separate && set.has(chart.live.day);
  const candidates = [...pts.map((p) => ({ book: p.book, label: p.label })), ...(live ? [{ book: chart.live.book, label: chart.live.label }] : [])];
  const peak = candidates.reduce((m, c) => (c.book > m.book ? c : m));
  const low = Math.min(...candidates.map((c) => c.book));
  const runs = `${pts.length} desk run${pts.length === 1 ? '' : 's'} · ${a.d} ${a.mon} – ${b.d} ${b.mon}`;
  return {
    left: live ? `${runs} · last point “Now” is the live mark at ${chart.live.time}` : runs,
    right: `Peak ${usd(peak.book)} on ${peak.label} · low ${usd(low)}`,
  };
}
