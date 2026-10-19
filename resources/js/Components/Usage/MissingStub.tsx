/** No file for the day: 14px hatched stub with a dashed outline at the baseline, never a zero bar. */
export function MissingStub({ x, width, baseline }: { x: number; width: number; baseline: number }) {
  return <rect x={x} y={baseline - 14} width={width} height="14" fill="url(#u-hatch)" stroke="#B9B4A8" strokeDasharray="3 2" rx="2" />;
}

/** Legend key / inline swatch. */
export function MissingKey() {
  return <i className="miss" style={{ width: 22, height: 12, display: 'inline-block' }} aria-hidden="true" />;
}

/** Shared <defs> for hatched (missing) and striped (today, partial) fills. */
export function ChartPatterns() {
  return (
    <defs>
      <pattern id="u-hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
        <rect width="6" height="6" fill="#fff" /><rect width="3" height="6" fill="#ECEAE4" />
      </pattern>
      <pattern id="u-today" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
        <rect width="6" height="6" fill="none" /><rect width="2" height="6" fill="#ffffff66" />
      </pattern>
    </defs>
  );
}

export const MISSING_TEXT = 'The estimator has no file for this day, so it is not counted in any total. This is not the same as zero usage.';
