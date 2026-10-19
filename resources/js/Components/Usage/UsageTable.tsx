import { Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { colorVar, count, pct, withQuery, type UsageTableData } from '../../lib/usage';
import { Est } from './Est';
import { FactorBadge } from './FactorBadge';
import { Sparkline } from './Sparkline';

type Props = { table: UsageTableData; range: number; narrow: boolean };

/** All agents table. Name links to the detail page (no whole-row click). Narrow: Agent + Tokens, rest in an expandable row. */
export function UsageTable({ table, range, narrow }: Props) {
  const [open, setOpen] = useState<Record<string, boolean>>({});
  const maxShare = Math.max(0.0001, ...table.rows.map((r) => r.share));
  const href = (id: string) => withQuery(`/usage/agents/${encodeURIComponent(id)}`, { range: range === 7 ? null : range });
  const scope = table.day ? table.dayLabel : `last 7 days, ${table.fromLabel} – ${table.toLabel}`;
  const caption = `Estimated tokens per agent, ${scope}`;

  if (narrow) {
    return (
      <table className="tbl">
        <caption className="sr-only">{caption}</caption>
        <thead><tr><th scope="col">Agent</th><th scope="col">Tokens (est.)</th><th scope="col"><span className="sr-only">Details</span></th></tr></thead>
        <tbody>
          {table.rows.map((r) => {
            const isOpen = !!open[r.agentId];
            const id = `more-${r.agentId}`;
            return (
              <Fragment key={r.agentId}>
                <tr>
                  <td><span className="an"><i style={{ background: colorVar(r.color) }} aria-hidden="true" /><Link href={href(r.agentId)}>{r.name}</Link></span></td>
                  <td><Est value={r.tokens} /></td>
                  <td>
                    <button type="button" className="xbtn" aria-expanded={isOpen} aria-controls={id} onClick={() => setOpen((o) => ({ ...o, [r.agentId]: !isOpen }))}>
                      <span aria-hidden="true">{isOpen ? '▴' : '▾'}</span><span className="sr-only">Details for {r.name}</span>
                    </button>
                  </td>
                </tr>
                {isOpen && (
                  <tr className="more" id={id}>
                    <td colSpan={3}>
                      <dl>
                        <dt>Share</dt><dd>{pct(r.share)}</dd>
                        <dt>Turns</dt><dd>{count(r.turns)}</dd>
                        <dt>Routine runs</dt><dd>{count(r.routineRuns)}</dd>
                        <dt>Unmatched wakes</dt><dd>{count(r.unmatched)}</dd>
                        <dt>Fresh text only (est.)</dt><dd><Est value={r.io} /></dd>
                        <dt>Factor</dt><dd><FactorBadge factor={r.factor} /></dd>
                      </dl>
                    </td>
                  </tr>
                )}
              </Fragment>
            );
          })}
          <tr className="tot"><td>All {table.rows.length} agents</td><td><Est value={table.totals.tokens} /></td><td /></tr>
        </tbody>
      </table>
    );
  }

  return (
    <div className="tscroll">
      <table className="tbl">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr>
            <th scope="col">Agent</th>
            <th scope="col">Tokens (est.)</th>
            <th scope="col">Share</th>
            <th scope="col">Daily<span className="sr-only"> (last 7 days, chart only)</span></th>
            <th scope="col">Turns</th>
            <th scope="col">Routine runs</th>
            <th scope="col">Unmatched wakes</th>
            <th scope="col">Fresh text only (est.)</th>
            <th scope="col">Factor</th>
          </tr>
        </thead>
        <tbody>
          {table.rows.map((r) => (
            <tr key={r.agentId}>
              <td><span className="an"><i style={{ background: colorVar(r.color) }} aria-hidden="true" /><Link href={href(r.agentId)}>{r.name}</Link></span></td>
              <td><Est value={r.tokens} /></td>
              <td><span className="share"><b style={{ width: `${Math.max(r.share > 0 ? 2 : 0, Math.round((r.share / maxShare) * 60))}px` }} aria-hidden="true" />{pct(r.share)}</span></td>
              <td><Sparkline values={r.daily} color={r.color} /></td>
              <td>{count(r.turns)}</td>
              <td>{count(r.routineRuns)}</td>
              <td>{count(r.unmatched)}</td>
              <td><Est value={r.io} /></td>
              <td><FactorBadge factor={r.factor} /></td>
            </tr>
          ))}
          <tr className="tot">
            <td>All {table.rows.length} agents</td>
            <td><Est value={table.totals.tokens} /></td>
            <td>{table.totals.tokens > 0 ? '100%' : '—'}</td>
            <td />
            <td>{count(table.totals.turns)}</td>
            <td>{count(table.totals.routineRuns)}</td>
            <td>{count(table.totals.unmatched)}</td>
            <td><Est value={table.totals.io} /></td>
            <td />
          </tr>
        </tbody>
      </table>
    </div>
  );
}
