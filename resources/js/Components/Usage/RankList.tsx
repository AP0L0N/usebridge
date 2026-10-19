import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { colorVar, type ColorKey } from '../../lib/usage';
import { Est } from './Est';

export type RankItem = { key: string; name: string; href?: string; value: number; color?: ColorKey; meta: ReactNode };

/** Top agents / Top routines rows: rank, name, value, 6px bar (relative to #1), meta line. */
export function RankList({ items, label }: { items: RankItem[]; label: string }) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return (
    <ol className="rank" aria-label={label}>
      {items.map((it) => (
        <li key={it.key}>
          <span className="nm">{it.href ? <Link href={it.href}>{it.name}</Link> : it.name}</span>
          <span className="v"><Est value={it.value} /></span>
          <span className="bar" aria-hidden="true"><span style={{ width: `${((it.value / max) * 100).toFixed(1)}%`, ...(it.color ? { background: colorVar(it.color) } : {}) }} /></span>
          <span className="meta">{it.meta}</span>
        </li>
      ))}
    </ol>
  );
}
