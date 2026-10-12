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
  'proj.updates.heading': 'Status updates',
  'proj.updates.order': 'Newest first',
  'status.archived': 'Archived',
  'status.smooth': 'Smooth sailing',
  'status.waiting': 'Waiting input',
  'status.waiting.age': '{n}d',
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
