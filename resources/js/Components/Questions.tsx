import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { updateTime } from '../lib/dates';
import { t } from '../lib/strings';
import type { AnswerChoice, QuestionView } from '../lib/types';

export type Answer = { choice: AnswerChoice | null; text: string | null };
type SaveFn = (q: QuestionView, a: Answer) => Promise<boolean>;
type Phase = 'idle' | 'saving' | 'saved' | 'error';

const CHOICES: AnswerChoice[] = ['yes', 'no', 'skip', 'other'];
const SAVED_MS = 2000;
const ts = (iso: string | null) => (iso ? Date.parse(iso) : 0);

function Agent({ name }: { name: string }) {
  return <span className="agent"><i aria-hidden="true">{name.trim().charAt(0).toUpperCase() || '?'}</i>{name}</span>;
}

function answerLabel(q: QuestionView): string {
  if (q.type === 'text') return `“${q.answer_text ?? ''}”`;
  if (q.answer_choice === 'other') return `${t('q.other')}: “${q.answer_text ?? ''}”`;
  return q.answer_choice ? t(`q.${q.answer_choice}`) : '';
}

function QuestionForm({ q, now, onSave, onDone }: { q: QuestionView; now: string; onSave: SaveFn; onDone: (id: string) => void }) {
  // Pre-filled when reopened via "Change answer".
  const [choice, setChoice] = useState<AnswerChoice | null>(q.answer_choice);
  const [text, setText] = useState(q.answer_text ?? '');
  const [phase, setPhase] = useState<Phase>('idle');
  const otherRef = useRef<HTMLInputElement>(null);
  const timer = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(timer.current), []);

  const yesno = q.type === 'yesno';
  const needsText = !yesno || choice === 'other';
  const valid = yesno ? choice !== null && (choice !== 'other' || text.trim() !== '') : text.trim() !== '';
  const saving = phase === 'saving';
  const err = phase === 'error';
  const ids = { ctx: `${q.id}-c`, err: `${q.id}-e`, field: `${q.id}-a` };
  const describedBy = [q.context ? ids.ctx : null, err ? ids.err : null].filter(Boolean).join(' ') || undefined;

  const pick = (c: AnswerChoice) => {
    setChoice(c);
    if (phase === 'error') setPhase('idle');
    if (c === 'other') requestAnimationFrame(() => otherRef.current?.focus());
  };
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!valid || saving) return;
    setPhase('saving');
    const ok = await onSave(q, { choice: yesno ? choice : null, text: needsText ? text.trim() : null });
    if (!ok) {
      setPhase('error');
      return;
    }
    setPhase('saved');
    timer.current = window.setTimeout(() => onDone(q.id), SAVED_MS);
  };

  const ctx = q.context && <p className="qctx" id={ids.ctx}>{q.context}</p>;
  const meta = <p className="qmeta"><Agent name={q.asked_by} /><span>{t('q.asked', { t: updateTime(q.created_at, now) })}</span></p>;
  const field = (cls: 'qin' | 'qta') => {
    const common = {
      id: ids.field, className: cls, value: text, disabled: saving,
      'aria-describedby': describedBy, 'aria-invalid': err || undefined,
      onChange: (e: { target: { value: string } }) => { setText(e.target.value); if (err) setPhase('idle'); },
    };
    return cls === 'qin' ? <input ref={otherRef} type="text" maxLength={5000} {...common} /> : <textarea maxLength={5000} {...common} />;
  };

  return (
    <li className={`q${err ? ' err' : ''}`}>
      <form onSubmit={submit} noValidate>
        {yesno ? (
          <fieldset aria-describedby={q.context ? ids.ctx : undefined}>
            <legend>{q.text}</legend>
            {ctx}
            {meta}
            <div className="opts">
              {CHOICES.map((c) => (
                <label className="opt" key={c}>
                  <input type="radio" name={q.id} value={c} checked={choice === c} disabled={saving} onChange={() => pick(c)} />
                  {t(`q.${c}`)}
                </label>
              ))}
            </div>
            {choice === 'other' && (
              <div className="other"><label className="flabel" htmlFor={ids.field}>{t('q.yourAnswer')}</label>{field('qin')}</div>
            )}
          </fieldset>
        ) : (
          <>
            <p className="qt">{q.text}</p>
            {ctx}
            {meta}
            <label className="flabel" htmlFor={ids.field} style={{ display: 'block', marginBottom: 6 }}>{t('q.yourAnswer')}</label>
            {field('qta')}
          </>
        )}
        <div className="qact">
          <button className="btn" type="submit" disabled={!valid || saving} aria-disabled={!valid || saving}>
            {saving && <span className="spin" aria-hidden="true" />}
            {saving ? t('q.saving') : err ? t('q.retry') : t('q.save')}
          </button>
          {err ? (
            <span className="qstat err" id={ids.err} role="alert">{t('q.error')}</span>
          ) : (
            <span className={`qstat${saving ? ' busy' : phase === 'saved' ? ' ok' : ''}`} role="status" aria-live="polite">
              {saving ? t('q.savingStatus') : phase === 'saved' ? t('q.saved', { a: q.asked_by }) : ''}
            </span>
          )}
        </div>
      </form>
    </li>
  );
}

function AnsweredItem({ q, now, onChange }: { q: QuestionView; now: string; onChange: (id: string) => void }) {
  return (
    <li className="q done">
      <details>
        <summary id={`${q.id}-s`}>
          <span className="tick" aria-hidden="true">✓</span>
          <span>
            <span className="qt"><s>{q.text}</s></span>
            <p className="ans">{t('q.youAnswered')} <b>{answerLabel(q)}</b> · {updateTime(q.answered_at, now)} · {q.asked_by}</p>
          </span>
          <span className="chev"><span className="cd">{t('q.details')}</span><span className="ch">{t('q.hide')}</span></span>
        </summary>
        <div className="dbody">
          {q.context && <p>{q.context}</p>}
          <p><button className="abtn" type="button" onClick={() => onChange(q.id)}>{t('q.change')}</button></p>
        </div>
      </details>
    </li>
  );
}

/** r4 "Questions for Tilen" (always rendered): open questions oldest first, answered (last 2 days) newest first, collapsed. */
export function Questions({ questions, now, onSave }: { questions: QuestionView[]; now: string; onSave: SaveFn }) {
  // Ids kept in the open list while saving / just saved / being changed, even if already answered.
  const [hold, setHold] = useState<Record<string, true>>({});
  const keep = (id: string) => setHold((h) => ({ ...h, [id]: true }));
  const release = (id: string) => {
    setHold((h) => { const n = { ...h }; delete n[id]; return n; });
    requestAnimationFrame(() => document.getElementById(`${id}-s`)?.focus());
  };

  const open = questions.filter((q) => q.state === 'open' || hold[q.id]).sort((a, b) => ts(a.created_at) - ts(b.created_at));
  const answered = questions.filter((q) => q.state === 'answered' && !hold[q.id]).sort((a, b) => ts(b.answered_at) - ts(a.answered_at));
  const openCount = questions.filter((q) => q.state === 'open').length;

  const save: SaveFn = (q, a) => { keep(q.id); return onSave(q, a); };

  return (
    <section className="qs" aria-labelledby="h-q">
      <div className="qhead"><h2 id="h-q">{t('q.heading')}</h2></div>
      <p className="sub"><b>{openCount}</b> {t('q.openCount')} · {questions.length - openCount} {t('q.answeredCount')}</p>
      {open.length === 0 ? (
        <div className="qempty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="m7.5 12.5 3 3 6-6" /></svg>
          <div><b>{t('q.none.title')}</b><p>{t('q.none.body')}</p></div>
        </div>
      ) : (
        <ul className="qlist">
          {open.map((q) => <QuestionForm key={q.id} q={q} now={now} onSave={save} onDone={release} />)}
        </ul>
      )}
      {answered.length > 0 && (
        <>
          <p className="donesect">{t('q.answered')} <span className="cnt">{answered.length}</span></p>
          <ul className="qlist">
            {answered.map((q) => <AnsweredItem key={q.id} q={q} now={now} onChange={keep} />)}
          </ul>
        </>
      )}
    </section>
  );
}

export function QuestionBadge({ n }: { n: number }) {
  if (n <= 0) return null;
  return (
    <span className="qbadge" aria-label={t(n === 1 ? 'q.badge.one' : 'q.badge.many', { n })}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" aria-hidden="true"><path d="M9 9a3 3 0 1 1 4 2.8c-.6.3-1 .9-1 1.6V14" /><circle cx="12" cy="18" r=".6" fill="currentColor" /></svg>
      {n}
    </span>
  );
}
