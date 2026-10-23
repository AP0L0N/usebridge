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
export type CoverageNote = { date: string; label: string; note: string };

export type OverviewProps = {
  range: 7 | 30; today: string; from: string; to: string; fromLabel: string; toLabel: string;
  empty: boolean; daysWithFile: number; rangeTotal: number; agentCount: number;
  updatedAt: string | null; generatedAt: string | null; coverageNotes: CoverageNote[]; unreadable: string[];
  series: Series[]; days: OverviewDay[]; topAgents: TopAgent[]; topRoutines: TopRoutine[];
  unmatched: { runs: number; agents: number; tokens: number }; table: UsageTableData; method: string | null;
};

export type HeatRow = DayEntry & { hours: number[] | null; cutoffHour: number | null };
export type RoutineRow = { kind: 'routine' | 'unmatched' | 'other'; routineId: string; name: string; runs: number | null; tokens: number; share: number; perRun: number | null };
export type AgentProps = {
  range: 7 | 30; today: string; from: string; to: string; fromLabel: string; toLabel: string; hoursFromLabel: string;
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

/** Axis labels: "~200 M", "~1 B". */
export function axisLabel(n: number): string {
  if (n === 0) return '0';
  const p = parts(n);
  return `~${String(parseFloat(p.num))} ${p.unit}`.trim();
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

export const rangeWords = (range: number) => `last ${range} days`;

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
