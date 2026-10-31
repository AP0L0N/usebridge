import { useState } from 'react';
import { cents, shares, usd, type Position, type Totals } from '../../lib/polymarket';
import { t } from '../../lib/strings-pm';
import { Delta } from './DeskBits';

export type PositionSort = 'value' | 'pnl' | 'ends';

const SORTS: { sort: PositionSort; label: Parameters<typeof t>[0] }[] = [
  { sort: 'value', label: 'pm.pos.sortValue' },
  { sort: 'pnl', label: 'pm.pos.sortPnl' },
  { sort: 'ends', label: 'pm.pos.sortEnds' },
];

const ExtIcon = () => (
  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M9 3h4v4M13 3 7.5 8.5M12 9.5V13H3V4h3.5" /></svg>
);
const Chevron = ({ open }: { open: boolean }) => (
  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" style={open ? { transform: 'rotate(90deg)' } : undefined}><path d="m6 4 4 4-4 4" /></svg>
);

export function PositionSortSeg({ sort, onChange }: { sort: PositionSort; onChange: (s: PositionSort) => void }) {
  return (
    <div className="pmx-seg" role="group" aria-label={t('pm.pos.sort')}>
      {SORTS.map((s) => <button key={s.sort} type="button" aria-pressed={s.sort === sort} onClick={() => onChange(s.sort)}>{t(s.label)}</button>)}
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

function Ends({ p }: { p: Position }) {
  const e = p.ends;
  const label = e?.kind === 'settled' ? t('pm.pos.ends.settled')
    : e?.kind === 'awaiting' ? t('pm.pos.ends.awaiting')
    : e?.kind === 'today' ? t('pm.pos.ends.today')
    : e?.kind === 'tomorrow' ? t('pm.pos.ends.tomorrow')
    : p.endLabel ? t('pm.pos.ends.date', { date: p.endLabel }) : '—';
  return <div className="pmx-ends"><span>{label}</span></div>;
}

const Pnl = ({ pnl, pct }: { pnl: number | null; pct: number | null }) =>
  pnl === null ? <>—</> : <Delta v={pnl} pct={pct} />;

function Row({ p }: { p: Position }) {
  return (
    <tr className={p.settled ? 'setl' : undefined}>
      <td className="l mk">
        {p.url
          ? <a href={p.url} target="_blank" rel="noopener noreferrer">{p.market}<ExtIcon /><span className="sr-only"> {t('pm.pos.opens')}</span></a>
          : p.market}
      </td>
      <td className="l">{p.outcome ? <span className={`pmx-side ${p.outcome.toLowerCase() === 'no' ? 'no' : ''}`}>{p.outcome}</span> : '—'}</td>
      <td>{p.shares === null ? '—' : shares(p.shares)}</td>
      <td>{p.avgPrice === null ? '—' : cents(p.avgPrice)}</td>
      <td>{p.curPrice === null ? '—' : cents(p.curPrice)}</td>
      <td>{p.cost === null ? '—' : usd(p.cost)}</td>
      <td>{p.value === null ? '—' : usd(p.value)}</td>
      <td><Pnl pnl={p.pnl} pct={p.pnlPct} /></td>
      <td><Ends p={p} /></td>
    </tr>
  );
}

/** Open positions (sorted by the seg), a Total row, and the settled group collapsed under it. */
export function PositionsTable({ open, settled, openTotals, settledTotals, sort }: { open: Position[]; settled: Position[]; openTotals: Totals; settledTotals: Totals; sort: PositionSort }) {
  const [showSettled, setShowSettled] = useState(false);
  const allZero = settled.every((p) => (p.value ?? 0) === 0);
  return (
    <>
      {open.length > 0 && (
        <div className="pmx-scroll" tabIndex={0} role="region" aria-label={t('pm.pos.title')}>
          <table className="pmx-tbl">
            <thead>
              <tr>
                <th scope="col" className="l">{t('pm.pos.col.market')}</th>
                <th scope="col" className="l">{t('pm.pos.col.side')}</th>
                <th scope="col">{t('pm.pos.col.shares')}</th>
                <th scope="col">{t('pm.pos.col.avg')}</th>
                <th scope="col">{t('pm.pos.col.now')}</th>
                <th scope="col">{t('pm.pos.col.cost')}</th>
                <th scope="col">{t('pm.pos.col.value')}</th>
                <th scope="col">{t('pm.pos.col.pnl')}</th>
                <th scope="col">{t('pm.pos.col.ends')}</th>
              </tr>
            </thead>
            <tbody>
              {sorted(open, sort).map((p, i) => <Row key={`o${i}-${p.market}`} p={p} />)}
              <tr className="tot">
                <td className="l" colSpan={5}>{t('pm.pos.total')}</td>
                <td>{openTotals.cost === null ? '—' : usd(openTotals.cost)}</td>
                <td>{openTotals.value === null ? '—' : usd(openTotals.value)}</td>
                <td><Pnl pnl={openTotals.pnl} pct={null} /></td>
                <td />
              </tr>
            </tbody>
          </table>
        </div>
      )}
      {settled.length > 0 && (
        <>
          <div className="pmx-settled">
            <button className="t" type="button" aria-expanded={showSettled} onClick={() => setShowSettled((v) => !v)}>
              <Chevron open={showSettled} />
              <span>
                {t('pm.pos.settled', { markets: `${settled.length} market${settled.length === 1 ? '' : 's'}`, resolved: allZero ? t('pm.pos.settled.zero') : t('pm.pos.settled.some') })}
                {!allZero && settledTotals.pnl !== null && <> · <span className="v">{usd(settledTotals.value ?? 0)}</span></>}
              </span>
              <span className="sr-only">{showSettled ? t('pm.pos.settled.hide') : t('pm.pos.settled.show')}{t('pm.pos.settled.aria')}</span>
            </button>
          </div>
          {showSettled && (
            <div className="pmx-scroll" tabIndex={0} role="region" aria-label={t('pm.pos.settled.aria')}>
              <table className="pmx-tbl"><tbody>{settled.map((p, i) => <Row key={`s${i}-${p.market}`} p={p} />)}</tbody></table>
            </div>
          )}
        </>
      )}
    </>
  );
}
