import { useState } from 'react';
import { AppShell } from '../Layouts/AppShell';

type DigestItem = {
  id: string;
  kind: 'unanswered' | 'commitment' | 'overdue';
  summary: string;
  projectSlug?: string | null;
  createdAt?: string | null;
};

const KIND_LABEL = {
  unanswered: 'Unanswered',
  commitment: 'Commitment',
  overdue: 'Overdue',
} as const;

export default function Ops({
  items,
  leadOrbitConnected,
}: {
  items: DigestItem[];
  leadOrbitConnected: boolean;
}) {
  const [tab, setTab] = useState<'digest' | 'stub'>('digest');
  const unanswered = items.filter((i) => i.kind === 'unanswered');
  const commitments = items.filter((i) => i.kind === 'commitment');
  const overdue = items.filter((i) => i.kind === 'overdue');
  const hasRows = items.length > 0;

  return (
    <AppShell>
      <main className="page page-ops">
        <header className="page-head">
          <h1>Ops</h1>
          <p>
            Digests, not mail. Unanswered threads, known commitments, overdue
            follow-ups — Lead Orbit read-only when connected.
          </p>
        </header>

        <div className="tabs" role="tablist" aria-label="Ops views">
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'digest'}
            onClick={() => setTab('digest')}
          >
            Digest
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'stub'}
            onClick={() => setTab('stub')}
          >
            Feed status {leadOrbitConnected ? '' : '(empty)'}
          </button>
        </div>

        {tab === 'digest' && (
          <div className="ops-panel" role="tabpanel">
            {!leadOrbitConnected && (
              <div className="banner">
                <div>
                  <h2>Lead Orbit adapter · stub</h2>
                  <p>
                    No Lead Orbit feed connected — digests will appear here when the
                    read-only adapter returns data. Nothing is invented in the
                    meantime.
                  </p>
                </div>
              </div>
            )}

            {!hasRows ? (
              <div className="empty">
                <h2>No Lead Orbit feed yet</h2>
                <p>
                  Bridge will show unanswered threads, commitments, and overdue
                  follow-ups here when the read-only adapter is connected. Nothing
                  is invented in the meantime.
                </p>
              </div>
            ) : (
              <>
                <DigestGroup title={`Unanswered · ${unanswered.length}`} kind="unanswered" rows={unanswered} />
                <DigestGroup
                  title={`Commitments · ${commitments.length} known`}
                  kind="commitment"
                  rows={commitments}
                  emptyCopy="No known commitments in feed. Bridge will not invent any."
                />
                <DigestGroup title={`Overdue follow-ups · ${overdue.length}`} kind="overdue" rows={overdue} />
              </>
            )}
            <p className="foot-note">Source: LeadOrbitFeed stub · mapped summary fields only</p>
          </div>
        )}

        {tab === 'stub' && (
          <div className="ops-panel" role="tabpanel">
            <div className="banner">
              <div>
                <h2>Connect Lead Orbit</h2>
                <p>
                  No Lead Orbit feed connected — digests will appear here when the
                  read-only adapter returns data. Credentials / API shape TBD (do
                  not invent).
                </p>
              </div>
              <button className="btn" type="button" disabled title="Pending credentials — Captain">
                Connect feed
              </button>
            </div>
            <div className="empty">
              <h2>No Lead Orbit feed yet</h2>
              <p>
                Bridge will show unanswered threads, commitments, and overdue
                follow-ups here when the read-only adapter is connected. Nothing is
                invented in the meantime.
              </p>
            </div>
          </div>
        )}
      </main>
    </AppShell>
  );
}

function DigestGroup({
  title,
  kind,
  rows,
  emptyCopy,
}: {
  title: string;
  kind: DigestItem['kind'];
  rows: DigestItem[];
  emptyCopy?: string;
}) {
  return (
    <div className="group">
      <h3>{title}</h3>
      {rows.length === 0 && emptyCopy && (
        <div className="digest-row">
          <span className={`kind kind-${kind}`}>{KIND_LABEL[kind]}</span>
          <div>
            <div className="summary muted-inline">{emptyCopy}</div>
          </div>
          <span className="age">—</span>
        </div>
      )}
      {rows.map((row) => (
        <div key={row.id} className="digest-row">
          <span className={`kind kind-${kind}`}>{KIND_LABEL[kind]}</span>
          <div>
            <div className="summary">{row.summary}</div>
            {row.projectSlug && <span className="chip">{row.projectSlug}</span>}
          </div>
          <span className="age">{row.createdAt?.slice(0, 10) ?? '—'}</span>
        </div>
      ))}
    </div>
  );
}
