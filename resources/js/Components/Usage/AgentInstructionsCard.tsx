import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { clockTime, longDate } from '../../lib/dates';
import { requestJson } from '../../lib/http';
import { t } from '../../lib/strings-usage';
import type { AgentInstructions, InstructionEdit } from '../../lib/usage';

const when = (iso: string | null) => (iso ? `${longDate(iso)} ${clockTime(iso)}` : '—');

/**
 * "Instructions / Description" on /usage/agents/{id}: the synced profile description, and for the workspace owner an
 * editor that saves a new revision in Bridge. Saving never changes the agent; the daily sync applies it
 * (docs/agent-instructions-sync.md). Conflict: both texts side by side, re-save from the current description.
 */
export function AgentInstructionsCard({ agentId, agentName, data }: { agentId: string; agentName: string; data: AgentInstructions }) {
  const fieldId = useId();
  const { profile, latest, history, canEdit } = data;
  const [draft, setDraft] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const current = profile?.description ?? '';

  const open = (text: string) => { setDraft(text); setError(null); setSaved(false); };
  const save = async () => {
    if (draft === null) return;
    setBusy(true);
    setError(null);
    const res = await requestJson('POST', `/usage/agents/${encodeURIComponent(agentId)}/instructions`, { description: draft });
    setBusy(false);
    if (!res.ok) {
      setError(res.data.errors?.description?.[0] ?? res.data.message ?? 'The description could not be saved.');
      return;
    }
    setDraft(null);
    setSaved(true);
    router.reload({ only: ['instructions'] });
  };

  return (
    <section className="card ai-card" aria-labelledby="h-instr">
      <div className="chead">
        <h2 id="h-instr">{t('instr.title')}</h2>
        <span className="sub">
          {profile ? <>{profile.title && <b className="ai-title">{profile.title} · </b>}{t('instr.synced', { when: when(profile.syncedAt) })}</> : t('instr.notSynced')}
        </span>
        {canEdit && draft === null && (
          <div className="right"><button type="button" className="abtn" onClick={() => open(current)}>{t('instr.edit')}</button></div>
        )}
      </div>

      {draft === null ? (
        profile && (current === '' ? <p className="sub">{t('instr.emptyDescription')}</p> : <div className="ai-text">{current}</div>)
      ) : (
        <div className="ai-edit">
          <label htmlFor={fieldId}>{t('instr.label', { name: agentName })}</label>
          <textarea id={fieldId} value={draft} rows={Math.min(24, Math.max(8, draft.split('\n').length + 2))} maxLength={20000}
            disabled={busy} aria-invalid={error ? true : undefined} onChange={(e) => setDraft(e.target.value)} />
          {error && <p className="ai-err" role="alert">{error}</p>}
          <div className="ai-actions">
            <button type="button" className="btn" onClick={save} disabled={busy || draft.trim() === ''}>{busy ? t('instr.saving') : t('instr.save')}</button>
            <button type="button" className="abtn" onClick={() => { setDraft(null); setError(null); }} disabled={busy}>{t('instr.cancel')}</button>
          </div>
        </div>
      )}

      {saved && <p className="ai-note ai-pending" role="status">{t('instr.saved')}</p>}
      {latest && !saved && <EditState edit={latest} current={current} canEdit={canEdit && draft === null} onEdit={open} />}

      {history.length > 0 && (
        <details className="ai-history">
          <summary>{t('instr.history', { n: history.length })}</summary>
          <ol>
            {history.map((e) => (
              <li key={e.id}>
                <p className="sub">{t('instr.history.item', { rev: e.revision, status: t(`instr.status.${e.status}`), who: e.author, when: when(e.createdAt) })}{e.statusReason ? ` · ${e.statusReason}` : ''}</p>
                <div className="ai-text small">{e.proposedDescription}</div>
              </li>
            ))}
          </ol>
        </details>
      )}
    </section>
  );
}

function EditState({ edit, current, canEdit, onEdit }: { edit: InstructionEdit; current: string; canEdit: boolean; onEdit: (text: string) => void }) {
  const rev = edit.revision;
  if (edit.status === 'pending') return <p className="ai-note ai-pending" role="status">{t('instr.pending.body', { rev })}</p>;
  if (edit.status === 'applied') return <p className="ai-note ai-applied">{t('instr.applied.body', { rev, when: when(edit.appliedAt) })}</p>;
  if (edit.status === 'rejected') return <p className="ai-note ai-rejected">{t('instr.rejected.body', { rev, reason: edit.statusReason ? `: ${edit.statusReason}` : '' })}</p>;
  if (edit.status !== 'conflict') return null;
  return (
    <div className="ai-note ai-conflict">
      <p>{t('instr.conflict.body', { rev, reason: edit.statusReason ?? 'the profile changed' })}</p>
      <div className="ai-compare">
        <div><h3>{t('instr.conflict.current')}</h3><div className="ai-text">{current}</div></div>
        <div><h3>{t('instr.conflict.proposed', { rev })}</h3><div className="ai-text">{edit.proposedDescription}</div></div>
      </div>
      {canEdit && (
        <div className="ai-actions">
          <button type="button" className="abtn" onClick={() => onEdit(current)}>{t('instr.conflict.fromCurrent')}</button>
          <button type="button" className="abtn" onClick={() => onEdit(edit.proposedDescription)}>{t('instr.conflict.fromProposed')}</button>
        </div>
      )}
    </div>
  );
}
