# Kanban UI shots (29 Oct 2026)

_Updated 29 Oct, about 09:35 CET, after Tommy's calls: `portfolio-1440/390.png` and `states-move-sheet-390.png` were reshot the same way. The other shots are byte-identical on reshoot or untouched._

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
| `states-ask-390.png` | — (SPEC §4) | Moving into Waiting on Tilen asks "What do you need from Tilen?" (viewport) |
| `states-move-undo-390.png` | — (SPEC §4) | After a Move-sheet move: the toast with Undo (viewport) |
| `states-move-error-1440.png` | — (SPEC §4) | A move whose request fails (500 injected in the browser): the card is back in Backlog, error toast "Couldn’t move CA-133. Try again." |
| `states-input-sent-1440/390.png` | `states-*` §5 | After "Send to Janice": SENT box, and the Tilen's-input bubble with the amber ack line |
| `states-new-divider-1440/390.png` | — (SPEC §2 thread) | CA-118 opened from the board: the unread reply from Erin sits under the navy New divider (viewport) |

## Checks (every shot)

Same checks as `/workspace/bridge-kanban/shoot.mjs`: horizontal overflow, clipped text, Atkinson Hyperlegible loaded,
and interactive targets under 32px.

| Shot | Overflow | Clipped text | Font | Targets < 32px |
|---|---|---|---|---|
| the 19 page and state shots | 0 | none | loaded | none |

`states-move-undo-390.png` and `states-move-error-1440.png` come from the flow script, which doesn't run these checks.

The checker skips three elements that predate this work and are unchanged: the TopBar brand link (30px), the
"← All projects" back link (24–26px, an inline text link) and the search `<input>`, which sits inside its 48px label.
It also skips `.sr-only`, which clips by design.

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
14. **New UI the mocks don't cover:** the status override menu (radio list, note, Save) behind the header badge, the Filter popover, the ask prompt, the empty-column copy for columns other than Waiting on Tilen and In progress, and "Show last 7 days only" after "Show N older". Tommy confirmed the override menu, Filter popover and ask prompt as built (29 Oct). There is no "New" dot on cards, per Tommy; the unread strip and the New divider in the thread stay.
