import type { KeyboardEvent, RefObject } from 'react';
import { ANSWER_MAX, COUNTER_FROM } from '../../lib/questions';
import { t } from '../../lib/strings-questions';

/**
 * r4 AnswerField: label + textarea that grows from 72px with its content (CSS field-sizing; older browsers get a
 * resizable field), max 2000 characters, the counter shows from 1800.
 */
export function AnswerField({ id, label, value, onChange, disabled, invalid, describedBy, onKeyDown, inputRef }: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  disabled: boolean;
  invalid: boolean;
  describedBy?: string;
  onKeyDown?: (e: KeyboardEvent<HTMLTextAreaElement>) => void;
  inputRef?: RefObject<HTMLTextAreaElement | null>;
}) {
  const n = value.length;
  return (
    <div className="qp-field">
      <div className="qp-flabel">
        <label className="flabel" htmlFor={id}>{label}</label>
        {n >= COUNTER_FROM && <span className="qp-count" aria-live="polite">{t('q.counter', { n, max: ANSWER_MAX })}</span>}
      </div>
      <textarea ref={inputRef} id={id} className={`qp-ta${invalid ? ' bad' : ''}`} value={value} maxLength={ANSWER_MAX} rows={2}
        placeholder={t('q.placeholder')} disabled={disabled} aria-invalid={invalid || undefined} aria-describedby={describedBy}
        onChange={(e) => onChange(e.target.value)} onKeyDown={onKeyDown} />
    </div>
  );
}
