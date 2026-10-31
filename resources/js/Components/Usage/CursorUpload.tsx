import { router } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { updateTime } from '../../lib/dates';
import { requestJson } from '../../lib/http';
import { count, fmt } from '../../lib/usage';

type DayResult = { date: string; state: string; calls: number; total: number; stored_calls: number | null };
type ImportResult = {
  from: string; to: string; rows: number; total_tokens: number;
  days_imported: number; days_zero: number; days_kept: number; days: DayResult[];
};

/**
 * Cursor CSV upload (workspace owner; individual plan, docs/MULTI-USER-PLAN.md §7): POST /usage/cursor-csv multipart
 * `file`. Shows the import summary incl. partial edge days kept, or the 422 errors with their line numbers.
 */
export function CursorUpload({ lastImportAt }: { lastImportAt: string | null }) {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [errors, setErrors] = useState<string[] | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const now = new Date().toISOString();

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const file = input.current?.files?.[0];
    if (!file) {
      setResult(null);
      setErrors(null);
      setMessage('Choose the CSV file you exported from Cursor first.');
      input.current?.focus();
      return;
    }
    const body = new FormData();
    body.append('file', file);
    setBusy(true);
    setMessage(null);
    const res = await requestJson<{ data?: ImportResult }>('POST', '/usage/cursor-csv', body);
    setBusy(false);
    if (res.ok && res.data.data) {
      setResult(res.data.data);
      setErrors(null);
      if (input.current) input.current.value = '';
      router.reload({ only: ['cli'] });
      return;
    }
    setResult(null);
    if (res.status === 422) {
      setMessage(res.data.message ?? 'The Cursor CSV is invalid; nothing was imported.');
      // keys are "line 12" (one per bad row, at most 20), "header" or "file"
      setErrors(Object.entries(res.data.errors ?? {}).flatMap(([k, msgs]) => msgs.map((m) => (/^line \d+$/.test(k) ? `Line ${k.slice(5)}: ${m}` : m))));
    } else {
      setErrors(null);
      setMessage(res.status === 429 ? 'Too many uploads. Try again in an hour.' : res.status === 413 ? 'The file is too large (10 MB at most).' : res.data.message ?? 'The upload failed.');
    }
  };

  const kept = result?.days.filter((d) => d.state.startsWith('kept')) ?? [];
  return (
    <form className="upl" onSubmit={submit} noValidate aria-labelledby="h-cur-upl">
      <h3 id="h-cur-upl">Cursor CSV upload</h3>
      <ol className="steps">
        <li><p>Open <a href="https://cursor.com/dashboard" target="_blank" rel="noreferrer">cursor.com/dashboard</a> and go to <b>Usage</b>.</p></li>
        <li><p>Choose the range (overlapping earlier uploads is fine), then <b>Export CSV</b>.</p></li>
        <li><p>Upload the file here. It counts as one account, “Cursor (all agents)”.</p></li>
      </ol>
      <div className="row">
        <label className="sr-only" htmlFor="cur-file">Cursor usage CSV</label>
        <input ref={input} id="cur-file" type="file" name="file" accept=".csv,text/csv" disabled={busy} aria-describedby="cur-file-h" />
        <button className="btn" type="submit" disabled={busy}>{busy ? <><span className="spin" aria-hidden="true" />Importing…</> : 'Import CSV'}</button>
      </div>
      <p className="fhint" id="cur-file-h">
        CSV, up to 10 MB. The first and last day of an export are often partial: a smaller partial day never replaces a fuller one already imported.
        {lastImportAt && <> Last import: {updateTime(lastImportAt, now)}.</>}
      </p>
      <div aria-live="polite">
        {busy && <p className="sr-only">Importing the Cursor CSV, please wait.</p>}
        {result && (
          <div className="callout ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 5-6" /></svg>
            <div>
              <p><span className="t">Imported {result.from === result.to ? result.from : `${result.from} to ${result.to}`}.</span></p>
              <dl>
                <dt>Rows</dt><dd>{count(result.rows)}</dd>
                <dt>Tokens</dt><dd>{fmt(result.total_tokens)}</dd>
                <dt>Days with usage</dt><dd>{count(result.days_imported)}</dd>
                <dt>Days with zero</dt><dd>{count(result.days_zero)}</dd>
                <dt>Kept (partial edge day)</dt><dd>{count(result.days_kept)}{kept.length > 0 && <> · {kept.map((d) => `${d.date}: stored ${d.stored_calls ?? 0} requests, file ${d.calls}`).join('; ')}</>}</dd>
              </dl>
            </div>
          </div>
        )}
      </div>
      {message && (
        <div className="callout bad" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5M12 16.5h.01" /></svg>
          <div>
            <p><span className="t">{message}</span></p>
            {errors && errors.length > 0 && <ul className="lines">{errors.map((e, i) => <li key={i}>{e}</li>)}</ul>}
          </div>
        </div>
      )}
    </form>
  );
}
