// Ticket references in stored text (App\Support\TicketRefs, docs/API-TICKETS.md "Ticket references"): pure helpers (no
// React), tested in tests/js/ticketRefs.test.ts. A mention of an existing ticket is stored as `[[ticket:<id>|<key>]]`;
// the id resolves, the key after the bar is the fallback text when the ticket is gone.

export const TOKEN = /\[\[ticket:([A-Za-z0-9_-]{1,64})(?:\|([A-Z][A-Z0-9]{0,7}-\d+))?\]\]/g;

export type RefPart = string | { id: string; key: string | null };

/** "See [[ticket:tk-1|BR-9]] first" → ['See ', {id: 'tk-1', key: 'BR-9'}, ' first']. */
export function splitRefs(text: string): RefPart[] {
  const out: RefPart[] = [];
  let last = 0;
  for (const m of text.matchAll(TOKEN)) {
    if (m.index > last) out.push(text.slice(last, m.index));
    out.push({ id: m[1], key: m[2] ?? null });
    last = m.index + m[0].length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

/** Ticket ids referenced in `text`. */
export const refIds = (text: string | null | undefined): string[] => (text ? [...new Set([...text.matchAll(TOKEN)].map((m) => m[1]))] : []);

/** Tokens → plain keys: the current key from `current` when known, else the key in the token, else nothing. */
export function plainRefs(text: string, current?: (id: string) => string | null | undefined): string;
export function plainRefs(text: string | null, current?: (id: string) => string | null | undefined): string | null;
export function plainRefs(text: string | null, current: (id: string) => string | null | undefined = () => null): string | null {
  if (text === null || !text.includes('[[ticket:')) return text;
  return text.replace(TOKEN, (_m, id: string, key: string | undefined) => current(id) ?? key ?? '');
}

/** Markdown link target of a reference (MarkdownBody turns tokens into these links, then renders them as chips). */
export const refHref = (id: string, key: string | null) => `#ticket-ref:${id}:${key ?? ''}`;

export function parseRefHref(href: string | undefined): { id: string; key: string | null } | null {
  const m = href?.match(/^#ticket-ref:([A-Za-z0-9_-]{1,64}):([A-Z0-9-]*)$/);
  return m ? { id: m[1], key: m[2] || null } : null;
}
