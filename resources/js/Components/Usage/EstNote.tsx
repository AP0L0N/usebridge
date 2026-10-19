/** Always visible, not dismissible (spec "Estimates: how they are labelled"). */
export function EstNote({ updatedAt }: { updatedAt: string | null }) {
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
      <span className="k">{updatedAt ? `Updated today ${updatedAt}` : 'No file for today yet'}</span>
    </div>
  );
}
