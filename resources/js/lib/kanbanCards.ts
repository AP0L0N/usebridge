// Kanban card density: the board header's "Simple" | "Detailed" control (title + one status dot vs the full card).
import type { Ticket } from './kanban.ts';

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

export type CardDot = 'blocked' | 'new' | 'waiting' | 'pickup';

/**
 * The single status dot of a simple card, from the same signals (and colours) the detailed card shows. Most urgent wins:
 * 1. blocked: the red "Blocked · …" strip (blocked_reason);
 * 2. new: new activity, the "New reply from …" pill (unread agent replies for the viewer);
 * 3. waiting: the ask strip / waiting-age pill (waiting on the owner, not answered yet);
 * 4. pickup: "Your input · not picked up yet" (the owner answered, the PM hasn't picked it up).
 * Null when none applies: no dot, the title keeps the dot's space.
 */
export function cardDot(t: Pick<Ticket, 'status' | 'blocked_reason' | 'unread_for_me' | 'ask' | 'waiting_level' | 'owner_answered_ask' | 'needs_pm_pickup'>): CardDot | null {
  if (t.blocked_reason) {
    return 'blocked';
  }
  if (t.unread_for_me) {
    return 'new';
  }
  if (!t.owner_answered_ask && ((t.status === 'waiting_tilen' && t.ask) || t.waiting_level)) {
    return 'waiting';
  }
  if (t.needs_pm_pickup) {
    return 'pickup';
  }
  return null;
}
