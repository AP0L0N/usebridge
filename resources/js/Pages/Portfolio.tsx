import { Link } from '@inertiajs/react';
import { HealthPill } from '../Components/HealthPill';
import { AppShell } from '../Layouts/AppShell';

type ProjectCard = {
  id: string;
  name: string;
  slug: string;
  ownerName?: string | null;
  ownerAgentId: string;
  nextDeadline: string | null;
  needsDecision: string | null;
  health: 'green' | 'yellow' | 'red';
  topBlocker: string;
};

export default function Portfolio({ projects }: { projects: ProjectCard[] }) {
  return (
    <AppShell>
      <main className="page page-portfolio">
        <header className="page-head">
          <h1>Portfolio</h1>
          <p>
            Live projects · health from deadlines, blockers, and stale updates. No
            invented clients or revenue.
          </p>
        </header>

        {projects.length === 0 ? (
          <div className="empty">
            <h2>No live projects seeded.</h2>
            <p>Bridge will list live projects here when they are seeded.</p>
          </div>
        ) : (
          <div className="grid">
            {projects.map((p) => (
              <Link
                key={p.id}
                className={`project-card health-${p.health}`}
                href={`/projects/${p.slug}`}
                aria-label={`Open ${p.name} cockpit`}
              >
                <div className="card-title-row">
                  <span className="card-title">{p.name}</span>
                  <span className="slug">{p.slug}</span>
                </div>
                <div className="meta-row">
                  <HealthPill health={p.health} />
                  <span className="owner">Owner · {p.ownerName ?? p.ownerAgentId}</span>
                </div>
                <div className="field">
                  <span className="lbl">Next</span>
                  <span className="val mono">{p.nextDeadline ?? '— no deadline'}</span>
                </div>
                <div className="field">
                  <span className="lbl">Blocker</span>
                  <span className={`val${p.topBlocker === 'none' ? ' muted' : ''}`}>
                    {p.topBlocker}
                  </span>
                </div>
                <div className="field">
                  <span className="lbl">Decision</span>
                  {p.needsDecision?.trim() ? (
                    <span className="val decision">{p.needsDecision}</span>
                  ) : (
                    <span className="val muted">—</span>
                  )}
                </div>
              </Link>
            ))}
          </div>
        )}

        <p className="foot-note">Health rules: DESIGN.md §7 · Seeds only</p>
      </main>
    </AppShell>
  );
}
