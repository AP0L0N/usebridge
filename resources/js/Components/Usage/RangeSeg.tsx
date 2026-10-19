import { router } from '@inertiajs/react';
import { withQuery } from '../../lib/usage';

const RANGES = [7, 30] as const;

/** 7 days | 30 days toggle group. Keeps the path, drops ?day (it belongs to the old range). */
export function RangeSeg({ range, onLoading }: { range: number; onLoading?: (busy: boolean) => void }) {
  const go = (r: number) => {
    if (r === range) return;
    router.get(withQuery(window.location.pathname + window.location.search, { range: r === 7 ? null : r, day: null }), {}, {
      preserveScroll: true,
      onStart: () => onLoading?.(true),
      onFinish: () => onLoading?.(false),
    });
  };
  return (
    <div className="seg" role="group" aria-label="Range">
      {RANGES.map((r) => (
        <button key={r} type="button" aria-pressed={r === range} onClick={() => go(r)}>{r} days</button>
      ))}
    </div>
  );
}
