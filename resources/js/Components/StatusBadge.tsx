import { IconArch, IconOk, IconWait } from './Icons';
import { ageAria, t } from '../lib/strings';
import type { Status } from '../lib/types';

type Props = { status: Status; level?: number | null; ageDays?: number | null };

export function StatusBadge({ status, level, ageDays }: Props) {
  if (status === 'smooth') {
    return <span className="badge b-ok" aria-label={t('status.smooth')}><IconOk /><span>{t('status.smooth')}</span></span>;
  }
  if (status === 'archived') {
    return <span className="badge b-arch" aria-label={t('status.archived')}><IconArch /><span>{t('status.archived')}</span></span>;
  }
  const days = ageDays ?? 0;
  const lvl = level ?? 1;
  const label = ageAria(days);
  const age = days < 1 ? '<1d' : t('status.waiting.age', { n: days });
  return (
    <span className={`badge b-w${lvl}`} title={label} aria-label={label} role="img">
      <IconWait />
      <span aria-hidden="true">{t('status.waiting')}</span>
      <span className="pips" aria-hidden="true">
        {[0, 1, 2].map((i) => <i key={i} className={i < lvl ? 'on' : ''} />)}
      </span>
      <span aria-hidden="true">{age}</span>
    </span>
  );
}

export function rowClass(status: Status, level?: number | null): string {
  if (status === 'smooth') return 's-ok';
  if (status === 'archived') return 's-arch';
  return `s-w${level ?? 1}`;
}
