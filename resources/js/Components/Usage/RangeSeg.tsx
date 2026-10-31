import { router } from '@inertiajs/react';
import { t } from '../../lib/strings-usage';
import { rangeParam, withQuery, type Range } from '../../lib/usage';

const RANGES: { range: Range; label: string }[] = [
  { range: 'yesterday', label: t('usage.range.yesterday') },
  { range: 7, label: t('usage.range.7') },
  { range: 30, label: t('usage.range.30') },
];

/** Yesterday | 7 days | 30 days toggle group. Keeps the path, drops ?day (it belongs to the old range). */
export function RangeSeg({ range, onLoading }: { range: Range; onLoading?: (busy: boolean) => void }) {
  const go = (r: Range) => {
    if (r === range) return;
    router.get(withQuery(window.location.pathname + window.location.search, { range: rangeParam(r), day: null }), {}, {
      preserveScroll: true,
      onStart: () => onLoading?.(true),
      onFinish: () => onLoading?.(false),
    });
  };
  return (
    <div className="seg" role="group" aria-label={t('usage.range.label')}>
      {RANGES.map(({ range: r, label }) => (
        <button key={r} type="button" aria-pressed={r === range} onClick={() => go(r)}>{label}</button>
      ))}
    </div>
  );
}
