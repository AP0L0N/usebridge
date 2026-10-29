import type { ReactNode } from 'react';

export function StatTile({ label, value, hint }: { label: string; value: ReactNode; hint: ReactNode }) {
  return (
    <div className="stat">
      <p className="lbl">{label}</p>
      <p className="val">{value}</p>
      <p className="hint">{hint}</p>
    </div>
  );
}
