import { router } from '@inertiajs/react';
import { rangeParam, withQuery, type Range } from '../../lib/usage';

const RANGES: { range: Range; label: string }[] = [
  { range: 'yesterday', label: 'Yesterday' },
  { range: 7, label: '7 days' },
  { range: 30, label: '30 days' },
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
    <div className="seg" role="group" aria-label="Range">
      {RANGES.map(({ range: r, label }) => (
        <button key={r} type="button" aria-pressed={r === range} onClick={() => go(r)}>{label}</button>
      ))}
    </div>
  );
}
