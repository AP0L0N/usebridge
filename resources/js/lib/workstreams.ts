// Portfolio Finder + board workstreams: shared types and pure helpers (no React), tested in tests/js/workstreams.test.ts.
import type { PortfolioProject } from './kanban.ts';
import { t, tn } from './strings-workstreams.ts';

/** One workstream row (Finder column 2, board tabs), from Workstreams::rowsFor. */
export interface WorkstreamRow {
  id: string;
  name: string;
  isDefault: boolean;
  tickets: number;
  forYou: number;
  open: number;
  /** Open tickets with a blocked reason (the board column's header counts while it loads). */
  blocked: number;
  allDone: boolean;
  /** Changes since this user last opened the board (0 = hidden). */
  badge: number;
}

export interface FinderProject extends PortfolioProject {
  archivedAt?: string | null;
  createdAt?: string | null;
  workstreams: WorkstreamRow[];
  /** Any non-archived workstream has a badge (the project's unread dot). */
  hasChanges: boolean;
}

export type ChangeType = 'created' | 'moved' | 'commented' | 'assigned' | 'workstream';

export interface Change {
  id: number;
  type: ChangeType;
  workstreamId: string;
  workstream: string;
  actor: string;
  ticketId: string | null;
  key: string | null;
  title: string | null;
  column: string | null;
  assignee: string | null;
  fromWorkstream: string | null;
  at: string | null;
}

export interface Preview { slug: string; total: number; workstreams: number; changes: Change[] }

export const BADGE_CAP = 99;
export const NAME_MAX = 60;

/** Badge text: hidden at 0, "99+" above the cap. */
export function badgeText(n: number): string | null {
  if (n <= 0) return null;
  return n > BADGE_CAP ? `${BADGE_CAP}+` : String(n);
}

export const isArchived = (p: Pick<PortfolioProject, 'archived' | 'pmStatus'>) => p.archived || p.pmStatus === 'archived';

/** Stream meta: "3 tickets · 1 for you" (done tickets not counted) · "all done". */
export function streamMeta(w: Pick<WorkstreamRow, 'open' | 'forYou' | 'allDone'>): string {
  if (w.allDone) return t('fd.ws.allDone');
  const parts = [tn(w.open, 'fd.ws.tickets.one', 'fd.ws.tickets.many')];
  if (w.forYou > 0) parts.push(t('fd.ws.forYou', { n: w.forYou }));
  return parts.join(' · ');
}

/**
 * Column 1's lists. Search matches project and workstream names (case-insensitive). "Show archived" off: archived
 * projects are hidden, also from search results; on: they show in their own section, search included.
 */
export function filterProjects<P extends Pick<FinderProject, 'name' | 'archived' | 'pmStatus' | 'workstreams'>>(projects: P[], q: string, showArchived: boolean): { active: P[]; archived: P[] } {
  const n = q.trim().toLowerCase();
  const hits = n ? projects.filter((p) => p.name.toLowerCase().includes(n) || p.workstreams.some((w) => w.name.toLowerCase().includes(n))) : projects;
  return { active: hits.filter((p) => !isArchived(p)), archived: showArchived ? hits.filter(isArchived) : [] };
}

/** Workstreams shown for a project: every one when the project name matches or there is no query, else only matching ones. */
export function filterWorkstreams(p: Pick<FinderProject, 'name' | 'workstreams'>, q: string): WorkstreamRow[] {
  const n = q.trim().toLowerCase();
  if (!n || p.name.toLowerCase().includes(n)) return p.workstreams;
  return p.workstreams.filter((w) => w.name.toLowerCase().includes(n));
}

/** Count of OTHER workstreams with a badge (the phone switcher's "N others" dot). */
export const othersWithChanges = (rows: WorkstreamRow[], currentId: string) => rows.filter((w) => w.id !== currentId && w.badge > 0).length;

/** Same name rules as the server (trimmed, 1–60, unique case-insensitive among `taken`). Returns an error code or null. */
export function nameError(name: string, taken: string[] = []): 'required' | 'max' | 'taken' | null {
  const v = name.trim().replace(/\s+/g, ' ');
  if (!v) return 'required';
  if (v.length > NAME_MAX) return 'max';
  if (taken.some((x) => x.toLowerCase() === v.toLowerCase())) return 'taken';
  return null;
}

/** What the delete dialog must ask for (README §3.2). */
export function deleteMode(ws: Pick<WorkstreamRow, 'tickets' | 'isDefault'>, others: number): 'empty' | 'transfer' | 'only' {
  if (others === 0) return 'only';
  return ws.tickets > 0 || ws.isDefault ? 'transfer' : 'empty';
}

/** Board URL of a workstream (`/projects/{slug}?ws={id}`). */
/** Rail initials (WS / Project rail item 84:1081): first letters of the first two words, else the first two letters. */
export function initials(name: string): string {
  const words = name.trim().split(/[\s_-]+/).filter(Boolean);
  const letters = words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? '').slice(0, 2);
  return letters.toUpperCase();
}

/** localStorage key of the user's Projects-column collapse choice ('1' = collapsed to the initials rail). */
export const PROJECTS_RAIL_KEY = 'bridge.portfolio.projectsRail';

/** The saved Projects-column choice: collapsed only when the user collapsed it; open by default or when storage is unavailable. */
export function readProjectsRail(storage: Pick<Storage, 'getItem'> | null | undefined): boolean {
  try {
    return storage?.getItem(PROJECTS_RAIL_KEY) === '1';
  } catch {
    return false;
  }
}

/** Saves the Projects-column choice; a storage that refuses (private mode, quota) just keeps it for this visit. */
export function saveProjectsRail(storage: Pick<Storage, 'setItem' | 'removeItem'> | null | undefined, collapsed: boolean): void {
  try {
    if (collapsed) storage?.setItem(PROJECTS_RAIL_KEY, '1');
    else storage?.removeItem(PROJECTS_RAIL_KEY);
  } catch {
    // ignore
  }
}

export const boardUrl = (slug: string, wsId: string) => `/projects/${slug}?ws=${encodeURIComponent(wsId)}`;

/**
 * Finder URL for a selection (`/portfolio?project={slug}`), keeping search / archived flags. `ws`: the workstream whose
 * board is open as the next column (`&ws={id}`); `ticket`: a ticket open in the page's drawer (`&ticket={key}`: the
 * board's own when it is the selected project's, else TicketRefHost's).
 */
export function finderUrl(slug: string | null, q = '', archived = false, ws: string | null = null, ticket: string | null = null): string {
  const u = new URLSearchParams();
  if (slug) u.set('project', slug);
  if (slug && ws) u.set('ws', ws);
  if (ticket) u.set('ticket', ticket);
  if (q) u.set('q', q);
  if (archived) u.set('archived', '1');
  const s = u.toString();
  return s ? `/portfolio?${s}` : '/portfolio';
}

/** Keyboard model of the Finder: ↑/↓ within a column, →/Enter into the next, ← back; Enter on a workstream opens it. */
export type FinderFocus = { col: 0 | 1; index: number };
export function finderKey(key: string, focus: FinderFocus, lengths: [number, number]): { focus: FinderFocus; open?: boolean } | null {
  const len = lengths[focus.col];
  switch (key) {
    case 'ArrowDown': return len ? { focus: { ...focus, index: Math.min(len - 1, focus.index + 1) } } : null;
    case 'ArrowUp': return len ? { focus: { ...focus, index: Math.max(0, focus.index - 1) } } : null;
    case 'ArrowRight': return focus.col === 0 && lengths[1] ? { focus: { col: 1, index: 0 } } : null;
    case 'Enter': return focus.col === 0 ? (lengths[1] ? { focus: { col: 1, index: 0 } } : null) : { focus, open: true };
    case 'ArrowLeft': return focus.col === 1 ? { focus: { col: 0, index: -1 } } : null;
    default: return null;
  }
}
