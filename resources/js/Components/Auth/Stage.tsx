import type { AuthCopy } from '../../lib/authCopy';
import { Wordmark } from './Wordmark';

/**
 * The dark left panel on wide screens: dot grid, wordmark, hero line and an activity card. Everything on the card is
 * sample data (synthetic names), labelled so on the panel.
 */
export function Stage({ copy }: { copy: AuthCopy }) {
  const s = copy.stage;
  return (
    <aside className="auth-stage" aria-label={s.label}>
      <Wordmark size={40} label={copy.home} />
      <div className="auth-stage-mid">
        <p className="auth-hero">{s.hero}</p>
        <p className="auth-subline">{s.subline}</p>
        <ul className="auth-activity" aria-label={s.activity_label}>
          {s.activity.map((r, i) => (
            <li key={i} className={`is-${r.kind}`}>
              <span className="auth-activity-dot" aria-hidden="true" />
              <span className="auth-activity-who">{r.who}</span>
              <span className="auth-activity-what">{r.what}</span>
            </li>
          ))}
        </ul>
      </div>
      <p className="auth-stage-sample">{s.sample}</p>
    </aside>
  );
}
