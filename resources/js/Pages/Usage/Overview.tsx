import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { TopBar } from '../../Components/TopBar';
import { ChartLegend } from '../../Components/Usage/ChartLegend';
import { EstNote } from '../../Components/Usage/EstNote';
import { Est } from '../../Components/Usage/Est';
import { InfoLines } from '../../Components/Usage/InfoLines';
import { MissingKey } from '../../Components/Usage/MissingStub';
import { RangeSeg } from '../../Components/Usage/RangeSeg';
import { RankList } from '../../Components/Usage/RankList';
import { DailyTable, StackedDailyChart } from '../../Components/Usage/StackedDailyChart';
import { UsageTable } from '../../Components/Usage/UsageTable';
import { count, initials, rangeWords, useNarrow, withQuery, type OverviewDay, type OverviewProps, type Series } from '../../lib/usage';

function ChartSkeleton() {
  return (
    <>
      <div style={{ display: 'flex', gap: 8, margin: '14px 0' }}>
        {[0, 1, 2, 3].map((i) => <div key={i} className="skel" style={{ height: 34, width: 120, borderRadius: 99 }} />)}
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-around', height: 230, borderBottom: '1.5px solid #CFCBC0' }}>
        {[90, 140, 170, 220, 200, 40, 120].map((h, i) => <div key={i} className="skel" style={{ height: h, width: '9%' }} />)}
      </div>
      <div className="skel-lines">
        {['80%', '64%', '72%'].map((w) => <div key={w} className="skel" style={{ height: 18, width: w }} />)}
      </div>
    </>
  );
}

/** ≤720px: one horizontal total bar per day + "Split" select (All agents / one series). */
function NarrowDays({ days, series }: { days: OverviewDay[]; series: Series[] }) {
  const [split, setSplit] = useState<string>('all');
  const idx = split === 'all' ? -1 : series.findIndex((s) => s.agentId === split);
  const val = (d: OverviewDay) => (idx < 0 ? d.total ?? 0 : d.segments?.[idx] ?? 0);
  const max = Math.max(1, ...days.filter((d) => d.present).map(val));
  const color = idx < 0 ? 'var(--accent)' : series[idx]!.color;
  return (
    <>
      <ul className="hbars" aria-label="Daily estimate">
        {days.map((d) => (
          <li key={d.date}>
            <span className={`d${d.today ? ' b' : ''}`}>{d.label}</span>
            {d.present
              ? <span className="t" style={{ width: `${Math.max(1, (val(d) / max) * 100).toFixed(1)}%`, background: color }} aria-hidden="true" />
              : <MissingKey />}
            <span className="v">{d.present ? <Est value={val(d)} /> : <span className="dim2">No file</span>}</span>
          </li>
        ))}
      </ul>
      <label className="picker" style={{ marginTop: 12, width: '100%' }}>
        Split:
        <select value={split} onChange={(e) => setSplit(e.target.value)}>
          <option value="all">All agents</option>
          {series.map((s) => <option key={s.agentId} value={s.agentId}>{s.name}</option>)}
        </select>
      </label>
    </>
  );
}

export default function Overview(p: OverviewProps) {
  const narrow = useNarrow();
  const [isolated, setIsolated] = useState<string | null>(null);
  const [asTable, setAsTable] = useState(false);
  const [loading, setLoading] = useState(false);
  const missing = p.days.length - p.daysWithFile;
  const rangeTxt = rangeWords(p.range);
  const agentHref = (id: string) => withQuery(`/usage/agents/${encodeURIComponent(id)}`, { range: p.range === 7 ? null : p.range });

  const openDay = (date: string) => {
    router.get(withQuery(window.location.pathname + window.location.search, { day: date }), {}, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => document.getElementById('h-all')?.scrollIntoView({ block: 'start' }),
    });
  };
  const clearDay = withQuery('/usage', { range: p.range === 7 ? null : p.range });
  const t = p.table;

  return (
    <>
      <Head title="Usage" />
      <TopBar />
      <main className="wrap">
        <div className="dhead">
          <div>
            <h1>Usage</h1>
            <p className="summary">
              {p.empty
                ? <>No usage files · {p.fromLabel} – {p.toLabel}</>
                : <><b><Est value={p.rangeTotal} /></b> tokens{narrow ? '' : ` across ${p.agentCount} agents`} · {p.fromLabel} – {p.toLabel}</>}
              {p.range === 30 && !p.empty && <> · <b>{p.daysWithFile} of {p.days.length} days have a file</b></>}
            </p>
          </div>
          <div className="tools"><RangeSeg range={p.range} onLoading={setLoading} /></div>
        </div>

        <EstNote updatedAt={p.updatedAt} />

        {p.empty && !loading ? (
          <section className="card">
            <div className="empty" style={{ margin: 0, padding: '48px 24px' }}>
              <div className="eico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg></div>
              <h2>No usage estimates yet</h2>
              <p>The estimator writes one file per day. None found for the {rangeTxt}, so there is nothing to chart. Settings → Usage still has the real total.</p>
            </div>
            <InfoLines unreadable={p.unreadable} />
          </section>
        ) : (
          <>
            <div className="ugrid">
              <section className="card" style={{ position: 'relative' }} aria-labelledby="h-daily" aria-busy={loading}>
                <div className="chead">
                  <h2 id="h-daily">{narrow ? 'Daily estimate' : 'Daily estimate by agent'}</h2>
                  {loading
                    ? <span className="sub" role="status">Loading usage files…</span>
                    : <span className="sub">{narrow ? 'total only' : 'Stacked by agent'}</span>}
                  {!narrow && !loading && (
                    <div className="right">
                      {missing > 0 && <span className="hscale"><MissingKey />No file for that day ({missing}) · not the same as zero</span>}
                      <button type="button" className="tlink" aria-expanded={asTable} onClick={() => setAsTable((v) => !v)}>{asTable ? 'Show as chart' : 'Show as table'}</button>
                    </div>
                  )}
                </div>
                {loading ? <ChartSkeleton /> : narrow ? (
                  <NarrowDays days={p.days} series={p.series} />
                ) : asTable ? (
                  <DailyTable days={p.days} series={p.series} caption={`Daily estimated tokens by agent, ${p.fromLabel} – ${p.toLabel}`} />
                ) : (
                  <>
                    <ChartLegend series={p.series} isolated={isolated} onToggle={(k) => setIsolated((cur) => (cur === k ? null : k))} />
                    <StackedDailyChart days={p.days} series={p.series} isolated={isolated} fromLabel={p.fromLabel} toLabel={p.toLabel} onOpenDay={openDay} />
                  </>
                )}
                <InfoLines notes={p.coverageNotes} unreadable={p.unreadable} />
              </section>

              <div className="side">
                <section className="card" aria-labelledby="h-ta">
                  <div className="chead"><h2 id="h-ta">Top agents</h2><span className="sub">{rangeTxt}</span></div>
                  {narrow ? (
                    <ul className="ntop">
                      {p.topAgents.map((a) => (
                        <li key={a.agentId}>
                          <span className="an"><i style={{ background: a.color }} aria-hidden="true" /><Link href={agentHref(a.agentId)}>{a.name}</Link></span>
                          <Est value={a.tokens} />
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <RankList label="Top agents" items={p.topAgents.map((a) => ({
                      key: a.agentId, name: a.name, href: agentHref(a.agentId), value: a.tokens, color: a.color,
                      meta: <>{Math.round(a.share * 100)}% of all · {count(a.turns)} turns</>,
                    }))} />
                  )}
                </section>
                <section className="card" aria-labelledby="h-tr">
                  <div className="chead"><h2 id="h-tr">Top routines</h2><span className="sub">{rangeTxt}</span></div>
                  {p.topRoutines.length === 0 && <p className="foot">No routine runs matched in the {rangeTxt}.</p>}
                  <RankList label="Top routines" items={p.topRoutines.map((r) => ({
                    key: `${r.agentId}-${r.routineId}`, name: r.name, value: r.tokens,
                    meta: (
                      <>
                        <span className="agent"><i>{initials(r.agentName)}</i>{r.agentName}</span>
                        {count(r.runs)} runs{r.perRun !== null && <> · <Est value={r.perRun} /> per run</>}
                      </>
                    ),
                  }))} />
                  {p.unmatched.runs > 0 && (
                    <p className="foot">Not in this list: <b>unmatched wakes</b>, {count(p.unmatched.runs)} runs across {p.unmatched.agents} agents, <Est value={p.unmatched.tokens} />. They have no routine to rank.</p>
                  )}
                </section>
              </div>
            </div>

            <h2 className="sect" id="h-all">
              All agents <span className="cnt">{t.rows.length}</span>{' '}
              <span style={{ textTransform: 'none', letterSpacing: 0 }}>
                · {t.day ? t.dayLabel : 'last 7 days'} · select a name for hours and routines
              </span>
            </h2>
            {t.day && t.dayMissing && (
              <p className="notice" role="status">No usage file for {t.dayLabel}. <Link href={clearDay} preserveScroll>Show the last 7 days</Link></p>
            )}
            {t.day && !t.dayMissing && (
              <p className="dayf">Showing {t.dayLabel} only. <Link href={clearDay} preserveScroll>Show the last 7 days</Link></p>
            )}
            {t.rows.length > 0 && <section className="card"><UsageTable table={t} range={p.range} narrow={narrow} /></section>}
          </>
        )}
      </main>
    </>
  );
}
