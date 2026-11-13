/**
 * Portfolio / board dialogs (frames 69:1037 and 71:789): name a project or workstream, delete a workstream with a
 * ticket transfer, move all tickets, delete a project (Archive suggested, type the name). All sit on the Kanban
 * Sheet (scrim, focus trap, Esc). `onSubmit` throws an ApiError on failure; the dialog shows its message and stays.
 */
import { useId, useState, type ReactNode } from 'react';
import { Sheet } from '../Kanban/Bits';
import { ApiError } from '../../lib/kanban';
import { deleteMode, nameError, NAME_MAX, type WorkstreamRow } from '../../lib/workstreams';
import { t, tn, type WsStrKey } from '../../lib/strings-workstreams';

const ticketsText = (n: number) => tn(n, 'dlg.delWs.tickets.one', 'dlg.delWs.tickets.many');

/** The server's field message (422), else its message, else the generic save error. */
export function errorText(e: unknown, field?: string): string {
  if (e instanceof ApiError) {
    const f = field ? e.errors[field]?.[0] : Object.values(e.errors)[0]?.[0];
    return f ?? (e.status && e.message ? e.message : t('toast.error'));
  }
  return t('toast.error');
}

function useSubmit() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const run = async (fn: () => Promise<void>, field?: string) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(errorText(e, field));
      setBusy(false);
    }
  };
  return { busy, error, setError, run };
}

function Shell({ title, onClose, children, restoreFocus }: { title: string; onClose: () => void; children: ReactNode; restoreFocus?: () => HTMLElement | null | undefined }) {
  return (
    <Sheet label={title} className="wsd" onClose={onClose} restoreFocus={restoreFocus}>
      <h3>{title}</h3>
      <div className="wsd-b">{children}</div>
    </Sheet>
  );
}

function Note({ children }: { children: ReactNode }) {
  return <p className="wsd-note"><i aria-hidden="true" />{children}</p>;
}

const NAME_ERRORS: Record<'required' | 'max', WsStrKey> = { required: 'dlg.name.required', max: 'dlg.name.max' };

/** New project / rename project (phone) / new or rename workstream (phone, board). */
export function NameDialog({ title, label, initial = '', hint, note, submitLabel, onSubmit, onClose, restoreFocus }: {
  title: string; label: string; initial?: string; hint?: string; note?: (name: string) => string | null; submitLabel?: string;
  onSubmit: (name: string) => Promise<void>; onClose: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const [name, setName] = useState(initial);
  const { busy, error, setError, run } = useSubmit();
  const id = useId();
  const submit = () => {
    const err = nameError(name);
    if (err === 'required' || err === 'max') { setError(t(NAME_ERRORS[err], { n: NAME_MAX })); return; }
    run(() => onSubmit(name.trim().replace(/\s+/g, ' ')), 'name');
  };
  const noteText = note?.(name.trim());
  return (
    <Shell title={title} onClose={onClose} restoreFocus={restoreFocus}>
      <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
        <label className="wsd-label" htmlFor={id}>{label}</label>
        <input id={id} className="wsd-in" value={name} maxLength={NAME_MAX + 20} autoFocus aria-invalid={!!error} aria-describedby={error ? `${id}-e` : undefined}
          onChange={(e) => { setName(e.target.value); setError(null); }} />
        {error && <p id={`${id}-e`} className="err-line" role="alert">{error}</p>}
        {hint && <p className="wsd-hint">{hint}</p>}
        {noteText && <Note>{noteText}</Note>}
        <div className="wsd-act">
          <button type="button" className="abtn" onClick={onClose}>{t('dlg.cancel')}</button>
          <button type="submit" className="btn" disabled={busy || !name.trim() || name.trim() === initial}>{busy ? t('dlg.saving') : submitLabel ?? t('dlg.save')}</button>
        </div>
      </form>
    </Shell>
  );
}

/** Delete a workstream (README §3.2): transfer to an existing or a new workstream when it has tickets / is the default. */
export function DeleteWorkstreamDialog({ ws, others, projectName, onSubmit, onClose, restoreFocus }: {
  ws: WorkstreamRow; others: WorkstreamRow[]; projectName: string;
  onSubmit: (body: { target_id?: string; new_name?: string }, targetName: string) => Promise<void>; onClose: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const mode = deleteMode(ws, others.length);
  const fallback = others.find((o) => o.isDefault) ?? others.find((o) => o.name === 'General') ?? others[0];
  const [choice, setChoice] = useState<'existing' | 'new'>(mode === 'only' ? 'new' : 'existing');
  const [target, setTarget] = useState(fallback?.id ?? '');
  const [newName, setNewName] = useState('');
  const { busy, error, setError, run } = useSubmit();
  const selId = useId();
  const newId = useId();
  const title = t('dlg.delWs.title', { name: ws.name });
  const targetRow = others.find((o) => o.id === target);
  const newErr = nameError(newName, [...others.map((o) => o.name)]);
  const valid = mode === 'empty' || (choice === 'existing' ? !!targetRow : newErr === null);

  if (mode === 'empty') {
    return (
      <Shell title={title} onClose={onClose} restoreFocus={restoreFocus}>
        <p className="wsd-p">{t('dlg.delWs.bodyEmpty', { name: ws.name })}</p>
        {error && <p className="err-line" role="alert">{error}</p>}
        <div className="wsd-act">
          <button type="button" className="abtn" onClick={onClose}>{t('dlg.cancel')}</button>
          <button type="button" className="btn dng" disabled={busy} onClick={() => run(() => onSubmit({}, ''))}>{busy ? t('dlg.saving') : t('dlg.delWs.submitEmpty')}</button>
        </div>
      </Shell>
    );
  }

  const body = mode === 'only'
    ? t('dlg.delWs.bodyOnly', { name: ws.name, project: projectName, tickets: ticketsText(ws.tickets) })
    : ws.tickets === 0 ? t('dlg.delWs.bodyDefault', { name: ws.name }) : t('dlg.delWs.body', { name: ws.name, tickets: ticketsText(ws.tickets) });
  const to = choice === 'existing' ? targetRow?.name ?? '' : newName.trim();
  const submitLabel = ws.tickets === 0 ? t('dlg.delWs.submitDefault')
    : t(choice === 'new' ? 'dlg.delWs.submitNew' : 'dlg.delWs.submit', { tickets: ticketsText(ws.tickets) });
  const submit = () => {
    if (!valid) return;
    run(() => onSubmit(choice === 'existing' ? { target_id: target } : { new_name: newName.trim() }, to), choice === 'existing' ? 'target' : 'new_name');
  };
  return (
    <Shell title={title} onClose={onClose} restoreFocus={restoreFocus}>
      <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
        <p className="wsd-p">{body}</p>
        <div className="wsd-choices" role="radiogroup" aria-label={title}>
          <label className={`wsd-choice${choice === 'existing' ? ' on' : ''}${mode === 'only' ? ' off' : ''}`}>
            <input type="radio" name="wsd-choice" checked={choice === 'existing'} disabled={mode === 'only'} onChange={() => { setChoice('existing'); setError(null); }} />
            <span className="wsd-ct">{t('dlg.delWs.existing')}</span>
            {mode === 'only' && <span className="wsd-cs">{t('dlg.delWs.existingNone')}</span>}
            {(choice === 'existing' || mode === 'only') && (
              <span className="wsd-sel">
                <label className="sr-only" htmlFor={selId}>{t('dlg.delWs.existing')}</label>
                <select id={selId} value={target} disabled={mode === 'only'} onChange={(e) => setTarget(e.target.value)}>
                  {mode === 'only' ? <option value="">{t('dlg.delWs.noOthers')}</option> : others.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </select>
              </span>
            )}
          </label>
          <label className={`wsd-choice${choice === 'new' ? ' on' : ''}`}>
            <input type="radio" name="wsd-choice" checked={choice === 'new'} onChange={() => { setChoice('new'); setError(null); }} />
            <span className="wsd-ct">{t('dlg.delWs.new')}</span>
            {mode !== 'only' && <span className="wsd-cs">{t('dlg.delWs.newHint', { name: ws.name })}</span>}
            {choice === 'new' && (
              <>
                <label className="sr-only" htmlFor={newId}>{t('dlg.newWs.label')}</label>
                <input id={newId} className="wsd-in" value={newName} autoFocus placeholder={t('dlg.delWs.newPlaceholder')} onChange={(e) => { setNewName(e.target.value); setError(null); }} />
              </>
            )}
          </label>
        </div>
        {to && <Note>{choice === 'existing' ? t('dlg.delWs.logExisting', { from: ws.name, to }) : t('dlg.delWs.logNew', { from: ws.name, to })} {t('dlg.delWs.footnote')}</Note>}
        {error && <p className="err-line" role="alert">{error}</p>}
        <div className="wsd-act">
          <button type="button" className="abtn" onClick={onClose}>{t('dlg.cancel')}</button>
          <button type="submit" className="btn dng" disabled={busy || !valid}>{busy ? t('dlg.saving') : submitLabel}</button>
        </div>
      </form>
    </Shell>
  );
}

/** "Move all tickets to…" (the workstream stays). */
export function MoveAllDialog({ ws, others, onSubmit, onClose, restoreFocus }: {
  ws: WorkstreamRow; others: WorkstreamRow[]; onSubmit: (targetId: string, targetName: string) => Promise<void>; onClose: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const [target, setTarget] = useState(others[0]?.id ?? '');
  const { busy, error, run } = useSubmit();
  const id = useId();
  const row = others.find((o) => o.id === target);
  return (
    <Shell title={t('dlg.moveAll.title', { name: ws.name })} onClose={onClose} restoreFocus={restoreFocus}>
      <form onSubmit={(e) => { e.preventDefault(); if (row) run(() => onSubmit(row.id, row.name), 'target_id'); }}>
        <p className="wsd-p">{t('dlg.moveAll.body', { name: ws.name, tickets: ticketsText(ws.tickets) })}</p>
        <label className="wsd-label" htmlFor={id}>{t('dlg.moveAll.label')}</label>
        <span className="wsd-sel">
          <select id={id} value={target} onChange={(e) => setTarget(e.target.value)}>
            {others.length === 0 ? <option value="">{t('dlg.delWs.noOthers')}</option> : others.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </select>
        </span>
        {error && <p className="err-line" role="alert">{error}</p>}
        <div className="wsd-act">
          <button type="button" className="abtn" onClick={onClose}>{t('dlg.cancel')}</button>
          <button type="submit" className="btn" disabled={busy || !row || ws.tickets === 0}>{busy ? t('dlg.saving') : t('dlg.moveAll.submit')}</button>
        </div>
      </form>
    </Shell>
  );
}

/** Delete a project: Archive suggested first; Delete only once the exact name is typed. Soft delete on the server. */
export function DeleteProjectDialog({ name, workstreams, tickets, archived, onDelete, onArchive, onClose, restoreFocus }: {
  name: string; workstreams: number; tickets: number; archived: boolean;
  onDelete: () => Promise<void>; onArchive: () => Promise<void>; onClose: () => void; restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const [typed, setTyped] = useState('');
  const { busy, error, run } = useSubmit();
  const id = useId();
  const ok = typed.trim() === name;
  return (
    <Shell title={t('dlg.delProject.title', { name })} onClose={onClose} restoreFocus={restoreFocus}>
      <form onSubmit={(e) => { e.preventDefault(); if (ok) run(onDelete, 'confirm_name'); }}>
        <p className="wsd-p">{t('dlg.delProject.body', { ws: tn(workstreams, 'fd.preview.ws.one', 'fd.preview.ws.many'), tickets: ticketsText(tickets) })}</p>
        <label className="wsd-label" htmlFor={id}>{t('dlg.delProject.confirm', { name })}</label>
        <input id={id} className="wsd-in" value={typed} autoFocus autoComplete="off" onChange={(e) => setTyped(e.target.value)} />
        {error && <p className="err-line" role="alert">{error}</p>}
        <div className="wsd-act">
          <button type="button" className="abtn" onClick={onClose}>{t('dlg.cancel')}</button>
          {!archived && <button type="button" className="abtn" disabled={busy} onClick={() => run(onArchive)}>{t('dlg.delProject.archive')}</button>}
          <button type="submit" className="btn dng" disabled={busy || !ok}>{busy ? t('dlg.saving') : t('dlg.delProject.submit')}</button>
        </div>
      </form>
    </Shell>
  );
}

/** The live "unique" check needs the other names; exported for inline inputs. */
export const takenNames = (rows: WorkstreamRow[], except?: string) => rows.filter((r) => r.id !== except).map((r) => r.name);
