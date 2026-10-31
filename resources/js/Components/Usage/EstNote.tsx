import { t } from '../../lib/strings-usage';

/** Always visible, not dismissible (spec "Estimates: how they are labelled"). */
/** `day`: which file `updatedAt` comes from (today's, or yesterday's in the yesterday range). */
export function EstNote({ updatedAt, day = 'today' }: { updatedAt: string | null; day?: 'today' | 'yesterday' }) {
  return (
    <div className="estnote" role="note">
      <svg viewBox="0 0 20 20" aria-hidden="true">
        <circle cx="10" cy="10" r="8.2" fill="none" stroke="currentColor" strokeWidth="1.8" />
        <path d="M10 9v5M10 6.2v.1" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
      </svg>
      <p className="long"><b>{t('usage.note.title')}</b>{t('usage.note.body')}</p>
      <p className="short"><b>{t('usage.note.short')}</b></p>
      <span className="k">{updatedAt ? t('usage.note.updated', { day: t(`usage.day.${day}`), time: updatedAt }) : t('usage.note.noFile', { day: t(`usage.day.${day}`) })}</span>
    </div>
  );
}
