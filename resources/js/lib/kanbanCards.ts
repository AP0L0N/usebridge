// Kanban card density: the board header's "Simple" | "Detailed" control (title + one status dot vs the full card).
import type { ColumnKey, Ticket } from './kanban.ts';

export type CardView = 'simple' | 'detailed';

/** localStorage key of the card view, shared by the full-page board and the Portfolio board column ('detailed' = full cards). */
export const CARD_VIEW_KEY = 'bridge.kanban.cardView';

/** The saved card view: Simple by default or when storage is unavailable; Detailed only after the user picked it. */
export function readCardView(storage: Pick<Storage, 'getItem'> | null | undefined): CardView {
  try {
    return storage?.getItem(CARD_VIEW_KEY) === 'detailed' ? 'detailed' : 'simple';
  } catch {
    return 'simple';
  }
}

/** Saves the card view; a storage that refuses (private mode, quota) just keeps it for this visit. */
export function saveCardView(storage: Pick<Storage, 'setItem' | 'removeItem'> | null | undefined, view: CardView): void {
  try {
    if (view === 'detailed') storage?.setItem(CARD_VIEW_KEY, 'detailed');
    else storage?.removeItem(CARD_VIEW_KEY);
  } catch {
    // ignore
  }
}

export type CardDot = 'blocked' | 'new' | ColumnKey;

/**
 * The single status dot of a simple card (README §10.1). Most urgent wins:
 * 1. blocked (risk): the red "Blocked · …" strip (blocked_reason);
 * 2. new (agent): new activity, the "New reply from …" pill (unread agent replies for the viewer);
 * 3. otherwise the ticket's column colour, the same dot as the column header (Backlog ink/400, To do ink/600,
 *    In progress ink/950, Waiting on you signal, Review/QA warn, Done ok).
 */
export function cardDot(t: Pick<Ticket, 'status' | 'blocked_reason' | 'unread_for_me'>): CardDot {
  if (t.blocked_reason) {
    return 'blocked';
  }
  if (t.unread_for_me) {
    return 'new';
  }
  return t.status;
}
