import { count, pct, type RoutineRow } from '../../lib/usage';
import { Est } from './Est';

/** Routines + No routine (ad-hoc) + Other (chats, tasks and subagents). Narrow keeps Routine / Runs / Tokens. */
export function RoutineTable({ rows, totals, caption }: { rows: RoutineRow[]; totals: { wakes: number; tokens: number }; caption: string }) {
  return (
    <div className="tscroll">
      <table className="tbl">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr>
            <th scope="col">Routine</th>
            <th scope="col">Runs</th>
            <th scope="col">Tokens (est.)</th>
            <th scope="col" className="nw">Share of agent</th>
            <th scope="col" className="nw">Per run (est.)</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={`${r.kind}-${r.routineId}`}>
              <td className="l">
                <b>{r.name}</b>
                <span className={`rtag${r.kind === 'unmatched' ? ' um' : ''}`}>{r.kind === 'unmatched' ? 'ad-hoc' : r.routineId}</span>
              </td>
              <td>{r.runs === null ? <span aria-label="no runs">—</span> : count(r.runs)}</td>
              <td><Est value={r.tokens} /></td>
              <td className="nw"><span className="share"><b style={{ width: `${Math.max(r.share > 0 ? 2 : 0, Math.round(r.share * 160))}px` }} aria-hidden="true" />{pct(r.share)}</span></td>
              <td className="nw">{r.perRun === null ? <span className="dim2">—</span> : <Est value={r.perRun} />}</td>
            </tr>
          ))}
          <tr className="tot">
            <td className="l">Total</td>
            <td>{count(totals.wakes)} wakes</td>
            <td><Est value={totals.tokens} /></td>
            <td className="nw">{totals.tokens > 0 ? '100%' : '—'}</td>
            <td className="nw" />
          </tr>
        </tbody>
      </table>
    </div>
  );
}
