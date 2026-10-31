import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cents, plural, shares, signedPct, signedUsd, usd, type DeskFeed, type Fill, type Freshness, type SweepItem } from '../../lib/polymarket';
import { t } from '../../lib/strings-pm';

export const InfoIcon = () => (
  <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true"><circle cx="10" cy="10" r="7.5" /><path d="M10 6.2v4.6M10 13.6v.1" strokeLinecap="round" /></svg>
);
export const WifiIcon = () => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" aria-hidden="true"><path d="M3 9.5a13 13 0 0 1 18 0M6 13a8.5 8.5 0 0 1 12 0M9.2 16.3a4 4 0 0 1 5.6 0" /><path d="M12 19.5v.1" /></svg>
);
const InboxIcon = () => (
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13l2.5-8h13L21 13v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM3 13h5l1.5 3h5L16 13h5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" /></svg>
);

/** Signed amount with an arrow (↗ up, ↘ down): never colour alone. A zero shows no arrow. */
export function Delta({ v, text, pct }: { v: number; text?: string; pct?: number | null }) {
  const up = v > 0.005, down = v < -0.005;
  return (
    <span className={`pmx-d num ${up ? 'pos' : down ? 'neg' : ''}`}>
      {(up || down) && (
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          {up ? <path d="M4.5 11.5 11.5 4.5M5.5 4.5h6v6" /> : <path d="M4.5 4.5l7 7M11.5 5.5v6h-6" />}
        </svg>
      )}
      <span>{text ?? signedUsd(v)}</span>
      {pct !== undefined && pct !== null && <span className="pct">({signedPct(pct)})</span>}
    </span>
  );
}

export function FreshBanner({ f }: { f: Freshness }) {
  if (!f.banner) return null;
  return (
    <div className={`pmx-banner ${f.state === 'stale' ? 'stale' : ''}`} role="status">
      <InfoIcon />
      <div className="tx"><b>{f.banner.title}</b><span className="b">{f.banner.body}</span></div>
      <span className="k">{f.banner.key}</span>
      <button className="pmx-btn" type="button" onClick={() => router.reload()}>{t('pm.banner.retry')}</button>
    </div>
  );
}

export function MiniEmpty({ title, body }: { title: string; body: string }) {
  return (
    <div className="pmx-empty mini">
      <div className="ico"><InboxIcon /></div>
      <h3>{title}</h3>
      <p>{body}</p>
    </div>
  );
}

/** KPI tile; `nullText` switches to the grey "Not supplied" / "—" treatment. */
export function Tile({ label, value, hint, nullText, children }: { label: string; value?: ReactNode; hint: ReactNode; nullText?: string; children?: ReactNode }) {
  const isNull = nullText !== undefined;
  return (
    <div className={`pmx-card pmx-tile${isNull ? ' null' : ''}`}>
      <p className="lbl">{label}</p>
      <p className="val">{isNull ? nullText : value}</p>
      {children}
      <p className="hint">{hint}</p>
    </div>
  );
}

export function FillsCard({ fills }: { fills: DeskFeed['fills'] }) {
  const n = fills.items.length;
  const sub = !fills.available ? t('pm.tile.notInFeed') : t('pm.fills.sub', { fills: plural(n, 'fill'), detail: n ? t('pm.fills.detail', { bought: usd(fills.bought), sold: usd(fills.sold) }) : '' });
  return (
    <section className="pmx-card" aria-labelledby="pmx-h-fills">
      <div className="pmx-chead"><div><h2 id="pmx-h-fills">{t('pm.fills.title')}</h2><p className="s">{sub}</p></div></div>
      {!fills.available ? (
        <MiniEmpty title={t('pm.fills.unavailable.title')} body={t('pm.fills.unavailable.body')} />
      ) : n === 0 ? (
        <MiniEmpty title={t('pm.fills.none.title')} body={t('pm.fills.none.body')} />
      ) : (
        <>
          {fills.noneToday && <p className="pmx-quiet">{t('pm.fills.today')}{fills.lastFillLabel ? t('pm.fills.lastFill', { when: fills.lastFillLabel }) : ''}</p>}
          <ul className="pmx-list">{fills.items.map((f, i) => <FillRow key={i} f={f} />)}</ul>
        </>
      )}
    </section>
  );
}

function FillRow({ f }: { f: Fill }) {
  const sell = f.side === 'Sell';
  return (
    <li>
      <time dateTime={f.ts}>{f.dayLabel} · {f.time}</time>
      <div className="what">
        <div className="tags">
          {f.side && <span className="pmx-tag">{f.side}</span>}
          {f.outcome && <span className={`pmx-side ${f.outcome.toLowerCase() === 'no' ? 'no' : ''}`}>{f.outcome}</span>}
        </div>
        <p className="m">{f.market ?? '—'}</p>
        {(f.shares !== null || f.price !== null) && (
          <small>{t('pm.fills.at', { shares: f.shares === null ? '—' : shares(f.shares), price: f.price === null ? '—' : cents(f.price) })}</small>
        )}
      </div>
      {f.usdc === null ? <span className="amt">—</span> : <span className="amt"><Delta v={sell ? f.usdc : -f.usdc} /></span>}
    </li>
  );
}

const NEWEST = 6;

export function SweepsCard({ sweeps, showAll, onToggleAll }: { sweeps: DeskFeed['sweeps']; showAll: boolean; onToggleAll: () => void }) {
  const items = showAll ? sweeps.items : sweeps.items.slice(0, NEWEST);
  const total = sweeps.items.length;
  const anyApprox = items.some((i) => i.approx);
  const sub = !sweeps.available ? t('pm.tile.notInFeed')
    : total === 0 ? t('pm.sweeps.sub')
    : [sweeps.sinceLabel ? t('pm.sweeps.subTotals', { since: sweeps.sinceLabel }) : t('pm.sweeps.sub'), t('pm.sweeps.totals', { redeemed: usd(sweeps.redeemed), swept: usd(sweeps.swept) })].join(' · ');
  return (
    <section className="pmx-card" aria-labelledby="pmx-h-sw">
      <div className="pmx-chead">
        <div><h2 id="pmx-h-sw">{t('pm.sweeps.title')}</h2><p className="s">{sub}</p></div>
        {total > NEWEST && (
          <button className="pmx-more" type="button" aria-expanded={showAll} onClick={onToggleAll}>
            {showAll ? t('pm.sweeps.newest', { n: NEWEST }) : t('pm.sweeps.all', { n: total })}
          </button>
        )}
      </div>
      {!sweeps.available ? (
        <MiniEmpty title={t('pm.sweeps.unavailable.title')} body={t('pm.sweeps.unavailable.body')} />
      ) : total === 0 ? (
        <MiniEmpty title={t('pm.sweeps.none.title')} body={t('pm.sweeps.none.body')} />
      ) : (
        <>
          {!sweeps.recent && <p className="pmx-quiet">{t('pm.sweeps.quiet')}</p>}
          <ul className="pmx-list">{items.map((w, i) => <SweepRow key={i} w={w} />)}</ul>
          {anyApprox && <p className="pmx-tnote">{t('pm.sweeps.approx')}</p>}
        </>
      )}
    </section>
  );
}

function SweepRow({ w }: { w: SweepItem }) {
  const redeem = w.type === 'redeem';
  return (
    <li>
      <time dateTime={w.ts} title={w.approx ? 'Approximate' : undefined}>{w.dayLabel} · {w.time}</time>
      <div className="what">
        <div className="tags"><span className="pmx-tag">{redeem ? t('pm.sweeps.redeem') : t('pm.sweeps.sweep')}</span></div>
        <p className="m">{redeem ? (w.market ?? '—') : t('pm.sweeps.sweep.name')}</p>
        <small>{redeem ? t('pm.sweeps.redeem.note') : t('pm.sweeps.sweep.note')}</small>
      </div>
      <span className="amt"><Delta v={redeem ? w.amount : -w.amount} /></span>
    </li>
  );
}
