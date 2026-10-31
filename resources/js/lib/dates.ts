import { t } from './strings';
const TZ = 'Europe/Ljubljana';
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function parts(d: Date) {
  const p = new Intl.DateTimeFormat('en-GB', {
    timeZone: TZ, year: 'numeric', month: 'numeric', day: 'numeric', weekday: 'short',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(d);
  const g = (t: string) => p.find((x) => x.type === t)?.value ?? '';
  return { y: g('year'), m: +g('month'), mon: MONTHS[+g('month') - 1], d: g('day'), wd: g('weekday'), hh: g('hour'), mm: g('minute') };
}

/** Calendar-day difference in Europe/Ljubljana. */
function dayDiff(a: Date, now: Date): number {
  const k = (x: Date) => {
    const p = parts(x);
    return Date.UTC(+p.y, p.m - 1, +p.d);
  };
  return Math.round((k(now) - k(a)) / 86400000);
}

/** Portfolio "Updated": Today, 09:12 · Yesterday · N days ago (≤6) · 12 Sep 2026. */
export function lastUpdated(iso: string | null, nowIso: string): string {
  if (!iso) return '—';
  const d = new Date(iso), now = new Date(nowIso), diff = dayDiff(d, now), p = parts(d);
  if (diff <= 0) return `Today, ${p.hh}:${p.mm}`;
  if (diff === 1) return 'Yesterday';
  if (diff <= 6) return `${diff} days ago`;
  return `${+p.d} ${p.mon} ${p.y}`;
}

/** Timeline: Today, 18:40 · Yesterday, 10:00 · Thu 8 Oct, 11:20 (year added when not current). */
export function updateTime(iso: string | null, nowIso: string): string {
  if (!iso) return '';
  const d = new Date(iso), now = new Date(nowIso), diff = dayDiff(d, now), p = parts(d);
  const time = `${p.hh}:${p.mm}`;
  if (diff <= 0) return `Today, ${time}`;
  if (diff === 1) return `Yesterday, ${time}`;
  const y = parts(now).y === p.y ? '' : ` ${p.y}`;
  return `${p.wd} ${+p.d} ${p.mon}${y}, ${time}`;
}

/** Inputs: Today · Yesterday · Thu 8 Oct. */
export function shortDay(iso: string | null, nowIso: string): string {
  if (!iso) return '';
  const d = new Date(iso), now = new Date(nowIso), diff = dayDiff(d, now), p = parts(d);
  if (diff <= 0) return 'Today';
  if (diff === 1) return 'Yesterday';
  const y = parts(now).y === p.y ? '' : ` ${p.y}`;
  return `${p.wd} ${+p.d} ${p.mon}${y}`;
}

/** Kanban "Last activity" on portfolio cards: 07:58 (today) · Yesterday · Wed 21 Oct (year added when not current). */
export function activityDay(iso: string | null, nowIso: string): string {
  if (!iso) return '—';
  const d = new Date(iso), now = new Date(nowIso), diff = dayDiff(d, now), p = parts(d);
  if (diff <= 0) return `${p.hh}:${p.mm}`;
  if (diff === 1) return 'Yesterday';
  const y = parts(now).y === p.y ? '' : ` ${p.y}`;
  return `${p.wd} ${+p.d} ${p.mon}${y}`;
}

/** Tokens / admin dates: 29 Jan 2027. */
export function longDate(iso: string | null): string {
  if (!iso) return '—';
  const p = parts(new Date(iso));
  return `${+p.d} ${p.mon} ${p.y}`;
}

/** Whole days from now until iso (negative = past), by calendar day in Europe/Ljubljana. */
export function daysUntil(iso: string, nowIso: string): number {
  return -dayDiff(new Date(iso), new Date(nowIso));
}

/** Lockout: 14:35 (the local time a lock ends). */
export function clockTime(iso: string): string {
  const p = parts(new Date(iso));
  return `${p.hh}:${p.mm}`;
}

/** Relative age for the redesigned cards: just now · 40 min ago · 2h ago · yesterday (opt-in) · 5d ago · 3 weeks ago · 2 months ago. */
export function ago(iso: string | null | undefined, nowIso: string, opts: { yesterday?: boolean } = {}): string {
  if (!iso) return '';
  const mins = Math.max(0, Math.floor((new Date(nowIso).getTime() - new Date(iso).getTime()) / 60000));
  if (mins < 1) return t('portfolio.time.now');
  if (mins < 60) return t('portfolio.time.min', { n: mins });
  if (mins < 1440) return t('portfolio.time.hour', { n: Math.floor(mins / 60) });
  const days = Math.floor(mins / 1440);
  if (days === 1 && opts.yesterday) return t('portfolio.time.yesterday');
  if (days < 14) return t('portfolio.time.day', { n: days });
  if (days < 60) { const n = Math.floor(days / 7); return t('portfolio.time.week', { n, weeks: t('portfolio.time.week.many') }); }
  const n = Math.floor(days / 30);
  return t('portfolio.time.month', { n, months: t('portfolio.time.month.many') });
}
