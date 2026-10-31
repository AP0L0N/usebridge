import { useRef, useState, type FormEvent } from 'react';
import { Sheet } from './Bits';
import { fieldErrors, requestJson } from '../../lib/http';
import type { ColumnKey } from '../../lib/kanban';

export type ColumnDef = { key: ColumnKey | string; label: string; order: number };

/** Longest column name (App\Support\BoardColumns::LABEL_MAX). */
export const LABEL_MAX = 40;

/**
 * PUT /projects/{slug}/column-labels. Errors come back per column key ({backlog: '…'}) plus `form` for the rest
 * (unknown column, network, 404).
 */
export async function saveColumnLabels(url: string, labels: Record<string, string>): Promise<{ ok: true } | { ok: false; errors: Record<string, string> }> {
  const res = await requestJson('PUT', url, { column_labels: labels });
  if (res.ok) return { ok: true };
  if (res.status !== 422) return { ok: false, errors: { form: res.data.message ?? 'Couldn’t save the column names. Try again.' } };
  const errors: Record<string, string> = {};
  for (const [k, v] of Object.entries(fieldErrors(res.data.errors))) errors[k.startsWith('column_labels.') ? k.slice('column_labels.'.length) : 'form'] = v;
  return { ok: false, errors };
}

/** Client check before sending: every name 1–40 characters after trimming (the server checks the same). */
export function checkLabels(labels: Record<string, string>): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const [k, v] of Object.entries(labels)) {
    if (v.trim() === '') errors[k] = 'Give every column a name.';
    else if (v.trim().length > LABEL_MAX) errors[k] = `Column names are at most ${LABEL_MAX} characters.`;
  }
  return errors;
}

/**
 * Board settings: rename this project's columns (keys and order stay; agents keep using the keys). Desktop: centred
 * dialog, mobile: bottom sheet (the shared Sheet). Saving reloads the board; "Use default names" fills the defaults in.
 */
export function ColumnLabelsSheet({ url, columns, defaults, onSaved, onClose, restoreFocus }: {
  url: string;
  columns: ColumnDef[];
  defaults: ColumnDef[];
  onSaved: () => void;
  onClose: () => void;
  restoreFocus?: () => HTMLElement | null | undefined;
}) {
  const [labels, setLabels] = useState<Record<string, string>>(() => Object.fromEntries(columns.map((c) => [c.key, c.label])));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const first = useRef<HTMLInputElement>(null);
  const defaultOf = (k: string) => defaults.find((d) => d.key === k)?.label ?? k;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local = checkLabels(labels);
    setErrors(local);
    if (Object.keys(local).length) {
      document.getElementById(`cl-${Object.keys(local)[0]}`)?.focus();
      return;
    }
    setBusy(true);
    const res = await saveColumnLabels(url, Object.fromEntries(Object.entries(labels).map(([k, v]) => [k, v.trim()])));
    setBusy(false);
    if (res.ok) onSaved();
    else setErrors(res.errors);
  };

  return (
    <Sheet label="Column labels" className="colsheet" onClose={onClose} initialFocus={first} restoreFocus={restoreFocus}>
      <h3>Column labels</h3>
      <p className="sub2">Rename this board’s columns. Tickets stay where they are; your bot still uses the same columns.</p>
      <form className="sheetb fform" onSubmit={submit} noValidate>
        {errors.form && <p className="err-line" role="alert">✕ {errors.form}</p>}
        {columns.map((c, i) => {
          const err = errors[c.key];
          const changed = (labels[c.key] ?? '').trim() !== defaultOf(c.key);
          return (
            <div className="clrow" key={c.key}>
              <label className="flabel" htmlFor={`cl-${c.key}`}><span className={`dot c-${c.key}`} aria-hidden="true" />Column {i + 1}</label>
              <input ref={i === 0 ? first : undefined} id={`cl-${c.key}`} className="qin" value={labels[c.key] ?? ''} maxLength={LABEL_MAX} autoComplete="off"
                aria-invalid={err ? true : undefined} aria-describedby={`cl-${c.key}-h${err ? ` cl-${c.key}-e` : ''}`}
                onChange={(e) => setLabels((cur) => ({ ...cur, [c.key]: e.target.value }))} />
              <p className="fhint" id={`cl-${c.key}-h`}>{changed ? <>Default: {defaultOf(c.key)}</> : 'Default name'}</p>
              {err && <p className="err-line" id={`cl-${c.key}-e`}>✕ {err}</p>}
            </div>
          );
        })}
        <div className="qact">
          <button className="btn" type="submit" disabled={busy}>{busy ? <><span className="spin" />Saving…</> : 'Save column labels'}</button>
          <button className="abtn" type="button" onClick={onClose}>Cancel</button>
          <button className="lnk" type="button" disabled={busy} onClick={() => { setLabels(Object.fromEntries(defaults.map((d) => [d.key, d.label]))); setErrors({}); }}>Use default names</button>
        </div>
      </form>
    </Sheet>
  );
}
