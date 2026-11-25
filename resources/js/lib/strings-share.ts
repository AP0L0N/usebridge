// Public ticket share links: the ticket drawer's "Public links" list and the public read-only page (/s/{token}). Same shape as strings-workstreams.ts.
export const S = {
  // Ticket drawer sidebar (workspace owner only)
  'side.title': 'Public links',
  'side.empty': 'Not shared. A public link lets anyone read this ticket without signing in.',
  'side.loading': 'Loading links…',
  'side.loadErr': 'Couldn’t load the public links.',
  'side.share': 'Share publicly',
  'side.create': 'Create link',
  'side.cancel': 'Cancel',
  'side.save': 'Save',
  'side.copy': 'Copy',
  'side.copied': 'Public link copied',
  'side.copyFallback': 'Copy this link: {url}',
  'side.editExpiry': 'Edit expiry',
  'side.revoke': 'Revoke',
  'side.revokeAsk': 'Revoke this link? It stops working right away.',
  'side.revoked': 'Link revoked',
  'side.comments': 'Visitors can comment',
  'side.expiry': 'Expires',
  'side.expiry.days': 'Days',
  'side.expiry.custom': 'Custom…',
  'side.expiry.never': 'Never expires',
  'side.expiry.option': '{n} days',
  'side.expiry.option1': '1 day',
  'side.err': 'Couldn’t save the link. {message}',
  'side.daysErr': 'Enter a number of days from 1 to {max}.',
  'exp.never': 'Never expires',
  'exp.expired': 'Expired',
  'exp.day': 'Expires within a day',
  'exp.days': 'Expires in {n} days',
  // Board card
  'card.shared': 'Shared publicly',
  // Internal thread
  'thread.public': 'Public',
  'thread.publicTip': 'Posted by a visitor on a public link',
  // Public page
  'pub.description': 'Description',
  'pub.noDescription': 'No description.',
  'pub.comments': 'Comments',
  'pub.noComments': 'No comments yet.',
  'pub.visitor': 'Visitor',
  'pub.form.title': 'Add a comment',
  'pub.form.name': 'Your name',
  'pub.form.body': 'Comment',
  'pub.form.send': 'Post comment',
  'pub.form.sending': 'Posting…',
  'pub.form.sent': 'Thanks, your comment was posted.',
  'pub.form.public': 'Your name and comment are visible to everyone with this link.',
  'pub.form.err': 'Couldn’t post your comment. Try again.',
  'pub.form.limit': 'Too many comments. Try again later.',
} as const;

export type ShareStrKey = keyof typeof S;

export function t(key: ShareStrKey, vars: Record<string, string | number> = {}): string {
  return S[key].replace(/\{(\w+)\}/g, (_, name: string) => String(vars[name] ?? `{${name}}`));
}
