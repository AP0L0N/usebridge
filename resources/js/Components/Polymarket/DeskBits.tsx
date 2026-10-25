import type { ReactNode } from 'react';
import { cents, plural, shares, usd, type DeskFeed, type Fill, type Freshness, type SweepItem } from '../../lib/polymarket';

const ClockIcon = () => (
  <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="10" cy="10" r="7.5" /><path d="M10 6v4l2.5 2" /></svg>
);
const CheckIcon = () => (
  <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="10" cy="10" r="7.5" /><path d="m6.8 10.2 2.2 2.2 4.2-4.6" /></svg>
);
export const WarnIcon = () => (
  <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" aria-hidden="true"><path d="M10 3 18 17H2z" /><path d="M10 8.5v3.5M10 14.6v.1" strokeLinecap="round" /></svg>
);
const InfoIcon = () => (
  <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="10" cy="10" r="7.5" /><path d="M10 9v5M10 6.2v.1" strokeLinecap="round" /></svg>
);
const InboxIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13l2.5-8h13L21 13v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM3 13h5l1.5 3h5L16 13h5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" /></svg>
);

const BADGE: Record<Freshness['state'], { cls: string; icon: ReactNode }> = {
  fresh: { cls: 'b-ok', icon: <CheckIcon /> },
  overnight: { cls: 'b-arch', icon: <ClockIcon /> },
  late: { cls: 'b-w1', icon: <ClockIcon /> },
  stale: { cls: 'b-w2', icon: <WarnIcon /> },
};

export function FreshBadge({ f }: { f: Freshness }) {
  const b = BADGE[f.state];
  return <span className={`badge ${b.cls} sm`}>{b.icon}<span>{f.badge}</span></span>;
}

/** Under the header when the feed is not fresh: overnight (blue note), late (w1), stale (w2). */
export function FreshBanner({ f }: { f: Freshness }) {
  if (!f.banner) return null;
  const cls = f.state === 'stale' ? 'w2' : f.state === 'late' ? 'w1' : 'nt';
  const icon = f.state === 'stale' ? <WarnIcon /> : f.state === 'late' ? <ClockIcon /> : <InfoIcon />;
  return (
    <div className={`banner ${cls}`} role="status">
      {icon}
      <p><b>{f.banner.title}</b> {f.banner.body}</p>
      <span className="k">{f.banner.key}</span>
    </div>
  );
}

export function MiniEmpty({ title, body }: { title: string; body: string }) {
  return (
    <div className="mini-empty">
      <div className="eico"><InboxIcon /></div>
      <h3>{title}</h3>
      <p>{body}</p>
    </div>
  );
}

export function Quiet({ children }: { children: ReactNode }) {
  return <p className="quiet"><ClockIcon /><span>{children}</span></p>;
}

/** Stat tile; `nullText` switches to the grey null state ("Not supplied" / "—"). */
export function Tile({ label, value, hint, cls = '', nullText, children }: { label: string; value?: ReactNode; hint: ReactNode; cls?: string; nullText?: string; children?: ReactNode }) {
  const isNull = nullText !== undefined;
  return (
    <div className={`stat${isNull ? ' null' : ''}${cls ? ` ${cls}` : ''}`}>
      <p className="lbl">{label}</p>
      <p className="val">{isNull ? nullText : value}</p>
      {children}
      <p className="hint">{hint}</p>
    </div>
  );
}

/** Consecutive items of the same day under one day heading. */
function byDay<T extends { day: string; dayLabel: string }>(items: T[]): { day: string; label: string; items: T[] }[] {
  const out: { day: string; label: string; items: T[] }[] = [];
  for (const it of items) {
    const last = out[out.length - 1];
    if (last && last.day === it.day) last.items.push(it);
    else out.push({ day: it.day, label: it.dayLabel, items: [it] });
  }
  return out;
}

export function FillsCard({ fills }: { fills: DeskFeed['fills'] }) {
  const n = fills.items.length;
  return (
    <section className="card" aria-labelledby="h-fills">
      <div className="chead">
        <h2 id="h-fills">Fills · last 7 days</h2>
        <p className="sub">{fills.available ? `${plural(n, 'fill')}${n ? ` · bought ${usd(fills.bought)} · sold ${usd(fills.sold)}` : ''}` : 'Not in the feed'}</p>
      </div>
      {!fills.available ? (
        <MiniEmpty title="No fills in this feed" body="Frank’s feed doesn’t list fills yet; they show here once it does." />
      ) : n === 0 ? (
        <MiniEmpty title="No fills in the last 7 days" body="The desk hasn’t bought or sold anything this week." />
      ) : (
        <>
          {fills.noneToday && <Quiet>No fills today.{fills.lastFillLabel ? ` Last fill ${fills.lastFillLabel}.` : ''}</Quiet>}
          <ul className="tl">
            {byDay(fills.items).map((g) => [
              <li key={`d-${g.day}`} className="day">{g.label}</li>,
              ...g.items.map((f, i) => <FillRow key={`${g.day}-${i}`} f={f} />),
            ])}
          </ul>
        </>
      )}
    </section>
  );
}

function FillRow({ f }: { f: Fill }) {
  const sell = f.side === 'Sell';
  return (
    <li>
      <span className="dot" />
      <div className="th">
        <time dateTime={f.ts}>{f.time}</time>
        {f.side && <span className={`tside${sell ? ' sell' : ''}`}>{f.side}</span>}
        {f.outcome && <span className={`sidep ${f.outcome.toLowerCase() === 'no' ? 'no' : 'yes'}`}>{f.outcome}</span>}
        <span className="amt">{f.usdc === null ? '—' : usd(f.usdc)}</span>
      </div>
      <p className="m">
        {f.market ?? '—'}
        {(f.shares !== null || f.price !== null) && (
          <><br /><small>{f.shares === null ? '—' : shares(f.shares)} shares @ {f.price === null ? '—' : cents(f.price)}</small></>
        )}
      </p>
    </li>
  );
}

const NEWEST = 6;

export function SweepsCard({ sweeps, showAll, onToggleAll }: { sweeps: DeskFeed['sweeps']; showAll: boolean; onToggleAll: () => void }) {
  const items = showAll ? sweeps.items : sweeps.items.slice(0, NEWEST);
  const total = sweeps.items.length;
  const anyApprox = items.some((i) => i.approx);
  return (
    <section className="card" aria-labelledby="h-sw">
      <div className="chead">
        <h2 id="h-sw">Redeems &amp; sweeps</h2>
        <p className="sub">
          {!sweeps.available ? 'Not in the feed' : [sweeps.sinceLabel && `Since ${sweeps.sinceLabel}`, total > 0 && `redeemed ${usd(sweeps.redeemed)} · swept ${usd(sweeps.swept)}`].filter(Boolean).join(' · ')}
        </p>
        {total > NEWEST && (
          <div className="right">
            <button className="tlink" type="button" aria-expanded={showAll} onClick={onToggleAll}>{showAll ? `Newest ${NEWEST}` : `All ${total}`}</button>
          </div>
        )}
      </div>
      {!sweeps.available ? (
        <MiniEmpty title="No redeems or sweeps in this feed" body="Frank’s feed doesn’t list them yet; winning payouts and sweeps to Bitstamp show here once it does." />
      ) : total === 0 ? (
        <MiniEmpty title="No redeems or sweeps yet" body="Winning payouts and sweeps to Bitstamp will appear here." />
      ) : (
        <>
          {!sweeps.recent && <Quiet>None in the last 7 days. Latest below.</Quiet>}
          <ul className="tl">
            {byDay(items).map((g) => [
              <li key={`d-${g.day}`} className="day">{g.label}</li>,
              ...g.items.map((w, i) => <SweepRow key={`${g.day}-${i}`} w={w} />),
            ])}
          </ul>
          {anyApprox && <p className="foot">~ = approximate time (the desk run that saw it, or a time window). Sweeps leave the desk, so they lower book value; they count in total P&amp;L.</p>}
        </>
      )}
    </section>
  );
}

function SweepRow({ w }: { w: SweepItem }) {
  const time = w.approx
    ? <span className="approx" title="Approximate: time of the desk run that saw it, or a time window">{w.time}</span>
    : w.time;
  if (w.type === 'redeem') {
    return (
      <li>
        <span className="dot r" />
        <div className="th"><time dateTime={w.ts}>{time}</time><span className="tside red">Redeem</span><span className="amt">{usd(w.amount)}</span></div>
        {w.market && <p className="m">{w.market}<br /><small>Winning shares paid out to desk cash</small></p>}
      </li>
    );
  }
  return (
    <li>
      <span className="dot s" />
      <div className="th"><time dateTime={w.ts}>{time}</time><span className="tside swp">Sweep → Bitstamp</span><span className="amt">{usd(w.amount)}</span></div>
    </li>
  );
}
