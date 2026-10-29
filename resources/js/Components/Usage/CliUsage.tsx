import { Fragment, useState } from 'react';
import { count, fmt, rangeWords, spanLabel, type CliBlock, type CliSums, type CliToolStats, type OverviewDay, type Range, type Series } from '../../lib/usage';
import { ChartLegend } from './ChartLegend';
import { Measured, MeasuredBadge } from './Measured';
import { MissingKey } from './MissingStub';
import { DailyTable, StackedDailyChart } from './StackedDailyChart';

const MISSING = {
  short: 'no data, not counted in any total',
  long: 'No collector data for this day: before the logs on the box start, or not imported yet. Not the same as zero usage.',
};

type Props = { cli: CliBlock; range: Range; fromLabel: string; toLabel: string; narrow: boolean; loading: boolean };

const SUM_COLS: { key: keyof CliSums; label: string }[] = [
  { key: 'input', label: 'Input' },
  { key: 'output', label: 'Output' },
  { key: 'cacheRead', label: 'Cache read' },
  { key: 'cacheCreation', label: 'Cache creation' },
  { key: 'total', label: 'Total' },
];

function ToolTile({ t, range }: { t: CliToolStats; range: Range }) {
  return (
    <div className="cli-tool">
      <h3><i style={{ background: t.color }} aria-hidden="true" />{t.name}<span className="sub">{t.calls > 0 ? `${count(t.days)} ${t.days === 1 ? 'day' : 'days'} with calls` : `no calls ${rangeWords(range)}`}</span></h3>
      <div className="cli-stats">
        {SUM_COLS.map((c) => (
          <div key={c.key} className={`cli-stat${c.key === 'total' ? ' tot' : ''}`}>
            <p className="lbl">{c.label}</p>
            <p className="val"><Measured value={t[c.key]} /></p>
          </div>
        ))}
        <div className="cli-stat"><p className="lbl">Calls</p><p className="val">{count(t.calls)}</p></div>
      </div>
    </div>
  );
}

/** ≤720px: one horizontal total bar per day (both tools). */
function NarrowDays({ days }: { days: OverviewDay[] }) {
  const max = Math.max(1, ...days.filter((d) => d.present).map((d) => d.total ?? 0));
  return (
    <ul className="hbars" aria-label="Daily measured tokens">
      {days.map((d) => (
        <li key={d.date}>
          <span className={`d${d.today ? ' b' : ''}`}>{d.label}</span>
          {d.present
            ? <span className="t" style={{ width: `${Math.max(1, ((d.total ?? 0) / max) * 100).toFixed(1)}%`, background: 'var(--accent)' }} aria-hidden="true" />
            : <MissingKey />}
          <span className="v">{d.present ? <Measured value={d.total ?? 0} /> : <span className="dim2">No data</span>}</span>
        </li>
      ))}
    </ul>
  );
}

function Cells({ r }: { r: CliSums }) {
  return (
    <>
      {SUM_COLS.map((c) => <td key={c.key}>{c.key === 'total' ? <b><Measured value={r[c.key]} /></b> : fmt(r[c.key])}</td>)}
      <td>{count(r.calls)}</td>
    </>
  );
}

/** Day × tool table, with one indented row per agent when a (day, tool) has more than one. */
function CliTable({ cli, scope }: { cli: CliBlock; scope: string }) {
  return (
    <div className="ttbl">
      <table className="tbl">
        <caption>Measured tokens per day and tool, {scope}. Agent rows (indented) are a best-effort split by working directory.</caption>
        <thead>
          <tr>
            <th scope="col">Day</th>
            <th scope="col">Tool</th>
            {SUM_COLS.map((c) => <th key={c.key} scope="col">{c.label}</th>)}
            <th scope="col">Calls</th>
          </tr>
        </thead>
        <tbody>
          {cli.table.map((r) => (
            <Fragment key={`${r.date}-${r.tool}`}>
              <tr>
                <th scope="row">{r.label}</th>
                <td className="l"><span className="an"><i style={{ background: r.color }} aria-hidden="true" />{r.name}</span></td>
                <Cells r={r} />
              </tr>
              {r.agents.map((a) => (
                <tr key={a.agentId} className="sub">
                  <th scope="row">{a.name}</th>
                  <td className="l">{r.name}</td>
                  <Cells r={a} />
                </tr>
              ))}
            </Fragment>
          ))}
          <tr className="tot">
            <td colSpan={2}>All days, both tools</td>
            <td>{fmt(cli.tools.reduce((s, t) => s + t.input, 0))}</td>
            <td>{fmt(cli.tools.reduce((s, t) => s + t.output, 0))}</td>
            <td>{fmt(cli.tools.reduce((s, t) => s + t.cacheRead, 0))}</td>
            <td>{fmt(cli.tools.reduce((s, t) => s + t.cacheCreation, 0))}</td>
            <td><Measured value={cli.total} /></td>
            <td>{count(cli.calls)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

/**
 * "Claude Code & Kimi Code — measured tokens": real counts from the CLI logs on the shared box, over the
 * same range as the estimates above (yesterday / 7 / 30 days), independent of the estimator files.
 */
export function CliUsage({ cli, range, fromLabel, toLabel, narrow, loading }: Props) {
  const [isolated, setIsolated] = useState<string | null>(null);
  const [asTable, setAsTable] = useState(false);
  const span = spanLabel(fromLabel, toLabel);
  const missing = cli.days.filter((d) => !d.present).length;
  const series: Series[] = cli.series;

  return (
    <>
      <h2 className="sect" id="h-cli">
        Claude Code &amp; Kimi Code <span style={{ textTransform: 'none', letterSpacing: 0 }}>· measured tokens · {span}</span>
      </h2>
      <section className="card" aria-labelledby="h-cli" aria-busy={loading}>
        <div className="chead">
          <MeasuredBadge />
          <span className="sub">
            Counted from the CLI logs on the shared box, not scaled like the estimates above.
            {cli.coverageFromLabel && <> Coverage from <b>{cli.coverageFromLabel}</b>.</>}
          </span>
        </div>

        {cli.empty ? (
          <p className="foot" style={{ marginTop: 10 }}>
            No measured Claude Code or Kimi Code usage {range === 'yesterday' ? 'for yesterday' : `in the ${rangeWords(range)}`}
            {cli.coverageFromLabel ? <> (the collector's logs start {cli.coverageFromLabel}).</> : ' (nothing imported yet).'}
          </p>
        ) : (
          <>
            <div className="cli-tools">
              {cli.tools.map((t) => <ToolTile key={t.tool} t={t} range={range} />)}
            </div>

            <div className="chead">
              <h3 style={{ margin: 0, fontSize: 16 }}>Daily measured tokens by tool</h3>
              {!narrow && (
                <div className="right">
                  {missing > 0 && <span className="hscale"><MissingKey />No data for that day ({missing}) · not the same as zero</span>}
                  <button type="button" className="tlink" aria-expanded={asTable} onClick={() => setAsTable((v) => !v)}>{asTable ? 'Show as chart' : 'Show as table'}</button>
                </div>
              )}
            </div>
            {narrow ? (
              <NarrowDays days={cli.days} />
            ) : asTable ? (
              <DailyTable days={cli.days} series={series} kind="measured" caption={`Daily measured tokens by tool, ${fromLabel} – ${toLabel}`} missingText="No data. Not counted in any total." />
            ) : (
              <>
                <ChartLegend series={series} isolated={isolated} onToggle={(k) => setIsolated((cur) => (cur === k ? null : k))} kind="measured" label="Tools in the chart. Select one to isolate it." />
                <StackedDailyChart
                  days={cli.days}
                  series={series}
                  isolated={isolated}
                  fromLabel={fromLabel}
                  toLabel={toLabel}
                  kind="measured"
                  idPrefix="cli-chart"
                  title={`Daily measured tokens by tool, ${fromLabel} to ${toLabel}. Table view has every value.`}
                  missing={MISSING}
                />
              </>
            )}

            {!narrow && <CliTable cli={cli} scope={span} />}
            {narrow && (
              <ul className="ntop cli" style={{ marginTop: 12 }} aria-label="Measured tokens per day and tool">
                {cli.table.map((r) => (
                  <li key={`${r.date}-${r.tool}`}>
                    <span className="an"><i style={{ background: r.color }} aria-hidden="true" /><span><b>{r.label}</b> · {r.name} · {count(r.calls)} calls</span></span>
                    <span className="v"><Measured value={r.total} /></span>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}

        <p className="foot" style={{ marginTop: 12 }}>
          Total = input + output + cache read + cache creation; cache reads are billed at a discount. Calls = counted LLM calls.
          Agent split is best effort, by the working directory of each session; the rest is “Unattributed”.
        </p>
      </section>
    </>
  );
}
