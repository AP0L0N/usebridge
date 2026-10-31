import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BookValueChart, ChartRangeSeg, chartFoot, visibleDays } from '../../Components/Polymarket/BookValueChart';
import { Delta, FillsCard, FreshBanner, MiniEmpty, SweepsCard, Tile, WifiIcon } from '../../Components/Polymarket/DeskBits';
import { PositionSortSeg, PositionsTable, type PositionSort } from '../../Components/Polymarket/PositionsTable';
import { TopBar } from '../../Components/TopBar';
import { plural, usd, type ChartRange, type DeskFeed, type DeskProps } from '../../lib/polymarket';
import { t } from '../../lib/strings-pm';
import { useNarrow } from '../../lib/usage';
import '../../../css/pm-desk.css';

/** Read-only view of the Polymarket desk. Nothing here trades, sweeps or messages. */
export default function Desk({ feed }: DeskProps) {
  return (
    <>
      <Head title="Polymarket" />
      <TopBar />
      <div className="pmx">
        <main className="pmx-main">
          {feed ? <DeskBody f={feed} /> : <NoFeed />}
          <p className="pmx-foot">{t('pm.foot')}</p>
        </main>
      </div>
    </>
  );
}

function Pill({ tone, children }: { tone?: 'ok' | 'risk' | 'warn'; children: string }) {
  return <span className={`pmx-pill ${tone ?? ''}`}><i aria-hidden="true" />{children}</span>;
}

function NoFeed() {
  return (
    <>
      <div className="pmx-head">
        <div>
          <h1 className="pmx-title">{t('pm.title')}</h1>
          <p className="pmx-sub">{t('pm.updated.none')}</p>
        </div>
        <div className="pmx-pills">
          <Pill tone="risk">{t('pm.pill.feedUnavailable')}</Pill>
          <Pill>{t('pm.pill.gateway.unknown')}</Pill>
          <Pill>{t('pm.pill.decision.unknown')}</Pill>
          <Pill>{t('pm.pill.stop.unknown')}</Pill>
        </div>
      </div>
      <section className="pmx-card pmx-empty" aria-labelledby="pmx-h-na">
        <div className="ico"><WifiIcon /></div>
        <h2 id="pmx-h-na">{t('pm.unavailable.title')}</h2>
        <p>{t('pm.unavailable.body')}</p>
        <button className="pmx-btn dark" type="button" onClick={() => router.reload()}>{t('pm.banner.retry')}</button>
      </section>
    </>
  );
}

function Pills({ f }: { f: DeskFeed }) {
  const g = f.gateway;
  const gwOk = g?.status === 'ok' && g.geoblock !== true;
  const stopLabel = f.stop.usd !== null ? t('pm.pill.stop', { value: usd(f.stop.usd) })
    : f.stop.sinceLabel ? t('pm.pill.stop.noneSince', { since: f.stop.sinceLabel }) : t('pm.pill.stop.none');
  const fr = f.freshness;
  return (
    <div className="pmx-pills">
      <Pill tone={fr.state === 'fresh' ? 'ok' : fr.state === 'late' ? 'warn' : 'risk'}>{fr.state === 'fresh' ? t('pm.pill.fresh') : fr.badge}</Pill>
      {g
        ? <Pill tone={gwOk ? undefined : 'risk'}>{t(g.geoblock ? 'pm.pill.gateway.geoblocked' : 'pm.pill.gateway', { status: g.status ?? '—' })}</Pill>
        : <Pill>{t('pm.pill.gateway.unknown')}</Pill>}
      {f.lastDecision ? <Pill>{t('pm.pill.decision', { value: f.lastDecision })}</Pill> : <Pill>{t('pm.pill.decision.unknown')}</Pill>}
      <Pill>{stopLabel}</Pill>
    </div>
  );
}

function DeskBody({ f }: { f: DeskFeed }) {
  const narrow = useNarrow();
  const [range, setRange] = useState<ChartRange>('7');
  const [sort, setSort] = useState<PositionSort>('value');
  const [allSweeps, setAllSweeps] = useState(false);
  const foot = chartFoot(f.chart, range);
  const { open, settled, openTotals, settledTotals } = f.positions;

  return (
    <>
      <div className="pmx-head">
        <div>
          <h1 className="pmx-title">{t('pm.title')}</h1>
          <p className="pmx-sub">{t('pm.updated', { time: f.asOfLabel.startsWith('Today, ') ? f.asOfTime : f.asOfLabel })}</p>
        </div>
        <Pills f={f} />
      </div>

      <FreshBanner f={f.freshness} />

      {f.note && (
        <>
          <p className="pmx-via"><i aria-hidden="true" />{t('pm.via', { when: f.note.tsLabel ?? f.asOfTime })}</p>
          <section className="pmx-card pmx-note" aria-labelledby="pmx-h-note">
            <h2 id="pmx-h-note">{t('pm.note.title')}</h2>
            <p>{f.note.text}</p>
          </section>
        </>
      )}

      <Tiles f={f} />

      <section className="pmx-card pmx-sec" aria-labelledby="pmx-h-chart">
        <div className="pmx-chead">
          <div><h2 id="pmx-h-chart">{t('pm.chart.title')}</h2><p className="s">{t('pm.chart.sub')}</p></div>
          <ChartRangeSeg range={range} onChange={setRange} />
        </div>
        <ChartBlock f={f} range={range} narrow={narrow} />
        {foot && <div className="pmx-cfoot"><span>{foot.left}</span><span>{foot.right}</span></div>}
      </section>

      <section className="pmx-card pmx-sec" aria-labelledby="pmx-h-pos">
        <div className="pmx-chead">
          <div>
            <h2 id="pmx-h-pos">{t('pm.pos.title')}<span className="n">{open.length}</span></h2>
            <p className="s">{t('pm.pos.sub')}</p>
          </div>
          <PositionSortSeg sort={sort} onChange={setSort} />
        </div>
        {open.length === 0 && <MiniEmpty title={t('pm.pos.empty.title')} body={t('pm.pos.empty.body')} />}
        <PositionsTable open={open} settled={settled} openTotals={openTotals} settledTotals={settledTotals} sort={sort} />
        <p className="pmx-tnote">
          {f.costBasis ? t('pm.pos.note.fees') : ''}
          {t('pm.pos.note')}{settled.length > 0 ? t('pm.pos.note.settled') : ''}
        </p>
      </section>

      <div className="pmx-two">
        <FillsCard fills={f.fills} />
        <SweepsCard sweeps={f.sweeps} showAll={allSweeps} onToggleAll={() => setAllSweeps((v) => !v)} />
      </div>
    </>
  );
}

function Tiles({ f }: { f: DeskFeed }) {
  const b = f.book, tp = f.totalPnl, r = f.realized, u = f.unrealized, hw = f.highWater, sw = f.swept;
  const deltas = [
    b.deltaYesterday !== null && <span key="y"><Delta v={b.deltaYesterday} /> {t('pm.tile.book.since')}</span>,
    b.delta7d !== null && <span key="w"><Delta v={b.delta7d} /> {t('pm.tile.book.vs7')}</span>,
  ].filter(Boolean);
  const dash = t('pm.tile.dash');
  const openToday = f.positions.open.filter((p) => p.ends?.kind === 'today').length;
  const openTomorrow = f.positions.open.filter((p) => p.ends?.kind === 'tomorrow').length;

  return (
    <div className="pmx-tiles">
      <Tile label={t('pm.tile.book')} value={usd(b.value)}
        hint={deltas.length ? deltas.flatMap((d, i) => (i ? [' · ', d] : [d])) : t('pm.tile.book.none')} />
      {tp.value === null
        ? <Tile label={t('pm.tile.total')} nullText={dash} hint={tp.funded === null ? t('pm.tile.total.needsFunded') : t('pm.tile.notInFeed')} />
        : <Tile label={t('pm.tile.total')} value={<Delta v={tp.value} pct={tp.pct} />}
            hint={tp.funded === null ? t('pm.tile.total.hint') : t('pm.tile.total.hintFull', {
              swept: tp.swept !== null ? ` + ${usd(tp.swept)} swept` : '', funded: usd(tp.funded), since: tp.fundedSinceLabel ? ` (${tp.fundedSinceLabel})` : '' })} />}
      {r.value === null
        ? <Tile label={t('pm.tile.realized')} nullText={t('pm.tile.realized.none')} hint={t('pm.tile.realized.noneHint')} />
        : <Tile label={t('pm.tile.realized')} value={<Delta v={r.value} />} hint={t('pm.tile.realized.hint', { since: r.sinceLabel ? ` since ${r.sinceLabel}` : '' })} />}
      {u.value === null
        ? <Tile label={t('pm.tile.unrealized')} nullText={dash} hint={t('pm.tile.unrealized.none')} />
        : <Tile label={t('pm.tile.unrealized')} value={<Delta v={u.value} />} hint={u.count === 0 ? t('pm.tile.unrealized.none') : t('pm.tile.unrealized.hint')} />}
      {f.cash === null
        ? <Tile label={t('pm.tile.cash')} nullText={dash} hint={t('pm.tile.notInFeed')} />
        : <Tile label={t('pm.tile.cash')} value={usd(f.cash)} hint={b.value > 0 ? t('pm.tile.cash.hint', { pct: Math.round((f.cash / b.value) * 100) }) : t('pm.tile.cash.hintPlain')} />}
      <Tile label={t('pm.tile.open')} value={String(f.openCount)}
        hint={f.openCount === 0 ? t('pm.tile.open.settledBelow')
          : openToday > 0 ? t('pm.tile.open.resolvesToday', { n: openToday })
          : openTomorrow > 0 ? t('pm.tile.open.resolvesTomorrow', { n: openTomorrow })
          : t('pm.tile.open.cost', { markets: plural(f.openCount, 'market'), cost: usd(f.openCost) })} />
      {hw.value === null ? (
        <Tile label={t('pm.tile.high')} nullText={dash} hint={t('pm.tile.notInFeed')} />
      ) : (
        <Tile label={t('pm.tile.high')} value={usd(hw.value)}
          hint={`${hw.below !== null && hw.below > 0.05 ? t('pm.tile.high.below', { amount: usd(hw.value - b.value) }) : t('pm.tile.high.at')}${hw.setLabel ? ` · ${t('pm.tile.high.set', { when: hw.setLabel })}` : ''}`}>
          <div className="pmx-hwbar" aria-hidden="true"><span style={{ width: `${Math.max(0, Math.min(100, (b.value / hw.value) * 100)).toFixed(1)}%` }} /></div>
        </Tile>
      )}
      {sw.value === null
        ? <Tile label={t('pm.tile.swept')} nullText={dash} hint={t('pm.tile.notInFeed')} />
        : <Tile label={t('pm.tile.swept')} value={usd(sw.value)}
            hint={sw.count ? t('pm.tile.swept.hint', { sweeps: plural(sw.count, 'sweep'), last: sw.lastLabel ? ` · ${sw.lastLabel}` : '' }) : t('pm.tile.swept.none')} />}
    </div>
  );
}

function LegendDot({ color, dashed, border }: { color: string; dashed?: boolean; border?: boolean }) {
  return <i aria-hidden="true" style={border ? { background: color, border: '1px solid var(--chart-grid)' } : { background: dashed ? 'transparent' : color, boxShadow: dashed ? `inset 0 0 0 2px ${color}` : undefined }} />;
}

function ChartBlock({ f, range, narrow }: { f: DeskFeed; range: ChartRange; narrow: boolean }) {
  const c = f.chart;
  const days = new Set(visibleDays(c, range).map((d) => d.date));
  const runs = c.points.filter((p) => days.has(p.day));
  const enough = runs.length + (c.live.separate && days.has(c.live.day) ? 1 : 0) >= 2;
  if (!enough) {
    return <MiniEmpty title={t('pm.chart.empty.title')} body={t('pm.chart.empty.body') + (range !== 'all' ? t('pm.chart.empty.all') : '')} />;
  }
  const stopVisible = runs.some((p) => p.stop !== null);
  const sweepsVisible = c.sweeps.some((s) => days.has(s.day));
  const hwValue = c.highWater ?? Math.max(c.live.book, ...c.points.map((p) => p.book));
  return (
    <>
      <ul className="pmx-legend">
        <li><LegendDot color="var(--ink-950)" />{narrow ? t('pm.chart.legend.bookShort') : t('pm.chart.legend.book')}</li>
        <li><LegendDot color="var(--chart-5)" />{narrow ? t('pm.chart.legend.hwShort', { value: usd(hwValue) }) : t('pm.chart.legend.hw')}</li>
        {c.funded !== null && <li><LegendDot color="var(--ink-600)" />{narrow ? t('pm.chart.legend.fundedShort', { value: usd(c.funded) }) : t('pm.chart.legend.funded')}</li>}
        {stopVisible && c.stop && <li><LegendDot color="var(--risk)" />{c.stop.removed ? t('pm.chart.legend.oldStop', { span: stopSpan(c.stop.fromLabel, c.stop.toLabel) }) : t('pm.chart.legend.stop')}</li>}
        {sweepsVisible && <li><LegendDot color="var(--chart-3)" />{t('pm.chart.legend.sweep')}</li>}
        <li><LegendDot color="var(--paper)" border />{t('pm.chart.legend.weekend')}</li>
      </ul>
      <BookValueChart chart={c} range={range} narrow={narrow} />
    </>
  );
}

/** "3–6 Oct" · "28 Sep–6 Oct" · "6 Oct" */
function stopSpan(from: string, to: string): string {
  if (from === to) return from;
  const [fd, fm] = from.split(' '), [, tm] = to.split(' ');
  return fm === tm ? `${fd}–${to}` : `${from}–${to}`;
}
