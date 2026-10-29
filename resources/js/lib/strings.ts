// Marie's final copy (design/r1/STRINGS.md), Tilen's status names kept.
export const S = {
  'app.name': 'Bridge',
  'dash.archived.heading': 'Archived',
  'dash.empty.body': 'Projects show up here after an agent posts its first update.',
  'dash.empty.title': 'No projects yet',
  'dash.noresults.body': 'Search covers names, updates and archived projects. Try a shorter word.',
  'dash.noresults.clear': 'Clear search',
  'dash.noresults.title': 'No projects match “{q}”',
  'dash.reopened': 'Reopened',
  'dash.reopened.tooltip': 'Reopened by a new update',
  'dash.results': '{n} results for “{q}”, incl. archived',
  'dash.search.placeholder': 'Search projects and updates',
  'dash.showArchived': 'Show archived',
  'dash.summary': '{w} waiting on input · {o} smooth sailing',
  'dash.title': 'Portfolio',
  'dash.updated': 'Updated',
  'inputs.doneBy': 'Provided by',
  'inputs.none.body': 'Inputs requested in status updates show up here.',
  'inputs.none.title': 'Nothing waiting on anyone',
  'inputs.since': 'since',
  'inputs.waitingOn': 'Waiting on',
  'proj.back': '← Portfolio',
  'proj.inputs.doneCount': 'done',
  'proj.inputs.heading': 'Required inputs',
  'proj.inputs.openCount': 'open',
  'proj.next.heading': 'Next steps',
  'proj.next.inProgress': 'In progress',
  'proj.next.blockers': 'Blockers',
  'proj.next.none': 'Nothing here right now',
  'proj.updates.window': 'Last {n} days',
  'proj.updates.more': 'Show 7 more days',
  'proj.updates.emptyWindow': 'No updates in the last {n} days',
  'proj.updates.heading': 'Status updates',
  'proj.updates.order': 'Newest first',
  'status.archived': 'Archived',
  'status.smooth': 'Smooth sailing',
  'status.waiting': 'Waiting input',
  'status.waiting.age': '{n}d',
  // r2 additions, pending Marie's review (aria-label/title + error line).
  'inputs.markDone': 'Mark done',
  'inputs.reopen': 'Reopen',
  'inputs.saveError': 'Couldn’t save that. Try again.',
  // r4 Questions for Tilen (Tommy's mock, design/r4-questions/SPEC.md), pending Marie's review.
  'q.heading': 'Questions for Tilen',
  'q.openCount': 'open',
  'q.answeredCount': 'answered',
  'q.asked': 'asked {t}',
  'q.yes': 'Yes',
  'q.no': 'No',
  'q.skip': 'Skip',
  'q.other': 'Other',
  'q.yourAnswer': 'Your answer',
  'q.save': 'Save answer',
  'q.retry': 'Try again',
  'q.saving': 'Saving…',
  'q.savingStatus': 'Saving your answer…',
  'q.saved': '✓ Saved · sent to {a}',
  'q.error': '✕ Couldn’t save. Check your connection and try again. Your text is kept.',
  'q.answered': 'Answered',
  'q.youAnswered': 'You answered',
  'q.details': 'Details',
  'q.hide': 'Hide',
  'q.change': 'Change answer',
  'q.none.title': 'No open questions',
  'q.none.body': 'Agents will post here when they need a decision from you.',
  'q.badge.one': '{n} open question for you',
  'q.badge.many': '{n} open questions for you',
  // Not in STRINGS.md: Tilen's own additions (r1 approval).
  'proj.archive': 'Archive',
  'proj.unarchive': 'Unarchive',
} as const;

export type StrKey = keyof typeof S;

export function t(key: StrKey, vars: Record<string, string | number> = {}): string {
  return S[key].replace(/\{(\w)\}/g, (_, k: string) => String(vars[k] ?? `{${k}}`));
}

export function ageAria(days: number): string {
  if (days < 1) return 'Waiting input, less than 1 day';
  return `Waiting input, ${days} ${days === 1 ? 'day' : 'days'}`;
}
