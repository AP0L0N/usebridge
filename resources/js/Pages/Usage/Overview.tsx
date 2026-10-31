import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import '../../../css/usage-redesign.css';
import type { Shared } from '../../lib/account';
import { AppTopBar } from '../../Components/Shell/AppTopBar';
import { ChartLegend } from '../../Components/Usage/ChartLegend';
import { CliUsage } from '../../Components/Usage/CliUsage';
import { CursorUsage } from '../../Components/Usage/CursorUsage';
import { EstNote } from '../../Components/Usage/EstNote';
import { Est } from '../../Components/Usage/Est';
import { InfoLines } from '../../Components/Usage/InfoLines';
import { MissingKey } from '../../Components/Usage/MissingStub';
import { RangeSeg } from '../../Components/Usage/RangeSeg';
import { RankList } from '../../Components/Usage/RankList';
import { DailyTable, StackedDailyChart } from '../../Components/Usage/StackedDailyChart';
import { UsageTable } from '../../Components/Usage/UsageTable';
import { t } from '../../lib/strings-usage';
import { count, initials, recolorOverview, rangeParam, rangeWords, spanLabel, useNarrow, withQuery, type OverviewDay, type OverviewProps, type Series } from '../../lib/usage';

function ChartSkeleton() {
  return (
    <>
      <div style={{ display: 'flex', gap: 8, margin: '14px 0' }}>
        {[0, 1, 2, 3].map((i) => <div key={i} className="skel" style={{ height: 34, width: 120, borderRadius: 99 }} />)}
      </div>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-around', height: 230, borderBottom: '1.5px solid var(--chart-grid)' }}>
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
  const color = idx < 0 ? 'var(--chart-1)' : series[idx]!.color;
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

export default function Overview(props: OverviewProps) {
  const p = recolorOverview(props);
  const signedIn = usePage<Shared>().props.auth?.user != null;
  const narrow = useNarrow();
  const [isolated, setIsolated] = useState<string | null>(null);
  const [asTable, setAsTable] = useState(false);
  const [loading, setLoading] = useState(false);
  const [howOpen, setHowOpen] = useState(false);
  const missing = p.days.length - p.daysWithFile;
  const rangeTxt = rangeWords(p.range);
  const span = spanLabel(p.fromLabel, p.toLabel);
  const agentHref = (id: string) => withQuery(`/usage/agents/${encodeURIComponent(id)}`, { range: rangeParam(p.range) });

  const openDay = (date: string) => {
    router.get(withQuery(window.location.pathname + window.location.search, { day: date }), {}, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => document.getElementById('h-all')?.scrollIntoView({ block: 'start' }),
    });
  };
  const clearDay = withQuery('/usage', { range: rangeParam(p.range) });
  const tb = p.table;
  const yesterday = p.range === 'yesterday';
  // Yesterday range: the table is scoped to that day by the range itself (no "only" notice).
  const tableByRange = yesterday && tb.day === p.to;
  const clearDayText = yesterday ? t('usage.all.clearYesterday') : t('usage.all.clear7');

  const names = p.table.rows.map((r) => r.name);
  const mineNames = names.length > 1 ? `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}` : names[0] ?? '';

  return (
    <>
      <Head title={t('usage.title')} />
      <AppTopBar />
      <div className="bu">
      <main className="wrap">
        <div className="dhead">
          <div>
            <div className="titleline"><h1>{t('usage.title')}</h1></div>
            <p className="summary">
              {p.empty
                ? t('usage.summary.empty', { span })
                : <><b><Est value={p.rangeTotal} /></b> {narrow ? t('usage.summary.totalShort', { span }) : t('usage.summary.total', { n: p.agentCount, span })}</>}
              {p.range === 30 && !p.empty && <> · <b>{t('usage.summary.days', { n: p.daysWithFile, m: p.days.length })}</b></>}
            </p>
            {signedIn && <p className="mine">{mineNames ? t('usage.mine', { names: mineNames }) : t('usage.mine.none')}</p>}
          </div>
          <div className="tools"><RangeSeg range={p.range} onLoading={setLoading} /></div>
        </div>

        {!p.empty && <EstNote updatedAt={p.updatedAt} day={yesterday ? 'yesterday' : 'today'} />}

        {p.empty && !loading ? (
          <section className="card">
            <div className="empty" style={{ margin: 0, padding: '72px 24px', border: 0 }}>
              <div className="eico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg></div>
              <h2>{t('usage.empty.title')}</h2>
              <p>{t('usage.empty.body')}</p>
              <button type="button" className="bbtn" aria-expanded={howOpen} onClick={() => setHowOpen((v) => !v)}>{t('usage.empty.cta')}</button>
              {howOpen && <p style={{ marginTop: 16 }}>{p.method ?? t('usage.note.body')}</p>}
            </div>
            <InfoLines unreadable={p.unreadable} />
          </section>
        ) : (
          <>
            <div className="ugrid">
              <section className="card" style={{ position: 'relative' }} aria-labelledby="h-daily" aria-busy={loading}>
                <div className="chead">
                  <h2 id="h-daily">{narrow ? t('usage.daily.titleShort') : t('usage.daily.title')}</h2>
                  {loading
                    ? <span className="sub" role="status">{t('usage.loading')}</span>
                    : <span className="sub">{narrow ? t('usage.daily.subShort') : t('usage.daily.sub')}</span>}
                  {!narrow && !loading && (
                    <div className="right">
                      {missing > 0 && <span className="hscale"><MissingKey />{t('usage.missing.key', { n: missing })}</span>}
                      <button type="button" className="tlink" aria-expanded={asTable} onClick={() => setAsTable((v) => !v)}>{asTable ? t('usage.showChart') : t('usage.showTable')}</button>
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
                  <div className="chead"><h2 id="h-ta">{t('usage.agents.title')}</h2><span className="sub">{t('usage.agents.sub', { range: rangeTxt })}</span></div>
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
                  <div className="chead"><h2 id="h-tr">{t('usage.routines.title')}</h2><span className="sub">{t('usage.routines.sub')}</span></div>
                  {p.topRoutines.length === 0 && <p className="foot">{t('usage.routines.none', { range: rangeTxt })}</p>}
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
                    <p className="foot">Not in this list: <b>no routine (ad-hoc)</b>, {count(p.unmatched.runs)} runs across {p.unmatched.agents} agents, <Est value={p.unmatched.tokens} />. They have no routine to rank.</p>
                  )}
                </section>
              </div>
            </div>

            <section className="card" aria-labelledby="h-all">
              <div className="chead">
                <h2 id="h-all">{t('usage.all.title')} <span className="cnt">{tb.rows.length}</span></h2>
                <span className="sub">{t('usage.all.sub', { range: tableByRange ? 'yesterday' : tb.day ? tb.dayLabel ?? '' : rangeTxt })}</span>
              </div>
              {tb.day && !tableByRange && tb.dayMissing && (
              <p className="notice" role="status">{t('usage.all.noFile', { day: tb.dayLabel ?? '' })} <Link href={clearDay} preserveScroll>{clearDayText}</Link></p>
            )}
            {tb.day && !tableByRange && !tb.dayMissing && (
              <p className="dayf">{t('usage.all.dayOnly', { day: tb.dayLabel ?? '' })} <Link href={clearDay} preserveScroll>{clearDayText}</Link></p>
            )}
            {tb.rows.length > 0 && <UsageTable table={tb} range={p.range} narrow={narrow} />}
            </section>

          </>
        )}

        <CliUsage cli={p.cli} range={p.range} fromLabel={p.fromLabel} toLabel={p.toLabel} narrow={narrow} loading={loading} />
        <CursorUsage cli={p.cli} range={p.range} fromLabel={p.fromLabel} toLabel={p.toLabel} narrow={narrow} loading={loading} />
      </main>
      </div>
    </>
  );
}
