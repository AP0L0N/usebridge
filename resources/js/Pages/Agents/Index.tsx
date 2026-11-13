import { Head, Link } from '@inertiajs/react';
import '../../../css/usage-redesign.css';
import { AppTopBar } from '../../Components/Shell/AppTopBar';
import { t } from '../../lib/strings-usage';
import type { AgentSection } from '../../lib/usage';

/**
 * GET /agents: avatar grid (README §1.4, frames 75:30 / 75:151 / 75:264). One section per roster section, in section
 * order, with its count; each tile (AG / Agent tile: 72px ink-800 circle, Geist Mono initials, name, title) opens the
 * unchanged detail page /usage/agents/{id}. 5 tiles per row at 1440, 2 on a phone.
 */
export default function AgentsIndex({ sections }: { sections: AgentSection[] }) {
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
          {sections.length === 0 ? (
            <section className="ag-empty">
              <h2>{t('agents.empty.title')}</h2>
              <p>{t('agents.empty.body')}</p>
            </section>
          ) : (
            sections.map((section, i) => (
              <section key={section.name} className="ag-sec" aria-labelledby={`ag-sec-${i}`}>
                <h2 id={`ag-sec-${i}`}>{section.name} <span className="ag-n" aria-label={t('agents.count', { n: section.count })}>{section.count}</span></h2>
                <ul className="ag-grid">
                  {section.agents.map((a) => (
                    <li key={a.id}>
                      <Link className="ag-tile" href={a.url}
                        aria-label={a.title ? t('agents.tile', { name: a.name, title: a.title }) : t('agents.tile.noTitle', { name: a.name })}>
                        <span className="ag-avi" aria-hidden="true">{a.initials}</span>
                        <span className="ag-name">{a.name}</span>
                        {a.title && <span className="ag-title">{a.title}</span>}
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            ))
          )}
        </main>
      </div>
    </>
  );
}
