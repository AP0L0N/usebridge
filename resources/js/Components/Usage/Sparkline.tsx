import { colorVar, type ColorKey } from '../../lib/usage';

/** 84×22, one bar per day (7). Zero = 1px stub, no file = nothing drawn. Decorative: values are in the row. */
export function Sparkline({ values, color }: { values: (number | null)[]; color: ColorKey }) {
  const max = Math.max(1, ...values.map((v) => v ?? 0));
  return (
    <svg className="spark" width="84" height="22" viewBox="0 0 84 22" aria-hidden="true">
      {values.map((v, i) => {
        if (v === null) return null;
        const h = v === 0 ? 1 : Math.max(1.5, (v / max) * 22);
        return <rect key={i} x={1 + i * 12} y={22 - h} width="9" height={h} rx="1.5" fill={colorVar(color)} />;
      })}
    </svg>
  );
}
