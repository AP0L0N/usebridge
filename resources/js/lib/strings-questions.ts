// r4 Questions panel copy: Marie's final copy (design/r4/COPY-FINAL-marie.md), which wins over SPEC.md / Figma.
// Same shape as strings.ts. Plural pairs are `<key>.one` / `<key>.other`, picked by tc() (Intl.PluralRules).
export const S = {
  'q.heading': 'Questions for you',
  'q.openCount': '{n} open',
  'q.sub': 'Tickets on this project waiting on your answer · oldest first',
  'q.sub.none': 'Nothing waiting on you here right now',
  'q.hidePanel': 'Hide panel',
  'q.showPanel': 'Show',
  // card
  'q.asked': 'asked {time}',
  'q.yourAnswer': 'Your answer',
  'q.noteFor': 'Note for {asker} (optional)',
  'q.placeholder': 'Add a note or write your own answer…',
  'q.send': 'Send to {asker}',
  'q.postsTo': 'Posts to {ticket} as your input',
  'q.shortcut.mac': '⌘↵ to send',
  'q.shortcut.other': 'Ctrl+Enter to send',
  'q.helper': 'Pick an option or write an answer.',
  'q.invalid': '✕ Pick an option or write an answer before sending.',
  'q.sending': 'Sending…',
  'q.sendingStatus': 'Sending your answer to {asker}…',
  'q.retry': 'Try again',
  'q.sendErr': '✕ Couldn’t send. Check your connection and try again. Your answer is kept.',
  'q.answerOverline': 'Your answer',
  'q.sent': '✓ Sent {time} · waiting for {asker} to pick it up',
  'q.change': 'Change answer',
  'q.dismiss': 'Dismiss',
  'q.dismiss.aria': 'Dismiss question',
  'q.restore': 'Restore',
  'q.details': 'Details',
  // Not in the final copy (owner addition, pending Marie): leaves "Change answer" without posting again.
  'q.cancelChange': 'Cancel',
  'q.counter': '{n} / {max}',
  // groups
  'q.group.answered': 'Answered · waiting for pickup',
  'q.group.answered.m': 'Waiting for pickup',
  'q.group.closed': 'Closed and dismissed',
  'q.group.show': 'Show',
  'q.group.hide': 'Hide',
  // rows (closed: the parts that apply, joined with " · ")
  'q.row.answered': 'You answered “{x}”',
  'q.row.pickedUp': 'picked up by {agent}',
  'q.row.closed': 'closed {date}',
  // Not in the final copy (pending Marie): a closed question that was never answered (the ticket moved on).
  'q.row.closedOnly': 'Closed {date}',
  'q.row.dismissed': 'Dismissed by you · {date}',
  // empty
  'q.empty.title': 'No open questions',
  'q.empty.body': 'Agents will post here when a ticket needs your answer. Answered questions move to Closed.',
  // toast
  'q.toast.dismissed': 'Question dismissed',
  'q.toast.undo': 'Undo',
  // Not in the final copy (pending Marie): dismiss / restore failed.
  'q.toast.dismissErr': 'Couldn’t dismiss the question. Try again.',
  'q.toast.restoreErr': 'Couldn’t restore the question. Try again.',
  // portfolio
  'q.portfolio.chip.one': '{n} question for you',
  'q.portfolio.chip.other': '{n} questions for you',
  'q.portfolio.none': 'No open questions',
  'q.portfolio.summary': '{a} active {projects} · {w} waiting input · {questions}',
} as const;

export type QStrKey = keyof typeof S;
type PluralBase<K> = K extends `${infer B}.one` ? B : never;
export type QPluralKey = PluralBase<QStrKey>;

export function t(key: QStrKey, vars: Record<string, string | number> = {}): string {
  return S[key].replace(/\{(\w+)\}/g, (_, k: string) => String(vars[k] ?? `{${k}}`));
}

const rules = new Intl.PluralRules('en');

/** Plural pick (the trans_choice equivalent): `<base>.one` / `<base>.other` by the English plural rules, {n} filled in. */
export function tc(base: QPluralKey, n: number, vars: Record<string, string | number> = {}): string {
  const form = rules.select(n) === 'one' ? 'one' : 'other';
  return t(`${base}.${form}` as QStrKey, { n, ...vars });
}
