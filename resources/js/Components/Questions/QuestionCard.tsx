import { useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { LabelChip } from '../Kanban/Bits';
import { AnswerField } from './AnswerField';
import { OptionChips } from './OptionChips';
import { updateTime } from '../../lib/dates';
import { requestJson } from '../../lib/http';
import { firstName, type Label } from '../../lib/kanban';
import { initials2, isApple, questionUrl, waitingDays, type Question } from '../../lib/questions';
import { t } from '../../lib/strings-questions';

type Phase = 'idle' | 'invalid' | 'sending' | 'failed';

/** Ticket key chip, label chips, asker (avatar, name, role), "asked {time}" and the waiting age. */
export function QuestionMeta({ q, labels, now, age = true }: { q: Question; labels: Label[]; now: string; age?: boolean }) {
  const who = q.asked_by;
  const days = waitingDays(q.asked_at, now);
  return (
    <div className="qp-meta">
      <span className="lb qp-key">{q.ticket_key}</span>
      {labels.map((l) => <LabelChip key={l.name} label={l} />)}
      <i className={`qp-av${who.type === 'human' ? ' h' : ''}`} aria-hidden="true">{initials2(firstName(who.name))}</i>
      <b className="qp-who">{firstName(who.name)}</b>
      <span className="qp-when">{who.role ? `· ${who.role} · ` : '· '}{t('q.asked', { time: updateTime(q.asked_at, now) })}</span>
      {age && <span className="qp-age"><span className="dotk" aria-hidden="true" />{days < 1 ? '<1d' : `${days}d`}<span className="sr-only"> waiting</span></span>}
    </div>
  );
}

/**
 * r4 QuestionCard: an open question (or "Change answer" on an answered one, prefilled). Send is optimistic: the
 * card shows Sending…, and on 2xx hands the server's question to onSent (the panel moves it to the answered group).
 * Errors keep the values. ⌘/Ctrl+Enter sends.
 */
export function QuestionCard({ q, slug, now, labels, change = false, onSent, onDismiss, onCancel, onStale }: {
  q: Question;
  slug: string;
  now: string;
  labels: Label[];
  /** Unused since QA LOW-1 (the Dismiss button reads "Dismiss" at every width); kept for the panel's shared props. */
  mobile?: boolean;
  /** Change answer: prefilled, Cancel instead of Dismiss. */
  change?: boolean;
  onSent: (q: Question) => void;
  onDismiss: (q: Question) => void;
  onCancel?: () => void;
  /** 409 / 404: the question moved on server-side; the panel reloads. */
  onStale: () => void;
}) {
  const [choice, setChoice] = useState<string | null>(change ? q.answer_choice : null);
  const [text, setText] = useState(change ? (q.answer_text ?? '') : '');
  const [phase, setPhase] = useState<Phase>('idle');
  const [msg, setMsg] = useState<string | null>(null);
  const field = useRef<HTMLTextAreaElement>(null);
  const asker = firstName(q.asked_by.name) || q.asked_by.role || '';
  const base = `qp-${q.id}`;
  const empty = choice === null && text.trim() === '';
  const busy = phase === 'sending';
  const hasOptions = q.options.length > 0;

  const submit = async (e?: FormEvent) => {
    e?.preventDefault();
    if (busy) return;
    if (empty) {
      setPhase('invalid');
      setMsg(t('q.invalid'));
      field.current?.focus();
      return;
    }
    setPhase('sending');
    setMsg(null);
    const res = await requestJson<{ data: Question }>('POST', questionUrl(slug, q.id, 'answer'), { choice, text: text.trim() || null });
    if (res.ok && res.data.data) { onSent(res.data.data); return; }
    if (res.status === 422) {
      const first = Object.values(res.data.errors ?? {})[0]?.[0] ?? res.data.message;
      setPhase('invalid');
      setMsg(first ? `✕ ${first}` : t('q.invalid'));
      return;
    }
    setPhase('failed');
    setMsg(t('q.sendErr'));
    if (res.status === 404 || res.status === 409) onStale();
  };

  const onKey = (e: KeyboardEvent<HTMLElement>) => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); void submit(); }
  };
  const pick = (v: string) => { setChoice(v); if (phase === 'invalid') setPhase('idle'); };
  const type = (v: string) => { setText(v); if (phase === 'invalid' && v.trim()) setPhase('idle'); };

  const bad = phase === 'invalid' || phase === 'failed';
  const hint = phase === 'sending'
    ? <p className="qp-hint" id={`${base}-hint`} role="status">{t('q.sendingStatus', { asker })}</p>
    : phase === 'failed'
      ? <p className="qp-hint err" id={`${base}-hint`} role="alert">{msg}</p>
      : <p className="qp-hint" id={`${base}-hint`}>{empty && phase === 'idle' ? t('q.helper') : `${t('q.postsTo', { ticket: q.ticket_key })} · ${t(isApple() ? 'q.shortcut.mac' : 'q.shortcut.other')}`}</p>;

  return (
    <form className={`qp-card${bad ? ' bad' : ''}`} onSubmit={submit} onKeyDown={onKey} aria-labelledby={`${base}-h`} noValidate>
      <QuestionMeta q={q} labels={labels} now={now} />
      <h3 className="qp-q" id={`${base}-h`}>{q.text}</h3>
      {q.context && <p className="qp-ctx">{q.context}</p>}
      {hasOptions && <OptionChips name={`${base}-opt`} options={q.options} value={choice} onChange={pick} disabled={busy} labelledBy={`${base}-h`} />}
      {q.allow_text && (
        <div className="qp-fieldw">
          <AnswerField id={`${base}-t`} inputRef={field} label={hasOptions ? t('q.noteFor', { asker }) : t('q.yourAnswer')} value={text} onChange={type}
            disabled={busy} invalid={phase === 'invalid'} describedBy={phase === 'invalid' ? `${base}-err` : `${base}-hint`} />
          {phase === 'invalid' && <p className="qp-err" id={`${base}-err`} role="alert">{msg}</p>}
        </div>
      )}
      {!q.allow_text && phase === 'invalid' && <p className="qp-err" id={`${base}-err`} role="alert">{msg}</p>}
      <div className="qp-actions">
        <button type="submit" className={`btn${empty && phase === 'idle' ? ' qp-off' : ''}`} disabled={busy}
          aria-disabled={empty && phase === 'idle' ? true : undefined} aria-describedby={`${base}-hint`}>
          {busy ? <><span className="spin" aria-hidden="true" />{t('q.sending')}</> : phase === 'failed' ? t('q.retry') : t('q.send', { asker })}
        </button>
        {hint}
        {change
          ? <button type="button" className="gbtn" onClick={onCancel} disabled={busy}>{t('q.cancelChange')}</button>
          : (
            <button type="button" className="gbtn" onClick={() => onDismiss(q)} disabled={busy} aria-label={t('q.dismiss.aria')}>
              {t('q.dismiss')}
            </button>
          )}
      </div>
    </form>
  );
}
