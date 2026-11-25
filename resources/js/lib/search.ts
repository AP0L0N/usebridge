// Global search (Cmd+K on Mac, Ctrl+K elsewhere; GET /search): types and pure helpers (no React), tested in
// tests/js/search.test.ts. The server ranks and caps each group; this file only flattens the groups for the keyboard.
import { boardUrl, finderUrl } from './workstreams.ts';

export interface SearchTicket { id: string; key: string; title: string; project_slug: string; project_name: string; archived: boolean }
export interface SearchProject { id: string; slug: string; name: string; archived: boolean }
export interface SearchWorkstream { id: string; name: string; project_slug: string; project_name: string; archived: boolean }
export interface SearchResults { tickets: SearchTicket[]; projects: SearchProject[]; workstreams: SearchWorkstream[] }

export type SearchGroup = keyof SearchResults;
/** Group order in the modal (and for ArrowUp / ArrowDown). */
export const SEARCH_GROUPS: readonly SearchGroup[] = ['tickets', 'projects', 'workstreams'];

export const EMPTY_RESULTS: SearchResults = { tickets: [], projects: [], workstreams: [] };

/** One row of the modal: where Enter / a click goes. */
export interface SearchItem { group: SearchGroup; id: string; href: string; archived: boolean }

/** Mac-like platform (navigator.userAgentData?.platform ?? navigator.platform): Cmd is the shortcut's modifier there. */
export const isMacPlatform = (platform: string | null | undefined) => /mac|iphone|ipad|ipod/i.test(platform ?? '');

type ShortcutEvent = Pick<KeyboardEvent, 'key' | 'metaKey' | 'ctrlKey' | 'altKey' | 'shiftKey'>;

/** Cmd+K on Mac, Ctrl+K elsewhere; no other modifier. */
export function isSearchShortcut(e: ShortcutEvent, mac: boolean): boolean {
  if (e.key.toLowerCase() !== 'k' || e.altKey || e.shiftKey) return false;
  return mac ? e.metaKey && !e.ctrlKey : e.ctrlKey && !e.metaKey;
}

/** "Show archived" is the Portfolio's `archived=1` URL flag (not stored anywhere else): the search uses the current page's. */
export const showArchivedIn = (search: string) => new URLSearchParams(search).get('archived') === '1';

/** The Portfolio's paths ("/" and /portfolio render the same page). */
const isPortfolioPath = (pathname: string) => pathname === '/' || pathname === '/portfolio';

/**
 * Where a ticket result goes from the page at `pathname` + `search`. On the Portfolio it stays there and opens the
 * ticket in the page's drawer: the current URL with `ticket` set (project, ws, q, archived… kept). Anywhere else it is
 * `elsewhere` (kanban.ts' ticketUrl, the ticket on its own board).
 */
export function searchTicketHref(pathname: string, search: string, elsewhere: (slug: string, key: string) => string): { href: (slug: string, key: string) => string; inPlace: boolean } {
  if (!isPortfolioPath(pathname)) return { href: elsewhere, inPlace: false };
  return {
    inPlace: true,
    href: (_slug, key) => {
      const u = new URLSearchParams(search);
      u.set('ticket', key);
      return `${pathname}?${u}`;
    },
  };
}

/**
 * Rows in display order (Tickets, Projects, Workstreams), with the existing URLs: a ticket opens on its board
 * (`ticketHref`: kanban.ts' ticketUrl, /projects/{slug}/tickets/{key}; searchTicketHref on the Portfolio), a project in
 * the Portfolio Finder (keeping "Show archived"), a workstream's board.
 */
export function searchItems(r: SearchResults, showArchived: boolean, ticketHref: (slug: string, key: string) => string): SearchItem[] {
  return [
    ...r.tickets.map((x): SearchItem => ({ group: 'tickets', id: x.id, href: ticketHref(x.project_slug, x.key), archived: x.archived })),
    ...r.projects.map((x): SearchItem => ({ group: 'projects', id: x.id, href: finderUrl(x.slug, '', showArchived), archived: x.archived })),
    ...r.workstreams.map((x): SearchItem => ({ group: 'workstreams', id: x.id, href: boardUrl(x.project_slug, x.id), archived: x.archived })),
  ];
}

/** ArrowDown (+1) / ArrowUp (-1) from `current`, wrapping around; -1 when there is nothing to select. */
export function moveIndex(current: number, delta: 1 | -1, count: number): number {
  if (count <= 0) return -1;
  if (current < 0) return delta > 0 ? 0 : count - 1;
  return (current + delta + count) % count;
}
