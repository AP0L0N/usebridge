import { useEffect, useState } from 'react';

// Usage view: every token figure is an estimate (est_* fields of the daily files). Counts are real.

// Agent colour: "#RRGGBB" from the server (AgentColors), fixed per agent_id across ranges and pages.
export type AgentColor = string;
export type Factor = 'calibrated' | 'median';

export type DayEntry = { date: string; label: string; longLabel: string; present: boolean; today: boolean };
export type OverviewDay = DayEntry & { total: number | null; segments: number[] | null };
export type Series = { agentId: string; name: string; color: AgentColor; total: number };
export type TopAgent = { agentId: string; name: string; color: AgentColor; tokens: number; share: number; turns: number };
export type TopRoutine = { routineId: string; name: string; agentId: string; agentName: string; color: AgentColor; runs: number; tokens: number; perRun: number | null };
export type TableRow = {
  agentId: string; name: string; color: AgentColor; tokens: number; share: number; daily: (number | null)[];
  turns: number; routineRuns: number; unmatched: number; io: number; factor: Factor;
};
export type UsageTableData = {
  day: string | null; dayLabel: string | null; dayMissing: boolean; from: string; fromLabel: string; toLabel: string;
  dailyLabels: string[]; rows: TableRow[];
  totals: { tokens: number; turns: number; routineRuns: number; unmatched: number; io: number };
};
export type Range = 'yesterday' | 7 | 30;
export type CoverageNote = { date: string; label: string; note: string };

export type OverviewProps = {
  range: Range; today: string; from: string; to: string; fromLabel: string; toLabel: string;
  empty: boolean; daysWithFile: number; rangeTotal: number; agentCount: number;
  updatedAt: string | null; generatedAt: string | null; coverageNotes: CoverageNote[]; unreadable: string[];
  series: Series[]; days: OverviewDay[]; topAgents: TopAgent[]; topRoutines: TopRoutine[];
  unmatched: { runs: number; agents: number; tokens: number }; table: UsageTableData; method: string | null;
  cli: CliBlock;
};

// Measured Claude Code / Kimi Code / OpenCode Go tokens (cli_usage_days): real counts from the CLI logs, not estimates.
export type CliTool = 'claude_code' | 'kimi_code' | 'opencode_go' | 'cursor';
export type CliSums = { input: number; output: number; cacheRead: number; cacheCreation: number; total: number; calls: number };
/** One model inside a tool / day cell / agent, total desc; model null = not recorded in the log (label "model not recorded"). */
export type CliModelRow = CliSums & { model: string | null; label: string };
export type CliToolStats = CliSums & { tool: CliTool; name: string; color: AgentColor; days: number; models: (CliModelRow & { days: number })[] };
export type CliAgentRow = CliSums & { agentId: string; name: string; models: CliModelRow[] };
export type CliTableRow = CliSums & { date: string; label: string; tool: CliTool; name: string; color: AgentColor; agents: CliAgentRow[]; models: CliModelRow[] };
export type CliBlock = {
  empty: boolean; coverageFrom: string | null; coverageFromLabel: string | null; daysWithRows: number;
  total: number; calls: number; tools: CliToolStats[]; series: Series[]; days: OverviewDay[]; table: CliTableRow[];
  /** date => tools a file covered that day (marker): covered + no rows = zero, missing = no file. */
  importedTools: Record<string, CliTool[]>;
};

/** How a token figure is rendered: estimate (tilde, "estimated") or measured (plain, "measured"). */
export type ValueKind = 'est' | 'measured';

export type HeatRow = DayEntry & { hours: number[] | null; cutoffHour: number | null };
export type RoutineRow = { kind: 'routine' | 'unmatched' | 'other'; routineId: string; name: string; runs: number | null; tokens: number; share: number; perRun: number | null };
export type AgentProps = {
  range: Range; today: string; from: string; to: string; fromLabel: string; toLabel: string; hoursFromLabel: string;
  updatedAt: string | null;
  agent: { id: string; name: string; color: AgentColor; rank: number | null; of: number; factor: Factor; K: number; medianK: number };
  zero: boolean;
  stats: { tokens: number; share: number; io: number; turns: number; matchedRuns: number; unmatchedRuns: number };
  days: (DayEntry & { value: number | null })[];
  hourTotals: number[]; heat: HeatRow[]; routines: RoutineRow[];
  routineTotals: { wakes: number; tokens: number }; unreadable: string[];
};

const UNITS = [
  { min: 1e9, div: 1e9, unit: 'B', word: 'billion' },
  { min: 1e6, div: 1e6, unit: 'M', word: 'million' },
  { min: 1e3, div: 1e3, unit: 'k', word: 'thousand' },
] as const;

/** Decimals per spec: 2.63 B · 427 M · 40.8 M · 2.60 M · 148 k (k below 10 k: 1 decimal). */
function decimals(n: number, unit: string) {
  if (unit === 'B') return 2;
  if (unit === 'M') return n >= 1e8 ? 0 : n >= 1e7 ? 1 : 2;
  return n >= 1e4 ? 0 : 1;
}

function parts(n: number): { num: string; unit: string; word: string } {
  const abs = Math.abs(Math.round(n));
  for (let i = 0; i < UNITS.length; i++) {
    const u = UNITS[i];
    if (abs < u.min) continue;
    const num = (abs / u.div).toFixed(decimals(abs, u.unit));
    // 999.6 M rounds to "1000 M": promote to the next unit.
    if (i > 0 && parseFloat(num) >= 1000) return parts(UNITS[i - 1].min);
    return { num, unit: u.unit, word: u.word };
  }
  return { num: String(abs), unit: '', word: '' };
}

/** Number only, no tilde: "427 M". */
export function fmt(n: number): string {
  const p = parts(n);
  return p.unit ? `${p.num} ${p.unit}` : p.num;
}

/** Screen-reader text: "about 427 million tokens, estimated". */
export function estSpoken(n: number): string {
  if (n === 0) return '0 tokens';
  const p = parts(n);
  return `about ${p.num}${p.word ? ` ${p.word}` : ''} tokens, estimated`;
}

/** Plain-text estimate for SVG text / titles: "~427 M" (true zero: "0"). */
export const estText = (n: number) => (n === 0 ? '0' : `~${fmt(n)}`);

/** Screen-reader text for a measured figure: "427 million tokens, measured". */
export function measuredSpoken(n: number): string {
  if (n === 0) return '0 tokens';
  const p = parts(n);
  return `${p.num}${p.word ? ` ${p.word}` : ''} tokens, measured`;
}

/** Plain-text figure by kind: "~427 M" for estimates, "427 M" measured. */
export const valueText = (n: number, kind: ValueKind) => (kind === 'measured' ? fmt(n) : estText(n));
export const valueSpoken = (n: number, kind: ValueKind) => (kind === 'measured' ? measuredSpoken(n) : estSpoken(n));

/** Axis labels: "~200 M", "~1 B" (measured: no tilde). */
export function axisLabel(n: number, kind: ValueKind = 'est'): string {
  if (n === 0) return '0';
  const p = parts(n);
  return `${kind === 'measured' ? '' : '~'}${String(parseFloat(p.num))} ${p.unit}`.trim();
}

/** Gridline step from 1/2/5 × 10^k so the axis gets ~5 lines (807 M → 200 M, top 1 B). */
export function niceStep(max: number, lines = 5): number {
  if (max <= 0) return 1;
  const raw = max / lines;
  const pow = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * pow).find((s) => s >= raw) ?? 10 * pow;
  return step;
}

export const pct = (share: number, digits = 1) => `${(share * 100).toFixed(digits)}%`;
export const count = (n: number) => String(n); // counts as in the mocks: 2459, no grouping

export const initials = (name: string) =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('');

/** "yesterday" · "last 7 days" · "last 30 days" */
export const rangeWords = (range: Range) => (range === 'yesterday' ? 'yesterday' : `last ${range} days`);
/** For "in the …" sentences: "yesterday" · "in the last 7 days". */
export const inRange = (range: Range) => (range === 'yesterday' ? 'yesterday' : `in the ${rangeWords(range)}`);
/** "Tue 13 Oct – Mon 19 Oct", or one label when the range is one day. */
export const spanLabel = (fromLabel: string, toLabel: string) => (fromLabel === toLabel ? fromLabel : `${fromLabel} – ${toLabel}`);
/** ?range= value: 7 is the default and has no param. */
export const rangeParam = (range: Range) => (range === 7 ? null : range);

/** Query helper: keep other params, set/unset one. */
export function withQuery(base: string, params: Record<string, string | number | null | undefined>) {
  const u = new URL(base, window.location.origin);
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '') u.searchParams.delete(k);
    else u.searchParams.set(k, String(v));
  }
  return u.pathname + u.search;
}

/** ≤720px layout switch (spec "Narrow width"). */
export function useNarrow(): boolean {
  const q = '(max-width: 720px)';
  const [narrow, setNarrow] = useState(() => typeof window !== 'undefined' && window.matchMedia(q).matches);
  useEffect(() => {
    const m = window.matchMedia(q);
    const on = () => setNarrow(m.matches);
    on();
    m.addEventListener('change', on);
    return () => m.removeEventListener('change', on);
  }, []);
  return narrow;
}
