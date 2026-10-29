/** Always visible, not dismissible (spec "Estimates: how they are labelled"). */
/** `day`: which file `updatedAt` comes from (today's, or yesterday's in the yesterday range). */
export function EstNote({ updatedAt, day = 'today' }: { updatedAt: string | null; day?: 'today' | 'yesterday' }) {
  return (
    <div className="estnote" role="note">
      <svg viewBox="0 0 20 20" aria-hidden="true">
        <circle cx="10" cy="10" r="8.2" fill="none" stroke="currentColor" strokeWidth="1.8" />
        <path d="M10 9v5M10 6.2v.1" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
      </svg>
      <p className="long">
        <b>Every figure here is an estimate (~), not billing.</b> It is scaled from visible chat text with a per-agent calibration factor; tool calls and prompt caching are not counted exactly. <b>Settings → Usage holds the real total.</b>
      </p>
      <p className="short"><b>Estimates (~), not billing.</b> Real total: Settings → Usage.</p>
      <span className="k">{updatedAt ? `Updated ${day} ${updatedAt}` : day === 'today' ? 'No file for today yet' : 'No file for yesterday'}</span>
    </div>
  );
}
