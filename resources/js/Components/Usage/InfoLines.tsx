/** Info lines under a chart: coverage_note per day, and stored files that could not be read (treated as missing). */
export function InfoLines({ notes, unreadable }: { notes?: { date: string; label: string; note: string }[]; unreadable: string[] }) {
  const icon = (
    <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8.2" fill="none" stroke="currentColor" strokeWidth="1.8" /><path d="M10 9v5M10 6.2v.1" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
  );
  return (
    <>
      {(notes ?? []).map((n) => <p key={n.date} className="info">{icon}<span><b>{n.label}:</b> {n.note}</span></p>)}
      {unreadable.length > 0 && (
        <p className="info">{icon}<span>{unreadable.length === 1 ? '1 file' : `${unreadable.length} files`} could not be read ({unreadable.join(', ')}). {unreadable.length === 1 ? 'That day is' : 'Those days are'} treated as missing.</span></p>
      )}
    </>
  );
}
