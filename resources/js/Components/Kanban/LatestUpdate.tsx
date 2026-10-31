import { useLayoutEffect, useRef, useState } from 'react';
import { MarkdownBody } from '../MarkdownBody';
import { updateTime } from '../../lib/dates';
import type { LatestUpdate as Update } from '../../lib/kanban';

/**
 * Go-live N1: the newest status update an agent posted (POST /api/status-updates, MCP post_status), under the
 * project status. Clamped to a few lines with "Show more" when it is longer. Hidden when there is none.
 */
export function LatestUpdate({ update: u, now }: { update: Update | null; now: string }) {
  const body = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [long, setLong] = useState(false);
  useLayoutEffect(() => {
    const el = body.current;
    if (el && !open) setLong(el.scrollHeight > el.clientHeight + 2);
  }, [u?.id, u?.body, open]);
  if (!u) return null;
  const who = u.agent_name ?? u.agent_id;
  return (
    <section className="plu" aria-label="Latest status update">
      <p className="plu-h">
        <span className="plu-k">Latest update</span>
        <span className="plu-by"><span className="agentdot" aria-hidden="true" /><b>{who}</b></span>
        {u.created_at && <time dateTime={u.created_at}>{updateTime(u.created_at, now)}</time>}
        {u.count > 1 && <span className="plu-n">{u.count} updates</span>}
      </p>
      <div ref={body} id={`plu-${u.id}`} className={`plu-b${open ? ' open' : ''}`}><MarkdownBody content={u.body} /></div>
      {(long || open) && (
        <button type="button" className="lnk plu-more" aria-expanded={open} aria-controls={`plu-${u.id}`} onClick={() => setOpen(!open)}>
          {open ? 'Show less' : 'Show more'}
        </button>
      )}
    </section>
  );
}
