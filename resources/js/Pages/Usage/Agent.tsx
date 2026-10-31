import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import '../../../css/usage-redesign.css';
import { t } from '../../lib/strings-usage';
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

  const total = stats.matchedRuns + stats.unmatchedRuns;
  return (
    <>
      <Head title={`${agent.name} · ${t('usage.title')}`} />
      <TopBar />
      <div className="bu">
      <main className="wrap">
        <Link className="crumb" href={withQuery('/usage', { range: rangeParam(p.range) })}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>
          {t('usage.back')}
        </Link>
        <div className="ahead">
          <div>
            <div className="titleline">
              <span className="dot" style={{ background: agent.color }} aria-hidden="true" />
              <h1>{agent.name}</h1>
              <FactorBadge factor={agent.factor} k={Math.round(agent.K)} />
              <span className="chip">{agent.rank !== null ? t('agent.badge.rank', { rank: agent.rank, of: agent.of, range: rangeTxt }) : t('agent.badge.none', { range: rangeTxt })}</span>
            </div>
            <p className="asub"><Est value={stats.tokens} /> {t('agent.sub', { range: rangeTxt })}</p>
          </div>
          <div className="tools"><RangeSeg range={p.range} /></div>
        </div>

        <EstNote updatedAt={p.updatedAt} day={p.range === 'yesterday' ? 'yesterday' : 'today'} />

        {p.zero ? (
          <div className="empty zero">
            <div className="eico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg></div>
            <h2>{t('agent.zero.title', { range: inRange(p.range) })}</h2>
            <p>{t('agent.zero.body')}</p>
            <InfoLines unreadable={p.unreadable} />
          </div>
        ) : (
          <>
            <div className="stats">
              <StatTile label={t('agent.tile.tokens')} value={<Est value={stats.tokens} />} hint={t('agent.tile.tokens.hint', { share: pct(stats.share, 0), turns: count(stats.turns) })} />
              <StatTile label={t('agent.tile.fresh')} value={<Est value={stats.io} />} hint={t('agent.tile.fresh.hint')} />
              <StatTile label={t('agent.tile.wakes')} value={count(total)} hint={<>{t('agent.tile.wakes.hint')}<br />{t('agent.tile.wakes.split', { matched: count(stats.matchedRuns), unmatched: count(stats.unmatchedRuns) })}</>} />
            </div>

            <section className="card" aria-labelledby="h-hr" style={{ marginBottom: 32 }}>
              <div className="chead">
                <h2 id="h-hr">{t('agent.hours.title')}</h2>
                <span className="sub">{narrow ? hourScope + 'hour totals' : t('agent.hours.sub') + (hourScope ? ` · ${hourScope.replace(/ · $/, '')}` : '')}</span>
                <div className="right">
                  <button type="button" className="tlink" aria-expanded={hoursTable} onClick={() => setHoursTable((v) => !v)}>{hoursTable ? t('usage.showChart') : t('usage.showTable')}</button>
                </div>
              </div>
              {hoursTable ? (
                <HourTable rows={p.heat} caption={`Estimated tokens for ${agent.name} by day and hour, ${p.hoursFromLabel} – ${p.toLabel}`} />
              ) : (
                <>
                  {narrow && <HourBars narrow={narrow} totals={p.hourTotals} title={`Estimated tokens for ${agent.name} by hour of day, summed over ${p.hoursFromLabel} – ${p.toLabel}. Show as table has every value.`} />}
                  {!narrow && <HourHeatmap rows={p.heat} updatedAt={p.updatedAt} />}
                </>
              )}
              <p className="foot">{t('agent.hours.busiest', { value: estText(busiest) })}</p>
            </section>

            <div className="dgrid wide">
              <section className="card" aria-labelledby="h-dd">
                <div className="chead">
                  <h2 id="h-dd">{t('agent.daily.title')}</h2>
                  <span className="sub">{t('agent.daily.sub')} · {spanLabel(p.fromLabel, p.toLabel)}</span>
                  <div className="right">
                    <button type="button" className="tlink" aria-expanded={dailyTable} onClick={() => setDailyTable((v) => !v)}>{dailyTable ? t('usage.showChart') : t('usage.showTable')}</button>
                  </div>
                </div>
                {dailyTable
                  ? <DailyTable days={p.days} caption={`Daily estimated tokens for ${agent.name}, ${p.fromLabel} – ${p.toLabel}`} />
                  : <AgentDailyBars narrow={narrow} days={p.days} color={agent.color} title={`Daily estimated tokens for ${agent.name}, ${p.fromLabel} to ${p.toLabel}. Show as table has every value.`} />}
                <InfoLines unreadable={p.unreadable} />
              </section>

              <div className="stack">
                <section className="card note-card" aria-labelledby="h-cal">
                  <h2 id="h-cal">{t('agent.calibration.title')}</h2>
                  <p>{t(agent.factor === 'calibrated' ? 'agent.calibration.calibrated' : 'agent.calibration.median', { k: Math.round(agent.K), name: agent.name })}</p>
                </section>
                <section className="card note-card" aria-labelledby="h-read">
                  <h2 id="h-read">{t('agent.read.title')}</h2>
                  <p>{t('agent.read.body', { name: agent.name })}</p>
                </section>
              </div>
            </div>

            <section className="card" aria-labelledby="h-rt">
              <div className="chead">
                <h2 id="h-rt">{t('agent.routines.title')}</h2>
                <span className="sub">{t('agent.routines.sub', { name: agent.name, range: rangeTxt })}</span>
              </div>
              <RoutineTable rows={p.routines} totals={p.routineTotals} caption={`Routine breakdown for ${agent.name}, ${rangeTxt}, estimated`} />
            </section>
          </>
        )}
      </main>
      </div>
    </>
  );
}
