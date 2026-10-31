# Bridge, multi-user: design prototypes

For Erin. Static HTML/CSS for opening Bridge to other people: sign-up, login, reset, account, API tokens + MCP, onboarding, Usage (with Cursor), and the nav for each role. The direction is **"Bridge, extended"**: it is the live app's look with no new colours or fonts. New work went into layout, copy and states.

Open `index.html` (works over `file://`, nothing to build, no CDN).

## Files

| File | What it is |
|---|---|
| `index.html` | Hub that links every screen and state |
| `signup.html` | Sign up |
| `login.html` | Log in (evolves today's "Workspace gate", `Gate.tsx`) |
| `reset.html` | Password reset flow |
| `account.html` | Account settings: Profile, Password, Sessions, Danger zone |
| `tokens.html` | API tokens + MCP connection |
| `onboarding.html` | New user's empty Portfolio and 3-step checklist |
| `usage.html` | Usage for one user's agents + new **Cursor models** section |
| `shell.html` | Nav / role differences (regular user vs owner, Admin users list) |
| `shared.css` | All styles (see below) |
| `proto.js` | Prototype-only behaviour: state switching, copy, show/hide password, menus |
| `ref/` | Live app CSS, untouched |
| `fonts/` | Atkinson Hyperlegible 400 / 700 / italic |

## States

Every page takes `?state=NAME`, e.g. `tokens.html?state=reveal`. The dashed **States** switcher (`.proto`) at the bottom right flips them in place and updates the URL. Without `?state` the first state listed is used. Buttons inside the screens also click through (e.g. *Create token* → `create` → *Create token* → `reveal`).

| File | States (first = default) |
|---|---|
| `signup.html` | `default`, `errors`, `loading`, `email-taken` |
| `login.html` | `default`, `wrong`, `locked`, `loading` |
| `reset.html` | `request`, `sent`, `set-new`, `expired`, `success` |
| `account.html` | `default`, `saved`, `error`, `delete` |
| `tokens.html` | `with-tokens`, `empty`, `create`, `reveal`, `revoke` |
| `onboarding.html` | `step1`, `step2`, `step3`, `done` |
| `usage.html` | `7d`, `yesterday`, `30d`, `cursor-empty` |
| `shell.html` | `user`, `owner`, `admin`, `user-menu`, `owner-menu` |

What each state shows:

- **signup**: `errors` shows an error summary (`role=alert`, focusable, links to fields) plus inline `.ferr` per field, tied with `aria-describedby`. `loading` disables the inputs and shows a spinner button. `email-taken` shows a banner with *Log in* / *reset* links and an inline error on Email.
- **login**: `wrong` shows one message for both fields (never says which one was wrong), says how many tries are left, and clears the password. `locked` means "Try again in 15 minutes" with a reset link, and the button is disabled with its reason in the label. `loading` is the spinner.
- **reset**: `sent` uses the same wording whether or not the account exists. `set-new` has new + confirm passwords, the strength meter and a "Passwords match" line. `expired` offers *Send a new link*. `success` leads to *Log in*.
- **account**: `saved` shows a toast, an inline "Profile saved", and a pending-verification banner for the new email (old email stays active until confirmed). `error` shows the summary and inline errors on Name and Email. `delete` is a modal; typing `sam@example.com` enables *Delete account*.
- **tokens**: `reveal` shows the full token once (`brg_live_EXAMPLE_…3f9a`) with Copy and a "You won't see this again" warning; the new row appears in the table. `revoke` is a confirm modal (`alertdialog`).
- **onboarding**: steps 1–3 sit beside a checklist (`aria-current="step"`). `done` is the Portfolio with one *Example project* card in the real `.pc` style.
- **usage**: the range seg (*Yesterday / 7 days / 30 days*) is tied to the states, and the Cursor section follows the same range. `cursor-empty` is the "No Cursor usage imported yet" empty state.
- **shell**: the "View as" seg switches between regular user (Sam) and owner (Tilen). `admin` is the owner's Users list. The `*-menu` states open the account menu and, at ≤720px, the `.navm` menu, so both can be screenshotted.

`proto.js` and the `.proto` switcher are design aids only and don't need porting. In React each `data-show` group is component state.

## How the prototype code is organised

- `data-show="a b"` / `data-hide="a b"` show or hide an element in the listed states. `data-goto="state"` is a click-through.
- Small per-page `window.onProtoState(id)` hooks set example values (e.g. an invalid email in `errors`) and `aria-pressed` / `aria-invalid`.
- Helpers: `[data-copy="#id"]` copies the target text and says "Copied" in a polite live region (falls back to `execCommand` on `file://`). `[data-pw="#id"]` toggles show/hide password. `[data-strength="#meter"]` gives a live strength demo.
- When a `.mdl` modal is visible, `proto.js` marks header and main `inert` and focuses `[autofocus]` (else the first field). Esc returns to the modal's `data-close` state. The real dialog should also trap focus and return it to the trigger.

## shared.css

1. **Copied verbatim** from `ref/bridge.css` and `ref/usage.css`; only the font paths are rewritten to `fonts/`. The `:root` tokens are identical.
2. **Kanban excerpts**, un-nested from `.kanban` in `ref/kanban.css`: portfolio card `.pc` / `.pcb` / `.pcf` / `.cbar` / `.ccount`, column `.col` / `.colh`, `.toast` / `.toasts`, `.ibtn`, `.pop`, `.lnk`, `.ok-line`. In the app these live under `.kanban`; either reuse them from there or lift them out.
3. **Multi-user additions**, in the commented block at the end, using existing tokens only.

**Derived tokens (2), both naming literals the app already uses:**

- `--bad-bg: #FBE6E4`, the kanban `.l-rose` background, for error surfaces.
- `--note-bg: #FFF4D6`, the usage `.notice` / `.rtag.um` background, for warnings.

Error text uses `#8A2318` and warning text `#5C4500`, the same as the existing `.l-rose` / `.notice` inks.

**New components / classes:**

| Class | Purpose |
|---|---|
| `.acct`, `.avi`, `.acct .menu`, `.aid` | Top-bar account menu: avatar initials + name → dropdown (Account, API tokens & MCP, Log out). Replaces `.who` on logged-in pages. Ink dropdown like `.navm`. |
| `.ownr` | "Owner" chip, solid `--w1`, so it never reads as the dashed `.sample` chip |
| `.gate .gcard` / `.gate form` | Evolved Workspace gate card (440px), with `.lede`, `.alt` (switch-flow line), `.fine` |
| `.fld2`, `.lrow`, `.fhint`, `.ferr` | Field wrapper, label row with a link (*Forgot password?*), hint, inline error (icon + text) |
| `.qsel` | Select styled like `.qin` |
| `.pw`, `.pwt` | Password input with a Show/Hide toggle button (`aria-pressed`) |
| `.meter[data-score=0–4]` | Strength meter: segment count + word + tip, never colour alone |
| `.chkrow` | Checkbox row (44px) |
| `.errsum` | Error summary (focusable, links to fields) |
| `.callout.bad/.warn/.ok/.info` | Inline banners (`.estnote` / `.notice` / `.allclear` family) |
| `.sethead`, `.setgrid`, `.subnav`, `.setcol` | Settings layout: 220px sub-nav + content; sub-nav becomes horizontal scroll pills ≤720px |
| `.form2`, `.cardfoot`, `.prof`, `.rowact`, `.pend` | Two-column form, card footer actions, avatar row, label + action rows, pending chip |
| `.card.dz` | Danger zone (6px `--w3` top rule, like `.inputs` / `.qs` use 6px rules) |
| `.mdl`, `.mdlbox`, `.mdlh`, `.mdlb`, `.mdlf` | Modal (scrim = kanban `--scrim`), bottom-sheet style ≤720px |
| `.opts2`, `.ropt` | Radio cards (token scope) |
| `.kb.rd`, `.kb.rw`, `.kb.ex` | Scope chips *read* / *write*; dashed "Example data" chip |
| `.secret`, `.code` (`.ch`, `pre`), `.code.one`, `.reveal`, `.tokname`, `.scopes`, `.codes` | Masked token, copyable snippet blocks (scroll inside, never the page), one-time reveal panel |
| `.steps` | Numbered steps (a real sequence) |
| `.can` | "What your bot can do" per scope |
| `.tbl.stack` | Table that becomes a card list ≤720px (`td[data-label]`) |
| `.obgrid`, `.olist`, `.prog`, `.colprev`, `.ghosttk` | Onboarding checklist, progress, editable column preview |
| `.role.own/.usr/.off`, `.who3`, `.vas` | Role chips, user cell, "View as" switch |
| `.cur-split`, `.mtag` | Cursor section layout |
| `.proto` | Prototype states switcher. **Not product.** |

Page-local styles from the per-page passes are folded into `shared.css` (commented "tokens page", "usage page", "folded in from page prototypes"). Only `index.html` (the hub layout) and `shell.html` (one prototype-only rule that stacks both open menus on phones) keep a `<style>` block.

## Accessibility

- Every input has a `<label>`. Hints and errors are linked with `aria-describedby`, and invalid fields get `aria-invalid="true"` plus a red border and a text message with an icon (not colour alone).
- Error summaries and banners use `role="alert"`. Toasts sit in an `aria-live="polite"` `.toasts` region. Copy confirmations go to a polite live region. Loading states keep the label ("Logging in…") and add a polite announcement.
- Focus is a 3px `--accent` outline with 2px offset everywhere, and white on the ink top bar (same as `.topin .nav a`).
- Tap targets are ≥44px (buttons, pills, menu items, show/hide, row toggles, the seg on mobile).
- Contrast: only existing token pairs, which are AA in the app. The new pairs `#8A2318` on `#FBE6E4` and `#5C4500` on `#FFF4D6` are both above 7:1.
- Strength meter: segments are `aria-hidden`; the word and tip are text in an `aria-live="polite"` line.
- Modals use `role="dialog"` (revoke uses `alertdialog`), `aria-modal`, a labelled heading and a close button with `aria-label="Close"`. Destructive confirmation needs typed input.
- Password manager friendly: `autocomplete` is `email` / `current-password` / `new-password` / `name`.
- `prefers-reduced-motion` turns off spinners, skeletons and transitions.

## Responsive

- **720px** is the main breakpoint, as in the app. Below it: nav becomes `.navm` (current page ▾); the account menu shows the avatar only; the `.sample` chip hides (as in the live app); settings sub-nav becomes horizontal pills; `.tbl.stack` tables become cards; modals become bottom-sheet style with full-width buttons; auth cards align to the top.
- **1100px** is secondary: `.ugrid` / `.obgrid` / `.cur-split` go to one column, and onboarding columns go from 5 to 3.
- No horizontal page scroll at 390. Wide tables and code blocks scroll inside their own container (`.tscroll`, `.code pre`).
- The `.proto` switcher is fixed at the bottom right on desktop (body has 72px bottom padding). **≤720px it sits in the page flow at the end**, so it never covers content.

## Example data

Everything is invented: user **Sam Rivera** (`sam@example.com`); projects *Example project*, *Garden planner*, *Client site redesign*; agents *Ava*, *Builder*, *Scout*. The owner view shows "Tilen" in the top bar but only generic projects (*Example project A*, *Studio website*, *Internal tools*). Other users are Alex Kim, Jordan Lee and Priya Patel. Tokens: `brg_live_••••3f9a`, with the one-time reveal `brg_live_EXAMPLE_xxxxxxxxxxxxxxxxxxxxxxxx3f9a`. MCP URL: `https://bridge.tilenpoje.eu/mcp` (example). All usage numbers and Cursor model ids are examples. Every app screen has the dashed **Sample data** chip.

## Open questions

1. **Cursor usage data source.** Is it an admin CSV export, the Cursor Admin API, or per-user API keys? Fields needed per row: model id, agent attribution (which key / user / agent), timestamp (day at least, in the user's timezone), requests, input tokens, output tokens, cache-read tokens (cache-write?), cost (show it or not?), and maybe a "max mode" / kind flag. What is the import cadence (daily / hourly)? How should we show "no file for that day" versus zero, as the estimate chart does?
2. **Agent attribution for Cursor.** Is it by API key per agent, or by user only? Anything we can't attribute shows as *Unattributed*.
3. **Token scopes.** Is read vs read & write enough, or do we need **per-project** scoping ("this bot can only touch Garden planner")? Are expiry options 30 / 90 days / 1 year / none acceptable, and should "No expiry" be allowed at all?
4. **MCP endpoint.** Path `/mcp`? Is auth a Bearer token header only, or OAuth later? Which transport (streamable HTTP)? Is there a rate limit per token? What exact tool list maps to read / write?
5. **Email verification.** Must new users verify before they can use Bridge, or before they can create tokens? The prototype assumes immediate use, with verification required only for email changes.
6. **Password policy.** The prototype uses at least 10 characters plus a strength meter (no composition rules). Do we check breached passwords? Does 5 failed attempts give a 15-minute lockout? Do we want 2FA later?
7. **Owner (Tilen) admin abilities.** List users, disable / enable. Can he see users' boards? Delete accounts? Impersonate? Invites only, or open sign-up?
8. **Data separation.** The existing agents' usage (The Many, Shodan, …) and the current projects stay Tilen's. How do we migrate them to his user, and how are the shared-box CLI logs (Claude Code / Kimi / OpenCode) attributed per user?
9. **Polymarket and Admin for non-owners.** Should they return **404** (hides that they exist; the prototype assumes this) or **403**?
10. **Account deletion.** Delete immediately or after a grace period? Offer a data export first?
11. **Sessions.** Is "Log out of all devices" in scope for v1 (needs session storage per device)?
