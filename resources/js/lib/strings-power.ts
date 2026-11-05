// Power Saver copy. Same shape as strings-questions.ts. The contract sentences on the page come from the server.
export const S = {
  'ps.title': 'Power Saver',
  'ps.summary': 'Stop thresholds as a percent of the shared weekly usage limit. Bridge stores them and does not turn anything off.',
  'ps.loading': 'Loading Power Saver',
  'ps.projects.title': 'Projects',
  'ps.projects.sub': 'Every project in this workspace that is not archived. An archived project stops at 0% until it is unarchived, then its saved threshold applies again.',
  'ps.projects.empty.title': 'No projects yet',
  'ps.projects.empty.body': 'Projects show up here after you create one. Each one can have its own threshold.',
  'ps.routines.title': 'Agent routines',
  'ps.routines.sub': 'Scheduled routines from usage files, plus any threshold already saved',
  'ps.routines.empty.title': 'No routines yet',
  'ps.routines.empty.body': 'Routines show up here after a usage file lists them. Ad-hoc runs with no routine are not listed.',
  'ps.col.threshold': 'Stop at',
  'ps.unset': 'No threshold',
  'ps.percent': '{n}%',
  'ps.slider': 'Stop threshold for {name}',
  'ps.number': 'Stop threshold percent for {name}',
  'ps.clear': 'Clear',
  'ps.saving': 'Saving…',
  'ps.saved': 'Saved',
  'ps.error.percent': 'Enter a whole number from 0 to 100, or clear the field for no threshold.',
  'ps.error.save': '✕ Couldn’t save that. Check your connection and try again.',
  'ps.retry': 'Try again',
  'ps.correct': 'Correct the value and save again.',
  'ps.project.agent': 'Owning agent · {name}',
  'ps.project.noAgent': 'No owning agent',
  'ps.project.shared': 'Same agent on other projects · usage is not split',
  'ps.routine.seen': 'Own reported usage',
  'ps.routine.unseen': 'No usage yet · counts as 0%',
  'ps.stop.details': 'How a stop works',
  'ps.stop.undecided': 'No stop action is chosen yet.',
  'ps.stop.locked': 'Locked',
  'ps.stop.proposed': 'Proposed',
  'ps.stop.not_built': 'Not built',
  'ps.stop.rejected': 'Not used',
  'ps.stop.archive_only': 'Archive only',
} as const;

export type PowerStrKey = keyof typeof S;

export function t(key: PowerStrKey, vars: Record<string, string | number> = {}): string {
  return S[key].replace(/\{(\w+)\}/g, (_, name: string) => String(vars[name] ?? `{${name}}`));
}
