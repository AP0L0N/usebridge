import { useState } from 'react';
import { cents, pnlClass, shares, signedPct, signedUsd, usd, type Position, type Totals } from '../../lib/polymarket';

export type PositionSort = 'value' | 'pnl' | 'ends';

const SORTS: { sort: PositionSort; label: string; words: string }[] = [
  { sort: 'value', label: 'Value', words: 'value' },
  { sort: 'pnl', label: 'P&L', words: 'P&L' },
  { sort: 'ends', label: 'Ends', words: 'end date' },
];

export const sortWords = (sort: PositionSort) => SORTS.find((s) => s.sort === sort)!.words;

const ExtIcon = () => (
  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M9 3h4v4M13 3 7.5 8.5M12 9.5V13H3V4h3.5" /></svg>
);
const Chevron = ({ open }: { open: boolean }) => (
  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" style={open ? { transform: 'rotate(90deg)' } : undefined}><path d="m6 4 4 4-4 4" /></svg>
);

export function PositionSortSeg({ sort, onChange }: { sort: PositionSort; onChange: (s: PositionSort) => void }) {
  return (
    <div className="seg" role="group" aria-label="Sort">
      {SORTS.map((s) => <button key={s.sort} type="button" aria-pressed={s.sort === sort} onClick={() => onChange(s.sort)}>{s.label}</button>)}
    </div>
  );
}

/** Value desc · P&L desc · Ends soonest first (no end date last). Ties keep the feed order. */
function sorted(rows: Position[], sort: PositionSort): Position[] {
  const key = (p: Position): number => {
    if (sort === 'value') return -(p.value ?? -Infinity);
    if (sort === 'pnl') return -(p.pnl ?? -Infinity);
    return p.endDate ? Date.parse(p.endDate) : Infinity;
  };
  return [...rows].sort((a, b) => key(a) - key(b));
}

const Side = ({ outcome }: { outcome: string | null }) =>
  outcome ? <span className={`sidep ${outcome.toLowerCase() === 'no' ? 'no' : 'yes'}`}>{outcome}</span> : <>—</>;

function Ends({ p }: { p: Position }) {
  const e = p.ends;
  let extra = null;
  if (e?.kind === 'settled') extra = <span className="kb med">Settled</span>;
  else if (e?.kind === 'awaiting') extra = <span className="kb warn">Awaiting result</span>;
  else if (e?.kind === 'today') extra = <span className="kb navy">Resolves today</span>;
  else if (e?.kind === 'tomorrow') extra = <span className="rel">tomorrow</span>;
  else if (e?.kind === 'days') extra = <span className="rel">in {e.days} days</span>;
  return <div className="ends"><span>{p.endLabel ?? '—'}</span>{extra}</div>;
}

function Row({ p }: { p: Position }) {
  return (
    <tr className={p.settled ? 'setl' : undefined}>
      <td className="l mkc">
        <div className="mk">
          {p.url ? <a href={p.url} target="_blank" rel="noopener noreferrer">{p.market}<span className="sr-only"> (opens Polymarket in a new tab)</span></a> : <span>{p.market}</span>}
          {p.url && <ExtIcon />}
        </div>
      </td>
      <td className="l sidec"><Side outcome={p.outcome} /></td>
      <td className="hide-m">{p.shares === null ? '—' : shares(p.shares)}</td>
      <td className="hide-m">{p.avgPrice === null ? '—' : cents(p.avgPrice)}</td>
      <td className="hide-m">{p.curPrice === null ? '—' : cents(p.curPrice)}</td>
      <td className="hide-m">{p.cost === null ? '—' : usd(p.cost)}</td>
      <td className="valc"><b>{p.value === null ? '—' : usd(p.value)}</b></td>
      <td className={`pnlc ${pnlClass(p.pnl)}`}>
        {p.pnl === null ? '—' : <b>{signedUsd(p.pnl)}</b>}
        {p.pnlPct !== null && <span className="pct">{signedPct(p.pnlPct)}</span>}
      </td>
      <td className="endc"><Ends p={p} /></td>
    </tr>
  );
}

/**
 * Open positions (sorted by the seg), totals row, then the settled group: one collapsed row
 * ("resolved at $0 · redeemable, nothing to collect"), Show/Hide expands the dimmed rows.
 */
export function PositionsTable({ open, settled, openTotals, settledTotals, sort }: { open: Position[]; settled: Position[]; openTotals: Totals; settledTotals: Totals; sort: PositionSort }) {
  const [showSettled, setShowSettled] = useState(false);
  const allZero = settled.every((p) => (p.value ?? 0) === 0);
  return (
    <table className="tbl ptbl" style={{ marginTop: 10 }}>
      <caption className="sr-only">Open positions, sorted by {sortWords(sort)}, with settled markets below</caption>
      <thead>
        <tr>
          <th scope="col">Market</th>
          <th scope="col" className="l" style={{ textAlign: 'left' }}>Side</th>
          <th scope="col" className="hide-m">Shares</th>
          <th scope="col" className="hide-m">Avg entry</th>
          <th scope="col" className="hide-m">Now</th>
          <th scope="col" className="hide-m">Cost*</th>
          <th scope="col">Value</th>
          <th scope="col">P&amp;L*</th>
          <th scope="col">Ends</th>
        </tr>
      </thead>
      <tbody>
        {sorted(open, sort).map((p, i) => <Row key={`o${i}-${p.market}`} p={p} />)}
        {open.length > 0 && (
          <tr className="tot">
            <td className="l mkc" colSpan={2}>Open total · {openTotals.count} market{openTotals.count === 1 ? '' : 's'}</td>
            <td className="hide-m" /><td className="hide-m" /><td className="hide-m" />
            <td className="hide-m">{openTotals.cost === null ? '—' : usd(openTotals.cost)}</td>
            <td className="valc">{openTotals.value === null ? '—' : usd(openTotals.value)}</td>
            <td className={`pnlc ${pnlClass(openTotals.pnl)}`}>
              {openTotals.pnl === null ? '—' : signedUsd(openTotals.pnl)}
              {openTotals.pnlPct !== null && <span className="pct">{signedPct(openTotals.pnlPct)}</span>}
            </td>
            <td className="hide-m" />
          </tr>
        )}
        {settled.length > 0 && (
          <tr className="grp">
            <td className="l mkc" colSpan={5}>
              <span className="gt">
                <Chevron open={showSettled} />
                Settled · {settled.length} market{settled.length === 1 ? '' : 's'} {allZero ? 'resolved at $0 · redeemable, nothing to collect' : 'resolved'}
              </span>
            </td>
            <td className="hide-m">{settledTotals.cost === null ? '—' : usd(settledTotals.cost)}</td>
            <td className="valc">{settledTotals.value === null ? '—' : usd(settledTotals.value)}</td>
            <td className="pnlc">{settledTotals.pnl === null ? '—' : signedUsd(settledTotals.pnl)}</td>
            <td className="endc" style={{ textAlign: 'right' }}>
              <button className="tlink" type="button" aria-expanded={showSettled} onClick={() => setShowSettled((v) => !v)}>
                {showSettled ? 'Hide' : 'Show'}<span className="sr-only"> settled markets</span>
              </button>
            </td>
          </tr>
        )}
        {showSettled && settled.map((p, i) => <Row key={`s${i}-${p.market}`} p={p} />)}
      </tbody>
    </table>
  );
}
