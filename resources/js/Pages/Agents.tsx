import { AppShell } from '../Layouts/AppShell';

type LoadBand = 'free' | 'balanced' | 'overloaded';

type Row = {
  agent: { id: string; name: string; roleLabel: string };
  load: LoadBand;
  dueSoonCount: number;
  dueSoonDisplay: string;
  chips: { label: string; title?: string }[];
};

const LOAD_CLASS: Record<LoadBand, string> = {
  free: 'load-free',
  balanced: 'load-ok',
  overloaded: 'load-over',
};

const LOAD_LABEL: Record<LoadBand, string> = {
  free: 'Free',
  balanced: 'Balanced',
  overloaded: 'Overloaded',
};

const BAR_CLASS: Record<LoadBand, string> = {
  free: 'bar-free',
  balanced: 'bar-ok',
  overloaded: 'bar-over',
};

export default function Agents({ rows }: { rows: Row[] }) {
  return (
    <AppShell>
      <main className="page page-agents">
        <header className="page-head">
          <h1>Agent load</h1>
          <p>
            Who owns what across projects, what is due soon, who is overloaded or
            free. Display refs only — no fake utilization metrics.
          </p>
        </header>
        <div className="legend">
          <span className="leg-free">Free ≤40% open due-soon pressure</span>
          <span className="leg-ok">Balanced</span>
          <span className="leg-over">Overloaded — multi-project + due soon ≥3 or flagged</span>
        </div>
        <div className="table-wrap">
          <table className="stack-table">
            <thead>
              <tr>
                <th>Agent</th>
                <th>Projects</th>
                <th>Due soon (7d)</th>
                <th>Load</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr
                  key={row.agent.id}
                  className={row.load === 'overloaded' ? 'overloaded' : undefined}
                >
                  <td data-label="Agent">
                    <span className="name">{row.agent.name}</span>
                    <span className="role">{row.agent.roleLabel}</span>
                  </td>
                  <td data-label="Projects">
                    {row.chips.map((c) => (
                      <span key={c.label} className="proj" title={c.title}>
                        {c.label}
                      </span>
                    ))}
                  </td>
                  <td className="mono" data-label="Due soon (7d)">
                    {row.dueSoonDisplay}
                  </td>
                  <td data-label="Load">
                    <span className={`load-pill ${LOAD_CLASS[row.load]}`}>
                      {LOAD_LABEL[row.load]}
                    </span>
                    <div className={`bar ${BAR_CLASS[row.load]}`} aria-hidden="true">
                      <i />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="foot-note">
          LOAD-1: Overloaded if dueSoon ≥ 3 OR (distinct projects incl. contrib ≥ 2
          AND dueSoon ≥ 2). Free if dueSoon = 0 and no ownership. Else Balanced.
          Bars illustrate state band, not invented hours. Merkwell owner remains
          Anatoly.
        </p>
      </main>
    </AppShell>
  );
}
