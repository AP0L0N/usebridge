import { useMemo, useState } from 'react';
import { t } from '../../lib/strings-usage';
import { chartVar, count, CURSOR, dayState, fmt, money, pct, rangeWords, spanLabel, type CliBlock, type CliModelRow, type OverviewDay, type Range, type Series } from '../../lib/usage';
import { ChartLegend } from './ChartLegend';
import { ModelName, ToolDayTable } from './CliUsage';
import { CursorUpload } from './CursorUpload';
import { Measured, MeasuredBadge } from './Measured';
import { MissingKey } from './MissingStub';
import { StackedDailyChart } from './StackedDailyChart';

// Model colours: chart tokens in rank order; the rest share the last one.
const MODEL_COLORS = [0, 1, 2, 3, 4].map(chartVar);

const MISSING = {
  short: 'no Cursor file for this day, not counted in any total',
  long: 'No Cursor CSV covered this day yet. Not the same as zero: a day an export covered without requests shows 0.',
};

type Props = { cli: CliBlock; range: Range; fromLabel: string; toLabel: string; narrow: boolean; loading: boolean };

/**
 * "Cursor models" (prototype usage.html, adapted to the individual plan): requests and tokens per model from the
 * dashboard CSV, one account "Cursor (all agents)" (no per-agent split), "no file that day" vs zero from
 * cli.importedTools, cost when the CSV had one, and the upload panel for the workspace owner.
 */
export function CursorUsage({ cli, range, fromLabel, toLabel, narrow, loading }: Props) {
  const [isolated, setIsolated] = useState<string | null>(null);
  const [asTable, setAsTable] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const span = spanLabel(fromLabel, toLabel);
  const tool = cli.tools.find((t) => t.tool === CURSOR) ?? null;
  const upload = cli.cursorUpload;
  const states = cli.days.map((d) => dayState(cli, d.date, CURSOR));
  const covered = states.filter((s) => s.kind !== 'nofile').length;
  const missing = cli.days.length - covered;
  const empty = tool === null && covered === 0;

  const models: (CliModelRow & { color: string })[] = useMemo(
    () => (tool?.models ?? []).map((m, i) => ({ ...m, color: MODEL_COLORS[Math.min(i, MODEL_COLORS.length - 1)]! })),
    [tool],
  );
  const series: Series[] = models.map((m) => ({ agentId: m.model ?? '', name: m.label, color: m.color, total: m.total }));
  const days: OverviewDay[] = cli.days.map((d, i) => {
    const st = states[i]!;
    if (st.kind === 'nofile') return { ...d, present: false, total: null, segments: null };
    const segs = models.map((m) => (st.kind === 'value' ? st.row.models.find((x) => x.model === m.model)?.total ?? 0 : 0));
    return { ...d, present: true, total: segs.reduce((a, b) => a + b, 0), segments: segs };
  });
  const hasCost = tool?.costCents !== undefined;

  return (
    <>
      <section className="card" id="cursor" aria-labelledby="h-cur" aria-busy={loading}>
        <div className="chead cur-head">
          <div className="cur-title">
            <h2 id="h-cur">{t('usage.cursor.title')}</h2>
            <span className="sub">{t('usage.cursor.sub')}</span>
          </div>
          <div className="right">
            <span className="chip soon"><i aria-hidden="true" />{t('usage.cursor.soon')}</span>
            {upload?.allowed && !empty && (
              <button type="button" className="bbtn" aria-expanded={importOpen} aria-controls="cur-import" onClick={() => setImportOpen((v) => !v)}>{t('usage.cursor.import')}</button>
            )}
          </div>
        </div>
        {!empty && <div className="cur-badges"><MeasuredBadge /></div>}

        {empty ? (
          <div className="cur-empty">
            <div className="eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 19V6M7 11l5-5 5 5M5 21h14" /></svg></div>
            <h3>{upload?.lastImportAt ? t('usage.cursor.empty.range', { range: range === 'yesterday' ? 'the day' : `the ${rangeWords(range)}` }) : t('usage.cursor.empty.title')}</h3>
            <p>
              {t('usage.cursor.empty.none')}{' '}
              {upload?.allowed ? t('usage.cursor.empty.owner') : t('usage.cursor.empty.member')}
            </p>
            {upload?.allowed && (
              <div className="actions">
                <button type="button" className="bbtn primary" aria-expanded={importOpen} aria-controls="cur-import" onClick={() => setImportOpen((v) => !v)}>{t('usage.cursor.import')}</button>
                <button type="button" className="bbtn ghost" aria-expanded={importOpen} aria-controls="cur-import" onClick={() => setImportOpen(true)}>{t('usage.cursor.importHow')}</button>
              </div>
            )}
          </div>
        ) : (
          <>
            <div className="cli-stats cur" style={{ margin: '14px 0 18px' }}>
              <div className="cli-stat"><p className="lbl">Requests</p><p className="val">{count(tool?.calls ?? 0)}</p></div>
              <div className="cli-stat"><p className="lbl">Input</p><p className="val"><Measured value={tool?.input ?? 0} /></p></div>
              <div className="cli-stat"><p className="lbl">Output</p><p className="val"><Measured value={tool?.output ?? 0} /></p></div>
              <div className="cli-stat"><p className="lbl">Cache read</p><p className="val"><Measured value={tool?.cacheRead ?? 0} /></p></div>
              <div className="cli-stat"><p className="lbl">Cache write</p><p className="val"><Measured value={tool?.cacheCreation ?? 0} /></p></div>
              <div className="cli-stat tot"><p className="lbl">Total</p><p className="val"><Measured value={tool?.total ?? 0} /></p></div>
              {hasCost && <div className="cli-stat"><p className="lbl">Cost (Cursor)</p><p className="val">{money(tool!.costCents!)}</p></div>}
            </div>

            {models.length > 0 ? (
              <div className="tscroll">
                <table className="tbl">
                  <caption className="sr">Cursor requests and tokens per model, {span}</caption>
                  <thead><tr>
                    <th scope="col">Model</th><th scope="col">Requests</th><th scope="col" className="nw">Input</th><th scope="col" className="nw">Output</th>
                    <th scope="col" className="nw">Cache read</th><th scope="col" className="nw">Cache write</th><th scope="col">Total tokens</th>
                    <th scope="col" className="nw">Share</th>{hasCost && <th scope="col">Cost</th>}
                  </tr></thead>
                  <tbody>
                    {models.map((m) => (
                      <tr key={m.model ?? 'none'}>
                        <th scope="row"><span className="an"><i style={{ background: m.color }} aria-hidden="true" /><ModelName m={m} /></span></th>
                        <td>{count(m.calls)}</td>
                        <td className="nw">{fmt(m.input)}</td><td className="nw">{fmt(m.output)}</td>
                        <td className="nw">{fmt(m.cacheRead)}</td><td className="nw">{fmt(m.cacheCreation)}</td>
                        <td><b><Measured value={m.total} /></b></td>
                        <td className="nw">{tool && tool.total > 0 ? pct(m.total / tool.total) : '—'}</td>
                        {hasCost && <td>{m.costCents !== undefined ? money(m.costCents) : '—'}</td>}
                      </tr>
                    ))}
                    <tr className="tot">
                      <td>All models</td><td>{count(tool?.calls ?? 0)}</td>
                      <td className="nw">{fmt(tool?.input ?? 0)}</td><td className="nw">{fmt(tool?.output ?? 0)}</td>
                      <td className="nw">{fmt(tool?.cacheRead ?? 0)}</td><td className="nw">{fmt(tool?.cacheCreation ?? 0)}</td>
                      <td><Measured value={tool?.total ?? 0} /></td><td className="nw">100%</td>
                      {hasCost && <td>{money(tool!.costCents!)}</td>}
                    </tr>
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="foot">No Cursor requests {range === 'yesterday' ? 'yesterday' : `in the ${rangeWords(range)}`}: the imported files cover {count(covered)} {covered === 1 ? 'day' : 'days'} with zero.</p>
            )}

            <div className="cur-split">
              <div>
                <div className="chead">
                  <h3>{t('usage.cursor.byModel')}</h3>
                  {!narrow && (
                    <div className="right">
                      {missing > 0 && <span className="hscale"><MissingKey />No file for that day ({missing}) · not the same as zero</span>}
                      <button type="button" className="tlink" aria-expanded={asTable} onClick={() => setAsTable((v) => !v)}>{asTable ? t('usage.showChart') : t('usage.showTable')}</button>
                    </div>
                  )}
                </div>
                {narrow || asTable ? (
                  <ToolDayTable cli={cli} days={cli.days} tools={[{ tool: CURSOR, name: 'Cursor (all agents)', color: tool?.color ?? MODEL_COLORS[0]! }]}
                    caption={`Daily Cursor tokens, ${fromLabel} – ${toLabel}. No file = no export covered that day; 0 = an export covered it without requests.`} />
                ) : (
                  <>
                    {series.length > 0 && <ChartLegend series={series} isolated={isolated} onToggle={(k) => setIsolated((cur) => (cur === k ? null : k))} kind="measured" label="Models in the chart. Select one to isolate it." />}
                    <StackedDailyChart days={days} series={series} isolated={isolated} fromLabel={fromLabel} toLabel={toLabel} kind="measured" idPrefix="cur-chart"
                      title={`Daily Cursor tokens by model, ${fromLabel} to ${toLabel}. The model table has every total.`} missing={MISSING} />
                  </>
                )}
              </div>
              <div>
                <div className="chead"><h3>{t('usage.cursor.byAccount')}</h3></div>
                <div className="tscroll">
                  <table className="tbl">
                    <caption className="sr">Cursor requests and tokens per account, {span}</caption>
                    <thead><tr><th scope="col">Account</th><th scope="col">Requests</th><th scope="col">Total tokens</th></tr></thead>
                    <tbody>
                      <tr>
                        <th scope="row" className="b"><span className="an"><i style={{ background: tool?.color ?? MODEL_COLORS[0] }} aria-hidden="true" />Cursor (all agents)</span></th>
                        <td>{count(tool?.calls ?? 0)}</td><td><b><Measured value={tool?.total ?? 0} /></b></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p className="foot">{t('usage.cursor.byAccountSub')}</p>
              </div>
            </div>
            <p className="foot">
              Total = input + cache write + cache read + output. Requests = rows in Cursor's export (errored and aborted requests count; they cost nothing).
              {hasCost && ' Cost is the export’s Cost column, as Cursor reports it.'}
            </p>
          </>
        )}

        {upload?.allowed && importOpen && <div id="cur-import"><CursorUpload lastImportAt={upload.lastImportAt} /></div>}
      </section>
    </>
  );
}
