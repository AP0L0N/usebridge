import { Fragment, useState } from 'react';
import { count, dayState, fmt, rangeWords, spanLabel, withoutCursor, type CliBlock, type CliModelRow, type CliSums, type CliToolStats, type OverviewDay, type Range, type Series } from '../../lib/usage';
import { ChartLegend } from './ChartLegend';
import { Measured, MeasuredBadge } from './Measured';
import { MissingKey } from './MissingStub';
import { StackedDailyChart } from './StackedDailyChart';

const MISSING = {
  short: 'no data, not counted in any total',
  long: 'No collector data for this day: before the logs on the box start, or not imported yet. Not the same as zero usage.',
};

type Props = { cli: CliBlock; range: Range; fromLabel: string; toLabel: string; narrow: boolean; loading: boolean };

export const SUM_COLS: { key: 'input' | 'output' | 'cacheRead' | 'cacheCreation' | 'total'; label: string }[] = [
  { key: 'input', label: 'Input' },
  { key: 'output', label: 'Output' },
  { key: 'cacheRead', label: 'Cache read' },
  { key: 'cacheCreation', label: 'Cache creation' },
  { key: 'total', label: 'Total' },
];

export function ModelName({ m }: { m: CliModelRow }) {
  return m.model ? <code className="mname">{m.model}</code> : <span className="mname none">{m.label}</span>;
}

/** "claude-opus-5-5" -> "opus 5.5" (tidy agent meta only; full ids stay in the model rows). */
function shortModel(m: CliModelRow): string {
  if (!m.model) return 'not recorded';
  return m.model.replace(/^claude-/, '').replace(/-(\d+)-(\d+)$/, ' $1.$2');
}

function ToolTile({ t, range, noFile }: { t: CliToolStats; range: Range; noFile: number }) {
  return (
    <div className="cli-tool">
      <h3>
        <i style={{ background: t.color }} aria-hidden="true" />{t.name}
        <span className="sub">
          {t.calls > 0 ? `${count(t.days)} ${t.days === 1 ? 'day' : 'days'} with calls` : `no calls ${rangeWords(range)}`}
          {noFile > 0 && <> · <MissingKey /> no file on {count(noFile)} {noFile === 1 ? 'day' : 'days'}</>}
        </span>
      </h3>
      <div className="cli-stats">
        {SUM_COLS.map((c) => (
          <div key={c.key} className={`cli-stat${c.key === 'total' ? ' tot' : ''}`}>
            <p className="lbl">{c.label}</p>
            <p className="val"><Measured value={t[c.key]} /></p>
          </div>
        ))}
        <div className="cli-stat"><p className="lbl">Calls</p><p className="val">{count(t.calls)}</p></div>
      </div>
      {t.calls > 0 && t.models.length > 0 && (
        <ul className="cli-models" aria-label={`${t.name} tokens per model`}>
          {t.models.map((m) => (
            <li key={m.model ?? 'none'}>
              <ModelName m={m} />
              <span className="mv"><Measured value={m.total} /></span>
              <span className="mbar" aria-hidden="true"><span style={{ width: `${t.total > 0 ? Math.max(1, (m.total / t.total) * 100).toFixed(1) : 0}%`, background: t.color }} /></span>
              <span className="mc">{count(m.calls)} calls</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Tool / model table (range totals): one row per tool, its models underneath, then Total. Replaces the per-tool tiles. */
function ToolModelTable({ cli, caption }: { cli: CliBlock; caption: string }) {
  const sum = (k: 'calls' | 'input' | 'output' | 'cacheRead' | 'cacheCreation' | 'total') => cli.tools.reduce((a, x) => a + x[k], 0);
  return (
    <div className="tscroll">
      <table className="tbl">
        <caption className="sr-only">{caption}</caption>
        <thead><tr>
          <th scope="col">Tool / model</th><th scope="col">Calls</th><th scope="col">Input</th><th scope="col">Output</th>
          <th scope="col" className="nw">Cache read</th><th scope="col" className="nw">Cache creation</th><th scope="col">Total</th>
        </tr></thead>
        <tbody>
          {cli.tools.map((tool) => (
            <Fragment key={tool.tool}>
              <tr>
                <th scope="row"><span className="an"><i style={{ background: tool.color }} aria-hidden="true" />{tool.name}</span></th>
                <td>{count(tool.calls)}</td><td><Measured value={tool.input} /></td><td><Measured value={tool.output} /></td>
                <td className="nw"><Measured value={tool.cacheRead} /></td><td className="nw"><Measured value={tool.cacheCreation} /></td><td><b><Measured value={tool.total} /></b></td>
              </tr>
              {tool.models.map((m) => (
                <tr key={m.model ?? 'none'} className="sub">
                  <th scope="row"><ModelName m={m} /></th>
                  <td>{count(m.calls)}</td><td>{fmt(m.input)}</td><td>{fmt(m.output)}</td>
                  <td className="nw">{fmt(m.cacheRead)}</td><td className="nw">{fmt(m.cacheCreation)}</td><td>{fmt(m.total)}</td>
                </tr>
              ))}
            </Fragment>
          ))}
          <tr className="tot">
            <td>Total</td><td>{count(sum('calls'))}</td><td>{fmt(sum('input'))}</td><td>{fmt(sum('output'))}</td>
            <td className="nw">{fmt(sum('cacheRead'))}</td><td className="nw">{fmt(sum('cacheCreation'))}</td><td>{fmt(sum('total'))}</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

/** ≤720px: one horizontal total bar per day (all tools). */
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

/**
 * Day × tool: the measured total, "0" when that day's file covered the tool without calls, or "No file" (no marker in
 * cli.importedTools and no rows): no file that day is not the same as zero.
 */
export function ToolDayTable({ cli, days, tools, caption }: { cli: CliBlock; days: OverviewDay[]; tools: { tool: CliToolStats['tool']; name: string; color: string }[]; caption: string }) {
  return (
    <div className="tscroll">
      <table className="tbl">
        <caption className="sr">{caption}</caption>
        <thead>
          <tr><th scope="col">Day</th>{tools.map((t) => <th key={t.tool} scope="col">{t.name}</th>)}</tr>
        </thead>
        <tbody>
          {days.map((d) => (
            <tr key={d.date}>
              <th scope="row">{d.longLabel}{d.today ? ' (so far)' : ''}</th>
              {tools.map((t) => {
                const st = dayState(cli, d.date, t.tool);
                return (
                  <td key={t.tool}>
                    {st.kind === 'value' ? <Measured value={st.row.total} />
                      : st.kind === 'zero' ? <span title="A file covered this day: no calls">0</span>
                        : <span className="num-miss"><MissingKey /> No file</span>}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Days of the range without a file for the tool (no rows, no marker). */
export const noFileDays = (cli: CliBlock, tool: CliToolStats['tool']) => cli.days.filter((d) => dayState(cli, d.date, tool).kind === 'nofile').length;

/** Day × tool table, with one indented row per agent when a (day, tool) has more than one. */
function CliTable({ cli, scope }: { cli: CliBlock; scope: string }) {
  const keyOf = (r: { date: string; tool: string }) => `${r.date}-${r.tool}`;
  const expandable = cli.table.filter((r) => r.models.length > 0);
  const [open, setOpen] = useState<Set<string>>(new Set());
  const allOpen = expandable.length > 0 && expandable.every((r) => open.has(keyOf(r)));
  const toggleOne = (k: string) => setOpen((cur) => { const n = new Set(cur); if (!n.delete(k)) n.add(k); return n; });
  return (
    <div className="ttbl">
      {expandable.length > 0 && (
        <p className="tbl-tools"><button type="button" className="tlink" aria-pressed={allOpen} onClick={() => setOpen(allOpen ? new Set() : new Set(expandable.map(keyOf)))}>{allOpen ? 'Hide models' : 'Show models'}</button></p>
      )}
      <table className="tbl">
        <caption>Measured tokens per day and tool, {scope}. Agent rows (indented) are a best-effort split by working directory; model rows (indented, from the toggle) split a day and tool by model.</caption>
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
            <Fragment key={keyOf(r)}>
              <tr>
                <th scope="row">
                  {r.models.length > 0 && (
                    <button type="button" className="rowtog" aria-expanded={open.has(keyOf(r))} aria-label={`${open.has(keyOf(r)) ? 'Hide' : 'Show'} models for ${r.label}, ${r.name}`} onClick={() => toggleOne(keyOf(r))}>{open.has(keyOf(r)) ? '▾' : '▸'}</button>
                  )}
                  {r.label}
                </th>
                <td className="l"><span className="an"><i style={{ background: r.color }} aria-hidden="true" />{r.name}</span></td>
                <Cells r={r} />
              </tr>
              {open.has(keyOf(r)) && r.models.map((m) => (
                <tr key={`m-${m.model ?? 'none'}`} className="sub msub">
                  <th scope="row"><ModelName m={m} /></th>
                  <td className="l">{r.name}</td>
                  <Cells r={m} />
                </tr>
              ))}
              {r.agents.map((a) => (
                <tr key={a.agentId} className="sub">
                  <th scope="row">{a.name}{a.models.length > 0 && <span className="ameta"> · {a.models.map(shortModel).join(' / ')}</span>}</th>
                  <td className="l">{r.name}</td>
                  <Cells r={a} />
                </tr>
              ))}
            </Fragment>
          ))}
          <tr className="tot">
            <td colSpan={2}>All days, all tools</td>
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
 * "Coding tools (Claude Code, Kimi Code & OpenCode Go) — measured tokens": real counts from the CLI logs on the shared box, over the
 * same range as the estimates above (yesterday / 7 / 30 days), independent of the estimator files.
 */
export function CliUsage({ cli: all, range, fromLabel, toLabel, narrow, loading }: Props) {
  const cli = withoutCursor(all);
  const [isolated, setIsolated] = useState<string | null>(null);
  const [asTable, setAsTable] = useState(false);
  const span = spanLabel(fromLabel, toLabel);
  const missing = cli.days.filter((d) => !d.present).length;
  const series: Series[] = cli.series;

  return (
    <>
      <section className="card" id="cli" aria-labelledby="h-cli" aria-busy={loading}>
        <div className="chead cur-head">
          <div className="cur-title">
            <h2 id="h-cli">Claude Code, Kimi Code &amp; OpenCode Go</h2>
            <span className="sub">
              Measured tokens · {span} · counted from your coding tools’ logs, not scaled like the estimates above.
              {cli.coverageFromLabel && <> Coverage from <b>{cli.coverageFromLabel}</b>.</>}
            </span>
          </div>
          <div className="right"><MeasuredBadge /></div>
        </div>

        {cli.empty ? (
          <p className="foot" style={{ marginTop: 10 }}>
            No measured Claude Code, Kimi Code or OpenCode Go usage {range === 'yesterday' ? 'for yesterday' : `in the ${rangeWords(range)}`}
            {cli.coverageFromLabel ? <> (the collector's logs start {cli.coverageFromLabel}).</> : ' (nothing imported yet).'}
          </p>
        ) : (
          <>
            <div className="cli-split">
              <ToolModelTable cli={cli} caption={`Measured tokens per tool and model, ${span}`} />
              <div>
                <div className="chead">
                  <h3 style={{ margin: 0 }}>Daily measured tokens by tool</h3>
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
                  <ToolDayTable cli={all} days={cli.days} tools={cli.tools} caption={`Daily measured tokens by tool, ${fromLabel} – ${toLabel}. No file = that day's collector file did not cover the tool; 0 = it did, without calls.`} />
                ) : (
                  <>
                    <ChartLegend series={series} isolated={isolated} onToggle={(k) => setIsolated((cur) => (cur === k ? null : k))} kind="measured" label="Tools in the chart. Select one to isolate it." />
                    <StackedDailyChart days={cli.days} series={series} isolated={isolated} fromLabel={fromLabel} toLabel={toLabel} kind="measured" idPrefix="cli-chart"
                      title={`Daily measured tokens by tool, ${fromLabel} to ${toLabel}. Table view has every value.`} missing={MISSING} />
                  </>
                )}
              </div>
            </div>

            {!narrow && (
              <details className="daydetail">
                <summary>Daily detail by tool and agent</summary>
                <CliTable cli={cli} scope={span} />
              </details>
            )}
            {narrow && (
              <ul className="ntop cli" style={{ marginTop: 12 }} aria-label="Measured tokens per day and tool">
                {cli.table.map((r) => (
                  <Fragment key={`${r.date}-${r.tool}`}>
                    <li>
                      <span className="an"><i style={{ background: r.color }} aria-hidden="true" /><span><b>{r.label}</b> · {r.name} · {count(r.calls)} calls</span></span>
                      <span className="v"><Measured value={r.total} /></span>
                    </li>
                    {r.models.map((m) => (
                      <li key={`m-${m.model ?? 'none'}`} className="msub">
                        <span className="an"><span><ModelName m={m} /> · {count(m.calls)} calls</span></span>
                        <span className="v"><Measured value={m.total} /></span>
                      </li>
                    ))}
                  </Fragment>
                ))}
              </ul>
            )}
          </>
        )}

        <p className="foot" style={{ marginTop: 12 }}>
          Total = input + output + cache read + cache creation; cache reads are billed at a discount. Calls = counted LLM calls.
          Agent split is best effort, by the working directory of each session; the rest is “Unattributed”.
          Models are the ids each tool logs (Claude Code message.model, Kimi Code usage.record model, OpenCode Go modelID); “model not recorded” = the log line had no model or the row predates per-model import.
        </p>
      </section>
    </>
  );
}
