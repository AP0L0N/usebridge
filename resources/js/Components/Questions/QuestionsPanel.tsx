import { useCallback, useEffect, useRef, useState } from 'react';
import { useToasts } from '../Kanban/Bits';
import { QuestionCard } from './QuestionCard';
import { QuestionRow } from './QuestionRow';
import { requestJson } from '../../lib/http';
import type { Label } from '../../lib/kanban';
import { questionUrl, type Question, type QuestionsPanelData } from '../../lib/questions';
import { t } from '../../lib/strings-questions';

const HIDDEN_KEY = 'bridge.r4.questions.hidden';
const byAsked = (a: Question, b: Question) => (a.asked_at ?? '').localeCompare(b.asked_at ?? '') || a.ticket_key.localeCompare(b.ticket_key);

function readHidden(slug: string): boolean {
  try { return (JSON.parse(window.localStorage.getItem(HIDDEN_KEY) ?? '[]') as string[]).includes(slug); } catch { return false; }
}
function writeHidden(slug: string, hidden: boolean) {
  try {
    const list = new Set(JSON.parse(window.localStorage.getItem(HIDDEN_KEY) ?? '[]') as string[]);
    if (hidden) list.add(slug); else list.delete(slug);
    window.localStorage.setItem(HIDDEN_KEY, JSON.stringify([...list]));
  } catch { /* private mode: the panel just isn't remembered as hidden */ }
}

/**
 * r4 "Questions for you" (design/r4): between the project's meta row and the board. Hidden only when the project
 * never had a question (has_any false). Open cards (oldest first), "Answered · waiting for pickup" rows, and a
 * collapsed "Closed and dismissed" group. Writes are optimistic; after each one the page reloads only
 * `questions` + `board` (the counts come with them). "Hide panel" is remembered per project (desktop).
 */
export function QuestionsPanel({ data, slug, now, mobile, labelsFor, onDetails, onReload }: {
  data: QuestionsPanelData;
  slug: string;
  now: string;
  mobile: boolean;
  labelsFor: (ticketKey: string) => Label[];
  onDetails: (ticketKey: string) => void;
  onReload: () => void;
}) {
  const toasts = useToasts();
  const [local, setLocal] = useState(data);
  const pending = useRef(0);
  useEffect(() => { if (pending.current === 0) setLocal(data); }, [data]);

  const [hidden, setHidden] = useState(() => readHidden(slug));
  const [closedOpen, setClosedOpen] = useState(false);
  const [changing, setChanging] = useState<string | null>(null);
  const [justSent, setJustSent] = useState<string | null>(null);
  const [restoring, setRestoring] = useState<string | null>(null);
  const rows = useRef(new Map<string, HTMLDivElement>());
  const section = useRef<HTMLElement>(null);
  const collapsed = hidden && !mobile;

  // Portfolio chip links to /projects/{slug}#questions: open the panel and bring it into view.
  useEffect(() => {
    if (window.location.hash !== '#questions') return;
    setHidden(false);
    window.requestAnimationFrame(() => section.current?.scrollIntoView({ block: 'start' }));
  }, []);

  // After a send, focus moves to the question's row in the answered group.
  useEffect(() => {
    if (justSent) rows.current.get(justSent)?.focus({ preventScroll: false });
  }, [justSent]);

  const toggle = () => setHidden((h) => { writeHidden(slug, !h); return !h; });

  const write = useCallback(async (q: Question, action: 'dismiss' | 'restore') => {
    pending.current++;
    const res = await requestJson<{ data: Question }>('POST', questionUrl(slug, q.id, action));
    pending.current--;
    return res;
  }, [slug]);

  const restore = useCallback(async (q: Question) => {
    setRestoring(q.id);
    setLocal((d) => ({ ...d, closed: d.closed.filter((x) => x.id !== q.id), open: [...d.open.filter((x) => x.id !== q.id), { ...q, state: 'open' as const, dismissed_at: null }].sort(byAsked) }));
    const res = await write(q, 'restore');
    setRestoring(null);
    if (!res.ok) toasts.push({ tone: 'err', body: <p>{t('q.toast.restoreErr')}</p> });
    onReload();
  }, [write, toasts, onReload]);

  const dismiss = useCallback(async (q: Question) => {
    const at = new Date().toISOString();
    setLocal((d) => ({ ...d, open: d.open.filter((x) => x.id !== q.id), closed: [{ ...q, state: 'dismissed' as const, dismissed_at: at }, ...d.closed.filter((x) => x.id !== q.id)] }));
    const res = await write(q, 'dismiss');
    if (res.ok) toasts.push({ body: <p>{t('q.toast.dismissed')}</p>, action: { label: t('q.toast.undo'), run: () => { void restore(q); } } });
    else toasts.push({ tone: 'err', body: <p>{t('q.toast.dismissErr')}</p> });
    onReload();
  }, [write, toasts, restore, onReload]);

  const sent = useCallback((q: Question) => {
    setLocal((d) => ({ ...d, open: d.open.filter((x) => x.id !== q.id), answered: [q, ...d.answered.filter((x) => x.id !== q.id)] }));
    setChanging(null);
    setJustSent(q.id);
    onReload();
  }, [onReload]);

  if (!local.has_any) return null;
  const nOpen = local.open.length;
  const cardProps = { slug, now, mobile, onDismiss: dismiss, onSent: sent, onStale: onReload };

  return (
    <section ref={section} id="questions" className={`qp${collapsed ? ' collapsed' : ''}`} aria-labelledby="qp-h">
      <div className="qp-head">
        <div className="qp-titlerow">
          <h2 id="qp-h">{t('q.heading')}</h2>
          <span className="qp-pill">{t('q.openCount', { n: nOpen })}</span>
        </div>
        <p className="qp-sub">{nOpen ? t('q.sub') : t('q.sub.none')}</p>
        {!mobile && (
          <button type="button" className="gbtn qp-toggle" aria-expanded={!collapsed} aria-controls="qp-body" onClick={toggle}>
            {collapsed ? t('q.showPanel') : t('q.hidePanel')}
          </button>
        )}
      </div>

      {!collapsed && (
        <div className="qp-body" id="qp-body">
          {nOpen > 0 ? (
            <div className="qp-grid">
              {local.open.map((q) => <QuestionCard key={q.id} q={q} labels={labelsFor(q.ticket_key)} {...cardProps} />)}
            </div>
          ) : (
            <div className="qp-empty">
              <span className="qp-eico" aria-hidden="true">✓</span>
              <div>
                <p className="qp-et">{t('q.empty.title')}</p>
                <p className="qp-eb">{t('q.empty.body')}</p>
              </div>
            </div>
          )}

          {local.answered.length > 0 && (
            <div className="qp-group">
              <h3 className="qp-gh">
                <span>{mobile ? t('q.group.answered.m') : t('q.group.answered')}</span>
                <span className="qp-gn">{local.answered.length}</span>
              </h3>
              <div className="qp-rows">
                {local.answered.map((q) => (changing === q.id
                  ? <QuestionCard key={q.id} q={q} labels={labelsFor(q.ticket_key)} change onCancel={() => setChanging(null)} {...cardProps} />
                  : <QuestionRow key={q.id} q={q} kind="awaiting" now={now} announce={justSent === q.id} onDetails={onDetails}
                      onChange={() => { setJustSent(null); setChanging(q.id); }}
                      ref={(el) => { if (el) rows.current.set(q.id, el); else rows.current.delete(q.id); }} />))}
              </div>
            </div>
          )}

          {local.closed.length > 0 && (
            <div className="qp-group">
              <h3 className="qp-gh">
                <span>{t('q.group.closed')}</span>
                <span className="qp-gn">{local.closed.length}</span>
                <button type="button" className="gbtn qp-gt" aria-expanded={closedOpen} aria-controls="qp-closed" onClick={() => setClosedOpen((v) => !v)}>
                  {closedOpen ? t('q.group.hide') : t('q.group.show')}
                </button>
              </h3>
              {closedOpen && (
                <div className="qp-rows" id="qp-closed">
                  {local.closed.map((q) => (
                    <QuestionRow key={q.id} q={q} kind={q.state === 'dismissed' ? 'dismissed' : 'closed'} now={now} onDetails={onDetails}
                      busy={restoring === q.id} onRestore={() => { void restore(q); }} />
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </section>
  );
}
