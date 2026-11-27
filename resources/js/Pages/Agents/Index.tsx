import { Head, Link } from '@inertiajs/react';
import '../../../css/usage-redesign.css';
import { AppTopBar } from '../../Components/Shell/AppTopBar';
import { t } from '../../lib/strings-usage';
import type { AgentListRow } from '../../lib/usage';

/**
 * GET /agents: one flat list of the roster agents, sorted by full name (no sections). Each row has the avatar (ink-800
 * circle, Geist Mono initials), the name (opens the unchanged detail page /usage/agents/{id}), the title and the full
 * description.
 */
export default function AgentsIndex({ agents }: { agents: AgentListRow[] }) {
  return (
    <>
      <Head title={t('agents.title')} />
      <AppTopBar />
      <div className="bu">
        <main className="wrap">
          <div className="dhead">
            <div>
              <h1>{t('agents.title')}</h1>
              <p className="summary">{t('agents.summary')}</p>
            </div>
          </div>
          {agents.length === 0 ? (
            <section className="ag-empty">
              <h2>{t('agents.empty.title')}</h2>
              <p>{t('agents.empty.body')}</p>
            </section>
          ) : (
            <ul className="ag-list">
              {agents.map((a) => (
                <li key={a.id} className="ag-row">
                  <span className="ag-avi" aria-hidden="true">{a.initials}</span>
                  <div>
                    <Link className="ag-name" href={`/usage/agents/${encodeURIComponent(a.id)}`}
                      aria-label={a.title ? t('agents.tile', { name: a.name, title: a.title }) : t('agents.tile.noTitle', { name: a.name })}>
                      {a.name}
                    </Link>
                    {a.title && <p className="ag-title">{a.title}</p>}
                    <p className="ag-desc">{a.description ?? t('agents.noDescription')}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </main>
      </div>
    </>
  );
}
