# Kanban UI shots (29 Oct 2026)

_Updated 29 Oct, after Tommy's review of 1ae66bd: every shot except the portfolio pair and the mobile ticket sheets was reshot with
`tests/browser/shoot-kanban.mjs` (a copy of the earlier shoot script, now in the repo, with the new states added). The header
badge gained a chevron, so all board shots changed. `portfolio-1440/390.png`, `ticket-390*.png` and `states-new-divider-390.png`
are unchanged._

Shot from a **local** server only: a throwaway sqlite DB in `/tmp`, seeded with `database/seeders/KanbanSampleSeeder.php`
(SAMPLE content mirroring Tommy's mocks; it refuses to run outside `local`/`testing`), `npm run build`, `php -S` on a free
port, and playwright-core + Chrome at 1440×1000 and 390×844 (DPR 1). Full-page shots unless noted. For dialogs the viewport
is stretched to the dialog's height, so the whole modal or sheet is in one image. Targets are in `/workspace/bridge-kanban/png/`.

The real clock was Thu 29 Oct 2026, about 09:00 CET, the same day as the mocks, so dates read like the mocks. Ages are computed live.

## Shots

| Shot | Compares to | What it shows |
|---|---|---|
| `portfolio-1440.png`, `portfolio-390.png` | `portfolio-1440/390.png` | Card grid with Show archived on (`/?archived=1`) |
| `board-1440.png`, `board-390.png` | `board-1440/390.png` | Concierge Avant board. Mobile opens on the Waiting on Tilen tab |
| `ticket-1440.png` | `ticket-1440.png` | CA-115 deep link: modal over the board, Your input box with the first option and a note filled in |
| `ticket-390.png`, `ticket-390-viewport.png` | `ticket-390.png`, `ticket-390-viewport.png` | The same ticket as a full-screen sheet (full height, then the first viewport) |
| `states-toast-1440/390.png` | `states-*` §3 | First open after the PM changed the status: the dark toast, once (viewport) |
| `states-history-1440/390.png` | `states-*` §3 | Status history: popover on desktop, sheet on mobile (viewport) |
| `states-drag-1440.png` | `states-1440` §4 | Mouse drag of CA-124 over In progress: lifted card, navy ring + `--drop` fill on the target, dashed placeholder (viewport) |
| `states-keyboard-1440.png` | — (keyboard model, SPEC §4) | CA-129 picked up with Space, then → and ↑: the card previews in In progress with the target ring (viewport) |
| `states-move-sheet-390.png` | `states-390` §4 | "Move CA-124 to…" sheet (viewport) |
| `states-ask-1440.png` | Tommy review c | CA-131 dragged onto Waiting on Tilen: the ask dialog, "What’s the ask?", the title subline and the Enter hint with 0/280 (viewport) |
| `states-ask-390.png` | Tommy review c1 | The same ask as the second step of CA-131's Move sheet: back arrow, full-width Move then Cancel (viewport) |
| `states-ask-error-1440/390.png` | Tommy review c3 | Move with an empty ask: red border, focus back in the field, "✕ Add the ask. Every ticket in Waiting on Tilen needs one." (viewport) |
| `states-status-menu-1440/390.png` | Tommy review a | Status menu: under the whole status card on desktop, a sheet on mobile. The checked radio has focus, the note is empty with "Janice’s note: …" above it, and Save is disabled (viewport) |
| `states-status-error-1440/390.png` | Tommy review a7 | Save fails (500 injected in the browser): "✕ Couldn’t save the status. Your note is kept.", and the form stays open with the note (viewport) |
| `states-filter-1440/390.png` | Tommy review b | Filters: a popover under Filter on desktop, the "Filter tickets" sheet at 390. Assignee has focus (viewport) |
| `states-filter-result-1440/390.png` | Tommy review b4/b5 | "New replies for you" on: only CA-118 is left. At 390 the board jumps to In progress, empty tabs show a grey 0 and the icon-only Filter button shows a count badge |
| `states-move-undo-390.png` | — (SPEC §4) | After a Move-sheet move: the toast with Undo (viewport) |
| `states-move-error-1440.png` | — (SPEC §4) | A move whose request fails (500 injected in the browser): the card is back in Backlog, error toast "Couldn’t move CA-133. Try again." (viewport) |
| `states-input-sent-1440/390.png` | `states-*` §5 | After "Send to Janice": SENT box, and the Tilen's-input bubble with the amber ack line |
| `ticket-after-ack-1440/390.png` | — (QA M1) | CA-115 after the PM acks Tilen's input while it stays in Waiting on Tilen: "You answered at …" with his answer and "Picked up by Janice", not a blank form; "Answer again" is secondary |
| `states-new-divider-1440/390.png` | — (SPEC §2 thread) | CA-118 opened from the board: the unread reply from Erin sits under the navy New divider (viewport) |

## Checks (every shot)

Same checks as `/workspace/bridge-kanban/shoot.mjs`: horizontal overflow, clipped text, Atkinson Hyperlegible loaded,
and interactive targets under 32px.

Added for this round: **Overlay edge**, meaning no element inside a sheet, popover, dialog or toast ends past the viewport's right edge.

| Shot | Overflow | Overlay edge | Clipped text | Font | Targets < 32px |
|---|---|---|---|---|---|
| `states-status-menu-1440/390` | 0 | none | none | loaded | none |
| `states-status-error-1440/390` | 0 | none | none | loaded | none |
| `states-filter-1440/390` | 0 | none | none | loaded | none |
| `states-filter-result-1440/390` | 0 | none | none | loaded | none |
| `states-ask-1440/390`, `states-ask-error-1440/390` | 0 | none | none | loaded | none |
| `states-move-sheet-390`, `states-move-undo-390`, `states-move-error-1440` | 0 | none | none | loaded | none |
| `board-1440/390`, `states-toast-*`, `states-history-*`, `states-drag-1440`, `states-keyboard-1440`, `ticket-*`, `states-input-sent-*`, `states-new-divider-*`, `portfolio-*` | 0 | none | none | loaded | none |

`states-move-undo-390.png` and `states-move-error-1440.png` are now taken by `shoot-kanban.mjs` as well, so they get the same checks.

The checker skips three elements that predate this work and are unchanged: the TopBar brand link (30px), the
"← All projects" back link (24–26px, an inline text link) and the search `<input>`, which sits inside its 48px label.
It also skips `.sr-only`, which clips by design.

`tests/browser/kanban-review.mjs` checks Tommy's review at 1440 and 390 (filters, focus return, error copy, ask step). It passes.

Flows run against the local server (all passed):
- Card click opens the modal; the URL changes to `/projects/concierge-avant/tickets/CA-118`. Focus goes to the title and stays trapped. Esc and browser Back both close it, and focus returns to the card at 1440 and 390.
- Keyboard: Space, →, ↑, Space moves CA-129 to In progress at position 2. The live region reads "CA-129, In progress, position 2 of 4".
- Drag CA-124 into Review / QA. Drag CA-131 into Waiting on Tilen: the ask prompt appears, an empty ask shows the required error, and with an ask filled in the card lands with "Ask: …".
- A failed move rolls back and shows the error toast. Quick add puts "Call the printer" at the end of Backlog. The "Blocked only" filter leaves CA-127.
- Mobile: the default tab is Waiting on Tilen. Move sheet to Review / QA, then Undo, returns the card to To do.

## Deviations from the mock

1. **No "Sample data · kanban mock" chip.** It's developer-only text and doesn't belong in the app.
2. **Waiting on Tilen is oldest `waiting_since` first** (SPEC §1/§2), so CA-119 (4d) sits above CA-115 (1d). The mock has them the other way round.
3. **Portfolio order follows SPEC §2.** Smooth sailing is sorted by latest activity, so ChatPulse (today) comes before AdriaEdition (yesterday). The mock has the reverse.
4. **Waiting ages are computed from `status_set_at` in whole days.** Merkwell shows 7d (mock 8d), the Shopify migration 3d (mock 4d), and Concierge Avant "<1d" because its status was set today at 07:40 (mock 1d).
5. **Portfolio Done counts only the last 7 days** (Tommy, 29 Oct) and the legend reads "Done (7 days)", so Concierge Avant shows Done (7 days) 3, as in the mock. The other columns count every live ticket.
6. **Labels are alphabetical** ("Check-in, Decision"), which is the order the API returns them in. The mock lists them in hand-picked order.
7. **The card's Ask line is the ticket's real `ask`**, clamped to 2 lines. The mock's card used a shorter paraphrase of the same ask.
8. **The Move sheet's Waiting on Tilen row says "Goes to the end, sorted oldest first"** (Tommy, 29 Oct): that column is ordered by age, so a moved ticket lands last. The other rows say "Top of column".
9. **Desktop modal:** the scrim covers the whole viewport, top bar included, and the dialog sits 48px from the viewport top. In the mock the scrim starts under the top bar; Tommy confirmed the full-viewport scrim (29 Oct). On mobile the top bar stays visible above the sheet, as in the mock.
10. **Side fields are live controls.** Assignee and Priority are native selects styled as plain values (the chevron shows on hover). Due opens the native date picker and has a Clear button. Labels has Edit (comma-separated names). The mock shows static values.
11. **The status-change toast floats** at the bottom centre for 15 s or until dismissed, and opening History dismisses it. The mock shows it inline.
12. **The thread shows every column move as an inline event**, including To do → In progress on CA-115. The mock only shows the last one.
13. **The composer's sent line reads "Sent · Janice gets it on their next run".** The states mock says "her"; the SPEC string uses "their".
14. **New UI the mocks don't cover:** the status override menu behind the header badge, the filters, the ask, the empty-column copy for columns other than Waiting on Tilen and In progress, and "Show last 7 days only" after "Show N older". Tommy's review of 1ae66bd (below) set their final form. There is no "New" dot on cards, per Tommy; the unread strip and the New divider in the thread stay.
15. **Filters live in page state**, like search. They aren't in the URL; neither were the earlier ones.

## Tommy review 29 Oct (of 1ae66bd)

All points are done. The PM's name comes from the project (`pm_name`, first name only); nothing is hard-coded.

| # | Done / how |
|---|---|
| a1 | `.smenu` is `role="dialog"` `aria-label="Set project status"`. Opening it focuses the checked radio (the first one if no status is set), on mobile too. Esc, outside click and Cancel return focus to `.bbtn` |
| a2 | The menu is positioned from `.pstat`: `top: calc(100% + 6px)`, `left: 0`, 400px wide. It no longer covers the PM note |
| a3 | The note starts empty with the placeholder "Why? (optional)". The current note shows in full above it in `.hint` style: "Janice’s note: “…”" ("Your note" if Tilen set it) |
| a4 | Save status is disabled until the status or the note changes |
| a5 | A 1px `--line` divider with 12px on each side sits above Archived. Selecting Archived shows "Hides the project from the portfolio. You can undo it in History." |
| a6 | Cancel (`.abtn`) sits next to Save with a 10px gap. In the mobile sheet both are full width, stacked |
| a7 | "✕ Couldn’t save the status. Your note is kept." with `role="alert"`; the form stays open. No HTTP code anywhere: `send()` no longer builds an "HTTP 500" message |
| a8 | "Saved as set by you. Janice sees it in History." |
| a9 | 12px chevron-down in `--ink2` on the badge |
| a10 | n/280 counter under the note, right-aligned, `.hint` style |
| b1 | A separate render path: `useMedia(MOBILE)` (`(max-width: 720px)`, the one mobile breakpoint, Tommy 29 Oct) renders the fields in the shared bottom `Sheet` titled "Filter tickets" with full-width selects, and as a popover above 720px. The old `position: fixed` override is gone |
| b2 | Opening focuses Assignee. Esc, Done and outside click return focus to `.fbtn` |
| b3 | The icon-only Filter button shows a count pill styled like the column count pill |
| b4 | When a filter changes on mobile and the current tab has no matches, the board jumps to the first tab that has some. Empty tabs show their 0 in `--ink3` |
| b5 | "Has unread" is now two filters: "New replies for you" (`unread_for_tilen`) and "Your input not picked up" (`needs_pm_pickup`). `GET /api/tickets` accepts `unread_for_tilen=1` too (documented in `docs/API-TICKETS.md`) |
| b6 | Labels after the first have 6px on top and 4px to their select. The footer is right-aligned: Clear filters, then Done, 10px apart |
| b7 | Desktop otherwise unchanged ("Filter · 2", "1 shown · Clear") |
| c1 | On mobile the ask is the second step of the Move sheet, with a back arrow to the column list and full-width Move (`.btn`) then Cancel (`.abtn`). Elsewhere on mobile (the ticket's status select) it's a bottom sheet too. Desktop keeps the dialog |
| c2 | Label "What’s the ask?", hint "Shown on the card and in Your input.", heading "Move CA-131 to Waiting on Tilen" from the real key, and the title as a 14px `--ink2` subline |
| c3 | "✕ Add the ask. Every ticket in Waiting on Tilen needs one." The textarea gets a red `--w3` border, and focus goes back to it with a visible ring |
| c4 | Esc after a desktop drag (on the ask, or during the drag itself) returns focus to the dragged card |
| c5 | "Enter moves it · Shift+Enter for a new line · n/280", right-aligned, `.hint` style |
| c6 | Kept: focus trap, initial focus on the textarea, `aria-modal`, required/invalid states, the card stays put until confirmed, and Esc cancels |

## Constance QA 29 Oct (of e1de76a)

- **M1 done.** The ticket view has `tilen_answered_ask`: true while Tilen's latest input answers the current ask (sent or picked up). It turns false when the PM sets a new ask or moves the ticket back into Waiting on Tilen after his answer, and only then does a blank Your input form show again. After an ack the box reads "You answered at {time}", with his answer, "Picked up by {PM} · time" and a secondary "Answer again" button. Shots: `ticket-after-ack-1440/390.png`. Test: `TilenWebTicketsTest::acked_input_keeps_the_answered_state_until_the_pm_asks_something_new`.
- **L1 done.** `overscroll-behavior-x: none` on the mobile board and tabs, and on `<html>` while the board page is mounted, so a right swipe only switches tabs. Not verified on a real device.
- **L3 done.** The portfolio's "Last activity" now uses the same format as the board ("Today, 07:58", "Yesterday, 21:15", "Wed 21 Oct, 10:05"). `portfolio-1440/390.png` were reshot.
