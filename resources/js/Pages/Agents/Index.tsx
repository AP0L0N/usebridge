import { Head, Link } from '@inertiajs/react';
import '../../../css/usage-redesign.css';
import { AppTopBar } from '../../Components/Shell/AppTopBar';
import { clockTime, longDate } from '../../lib/dates';
import { t } from '../../lib/strings-usage';
import type { AgentSection } from '../../lib/usage';

const when = (iso: string) => `${longDate(iso)} ${clockTime(iso)}`;

/**
 * GET /agents: the agents the roster sync listed (docs/agents-sync.md), one table per section in section order, sorted by
 * full name. The full name ("Name · Title") links to the detail page (/usage/agents/{id}).
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
            <section className="card">
              <h2>{t('agents.empty.title')}</h2>
              <p className="sub">{t('agents.empty.body')}</p>
            </section>
          ) : (
            sections.map((section, i) => (
              <section key={section.name} className="ag-sec" aria-labelledby={`ag-sec-${i}`}>
                <h2 id={`ag-sec-${i}`}>{section.name}</h2>
                <div className="tscroll">
                  <table className="tbl ag-tbl">
                    <caption className="sr-only">{t('agents.caption', { section: section.name })}</caption>
                    <thead>
                      <tr>
                        <th scope="col">{t('agents.col.agent')}</th>
                        <th scope="col">{t('agents.col.description')}</th>
                        <th scope="col">{t('agents.col.synced')}</th>
                        <th scope="col"><span className="sr-only">{t('agents.col.edit')}</span></th>
                      </tr>
                    </thead>
                    <tbody>
                      {section.agents.map((a) => (
                        <tr key={a.id}>
                          <th scope="row"><Link href={`/usage/agents/${encodeURIComponent(a.id)}`}>{a.fullName}</Link></th>
                          <td className="l ag-desc">{a.excerpt || <span className="sub">{t('agents.noDescription')}</span>}</td>
                          <td>{a.syncedAt ? when(a.syncedAt) : <span className="sub">{t('agents.notSynced')}</span>}</td>
                          <td>{a.editStatus && <span className={`chip ag-${a.editStatus}`}><i aria-hidden="true" />{t(`instr.status.${a.editStatus}`)}</span>}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ))
          )}
        </main>
      </div>
    </>
  );
}
