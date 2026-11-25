/**
 * Global search: Cmd+K on Mac, Ctrl+K elsewhere, from every signed-in app page (AppTopBar mounts it). Tickets by key or
 * title, projects and workstreams by name (GET /search), grouped; ArrowUp / ArrowDown move, Enter opens, Esc closes.
 * The panel is the Kanban Sheet (scrim, focus trap, Esc), portaled into a "kanban app" host so it looks the same on
 * pages without those roots. Archived items follow the current page's "Show archived" (?archived=1). On the Portfolio a
 * ticket opens in that page's drawer (?ticket=KEY added to the current URL), so closing it leaves the user where they were.
 */
import { Link, router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { IconSearch20 } from '../AppIcons';
import { Sheet } from '../Kanban/Bits';
import { requestJson } from '../../lib/http';
import { ticketUrl } from '../../lib/kanban';
import { EMPTY_RESULTS, isMacPlatform, isSearchShortcut, moveIndex, SEARCH_GROUPS, searchItems, searchTicketHref, showArchivedIn, type SearchGroup, type SearchItem, type SearchResults } from '../../lib/search';
import { t } from '../../lib/strings';

const DEBOUNCE_MS = 150;
/** A ticket opened on the Portfolio: only its drawer loads, the page keeps its state. */
const IN_PLACE = { only: ['ticket'], preserveState: true, preserveScroll: true };

export function GlobalSearch({ url }: { url: string }) {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    const mac = isMacPlatform(navigator.platform);
    const key = (e: KeyboardEvent) => {
      if (!isSearchShortcut(e, mac)) return;
      e.preventDefault();
      setOpen(true);
    };
    document.addEventListener('keydown', key);
    return () => document.removeEventListener('keydown', key);
  }, []);
  useEffect(() => { setOpen(false); }, [url]);
  if (!open) return null;
  return createPortal(<div className="kanban app gsr-host"><SearchSheet onClose={() => setOpen(false)} /></div>, document.body);
}

function SearchSheet({ onClose }: { onClose: () => void }) {
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const [showArchived] = useState(() => showArchivedIn(window.location.search));
  const [tickets] = useState(() => searchTicketHref(window.location.pathname, window.location.search, ticketUrl));
  const [q, setQ] = useState('');
  const [found, setFound] = useState<{ q: string; results: SearchResults } | null>(null);
  const [failed, setFailed] = useState(false);
  const [active, setActive] = useState(-1);
  const seq = useRef(0);
  /** Enter before the results for the typed text arrived: open the first one when they do. */
  const enterPending = useRef(false);

  const term = q.trim();
  const results = found?.results ?? EMPTY_RESULTS;
  const items = searchItems(results, showArchived, tickets.href);
  const visitOf = (item: SearchItem) => (tickets.inPlace && item.group === 'tickets' ? IN_PLACE : {});
  const open = (item: SearchItem | undefined) => {
    if (item === undefined) return;
    onClose();
    router.visit(item.href, visitOf(item));
  };

  useEffect(() => {
    const n = ++seq.current;
    enterPending.current = false;
    setFailed(false);
    if (!term) { setFound(null); setActive(-1); return; }
    const timer = window.setTimeout(async () => {
      const params = new URLSearchParams({ q: term });
      if (showArchived) params.set('archived', '1');
      const r = await requestJson<{ data: SearchResults }>('GET', `/search?${params}`);
      if (n !== seq.current) return;
      if (!r.ok) { setFailed(true); return; }
      const next = searchItems(r.data.data, showArchived, tickets.href);
      if (enterPending.current) { open(next[0]); return; }
      setFound({ q: term, results: r.data.data });
      setActive(next.length ? 0 : -1);
    }, DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [term, showArchived]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (active >= 0) document.getElementById(`${id}-${active}`)?.scrollIntoView({ block: 'nearest' });
  }, [active, id]);

  const onKeyDown = (e: ReactKeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((a) => moveIndex(a, e.key === 'ArrowDown' ? 1 : -1, items.length));
    } else if (e.key === 'Enter' && !e.nativeEvent.isComposing) {
      e.preventDefault();
      if (found?.q === term) open(items[active]);
      else if (term) enterPending.current = true;
    }
  };

  const meta = (text: string | null, archived: boolean) => {
    const parts = [text, archived ? t('search.archived') : null].filter(Boolean);
    return parts.length ? <span className="gsr-m">{parts.join(' · ')}</span> : null;
  };
  const rows: Record<SearchGroup, (i: number) => ReactNode> = {
    tickets: (i) => { const x = results.tickets[i]; return <><span className="gsr-key pf-mono">{x.key}</span><span className="gsr-t">{x.title}</span>{meta(x.project_name, x.archived)}</>; },
    projects: (i) => { const x = results.projects[i]; return <><span className="gsr-t">{x.name}</span>{meta(null, x.archived)}</>; },
    workstreams: (i) => { const x = results.workstreams[i]; return <><span className="gsr-t">{x.name}</span>{meta(x.project_name, x.archived)}</>; },
  };
  const note = failed ? t('search.error') : !term ? t('search.hint') : found?.q === term && items.length === 0 ? t('search.none', { q: term }) : null;

  let offset = 0;
  return (
    <Sheet label={t('search.label')} className="gsr" onClose={onClose} initialFocus={input}>
      <label className={`pf-search gsr-in${q ? ' filled' : ''}`}>
        <IconSearch20 /><span className="sr-only">{t('search.label')}</span>
        <input ref={input} type="text" role="combobox" aria-autocomplete="list" aria-expanded={items.length > 0} aria-controls={`${id}-list`}
          aria-activedescendant={active >= 0 ? `${id}-${active}` : undefined} autoComplete="off" spellCheck={false}
          placeholder={t('search.placeholder')} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onKeyDown} />
      </label>
      {note && <p className="gsr-note" role="status">{note}</p>}
      <div id={`${id}-list`} role="listbox" aria-label={t('search.label')} className="gsr-list">
        {SEARCH_GROUPS.map((g) => {
          const start = offset;
          const count = results[g].length;
          offset += count;
          if (!count) return null;
          return (
            <div key={g} role="group" aria-labelledby={`${id}-${g}`}>
              <h2 id={`${id}-${g}`} className="fd-ov" role="presentation">{t(`search.group.${g}`)}</h2>
              {items.slice(start, start + count).map((item, j) => (
                <Link key={item.id} id={`${id}-${start + j}`} href={item.href} role="option" aria-selected={active === start + j} tabIndex={-1}
                  className="gsr-row" {...visitOf(item)} onMouseMove={() => setActive(start + j)} onClick={onClose}>
                  {rows[g](j)}
                </Link>
              ))}
            </div>
          );
        })}
      </div>
    </Sheet>
  );
}
