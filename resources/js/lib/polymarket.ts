// Polymarket desk (Pages/Polymarket/Desk): props from App\Services\Polymarket\DeskReport + formatting (DATA-SPEC r1 §5).

export type Freshness = {
  state: 'fresh' | 'late' | 'stale';
  badge: string;
  banner: { title: string; body: string; key: string } | null;
};

export type Position = {
  market: string;
  url: string | null;
  outcome: string | null;
  shares: number | null;
  avgPrice: number | null;
  curPrice: number | null;
  cost: number | null;
  value: number | null;
  pnl: number | null;
  pnlPct: number | null;
  endDate: string | null;
  endLabel: string | null;
  settled: boolean;
  ends: { kind: 'today' | 'tomorrow' | 'days' | 'awaiting' | 'settled'; days: number | null } | null;
};

export type Totals = { cost: number | null; value: number | null; pnl: number | null; pnlPct: number | null; count: number };

export type ChartDay = { date: string; d: string; wd: string; mon: string; weekend: boolean; today: boolean };
export type ChartPoint = { ts: string; day: string; min: number; label: string; book: number; cash: number | null; stop: number | null; prevTime: string | null };
export type ChartLive = { ts: string; day: string; min: number; label: string; time: string; book: number; cash: number | null; separate: boolean; lastRunTime: string | null };
export type SweepDay = { day: string; min: number; total: number; count: number };
export type ChartData = {
  days: ChartDay[];
  points: ChartPoint[];
  live: ChartLive;
  funded: number | null;
  highWater: number | null;
  peak: { book: number; label: string; dayLabel: string } | null;
  stop: { usd: number; fromLabel: string; toLabel: string; removed: boolean } | null;
  sweeps: SweepDay[];
  defaultRange: ChartRange;
};
export type ChartRange = '7' | '30' | 'all';

export type Fill = { ts: string; day: string; dayLabel: string; time: string; side: string | null; outcome: string | null; market: string | null; shares: number | null; price: number | null; usdc: number | null };
export type SweepItem = { type: 'sweep' | 'redeem'; ts: string; day: string; dayLabel: string; time: string; approx: boolean; amount: number; market: string | null };

export type DeskFeed = {
  asOf: string;
  asOfLabel: string;
  asOfTime: string;
  freshness: Freshness;
  gateway: { status: string | null; country: string | null; geoblock: boolean | null } | null;
  lastDecision: string | null;
  stop: { usd: number | null; sinceLabel: string | null };
  note: { text: string; tsLabel: string | null } | null;
  book: { value: number; deltaYesterday: number | null; delta7d: number | null };
  totalPnl: { value: number | null; pct: number | null; swept: number | null; funded: number | null; fundedSinceLabel: string | null };
  realized: { value: number | null; sinceLabel: string | null };
  unrealized: { value: number | null; count: number };
  cash: number | null;
  openValue: number | null;
  openCount: number;
  openCost: number;
  highWater: { value: number | null; below: number | null; setLabel: string | null };
  swept: { value: number | null; count: number; lastLabel: string | null };
  chart: ChartData;
  positions: { open: Position[]; settled: Position[]; openTotals: Totals; settledTotals: Totals };
  fills: { available: boolean; items: Fill[]; bought: number; sold: number; noneToday: boolean; lastFillLabel: string | null };
  sweeps: { available: boolean; items: SweepItem[]; redeemed: number; swept: number; recent: boolean; sinceLabel: string | null };
  costBasis: string | null;
  receivedAt: string;
};

export type DeskProps = { now: string; feed: DeskFeed | null };

const MINUS = '−';
const money = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const whole = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

/** $1,019.34 (negative: −$4.98). */
export const usd = (n: number) => `${n < 0 ? MINUS : ''}$${money.format(Math.abs(n))}`;

/** +$117.57 / −$54.64 / $0.00 */
export const signedUsd = (n: number) => (Math.abs(n) < 0.005 ? '$0.00' : `${n > 0 ? '+' : MINUS}$${money.format(Math.abs(n))}`);

/** Axis: $1,000 */
export const usdAxis = (n: number) => `${n < 0 ? MINUS : ''}$${whole.format(Math.abs(n))}`;

/** +144.9% / −6.3% */
export const signedPct = (n: number) => `${n > 0.05 ? '+' : n < -0.05 ? MINUS : ''}${Math.abs(n).toFixed(1)}%`;

/** Price (0–1) in cents: 39.0¢, 0.45¢ below 1¢, 0¢. */
export function cents(p: number): string {
  const c = p * 100;
  if (Math.abs(c) < 0.005) return '0¢';
  return `${Math.abs(c) < 1 ? c.toFixed(2) : c.toFixed(1)}¢`;
}

/** 205 · 20.92 */
export const shares = (n: number) => (Number.isInteger(n) ? whole.format(n) : money.format(n));

/** P&L colour class: positive green, negative calm rust. */
export const pnlClass = (n: number | null) => (n === null || Math.abs(n) < 0.005 ? '' : n > 0 ? 'pos' : 'neg');

/** "8 markets" / "1 market" */
export const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
