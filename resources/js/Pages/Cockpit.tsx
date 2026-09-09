import { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { HealthPill } from '../Components/HealthPill';
import { AppShell } from '../Layouts/AppShell';

type Project = {
  id: string;
  name: string;
  slug: string;
  ownerName?: string | null;
  ownerAgentId: string;
  goal: string;
  phase: string;
  phaseDoneLooksLike: string;
  health: 'green' | 'yellow' | 'red';
};

type Milestone = {
  id: string;
  title: string;
  dueAt: string | null;
  status: string;
  lane: string;
};

type WorkItem = {
  id: string;
  title: string;
  ownerName: string;
  dueAt: string | null;
  status: 'open' | 'active' | 'blocked' | 'done';
  doneLooksLike: string;
};

type Risk = { id: string; text: string; severity: string; open: boolean };
type StatusUpdate = { id: string; body: string; agentName: string; createdAt: string | null };
type Agent = { id: string; name: string; roleLabel: string };

const STATUS_CLASS: Record<WorkItem['status'], string> = {
  active: 'st-active',
  open: 'st-open',
  blocked: 'st-blocked',
  done: 'st-done',
};

export default function Cockpit({
  project,
  milestones,
  workItems,
  risks,
  statusUpdates,
  agents,
}: {
  project: Project;
  milestones: Milestone[];
  workItems: WorkItem[];
  risks: Risk[];
  statusUpdates: StatusUpdate[];
  agents: Agent[];
}) {
  const openHigh = risks.some((r) => r.open && r.severity === 'high');
  const form = useForm({
    body: '',
    agent_id: project.ownerAgentId,
  });

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    form.post(`/projects/${project.slug}/status-updates`, {
      preserveScroll: true,
      onSuccess: () => form.reset('body'),
    });
  }

  return (
    <AppShell>
      <div className="layout">
        <aside className="rail">
          <p className="crumb">
            <Link href="/">Portfolio</Link> / {project.name}
          </p>
          <div>
            <h2>{project.name}</h2>
            <p className="slug">{project.slug}</p>
          </div>
          <HealthPill health={project.health} />
          <div className="rail-meta">
            <div>
              <div className="k">Phase</div>
              <span className="pill pill-phase">{project.phase}</span>
            </div>
            <div>
              <div className="k">Owner</div>
              {project.ownerName ?? project.ownerAgentId}
            </div>
          </div>
        </aside>

        <div className="cockpit-main">
          <section className="panel">
            <div className="section-label">Goal</div>
            <p className="goal">{project.goal}</p>
            <div className="spacer-16" />
            <div className="section-label">Phase done looks like</div>
            <div className="dll">
              <strong>Done looks like:</strong> {project.phaseDoneLooksLike}
            </div>
          </section>

          <section className="panel">
            <h3>Milestones</h3>
            {milestones.length === 0 ? (
              <p className="muted-copy">No milestones seeded for this project.</p>
            ) : (
              <div className="timeline" role="list">
                {milestones.map((m) => (
                  <div key={m.id} className={`ms ${m.lane}`} role="listitem">
                    <div className="node" aria-hidden="true" />
                    <div className="ms-title">{m.title}</div>
                    <div className="ms-date">
                      {m.dueAt ?? '—'} · {m.lane}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>

          <section className="panel">
            <h3>Work items</h3>
            {workItems.length === 0 ? (
              <p className="muted-copy">No open work items for this phase.</p>
            ) : (
              <div className="table-wrap">
                <table className="stack-table">
                  <thead>
                    <tr>
                      <th>Title</th>
                      <th>Owner</th>
                      <th>Due</th>
                      <th>Status</th>
                      <th>Done looks like</th>
                    </tr>
                  </thead>
                  <tbody>
                    {workItems.map((w) => (
                      <tr key={w.id}>
                        <td data-label="Title">{w.title}</td>
                        <td data-label="Owner">{w.ownerName}</td>
                        <td className="mono" data-label="Due">
                          {w.dueAt ?? '—'}
                        </td>
                        <td className={STATUS_CLASS[w.status]} data-label="Status">
                          {w.status}
                        </td>
                        <td data-label="Done looks like">{w.doneLooksLike}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <div className="two-col">
            <section className="panel">
              <h3>Risks / blockers</h3>
              {risks.length === 0 ? (
                <p className="muted-copy">No risks seeded. Top blocker = none.</p>
              ) : (
                <ul className="list">
                  {risks.map((r) => (
                    <li key={r.id}>
                      <span
                        className={`sev sev-${r.severity === 'medium' ? 'med' : r.severity}`}
                      >
                        {r.severity.toUpperCase()}
                      </span>
                      {r.text}
                      {!r.open ? ' (closed)' : ''}
                    </li>
                  ))}
                </ul>
              )}
              <p className="risk-note">
                {openHigh
                  ? 'Open HIGH risk present — portfolio health is red.'
                  : 'No open HIGH risks in this seed. Top blocker on portfolio = highest open severity (else “none”).'}
              </p>
            </section>
            <section className="panel">
              <h3>Recent status updates</h3>
              {statusUpdates.length === 0 ? (
                <p className="muted-copy">No status updates yet.</p>
              ) : (
                <ul className="list">
                  {statusUpdates.map((u) => (
                    <li key={u.id}>
                      <div className="update-meta">
                        {u.agentName} · {u.createdAt ?? '—'}
                      </div>
                      {u.body}
                    </li>
                  ))}
                </ul>
              )}

              <form className="status-form" onSubmit={onSubmit} style={{ marginTop: 16 }}>
                <div className="section-label">Add status update</div>
                <label htmlFor="su-agent" className="k">
                  Agent
                </label>
                <select
                  id="su-agent"
                  value={form.data.agent_id}
                  onChange={(e) => form.setData('agent_id', e.target.value)}
                  style={{
                    width: '100%',
                    marginBottom: 8,
                    background: 'var(--ink-850)',
                    color: 'var(--fog-100)',
                    border: '1px solid var(--ink-600)',
                    borderRadius: 4,
                    padding: '8px 10px',
                  }}
                >
                  {agents.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.name}
                    </option>
                  ))}
                </select>
                <textarea
                  required
                  rows={3}
                  value={form.data.body}
                  onChange={(e) => form.setData('body', e.target.value)}
                  placeholder="Short progress note…"
                  style={{
                    width: '100%',
                    background: 'var(--ink-850)',
                    color: 'var(--fog-100)',
                    border: '1px solid var(--ink-600)',
                    borderRadius: 4,
                    padding: 10,
                    fontFamily: 'inherit',
                  }}
                />
                <button className="btn btn-cyan" type="submit" disabled={form.processing} style={{ marginTop: 8 }}>
                  Post update
                </button>
              </form>
            </section>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
