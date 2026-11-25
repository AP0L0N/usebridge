// Prev/next ticket in the open ticket's column: the drawer's arrow buttons and Ctrl+ArrowLeft / Ctrl+ArrowRight.

export type TicketNeighbours = { prev: string | null; next: string | null };

/**
 * The tickets before and after `key` in its column, in the column's display order. Pass the board's *visible*
 * columns (filters applied), so a ticket hidden by a filter is never a neighbour. No wrap at the ends.
 */
export function ticketNeighbours(columns: { tickets: { key: string }[] }[], key: string): TicketNeighbours {
  for (const c of columns) {
    const i = c.tickets.findIndex((t) => t.key === key);
    if (i >= 0) {
      return { prev: c.tickets[i - 1]?.key ?? null, next: c.tickets[i + 1]?.key ?? null };
    }
  }
  return { prev: null, next: null };
}

type NavTarget = { tagName?: string; isContentEditable?: boolean } | null;
type NavKey = { key: string; ctrlKey: boolean; metaKey: boolean; altKey: boolean; shiftKey: boolean; isComposing?: boolean; keyCode?: number; target: unknown };

/** Focus is somewhere you type (input, textarea, select, the comment editor or any contenteditable). */
export function isTypingTarget(target: unknown): boolean {
  const el = target as NavTarget;
  if (!el || typeof el !== 'object') return false;
  return el.isContentEditable === true || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName?.toUpperCase() ?? '');
}

/** -1 / 1 for Ctrl+ArrowLeft / Ctrl+ArrowRight, null when the key is not the shortcut or focus is typing / composing. */
export function ticketNavStep(e: NavKey): -1 | 1 | null {
  if (!e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return null;
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return null;
  if (e.isComposing || e.keyCode === 229 || isTypingTarget(e.target)) return null;
  return e.key === 'ArrowLeft' ? -1 : 1;
}
