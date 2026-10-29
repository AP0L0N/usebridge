import { useState, type KeyboardEvent } from 'react';
import { estText, type HeatRow } from '../../lib/usage';
import { Est } from './Est';

const hh = (h: number) => `${String(h).padStart(2, '0')}:00`;

/** 5 bins of value / busiest hour: ≤5%, 5–15, 15–35, 35–60, >60%. 0 = empty outlined square. */
export function bin(v: number, max: number): number {
  if (v <= 0 || max <= 0) return 0;
  const r = v / max;
  return r <= 0.05 ? 1 : r <= 0.15 ? 2 : r <= 0.35 ? 3 : r <= 0.6 ? 4 : 5;
}

type CellState = { kind: 'miss' } | { kind: 'nr' } | { kind: 'v'; v: number };
const cellState = (r: HeatRow, h: number): CellState =>
  !r.present || !r.hours ? { kind: 'miss' }
    : r.cutoffHour !== null && h > r.cutoffHour ? { kind: 'nr' }
      : { kind: 'v', v: r.hours[h] ?? 0 };

/**
 * 7×24 grid, one tab stop: arrow keys / Home / End move, the "Selected" readout is aria-live.
 * Hours after today's generated_at are hatched ("not reached yet"); a day without a file is hatched too.
 */
export function HourHeatmap({ rows, updatedAt }: { rows: HeatRow[]; updatedAt: string | null }) {
  const max = Math.max(0, ...rows.flatMap((r) => r.hours ?? []));
  const start = (() => {
    let best = { r: 0, h: 0, v: -1 };
    rows.forEach((row, r) => row.hours?.forEach((v, h) => { if (v > best.v) best = { r, h, v }; }));
    return { r: best.r, h: best.h };
  })();
  const [sel, setSel] = useState(start);
  const [touched, setTouched] = useState(false);

  const describe = (r: HeatRow, h: number) => {
    const s = cellState(r, h);
    return s.kind === 'miss' ? 'no file' : s.kind === 'nr' ? 'not reached yet' : estText(s.v);
  };

  const onKey = (e: KeyboardEvent<HTMLDivElement>) => {
    const m: Record<string, [number, number]> = { ArrowRight: [0, 1], ArrowLeft: [0, -1], ArrowDown: [1, 0], ArrowUp: [-1, 0] };
    let { r, h } = sel;
    if (m[e.key]) { r += m[e.key]![0]; h += m[e.key]![1]; }
    else if (e.key === 'Home') h = 0;
    else if (e.key === 'End') h = 23;
    else return;
    e.preventDefault();
    r = Math.max(0, Math.min(rows.length - 1, r));
    h = Math.max(0, Math.min(23, h));
    setSel({ r, h });
    setTouched(true);
    (e.currentTarget.querySelector(`[data-cell="${r}-${h}"]`) as HTMLElement | null)?.focus();
  };

  const selRow = rows[sel.r];
  const selState = selRow ? cellState(selRow, sel.h) : null;

  return (
    <>
      <div className="hm" role="grid" aria-label="Estimated tokens per hour, one row per day. Arrow keys move; Show as table lists every value." onKeyDown={onKey}>
        <div role="row">
          <span role="columnheader" aria-hidden="true" />
          {Array.from({ length: 24 }, (_, h) => <span key={h} role="columnheader" className="hl" aria-label={hh(h)}>{h % 3 === 0 ? h : ''}</span>)}
        </div>
        {rows.map((row, r) => (
          <div role="row" key={row.date}>
            <span role="rowheader" className="rl">{row.today ? <b>{row.label} ·</b> : row.label}</span>
            {Array.from({ length: 24 }, (_, h) => {
              const s = cellState(row, h);
              const cls = s.kind === 'miss' ? 'miss' : s.kind === 'nr' ? 'nr' : bin(s.v, max) ? `l${bin(s.v, max)}` : '';
              const isSel = sel.r === r && sel.h === h;
              const label = `${row.longLabel} ${hh(h)} · ${describe(row, h)}`;
              return (
                <span
                  key={h}
                  role="gridcell"
                  data-cell={`${r}-${h}`}
                  className={`c ${cls}${isSel && touched ? ' sel' : ''}`}
                  tabIndex={isSel ? 0 : -1}
                  title={label}
                  aria-label={label}
                  onMouseEnter={() => { setSel({ r, h }); setTouched(true); }}
                  onFocus={() => { setSel({ r, h }); setTouched(true); }}
                />
              );
            })}
          </div>
        ))}
      </div>
      <div className="hscale">
        <span>0</span>
        <i style={{ background: 'var(--h0)', boxShadow: 'inset 0 0 0 1px var(--line)' }} aria-hidden="true" />
        <i style={{ background: 'var(--h1)' }} aria-hidden="true" /><span>≤5%</span>
        <i style={{ background: 'var(--h2)' }} aria-hidden="true" /><span>5–15%</span>
        <i style={{ background: 'var(--h3)' }} aria-hidden="true" /><span>15–35%</span>
        <i style={{ background: 'var(--h4)' }} aria-hidden="true" /><span>35–60%</span>
        <i style={{ background: 'var(--h5)' }} aria-hidden="true" /><span>&gt;60%</span>
        {rows.some((r) => r.cutoffHour !== null) && (
          <><i className="miss" aria-hidden="true" /><span>not reached yet{updatedAt ? ` (after ${updatedAt})` : ''}</span></>
        )}
        <span className="readout" aria-live="polite">
          {selRow && <><b>Selected:</b> {selRow.longLabel} {hh(sel.h)} · {selState?.kind === 'v' ? <Est value={selState.v} /> : describe(selRow, sel.h)}</>}
        </span>
      </div>
    </>
  );
}

/** "Show as table": Day × 00–23. */
export function HourTable({ rows, caption }: { rows: HeatRow[]; caption: string }) {
  return (
    <div className="ttbl">
      <table className="tbl">
        <caption>{caption}</caption>
        <thead><tr><th scope="col">Day</th>{Array.from({ length: 24 }, (_, h) => <th key={h} scope="col">{String(h).padStart(2, '0')}</th>)}</tr></thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.date}>
              <th scope="row" className={r.today ? 'b' : ''}>{r.longLabel}{r.today ? ' · so far' : ''}</th>
              {!r.present || !r.hours
                ? <td className="num-miss" colSpan={24}>No file. Not counted in any total.</td>
                : r.hours.map((v, h) => <td key={h}>{r.cutoffHour !== null && h > r.cutoffHour ? <span className="dim2">not yet</span> : <Est value={v} />}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
