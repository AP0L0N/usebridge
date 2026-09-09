import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { AppShell } from '../Layouts/AppShell';

type Status = 'open' | 'assigned' | 'dismissed';

type Item = {
  id: string;
  reason: string;
  suggestedAction: string;
  projectSlug?: string | null;
  chipLabel?: string | null;
  status: Status;
  surfacedAt: string | null;
  assignedAgentId?: string | null;
  assignedAgentName?: string | null;
};

type Agent = { id: string; name: string };

const FILTERS: { id: Status; label: string }[] = [
  { id: 'open', label: 'Open' },
  { id: 'assigned', label: 'Assigned' },
  { id: 'dismissed', label: 'Dismissed' },
];

export default function Queue({ items, agents }: { items: Item[]; agents: Agent[] }) {
  const [filter, setFilter] = useState<Status>('open');
  const [menuFor, setMenuFor] = useState<string | null>(null);
  const visible = items.filter((i) => i.status === filter);

  useEffect(() => {
    function onDoc(e: MouseEvent) {
      const t = e.target as HTMLElement | null;
      if (!t?.closest('[data-assign]') && !t?.closest('.assign-menu')) {
        setMenuFor(null);
      }
    }
    document.addEventListener('click', onDoc);
    return () => document.removeEventListener('click', onDoc);
  }, []);

  return (
    <AppShell>
      <main className="page page-queue">
        <header className="page-head">
          <h1>CEO queue</h1>
          <p>
            Only items needing owner/CEO action. Why it surfaced, suggested next
            step, dismiss or assign.
          </p>
        </header>
        <div className="filters" role="group" aria-label="Queue filter">
          {FILTERS.map((f) => (
            <button
              key={f.id}
              type="button"
              aria-pressed={filter === f.id}
              onClick={() => {
                setFilter(f.id);
                setMenuFor(null);
              }}
            >
              {f.label}
            </button>
          ))}
        </div>

        {visible.length === 0 ? (
          <div className="empty empty-visible">
            {filter === 'open'
              ? 'No CEO actions — portfolio clear of escalations.'
              : `No ${filter} items.`}
          </div>
        ) : (
          <div id="queue-list">
            {visible.map((item) => {
              const chip = item.chipLabel ?? item.projectSlug ?? 'company';
              const age =
                item.status === 'assigned' && item.assignedAgentName
                  ? `assigned → ${item.assignedAgentName}`
                  : `surfaced ${item.surfacedAt}`;
              return (
                <div
                  key={item.id}
                  className={`q-wrap q-item${item.status === 'dismissed' ? ' dismissed' : ''}`}
                >
                  <div className="why-label">Why surfaced</div>
                  <p className="why">{item.reason}</p>
                  <div className="action-box">
                    <div className="lbl">Suggested next action</div>
                    <div className="txt">{item.suggestedAction}</div>
                  </div>
                  <div className="meta">
                    <div>
                      <span className="chip">{chip}</span>
                      <span className="age">{age}</span>
                    </div>
                    <div className="actions">
                      {item.status !== 'dismissed' && (
                        <button
                          type="button"
                          className="btn btn-ghost"
                          onClick={() => {
                            router.post(`/queue/${item.id}/dismiss`);
                            setMenuFor(null);
                          }}
                        >
                          Dismiss
                        </button>
                      )}
                      {item.status === 'open' && (
                        <button
                          type="button"
                          className="btn btn-copper"
                          data-assign
                          onClick={() =>
                            setMenuFor((cur) => (cur === item.id ? null : item.id))
                          }
                        >
                          Assign
                        </button>
                      )}
                    </div>
                  </div>
                  {menuFor === item.id && (
                    <div className="assign-menu open">
                      {agents.map((a) => (
                        <button
                          key={a.id}
                          type="button"
                          onClick={() => {
                            router.post(`/queue/${item.id}/assign`, {
                              agent_id: a.id,
                            });
                            setMenuFor(null);
                          }}
                        >
                          {a.id === 'agent-william' ? 'Captain (self)' : a.name}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
        <p className="foot-note">Queue ≠ all work items. Dismiss/assign persist to DB.</p>
      </main>
    </AppShell>
  );
}
