// Portfolio Finder + board workstreams: shared types and pure helpers (no React), tested in tests/js/workstreams.test.ts.
import type { PortfolioProject } from './kanban.ts';

/** One workstream row (Finder column 2, board tabs), from Workstreams::rowsFor. */
export interface WorkstreamRow {
  id: string;
  name: string;
  isDefault: boolean;
  tickets: number;
  forYou: number;
  open: number;
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
export const boardUrl = (slug: string, wsId: string) => `/projects/${slug}?ws=${encodeURIComponent(wsId)}`;

/** Finder URL for a selection (`/portfolio?project={slug}`), keeping search / archived flags. */
export function finderUrl(slug: string | null, q = '', archived = false): string {
  const u = new URLSearchParams();
  if (slug) u.set('project', slug);
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
