import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { BookValueChart, ChartRangeSeg, LegendLine, chartFoot, visibleDays } from '../../Components/Polymarket/BookValueChart';
import { FillsCard, FreshBadge, FreshBanner, MiniEmpty, SweepsCard, Tile, WarnIcon } from '../../Components/Polymarket/DeskBits';
import { PositionSortSeg, PositionsTable, sortWords, type PositionSort } from '../../Components/Polymarket/PositionsTable';
import { TopBar } from '../../Components/TopBar';
import { plural, pnlClass, signedPct, signedUsd, usd, type ChartRange, type DeskFeed, type DeskProps } from '../../lib/polymarket';
import { useNarrow } from '../../lib/usage';

/** Read-only view of Frank Yang's Polymarket desk (DATA-SPEC r1). Nothing here trades, sweeps or messages. */
export default function Desk({ feed }: DeskProps) {
  return (
    <>
      <Head title="Polymarket" />
      <TopBar />
      <main className="wrap pmdesk">
        {feed ? <DeskBody f={feed} /> : <NoFeed />}
      </main>
    </>
  );
}

function NoFeed() {
  return (
    <>
      <div className="dhead"><div><h1>Polymarket desk</h1></div></div>
      <div className="empty">
        <div className="eico big"><WarnIcon /></div>
        <h2>The desk feed isn’t available</h2>
        <p>Bridge couldn’t read Frank’s desk feed (missing or invalid). Nothing here is guessed, so the page stays empty until the next good run.</p>
        <button className="btn" type="button" onClick={() => router.reload()}>Try again</button>
      </div>
    </>
  );
}

function DeskBody({ f }: { f: DeskFeed }) {
  const narrow = useNarrow();
  const [range, setRange] = useState<ChartRange>(() => (narrow ? '7' : f.chart.defaultRange));
  const [sort, setSort] = useState<PositionSort>('value');
  const [allSweeps, setAllSweeps] = useState(false);
  const since = f.chart.days[0] ? `${f.chart.days[0].d} ${f.chart.days[0].mon}` : '';
  const foot = chartFoot(f.chart, range);
  const { open, settled, openTotals, settledTotals } = f.positions;

  return (
    <>
      <div className="dhead">
        <div>
          <h1>Polymarket desk</h1>
          <p className="summary">
            <span>Updated <b>{f.asOfLabel}</b> · posted weekdays ~19:00</span>
            <FreshBadge f={f.freshness} />
          </p>
        </div>
        <Pills f={f} />
      </div>

      <FreshBanner f={f.freshness} />

      {f.note && (
        <section className="note" aria-label="Frank’s note">
          <span className="av" aria-hidden="true">F</span>
          <div>
            <p className="who2"><b>Frank Yang</b> · desk note</p>
            <blockquote>“{f.note.text}”</blockquote>
          </div>
          {f.note.tsLabel && <p className="nmeta">{f.note.tsLabel} run</p>}
        </section>
      )}

      <Tiles f={f} />

      <section className="card ccard" aria-labelledby="h-chart">
        <div className="chead">
          <h2 id="h-chart">Book value</h2>
          <p className="sub">Every desk run · Europe/Ljubljana · nights collapsed</p>
          <div className="right"><ChartRangeSeg range={range} since={since} onChange={setRange} /></div>
        </div>
        <ChartBlock f={f} range={range} narrow={narrow} />
        {foot && <div className="cfoot"><span>{foot.left}</span><span>{foot.right}</span></div>}
      </section>

      <section className="card" aria-labelledby="h-pos" style={{ marginBottom: 0 }}>
        <div className="chead">
          <h2 id="h-pos">Open positions</h2>
          <p className="sub">{plural(open.length, 'market')}{open.length > 0 ? ` · sorted by ${sortWords(sort)} · live prices from Polymarket` : ''}</p>
          {open.length > 1 && <div className="right"><PositionSortSeg sort={sort} onChange={setSort} /></div>}
        </div>
        {open.length === 0 && <MiniEmpty title="No open positions" body="All money is in cash. Settled markets stay listed below the table." />}
        {open.length + settled.length > 0 && <PositionsTable open={open} settled={settled} openTotals={openTotals} settledTotals={settledTotals} sort={sort} />}
        <p className="tnote">
          {f.costBasis ? '* Cost and P&L include Polymarket entry fees; avg entry is the price paid per share, without fees. ' : ''}
          Prices in cents (¢) = implied probability.{settled.length > 0 ? ' Settled losses are already counted in realized P&L.' : ''}
        </p>
      </section>

      <div className="bgrid">
        <FillsCard fills={f.fills} />
        <SweepsCard sweeps={f.sweeps} showAll={allSweeps} onToggleAll={() => setAllSweeps((v) => !v)} />
      </div>

      <p className="srcline">
        <span>Source: Frank Yang’s desk feed (feed.json) as of {f.asOfLabel.startsWith('Today, ') ? f.asOfTime : f.asOfLabel}</span>
        <span>All times Europe/Ljubljana</span>
        <span>Read-only view · Bridge never trades</span>
      </p>
    </>
  );
}

function Pills({ f }: { f: DeskFeed }) {
  const g = f.gateway;
  const gwOk = g?.status === 'ok' && g.geoblock !== true;
  return (
    <div className="hpills">
      {g && (
        <span className="pill">
          <i style={gwOk ? undefined : { background: 'var(--w1)' }} aria-hidden="true" />
          Gateway <b>{g.status ?? '—'}</b>{g.country ? ` · ${g.country}` : ''}{g.geoblock ? ' · geoblocked' : ''}
        </span>
      )}
      {f.lastDecision && <span className="pill">Last decision <b>{f.lastDecision}</b></span>}
      {f.stop.usd !== null
        ? <span className="pill">Stop-loss <b>{usd(f.stop.usd)}</b></span>
        : <span className="pill">Stop-loss <b>none</b>{f.stop.sinceLabel ? ` since ${f.stop.sinceLabel}` : ''}</span>}
    </div>
  );
}

function Tiles({ f }: { f: DeskFeed }) {
  const b = f.book, t = f.totalPnl, r = f.realized, u = f.unrealized, hw = f.highWater, sw = f.swept;
  const deltas = [
    b.deltaYesterday !== null && <span key="y"><span className={`d ${pnlClass(b.deltaYesterday)}`}>{signedUsd(b.deltaYesterday)}</span> since yesterday</span>,
    b.delta7d !== null && <span key="w"><span className={`d ${pnlClass(b.delta7d)}`}>{signedUsd(b.delta7d)}</span> vs 7 days ago</span>,
  ].filter(Boolean);
  // Big negative tile values stay ink; only positive ones are green (spec §5).
  const tone = (v: number) => (v > 0.005 ? 'pos' : '');

  return (
    <>
      <div className="stats pm">
        <Tile cls="key" label="Book value" value={usd(b.value)}
          hint={deltas.length ? deltas.flatMap((d, i) => (i ? [' · ', d] : [d])) : 'No earlier run to compare with'} />
        {t.value === null ? (
          <Tile label="Total P&L" nullText="—" hint={t.funded === null ? 'Needs funded amount' : 'Not in the feed'} />
        ) : (
          <div className="stat">
            <p className="lbl">Total P&amp;L</p>
            <p className={`val ${tone(t.value)}`}>{signedUsd(t.value)}{t.pct !== null && <span className={`chip ${pnlClass(t.pct)}`}>{signedPct(t.pct)}</span>}</p>
            <p className="hint">
              {t.funded === null ? 'Book + swept − funded' : `Book${t.swept !== null ? ` + ${usd(t.swept)} swept` : ''} − ${usd(t.funded)} funded${t.fundedSinceLabel ? ` (${t.fundedSinceLabel})` : ''}`}
            </p>
          </div>
        )}
        {r.value === null
          ? <Tile label="Realized P&L" nullText="Not supplied" hint="Frank derives it from Polymarket activity; it shows here once the feed has it." />
          : <Tile label="Realized P&L" cls="" value={<span className={tone(r.value)}>{signedUsd(r.value)}</span>}
              hint={`Closed desk markets${r.sinceLabel ? ` since ${r.sinceLabel}` : ''}, from Polymarket activity`} />}
        {u.value === null
          ? <Tile label="Unrealized P&L" nullText="—" hint="Needs open positions with P&L" />
          : <Tile label="Unrealized P&L" value={<span className={tone(u.value)}>{signedUsd(u.value)}</span>} hint={`${plural(u.count, 'open position')} at current prices`} />}
      </div>
      <div className="stats pm second">
        {f.cash === null
          ? <Tile label="Cash" nullText="—" hint="Not in the feed" />
          : <Tile label="Cash" value={usd(f.cash)} hint="pUSD on Polymarket, ready to bet" />}
        {f.openValue === null
          ? <Tile label="Open positions" nullText="—" hint={`${plural(f.openCount, 'market')} · value not in the feed`} />
          : <Tile label="Open positions" value={usd(f.openValue)} hint={`${plural(f.openCount, 'market')} · cost ${usd(f.openCost)}`} />}
        {hw.value === null ? (
          <Tile label="High-water" nullText="—" hint="Not in the feed" />
        ) : (
          <Tile label="High-water" value={usd(hw.value)}
            hint={`${hw.below !== null && hw.below > 0.05 ? `Book is ${hw.below.toFixed(1)}% below` : 'Book is at high-water'}${hw.setLabel ? ` · set ${hw.setLabel}` : ''}`}>
            <div className="hwbar" aria-hidden="true"><span style={{ width: `${Math.max(0, Math.min(100, (b.value / hw.value) * 100)).toFixed(1)}%` }} /></div>
          </Tile>
        )}
        {sw.value === null
          ? <Tile label="Swept to Bitstamp" nullText="—" hint="Not in the feed" />
          : <Tile label="Swept to Bitstamp" value={usd(sw.value)} hint={sw.count ? `${plural(sw.count, 'sweep')}${sw.lastLabel ? ` · last ${sw.lastLabel}` : ''}` : 'No sweeps yet'} />}
      </div>
    </>
  );
}

function ChartBlock({ f, range, narrow }: { f: DeskFeed; range: ChartRange; narrow: boolean }) {
  const c = f.chart;
  const days = new Set(visibleDays(c, range).map((d) => d.date));
  const runs = c.points.filter((p) => days.has(p.day));
  const enough = runs.length + (c.live.separate && days.has(c.live.day) ? 1 : 0) >= 2;
  if (!enough) {
    return <MiniEmpty title="Not enough runs for a line" body={`The chart needs at least 2 desk runs in the range.${range !== 'all' ? ' Try “All”.' : ''}`} />;
  }
  const stopVisible = runs.some((p) => p.stop !== null);
  const sweepsVisible = c.sweeps.some((s) => days.has(s.day));
  const hwValue = c.highWater ?? Math.max(c.live.book, ...c.points.map((p) => p.book));
  return (
    <>
      <ul className="clegend">
        <li><LegendLine color="#1F3A5F" width={2.5} />{narrow ? 'Book value' : 'Book value (cash + open positions at market)'}</li>
        <li><LegendLine color="#6B6F78" width={1.5} dash="6 5" />{narrow ? `High-water ${usd(hwValue)}` : 'High-water (running max)'}</li>
        {c.funded !== null && <li><LegendLine color="#8A8F99" width={1.5} dash="2 4" />{narrow ? `Funded ${usd(c.funded)}` : 'Funded'}</li>}
        {!narrow && stopVisible && c.stop && (
          <li><LegendLine color="#F28C28" width={2} dash="7 5" />{c.stop.removed ? `Old stop-loss (${stopSpan(c.stop.fromLabel, c.stop.toLabel)})` : 'Stop-loss'}</li>
        )}
        {!narrow && sweepsVisible && <li><svg width="14" height="12" aria-hidden="true"><path d="M1,1 L13,1 L7,11 Z" fill="#1F3A5F" /></svg>Sweep to Bitstamp</li>}
        {!narrow && <li><svg width="16" height="12" aria-hidden="true"><rect width="16" height="12" rx="2" fill="#F7F5F0" stroke="#E2DED4" /></svg>Weekend</li>}
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
