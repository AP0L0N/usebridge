// Kanban board types and helpers (shapes from App\Services\Tickets\TicketView / BoardView, docs/API-TICKETS.md).

export type ColumnKey = 'backlog' | 'todo' | 'in_progress' | 'waiting_tilen' | 'review' | 'done';
export type PmStatus = 'smooth' | 'waiting' | 'archived';
export type Priority = 'p1' | 'p2' | 'p3';
export type LabelColor = 'navy' | 'green' | 'amber' | 'rose' | 'violet' | 'grey';

export const WAITING: ColumnKey = 'waiting_tilen';
export const QUICK_ADD: ColumnKey[] = ['backlog', 'todo'];

export interface Label { id: string; name: string; color: LabelColor }

export interface Ticket {
  id: string;
  key: string;
  project_id: string;
  pm_agent_id: string | null;
  title: string;
  description: string | null;
  status: ColumnKey;
  status_label: string;
  position: number;
  assignee_type: 'agent' | 'human' | null;
  assignee_id: string | null;
  assignee_name: string | null;
  priority: Priority;
  due_on: string | null;
  blocked: boolean;
  blocked_reason: string | null;
  ask: string | null;
  ask_context: string | null;
  ask_options: string[] | null;
  waiting_on_tilen: boolean;
  waiting_since: string | null;
  waiting_age_days: number | null;
  waiting_level: 1 | 2 | 3 | null;
  tilen_input_at: string | null;
  tilen_input_ack_at: string | null;
  needs_pm_pickup: boolean;
  /** Tilen answered the current ask (sent or already picked up); no blank form until the PM posts a new ask. */
  tilen_answered_ask?: boolean;
  labels: Label[];
  comment_count: number;
  last_comment_at: string | null;
  unread_for_tilen: boolean;
  latest_unread_author: { id: string; name: string | null } | null;
  created_by: string | null;
  created_by_type: string | null;
  created_by_name: string | null;
  created_at: string | null;
  updated_at: string | null;
  done_at: string | null;
  archived: boolean;
}

export interface Comment {
  id: number;
  author_type: 'agent' | 'human';
  author_id: string;
  author_name: string | null;
  body: string | null;
  retracted: boolean;
  is_tilen_input: boolean;
  input_choice: string | null;
  picked_up_at: string | null;
  created_at: string | null;
}

export interface TicketEvent {
  id: number;
  type: string;
  from: Record<string, unknown> | null;
  to: Record<string, unknown> | null;
  actor_id: string | null;
  actor_name: string | null;
  source: 'api' | 'ui';
  created_at: string | null;
}

export interface TicketDetail extends Ticket {
  comments: Comment[];
  events: TicketEvent[];
  tilen_last_seen_at: string | null;
}

export interface Column { key: ColumnKey; label: string; order: number; count: number; tickets: Ticket[] }

export interface BoardProject {
  id: string;
  name: string;
  slug: string;
  pm_agent_id: string | null;
  pm_name: string | null;
  ticket_prefix: string;
  /** One line from the create form (web-created projects); null otherwise. */
  description: string | null;
  status: PmStatus | null;
  status_label: string;
  status_note: string | null;
  status_set_by: string | null;
  status_set_by_name: string | null;
  status_set_at: string | null;
  last_activity_at: string | null;
  archived: boolean;
  status_unseen_by_tilen: boolean;
}

export interface Board { project: BoardProject; columns: Column[]; done_since: string; done_older_count: number }

export interface StatusEvent {
  id: string;
  from_status: PmStatus | null;
  to_status: PmStatus;
  to_status_label: string;
  note: string | null;
  set_by: string;
  set_by_name: string | null;
  set_by_type: 'agent' | 'human';
  source: 'api' | 'ui';
  created_at: string | null;
}

export interface Person { id: string; name: string; role: string; type: 'agent' | 'human' }

export interface PortfolioProject {
  id: string;
  name: string;
  slug: string;
  archived: boolean;
  pmStatus: PmStatus | null;
  pmStatusNote: string | null;
  pmStatusSetBy: string | null;
  pmStatusSetByName: string | null;
  pmStatusSetAt: string | null;
  pmLevel: 1 | 2 | 3 | null;
  pmAgeDays: number | null;
  pmName: string | null;
  pmAgentId: string | null;
  /** Where the current status was set: 'api' (an agent token over MCP/REST), 'ui' (the web app), null = unknown/never set. */
  pmStatusSource?: 'api' | 'ui' | null;
  ticketCounts: Record<ColumnKey, number>;
  /** The project's own column names ({key: label}); keys it does not name use the defaults. */
  columnLabels?: Partial<Record<ColumnKey, string>>;
  waitingTilenCount: number;
  lastActivityAt: string | null;
  lastUpdatedAt: string | null;
}

/** What the status form and the archive controls need: the board project, or a portfolio card adapted to it. */
export type StatusSubject = Pick<BoardProject, 'name' | 'slug' | 'status' | 'status_note' | 'status_set_by' | 'status_set_by_name' | 'status_set_at' | 'pm_agent_id' | 'pm_name' | 'archived'>;

export const archiveMessage = (name: string) => `Archive ${name}? It moves to Archived; you can unarchive any time.`;

export const STATUS_LABEL: Record<PmStatus, string> = { smooth: 'Smooth sailing', waiting: 'Waiting input', archived: 'Archived' };

/** "Dr. Janice Polito" → "Janice"; "Captain William Diego" → "William"; "Tilen" → "Tilen". */
export function firstName(name: string | null | undefined): string {
  if (!name) return '';
  const words = name.split(/\s+/).filter((w) => !/^(dr\.?|captain|mr\.?|ms\.?)$/i.test(w));
  return words[0] ?? name;
}

export function initial(name: string | null | undefined): string {
  return (firstName(name) || '?').charAt(0).toUpperCase();
}

/** Role tag in the thread: the project's PM reads "PM", Tilen "Tilen", agents their short role. */
export function roleTag(authorId: string, pmId: string | null, people: Person[]): string {
  if (authorId === pmId) return 'PM';
  const p = people.find((x) => x.id === authorId);
  if (!p || p.type === 'human') return '';
  return p.role.split(/[·/,]/)[0].trim();
}

/** Calendar days from today (Europe/Ljubljana) to a YYYY-MM-DD date; negative when overdue. */
export function daysUntil(date: string, nowIso: string): number {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Ljubljana' }).format(new Date(nowIso));
  return Math.round((Date.parse(`${date}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86400000);
}

/** Due is amber + bold when 48 h or less away, or overdue (SPEC §2). */
export const dueSoon = (date: string, nowIso: string) => daysUntil(date, nowIso) <= 2;

const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function dueLabel(date: string): string {
  const d = new Date(`${date}T12:00:00Z`);
  return `${WD[d.getUTCDay()]} ${d.getUTCDate()} ${MON[d.getUTCMonth()]}`;
}

export const PRIORITY_LABEL: Record<Priority, string> = { p1: 'P1 · High', p2: 'P2 · Medium', p3: 'P3 · Normal' };

/** CSRF for the gate-session JSON endpoints: Laravel's XSRF-TOKEN cookie (fresh), else the meta tag. */
export function csrfHeaders(): Record<string, string> {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (m) return { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) };
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

export class ApiError extends Error {
  constructor(message: string, public status: number, public errors: Record<string, string[]> = {}) {
    super(message);
  }
}

/** JSON write to a Bridge web endpoint (gate session). Throws ApiError with the server's message on failure. */
export async function send<T = unknown>(method: 'POST' | 'PATCH', url: string, body: Record<string, unknown> = {}): Promise<T> {
  let res: Response;
  try {
    res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
      body: JSON.stringify(body),
    });
  } catch {
    throw new ApiError('Network error', 0);
  }
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(json.message ?? 'Something went wrong.', res.status, json.errors ?? {});
  return json as T;
}

export const ticketUrl = (slug: string, key: string) => `/projects/${slug}/tickets/${key}`;

export function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}
