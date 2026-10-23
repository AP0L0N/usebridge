import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { TopBar } from '../../Components/TopBar';
import { EstNote } from '../../Components/Usage/EstNote';
import { Est } from '../../Components/Usage/Est';
import { FactorBadge } from '../../Components/Usage/FactorBadge';
import { HourBars } from '../../Components/Usage/HourBars';
import { HourHeatmap, HourTable } from '../../Components/Usage/HourHeatmap';
import { InfoLines } from '../../Components/Usage/InfoLines';
import { ChartPatterns, MissingStub } from '../../Components/Usage/MissingStub';
import { RangeSeg } from '../../Components/Usage/RangeSeg';
import { RoutineTable } from '../../Components/Usage/RoutineTable';
import { StatTile } from '../../Components/Usage/StatTile';
import { count, estText, inRange, pct, rangeParam, rangeWords, spanLabel, useNarrow, withQuery, type AgentColor, type AgentProps } from '../../lib/usage';

type Day = AgentProps['days'][number];

const H = 200, BASE = 172, TOP = 22;

/** One series, one bar per day in the agent's colour. Missing day = hatched stub; today = stripe + "so far". */
function AgentDailyBars({ days, color, title, narrow }: { days: Day[]; color: AgentColor; title: string; narrow: boolean }) {
  const W = narrow ? 360 : 600;
  const n = days.length;
  const wide = n <= 7;
  const max = Math.max(1, ...days.map((d) => d.value ?? 0));
  const slot = W / n;
  const bw = Math.min(44, slot * 0.62);
  const h = (v: number) => (v / max) * (BASE - TOP);
  return (
    <svg className="chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby="u-daily-t">
      <title id="u-daily-t">{title}</title>
      <ChartPatterns />
      <line className="base" x1="0" x2={W} y1={BASE} y2={BASE} />
      {days.map((d, i) => {
        const x = i * slot + (slot - bw) / 2, cx = x + bw / 2;
        const label = (wide || i % 3 === 0 || d.today) && (
          <text className={`xl${d.today ? ' b' : ''}`} x={cx} y={H - 8} textAnchor="middle">{d.label}{d.today && wide && !narrow ? ' · so far' : ''}</text>
        );
        if (d.value === null) return <g key={d.date}><MissingStub x={x} width={bw} baseline={BASE} /><title>{`${d.longLabel}: no file`}</title>{label}</g>;
        const bh = d.value === 0 ? 1 : Math.max(1, h(d.value));
        return (
          <g key={d.date}>
            <title>{`${d.longLabel}${d.today ? ' (so far)' : ''}: ${estText(d.value)}`}</title>
            <rect x={x} y={BASE - bh} width={bw} height={bh} rx="2" fill={color} />
            {d.today && d.value > 0 && <rect x={x} y={BASE - bh} width={bw} height={bh} fill="url(#u-today)" />}
            {wide && !narrow && <text className="vl" x={cx} y={BASE - bh - 6} textAnchor="middle">{estText(d.value)}</text>}
            {label}
          </g>
        );
      })}
    </svg>
  );
}

function DailyTable({ days, caption }: { days: Day[]; caption: string }) {
  return (
    <div className="ttbl">
      <table className="tbl">
        <caption>{caption}</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Tokens (est.)</th></tr></thead>
        <tbody>
          {days.map((d) => (
            <tr key={d.date}>
              <th scope="row" className={d.today ? 'b' : ''}>{d.longLabel}{d.today ? ' · so far' : ''}</th>
              <td>{d.value === null ? <span className="num-miss">No file. Not counted in any total.</span> : <Est value={d.value} />}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function Agent(p: AgentProps) {
  const narrow = useNarrow();
  const [hoursTable, setHoursTable] = useState(false);
  const [dailyTable, setDailyTable] = useState(false);
  const { agent, stats } = p;
  const rangeTxt = rangeWords(p.range);
  const busiest = Math.max(0, ...p.heat.flatMap((r) => r.hours ?? []));
  const hourScope = p.range === 7 ? '' : p.range === 'yesterday' ? `yesterday (${p.toLabel}) · ` : `last 7 days (${p.hoursFromLabel} – ${p.toLabel}) · `;
  const K = Math.round(agent.K);

  return (
    <>
      <Head title={`${agent.name} · Usage`} />
      <TopBar />
      <main className="wrap">
        <Link className="crumb" href={withQuery('/usage', { range: rangeParam(p.range) })}>← Usage</Link>
        <div className="ahead">
          <h1>{agent.name}</h1>
          <span className="badge b-arch">{agent.rank !== null ? `#${agent.rank} of ${agent.of} · ${rangeTxt}` : `No usage · ${rangeTxt}`}</span>
          <FactorBadge factor={agent.factor} />
          <div className="tools"><RangeSeg range={p.range} /></div>
        </div>

        <EstNote updatedAt={p.updatedAt} day={p.range === 'yesterday' ? 'yesterday' : 'today'} />

        {p.zero ? (
          <div className="empty zero">
            <div className="eico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg></div>
            <h2>No estimated usage for this agent {inRange(p.range)}.</h2>
            <p>It stays in the All agents table with 0. Settings → Usage still has the real total.</p>
            <InfoLines unreadable={p.unreadable} />
          </div>
        ) : (
          <>
            <div className="stats">
              <StatTile label="Tokens (est.)" value={<Est value={stats.tokens} />} hint={`${pct(stats.share)} of all agents`} />
              <StatTile label="Fresh text only (est.)" value={<Est value={stats.io} />} hint="without context re-reads" />
              <StatTile label="Turns" value={count(stats.turns)} hint="counted, not estimated" />
              <StatTile label="Unprompted wakes" value={count(stats.matchedRuns + stats.unmatchedRuns)} hint={`${count(stats.matchedRuns)} matched a routine · ${count(stats.unmatchedRuns)} no routine (ad-hoc)`} />
            </div>

            <div className="dgrid">
              <section className="card" aria-labelledby="h-hr">
                <div className="chead">
                  <h2 id="h-hr">By hour</h2>
                  <span className="sub">
                    Europe/Ljubljana · {hourScope}{narrow ? 'hour totals' : <>shade = share of this agent's busiest hour ({estText(busiest)})</>}
                  </span>
                  <div className="right">
                    <button type="button" className="tlink" aria-expanded={hoursTable} onClick={() => setHoursTable((v) => !v)}>{hoursTable ? 'Show as chart' : 'Show as table'}</button>
                  </div>
                </div>
                {hoursTable ? (
                  <HourTable rows={p.heat} caption={`Estimated tokens for ${agent.name} by day and hour, ${p.hoursFromLabel} – ${p.toLabel}`} />
                ) : (
                  <>
                    <HourBars narrow={narrow} totals={p.hourTotals} title={`Estimated tokens for ${agent.name} by hour of day, summed over ${p.hoursFromLabel} – ${p.toLabel}. Show as table has every value.`} />
                    {!narrow && <HourHeatmap rows={p.heat} updatedAt={p.updatedAt} />}
                  </>
                )}
              </section>

              <section className="card" aria-labelledby="h-dd">
                <div className="chead">
                  <h2 id="h-dd">Daily</h2>
                  <span className="sub">{spanLabel(p.fromLabel, p.toLabel)}</span>
                  <div className="right">
                    <button type="button" className="tlink" aria-expanded={dailyTable} onClick={() => setDailyTable((v) => !v)}>{dailyTable ? 'Show as chart' : 'Show as table'}</button>
                  </div>
                </div>
                {dailyTable
                  ? <DailyTable days={p.days} caption={`Daily estimated tokens for ${agent.name}, ${p.fromLabel} – ${p.toLabel}`} />
                  : <AgentDailyBars narrow={narrow} days={p.days} color={agent.color} title={`Daily estimated tokens for ${agent.name}, ${p.fromLabel} to ${p.toLabel}. Show as table has every value.`} />}
                <div className="annot" style={{ marginTop: 12 }}>
                  <h3>Calibration</h3>
                  {agent.factor === 'calibrated'
                    ? <>Factor K = {K} (calibrated on this agent's transcripts). A median factor means a rougher estimate.</>
                    : <>Factor K = {K}, the median of the calibrated agents: this agent has too little transcript history of its own, so its figures are rougher than a calibrated agent's.</>}
                </div>
                <InfoLines unreadable={p.unreadable} />
              </section>
            </div>

            <section className="card" aria-labelledby="h-rt">
              <div className="chead">
                <h2 id="h-rt">Routines</h2>
                <span className="sub">{rangeTxt} · runs are unprompted wakes matched to the routine's schedule</span>
              </div>
              <RoutineTable rows={p.routines} totals={p.routineTotals} caption={`Routine breakdown for ${agent.name}, ${rangeTxt}, estimated`} />
            </section>
          </>
        )}
      </main>
    </>
  );
}
