# Bridge r1: final UI strings (Marie, 2026-10-12)

Source: `STRINGS.md` (30 keys). Key names are unchanged. Placeholders `{n}`, `{q}`, `{w}`, `{o}` are kept.

| Key | Final (EN) |
|---|---|
| `app.name` | Bridge |
| `app.sample` | Sample data |
| `dash.archived.heading` | Archived |
| `dash.empty.body` | Projects show up here after an agent posts its first update. |
| `dash.empty.title` | No projects yet |
| `dash.noresults.body` | Search covers names, updates and archived projects. Try a shorter word. |
| `dash.noresults.clear` | Clear search |
| `dash.noresults.title` | No projects match “{q}” |
| `dash.reopened` | Reopened |
| `dash.results` | {n} results for “{q}”, including archived |
| `dash.search.placeholder` | Search projects and updates |
| `dash.showArchived` | Show archived |
| `dash.summary` | {w} need input · {o} on track |
| `dash.title` | Portfolio |
| `dash.updated` | Updated |
| `inputs.doneBy` | Provided by |
| `inputs.none.body` | Inputs requested in status updates show up here. |
| `inputs.none.title` | Nothing waiting on anyone |
| `inputs.since` | since |
| `inputs.waitingOn` | Waiting on |
| `proj.back` | ← Portfolio |
| `proj.inputs.doneCount` | done |
| `proj.inputs.heading` | Required inputs |
| `proj.inputs.openCount` | open |
| `proj.updates.heading` | Status updates |
| `proj.updates.order` | Newest first |
| `status.archived` | Archived |
| `status.smooth` | On track |
| `status.waiting` | Needs input |
| `status.waiting.age` | {n}d |

## Notes
- **Status names:** “Smooth sailing” → **On track** (plain, and it scans faster). “Waiting input” → **Needs input** (grammatical, and it says Tilen is the one to act). “Archived” stays. `dash.summary` uses the same words.
- **Reopened:** shortened to “Reopened”. The tag sits on the project card, so “by new update” isn't needed. ⚑ Add a tooltip “Reopened by a new update” if Tommy has room.
- **No results:** the stand-in hardcoded “lisbon”. It's now `{q}`. ⚑ Tommy/dev need to add the placeholder.
- **proj.back:** I added “←” so it reads as navigation. Drop it if the mock already has an icon.
- **Open question:** does “Needs input” always mean input from Tilen? If inputs can be waiting on other people (`inputs.waitingOn`), “Waiting on input” is the safer alternative.
- **Open question:** `status.waiting.age` “{n}d”: confirm it shows next to the badge (e.g. “Needs input · 3d”). Otherwise use “{n} days”.
