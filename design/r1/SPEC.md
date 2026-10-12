# Bridge r1: design spec

Designer: Tommy Suarez · Implementer: Xerxes · Status: awaiting Tilen's approval. Mocks only. All project data is **sample data** drawn from Tilen's real project names. It is not a source of truth.

## 1. Scope
There are only two screens: **Portfolio** (dashboard) and **Project view**. Removed completely: deadlines, phases, goals, milestones, work items, risks, blockers, Ops, Agents and Queue. The old nav strip is gone; the project view's back link is the only navigation.

## 2. Font: Atkinson Hyperlegible (400 / 700 / italic)
- It was designed by the Braille Institute for low-vision readers. Its letterforms are deliberately differentiated: I/l/1, O/0 (slashed zero), rn/m and b/d/p/q. That suits a CEO scanning names, counts and dates quickly, and it fixes Tilen's complaint about the old Syne + IBM Plex mix.
- It reads warmer and more distinctive than Inter, so the app doesn't look like a template.
- One family for everything: no display face, no mono. Self-host the woff2 (OFL licence).
- Scale: h1 40/44 (mobile 30), h2 22, project name 20 (mobile 18), body 17/1.5 (mobile 16), meta 15, small 13. Minimum text size is 13px.

## 3. Tokens
| Token | Value | Use |
|---|---|---|
| --paper | #F4F2EC | page bg |
| --card | #FFFFFF | rows, panels |
| --ink / --ink2 / --ink3 | #17191F / #4A4F5A / #6B6F78 | text (all ≥4.5:1 on card and paper) |
| --line | #E2DED4 | dividers |
| --accent | #1F3A5F | links, focus, toggle on, timeline dots |
| --ok / --ok-bg | #1E6B47 / #E1F2E8 | Smooth sailing |
| --w1 / ink | #F7D94C / #3A2E00 | Waiting, 1 day |
| --w2 / ink | #F28C28 / #2B1400 | Waiting, 2 days |
| --w3 / ink | #B42318 / #FFFFFF | Waiting, 3+ days |
| --arch / --arch-bg | #5B606B / #ECEAE4 | Archived |
Radius: 14 for cards, 12 for inputs, 99 for pills. Spacing grid: 4/8. Max content width 1180 with 40px gutters (16 on mobile).

## 4. Statuses (the only ones)
| Status | Badge | Non-colour cue | Contrast |
|---|---|---|---|
| Smooth sailing | green tint pill | check-circle icon + label | ≈5.9:1 |
| Waiting input, L1 (waited ≥1 day, <2) | yellow | hourglass + label + **1 of 3 pips filled** + „1d" | >10:1 |
| Waiting input, L2 (≥2 days, <3) | orange | hourglass + **2 of 3 pips** + „2d" | ≈7:1 |
| Waiting input, L3 (≥3 days) | red | hourglass + **3 of 3 pips** + „Nd" | ≈6.5:1 |
| Archived | grey pill, muted row | archive-box icon + label | ≈5.6:1 |
- A row's left spine repeats the status colour. That's decorative only; the badge carries the meaning.
- A waiting input younger than 1 day still shows level 1 („1d"). Confirm with Tilen.

### 4a. Status naming option for Tilen
The mocks keep Tilen's names: **Smooth sailing / Waiting input / Archived**. Marie suggested **On track / Needs input** instead, as plainer and quicker to scan. If Tilen switches, change `status.smooth`, `status.waiting` and `dash.summary` together. Marie's caveat: „Needs input“ implies Tilen has to act, but inputs can be waiting on other people, so „Waiting on input“ is the safer form.
- Age: in the badge it's „{n}d“. Where an age stands on its own (tooltip, aria-label), it's „{n} days“ / „1 day“.
- The Reopened chip reads „Reopened“, with a tooltip/title of „Reopened by a new update“.

## 5. Data rules
- **Project status is derived from inputs.** If any input is open, the project is Waiting input, and the level comes from the **oldest** open input's age in whole days since it was raised (`since`). If there are no open inputs, it's Smooth sailing. Archived is a manual flag.
- **Auto-unarchive:** a new status update on an archived project clears `archived`. The row then shows a „Reopened" chip (tooltip „Reopened by a new update") until the next update, or for 7 days, whichever comes first. The chip is subtle: an accent-tint pill next to the name. It is not a status.
- **Required inputs** are aggregated from every update of the project. Each input has `text`, `waitingOn` (person), `since` (datetime), `doneAt?` and `doneBy?`. Open inputs come first, sorted oldest first (most urgent on top). Done inputs come after, crossed out (`<s>`), newest done first, and stay listed.
- **Latest update line** = the first plain-text line of the newest update, with Markdown stripped, clamped to 2 lines.
- **Last updated** is relative under 2 days („Today, 09:12", „Yesterday"), then „N days ago" up to 6 days, then an absolute date („12 Sep 2026"). Times are 24h, Europe/Ljubljana.
- **Dashboard order:** non-archived projects by status severity (L3 › L2 › L1 › Smooth), then by last updated, newest first. Archived projects are only shown with the toggle on, or as search hits, in a separate „Archived (n)" group at the bottom.
- **Search** is case-insensitive substring matching on the project name and all update text, **always including archived projects** whatever the toggle says. Matches are highlighted with `<mark>`. The summary line becomes „{n} results for “{q}”, including archived".
- **Markdown in updates:** CommonMark subset: paragraphs, **bold**, *italic*, ordered and unordered lists, links (open in a new tab, rel=noopener). Sanitise; no raw HTML and no images.

## 6. Components
- **Top bar:** ink background, logo + „Bridge", „Sample data" chip (mocks only, remove in build), user name.
- **Project row:** clickable as a whole (one `<a>`). Desktop grid: `6px spine | 1fr main | 220 badge | 170 updated`. Hover: shadow raises to 0 4px 16px #0001. Focus: 3px accent outline, 2px offset.
- **Search field:** 48px tall, 360 wide desktop, full width mobile. It has an accent border when filled and a clear (×) button.
- **Toggle „Show archived":** a 44×26 switch (`role="switch"`, aria-checked). The whole label is the tap target.
- **Badge:** pill with icon + label (+ pips + age for waiting). Compact variant (13px) inside input items.
- **Update timeline:** vertical line with dots; author bold, time muted, Markdown body. Newest first.
- **Required inputs panel:** sticky on desktop (top 20) with a 6px ink top border. Open item: empty checkbox, bold text, „Waiting on **X** · since D" + compact badge. Done item: green check, struck-through muted text, „Provided by X · D".
- **All-clear block** (no open inputs): green tint, check icon, title + hint.
- **Empty state:** dashed card, round icon, title, body, optional primary button.

## 7. States mocked
- Dashboard: default (mixed), search with an archived hit, archived shown, no results, empty portfolio.
- Project: many updates and inputs (AdriaEdition), no open inputs (Concierge Avant, also showing the Reopened case).
- Not mocked, Xerxes to build: loading (skeleton rows at the same heights) and error (an inline banner above the list with retry).

## 8. Responsive
- **≥ 721px:** layout as described. The project view is a `1fr / 400px` grid with the inputs panel on the right.
- **≤ 720px:** gutters 16. The tools stack (search full width, then toggle). Rows become `spine | stacked name → line → badge → updated`. In the project view, **Required inputs moves above the updates** (order −1) and is not sticky.
- Tap targets ≥ 44px. No horizontal scroll at 390 (verified).

## 9. Accessibility
- Status is never colour-only (see §4). Text contrast meets AA throughout.
- Badges get an `aria-label` and title such as „Waiting input, 4 days" / „1 day".
- The search `<input>` has a visually hidden label. The results summary is an `aria-live="polite"` region.

## 10. Copy
The final strings are in STRINGS.md. The HTML no longer carries `data-stand-in-key`.
