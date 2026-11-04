# Bridge r4 · Questions for you (per project): Figma pack

Tommy Suarez · Design · 4 Nov 2026 · Figma: https://www.figma.com/design/Zk5LNiLjIEL0umL71jbYhY?node-id=55-10 (page "r4 · Vprašanja po projektih")
This replaces the 18 Oct r4 HTML mock in `design/r4-questions/`, which used the v1 Atkinson look. That mock is SUPERSEDED and stays in place only as a reference. Everything here uses the Bridge v2 look: Geist type, `Bridge / Color` and `Bridge / Dimension` variables, and the Kanban board, drawer and portfolio frames. **All content is sample data**, and every frame carries the dashed "Sample data" chip.

## Frames (node → PNG)
| Frame | Node | PNG |
|---|---|---|
| Project · Questions panel · open / 1440 | 57:10 | 01-project-questions-open-1440.png |
| Project · Questions panel · open / 390 | 58:36 | 02-project-questions-open-390.png |
| Project · Questions panel · empty / 1440 | 59:56 | 03-project-questions-empty-1440.png |
| Project · Questions panel · empty / 390 | 59:568 | 04-project-questions-empty-390.png |
| Answer input · all states / 1440 | 56:10 | 05-answer-input-states-1440.png |
| Answer input · states / 390 | 61:122 | 06-answer-input-states-390.png |
| Portfolio · open-question indicator / 1440 | 60:89 | 07-portfolio-question-indicator-1440.png |
| Portfolio · open-question indicator / 390 | 60:510 | 08-portfolio-question-indicator-390.png |
| Dev notes for Xerxes | 62:132 | 09-dev-notes.png |

## Model
A question is a ticket that is waiting on you and carries an ask: the question, an optional context, optional option chips, and a free-text answer or note. The panel sits on the project view between the meta row and the board.
- Open questions are shown as cards (3 columns on desktop, stacked at 390), oldest first, under the signal count pill "{n} open".
- **Answered · awaiting PM pickup**: compact rows with the answer read back and a "Change answer" button.
- **Closed & dismissed**: collapsed, with Show/Hide.
- **Portfolio**: the footer chip "{n} for you" becomes "? {n} question(s) for you", and the summary line reads "{n} questions for you".

## States
Each state is a numbered frame on the page:
- 01 open, empty (Send disabled)
- 02 multiple-choice option selected
- 03 yes/no, with keyboard focus
- 04 typing
- 05 multi-line (auto-grows, 2000 max, counter)
- 06 validation error
- 07 submitting
- 08 send error (answer kept)
- 09 answered, awaiting PM pickup
- 10 closed
- 11 dismissed (Restore)
- 12 option-chip states
- empty panel

## Tokens
No new tokens. Open card: signal at 12% fill, 1px signal stroke, r16, the same as the drawer's "Your input" block. Errors use `risk`, sent and closed use `ok`, waiting age uses `warn`, and the avatar uses `agent` at 14%. Radius md 12 for fields and buttons, pill for chips. Tap targets are at least 44px. Type: H2 / H3 / Body M / Label M / Caption / Mono S / Overline. Tint opacities are literal paints because Figma can't bind opacity to a variable.

## Interactions
- Picking a chip enables Send. ⌘/Ctrl+Enter sends.
- Sending is optimistic: the card shows submitting, then moves to "Awaiting PM pickup" with focus on that row. On error the values are kept and the button becomes "Try again".
- The answer posts as a "your input" comment on the ticket, the same as the drawer.
- "Change answer" works until the PM picks the answer up. After that the question is closed and read-only.
- Dismiss shows the toast "Question dismissed · Undo". The agent still sees the question as unanswered.
- Use `role=status` for Sending and Sent, and `role=alert` for errors.

## Assumed data (confirm with Erin)
```
question { id, ticket_id, ticket_key, project_id, text, context|null,
  options: string[]   // [] = free text; ["Yes","No"] = yes/no
  allow_text: bool, asked_by { name, role }, asked_at,
  state: open|answered|closed|dismissed,
  answer_choice|null, answer_text|null, answered_at|null,
  picked_up_at|null, closed_at|null, dismissed_at|null }
ProjectSummary.openQuestions: number   // state=open
POST /projects/{slug}/questions/{id}/answer {choice?, text?} · …/dismiss · …/restore
```
Awaiting pickup means `state=answered` and `picked_up_at` is null. Today's `main` (Kanban) has no question model. The older r4 model on `feat/polymarket-wip` (yesno|text, `answer_choice`, `answer_text`) maps onto this one.

## Copy for Marie (new or changed strings)
Questions for you · {n} open · Tickets on this project waiting on your answer · oldest first · Hide panel · Your answer · Note for {asker} (optional) · Add a note or write your own answer… · Send to {asker} · Posts on {ticket} as your input · ⌘↵ to send · Pick an option or write an answer. · ✕ Pick an option or write an answer before sending. · Sending… · Sending your answer to {asker}… · Try again · ✕ Couldn’t send. Check your connection and try again. Your answer is kept. · YOUR ANSWER · ✓ Sent {time} · waiting for {asker} to pick it up · Change answer · Dismiss / Dismiss question · Restore · Details · Answered · awaiting PM pickup (Awaiting PM pickup on mobile) · Closed & dismissed · Show · No open questions · Agents will post here when a ticket needs your answer. Answered questions stay under Closed. · Nothing waiting on you here right now · {n} question(s) for you · {n} questions for you (portfolio summary) · Question dismissed · Undo · You answered “{x}” · picked up by {agent} · closed {date} · Dismissed by you · {date}
