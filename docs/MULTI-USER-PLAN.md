# Bridge multi-user plan (v2)

Tilen's initiative (31 Oct 2026, via Captain W. Diego): other people run their own projects in Bridge with their own Grok
bots. Tommy + Marc's prototypes: `bridge-design-multiuser/design/bridge-multiuser/` (DESIGN.md + 8 screens). Groundwork
is on `feat/multi-user` (§11, not deployed). Public landing page: not now.

Ops details (hosts, server paths, database names, backups, owner email, deploy sequences, client names) are kept
privately; this plan covers product decisions, architecture, API and the security model.

## 0. Decisions

**Relayed decisions (via CoS, 31 Oct 07:47)** — Tilen's, relayed by the Chief of Staff:

1. **Sign-up is open to anyone**, with abuse protection: email verified before creating tokens or using MCP; sign-up
   rate-limited per IP (5/hour); a bot check on the form. Proposal: **Cloudflare Turnstile** (free, no tracking
   cookies; server-side verify `POST https://challenges.cloudflare.com/turnstile/v0/siteverify` with the secret + the
   form's `cf-turnstile-response` + IP; fail closed). Setup (The Many / Tilen, Cloudflare dashboard; nothing created
   by us): `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` in `.env`. No-key fallback, always on: a hidden honeypot field
   + minimum fill time (~3 s, signed timestamp in the form). Alternatives: hCaptcha (same flow, other vendor) or a
   self-hosted proof-of-work widget (no third party, weaker). Disposable-email blocking: optional. Verification
   mail needs `MAIL_*` configured on live (today `MAIL_MAILER=log`, nothing is sent).
   **Turnstile without pre-clearance:** the sign-up page loads nothing from Cloudflare when it renders. The script
   (`api.js?render=explicit`) is added only when the person submits the form (`resources/js/lib/turnstile.ts`); the
   widget runs with `execution: 'execute'` and `appearance: 'interaction-only'` (invisible unless Cloudflare needs a
   click), and the form posts the token it returns. **In the Cloudflare dashboard, the widget's _Pre-clearance_
   setting must stay OFF**: with it on, Cloudflare would set a `cf_clearance` cookie, and the page must set no cookies
   besides the app's session and XSRF cookies (tests: `TurnstileLazyTest`). Server-side verification is unchanged.
2. **Owner admin = user list + disable/enable ONLY.** Tilen cannot see other users' boards, tickets, statuses, usage or
   any data; no impersonation. Enforced in API + query layer (§5).

**Relayed decisions (via CoS, 31 Oct 08:49)** — Tilen's answers, relayed by the Chief of Staff:

1. **Owner login email** comes from `BRIDGE_OWNER_EMAIL` on staging/live (value kept privately).
2. **Cursor is on an individual plan** → no Admin API. Usage comes from the **Cursor dashboard CSV export**, attributed
   to **one account shown as "Cursor (all agents)"**; never a faked per-agent split (§7).
3. **Mail domain pending**: `MAIL_MAILER=log` stays; sender address and name come from `MAIL_FROM_ADDRESS` /
   `MAIL_FROM_NAME` (§11). (Superseded for the domain by the 09:36 decision below: `usebridge.si`.)
4. **Turnstile keys will be created by The Many**; config-driven (`TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`).

**Relayed decision (via CoS, 31 Oct 09:36)** — Tilen's:

1. **Product domain is `usebridge.si`** (registered). Sender defaults: `support@usebridge.si` / "Bridge"
   (`MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`, `config/mail.php`). `MAIL_MAILER` stays `log` until SMTP + SPF/DKIM/DMARC
   for `usebridge.si` are in place (The Many).

**Decisions by the Chief of Staff (Captain W. Diego), 31 Oct** — his calls, not Tilen's:

1. **MCP uses `laravel/mcp`** (runtime dependency, `Mcp::web`, streamable HTTP; §6).
2. **Staging first**: The Many runs a Bridge staging site (its own DB copy); all multi-user work goes there first.
3. **Production only after a migration rehearsal** on a copy of the live DB, with row counts compared before/after.
4. **Breached passwords**: checked against the HIBP k-anonymity range API (sign-up, reset, change); **fail-open** when
   HIBP is down or slow.
5. **Per-project token scopes are v2.**
6. **His working defaults (table below) are confirmed** (by him; still cheap to change).
7. **Turnstile is the bot check**; keys pending (Cloudflare).
8. **Backfilled workspace name and slug from config**: `BRIDGE_WORKSPACE_NAME` (default "My workspace") and
   `BRIDGE_WORKSPACE_SLUG` (default `main`); an already-migrated database sets the slug it was migrated with.
9. **The AUTH-1 shared workspace password is removed** (31 Oct, Tilen's call, before launch): no `/gate` routes, page,
   middleware branch, `bridge_gate_unlocked` session flag or `BRIDGE_GATE_*` config. Only a normal account login reaches
   the app (guests → `/login`); a session that still carries the old flag gets nothing. `/api` and `/mcp` token auth is
   unchanged. The owner signs in with the `BRIDGE_OWNER_EMAIL` account (see §11 "Owner login"). Staging and production
   can drop `BRIDGE_GATE_PASSWORD` / `BRIDGE_GATE_ENABLED` from `.env` (no longer read).
10. **The `waiting_tilen` column is "Waiting on you" for new workspaces** (31 Oct, his call; done §11). The key
    `waiting_tilen` is unchanged in the API. Verified: labels are derived in code (`BoardColumns`), no table stores
    the label text (tickets store the key; `projects.column_labels` only holds a project's own names), so no row is
    rewritten. Approach taken: a workspace-level setting, not the slug — additive `workspaces.settings` (JSON, null)
    with `waiting_label`; migration `2026_10_31_160000` fills it for every workspace existing at migration time with
    `BRIDGE_OWNER_WAITING_LABEL`, or, when that is empty, "Waiting on " + the first name of the default workspace's
    owner (what they showed before). The existing workspace keeps its old label everywhere (web, portfolio, REST
    `status_label`, `GET /api/board-columns`, MCP, the "needs an ask" errors); new workspaces have no setting and show
    `BoardColumns`' default. A project's own `column_labels` still win on its board and in its errors. Deploy: set
    `BRIDGE_OWNER_WAITING_LABEL` to the exact current label before migrating to make the backfill explicit.
    **Changed by go-live B2:** the default for a workspace without a setting is now "Waiting on <owner's first name>"
    (`Workspace::waitingColumnLabel`, `HumanActors::defaultWaitingLabel`; "Waiting on you" only without an owner), so a
    member sees whom the column waits on. See §11 "People as actors (B2)".

Still open: SMTP + SPF/DKIM/DMARC for `usebridge.si` (The Many). Until it is set, and until The Many has created the
Turnstile keys, config decides:
- no `TURNSTILE_*` keys → sign-up bot check is honeypot + minimum fill time only;
- `MAIL_MAILER=log` → verification / reset links are written to the mail log only, not sent;
- owner email comes from `BRIDGE_OWNER_EMAIL` (08:49 decision 1); the backfill migration refuses to run without it.

**Working defaults (CoS, 31 Oct; confirmed by the CoS, decision 6 above)** — we build to these; each is cheap to change:

| Topic | Default |
|---|---|
| MCP | `/mcp`, streamable HTTP, `Authorization: Bearer`, rate limit per token |
| Token scopes v1 | `read` / `read & write` (mapping §4); per-project scoping v2 (CoS decision 5) |
| Token expiry | 30 / 90 / 365 days or none |
| Polymarket, Admin for non-owners | 404 |
| Passwords | ≥ 10 chars, strength meter, no composition rules; breached check via HIBP range API, fail-open (CoS decision 4) |
| Lockout | 5 failed logins → account locked 15 min |
| 2FA | later |
| Email verification | required before creating tokens (and MCP); not for using the boards |
| Account delete | 7-day grace, data export first; soft delete, purge only with Tilen's OK |
| Sessions | "Log out of all devices" in v1 (database sessions) |
| Existing data | all projects, agents, usage → Tilen's user/workspace (the §11 backfill does this) |

**DESIGN.md open questions**

| # | Question | Answer | Status |
|---|---|---|---|
| 1 | Cursor data source | Dashboard CSV export, uploaded (`POST /usage/cursor-csv`) or `bridge:cursor-csv-import` (§7) | **decided** (relayed 08:49: individual plan) |
| 2 | Cursor agent attribution | none: one account, "Cursor (all agents)"; no per-agent split | **decided** (relayed 08:49) |
| 3 | Scopes, expiry | read / read&write; 30/90/365/none; per-project v2 | default confirmed (CoS) |
| 4 | MCP endpoint | `/mcp`, streamable HTTP, Bearer, per-token limit, tools §6; `laravel/mcp` | **decided** (CoS) |
| 5 | Email verification | before tokens/MCP, not before use | relayed (1) + default |
| 6 | Password policy | 10 chars, meter, 5 → 15 min lock, 2FA later; breached check (HIBP, fail-open) | default; breached **decided** (CoS) |
| 7 | Owner admin | list + disable/enable only; no data, no delete, no impersonation; open sign-up | **decided** (relayed) |
| 8 | Data separation | everything existing → Tilen; shared-box CLI logs stay Tilen's (other users' tools aren't on the box) | default |
| 9 | 404 or 403 | 404 | default |
| 10 | Account deletion | 7-day grace + export, soft delete | default |
| 11 | Log out all devices | yes, v1 | default |

## 1. Data model

Done (§11): `workspaces` (`slug`, `name`, `owner_user_id`, `features` json, `settings` json — e.g. `waiting_label`, §0 decision 10), `workspace_user` (`role`
owner/admin/member), `users.is_admin`, nullable indexed `workspace_id` on 18 owned tables + `personal_access_tokens`,
per-workspace keys on `usage_days`, `cli_usage_days`, `polymarket_series_points`, `labels`. Not owned: `users`,
`password_reset_tokens`, `sessions`, cache/jobs. `projects.slug`, `ticket_prefix`, `tickets.key` and string PKs stay
globally unique for now (a second user cannot have `CA-1`).

**To add** (one migration per step, all nullable/defaulted, nothing dropped):

| Table | Columns | For |
|---|---|---|
| `users` | `failed_logins` smallint 0, `locked_until` ts | login lockout |
| `users` | `last_login_at` ts, `disabled_at` ts | admin list, disable |
| `users` | `pending_email`, `pending_email_at` ts | email change (old email stays active) |
| `users` | `timezone` (default `Europe/Ljubljana`) | account profile; display only in v1 (usage days stay Ljubljana) |
| `users` | `delete_requested_at` ts, `deleted_at` ts (SoftDeletes) | account delete with grace |
| `personal_access_tokens` | `token_suffix` char(4) (non-secret, last 4 of plaintext) | masked list `brg_live_••••3f9a` |
| `projects` | `column_labels` json null (rename/hide per fixed key) | onboarding step 2 (columns are fixed in `BoardColumns`, incl. `waiting_tilen`: the workspace's `settings.waiting_label`, else "Waiting on <owner's first name>") |
| `cli_usage_imports` | `workspace_id`, `date`, `tool`, `rows`, `imported_at`; unique (ws, date, tool) | "no file" vs zero (§7) |
| `cli_usage_days` | `cost_cents` decimal(12,4) null, `max_mode_calls` int null | Cursor (§7) |
| `ticket/project_read_states` | `user` = the reader's actor id (`user:<id>`; the legacy owner keeps `'tilen'`), no schema change | done (B2) |

Expiry (`expires_at`) and `last_used_at` already exist on Sanctum tokens. Pending email uses a temporary signed URL
(60 min, carries a hash of `pending_email`), so no extra token table.

## 2. Web auth and account

- Session guard, hand-rolled on framework built-ins (no Fortify). Login = `Auth::attempt` (+ "Keep me logged in" →
  `remember`) + `session()->regenerate()`; logout = `logout` + `invalidate` + `regenerateToken`. Bcrypt 12.
- **Sessions**: `config/session.php` defaults to `database` and `.env.example` sets `SESSION_DRIVER=database`; the
  `sessions` table exists (`user_id`, `ip_address`, `user_agent`, `last_activity`). Live's value is in its `.env`
  (not read here) — The Many confirms it is `database` before the Sessions screen ships. Sessions list = rows for
  `user_id`; "log out other devices" = `Auth::logoutOtherDevices($password)` + delete other rows; "all devices" =
  delete all rows + rotate `remember_token`. Password change/reset does the same (screen copy says so).
- **Lockout**: wrong password → `failed_logins++`; at 5 → `locked_until = now+15 min`, counter reset. Response for
  unknown emails is identical (counter kept in `RateLimiter` keyed by email), so it never reveals that an account
  exists. Plus 10/min per IP. Reset link works while locked; successful reset clears the lock. Risk: anyone can lock
  a known email for 15 min (accepted; reset is the way out).
- **Verification**: `MustVerifyEmail` + built-in notification; `verified` middleware on token creation and on `/mcp`
  (token user unverified → 403).
- **Disabled**: `disabled_at` set → login refused ("This account is disabled"), its `sessions` rows deleted, its
  tokens **blocked, not revoked**: `ResolveTokenWorkspace` (and `/mcp`) return 403 when the token user has
  `disabled_at`. Tokens stay, so enable restores the bots; revoking would force every bot to be reconfigured for what
  is usually a temporary measure. A `web` middleware also logs out a disabled user mid-session.
- **Delete**: typed-email confirm → `delete_requested_at = now`, user logged out everywhere, tokens blocked like
  disabled; within 7 days, logging in offers "cancel deletion". After 7 days a command soft-deletes (`deleted_at`) the
  user and marks the workspace. Hard purge (37 days after the request) exists behind a flag that is **off** until
  Tilen decides (§13). Export first: a JSON download of the
  workspace (projects, tickets, comments, statuses, usage) from the Danger zone.
- Password reset: built-in broker, 60 min, 60 s throttle (done in `AccountService`). CSRF, `same_site=lax`,
  `SESSION_SECURE_COOKIE=true` on live. Guests land on the login screen (§11 UI); the shared AUTH-1 workspace
  password was removed before launch (§0 decision 9).

## 3. Migrating current data

Done: `2026_10_31_110000_multi_user_backfill` (owner = `BRIDGE_OWNER_EMAIL` only, `migrate` refuses without it; workspace
`BRIDGE_WORKSPACE_SLUG` / `BRIDGE_WORKSPACE_NAME` (config `bridge.default_workspace` / `bridge.default_workspace_name`), polymarket on; every row and token → that workspace; idempotent, `down()` drops only what it made).
Live check: per-table counts equal before/after, `workspace_id IS NULL` = 0, `BRIDGE_API_TOKEN` lists the same projects.

## 4. API tokens

- Sanctum, sha256 at rest. Create (name, scope, expiry) → plaintext shown once; revoke = delete the token row (a
  credential, not data). Rotation = create new, revoke old.
- **Prefix**: `SANCTUM_TOKEN_PREFIX=brg_live_` (config `sanctum.token_prefix` exists). New tokens only; existing
  tokens keep working unchanged. Sanctum's plaintext is `<id>|brg_live_<40 chars><crc32>`; the reveal shows the part
  after `|` (Sanctum finds a token without `id|` by its hash), and `token_suffix` stores its last 4. Legacy tokens
  show "legacy token".
- **Scope → abilities** (abilities enforced per route today, `EnsureTokenAbility`):
  - `read` = `["boards:read"]`
  - `read & write` = `boards:read`, `boards:write`, `status:write`, `agents:write`, plus `usage:write` /
    `polymarket:write` only when the workspace has that feature (Polymarket: Tilen's; usage upload: Tilen's until
    other users have a collector). `polymarket:write` is additionally **owner-only**: only tokens created by the
    workspace owner get it, and every `/api/polymarket/*` route re-checks on each request that the token's user owns
    the workspace (404 otherwise, like the web desk), so a member token with `ceo` / `*` / a forged ability is refused.
    The Polymarket feed job must therefore post with a token of the owner's own account.
  - Legacy `ceo` / `*` keep meaning everything; the UI never issues them. List shows scope derived from abilities.
- Expiry 30/90/365 days or none → `expires_at` (Sanctum rejects expired tokens). Rate limit 120/min per token (MCP:
  done, `throttle:mcp`; `/api`: done, `throttle:api-token`). Creation requires a verified email.
- Workspace resolution (done): token's `workspace_id` (user must still be a member), else owned, else only
  membership, else 403; + 403 when the user is disabled or deletion-pending.

## 5. Admin and isolation

- `is_admin` unlocks only `/admin/users` (web) and its JSON: name, email, created, last login, status
  (active/disabled/deletion pending) and `POST /admin/users/{id}/disable|enable`. Nothing else. Not for itself.
- The prototype's Admin table also shows **Projects / Agents counts** per user and an "invited" state: counts are
  data about another workspace → **dropped** (relayed decision 2); "invited" is moot with open sign-up. Tommy to update.
- **No bypass**: `BelongsToWorkspace`/`WorkspaceScope` has no owner/admin bypass; every owned query is
  `workspace_id = current`, fail closed when missing. `acrossWorkspaces()` exists only for global uniqueness (ticket
  prefix/key) and parent lookups on console writes; it must never feed a response (review rule; a test greps for it).
  Tilen's workspace is his own data only.
- Tests: owner (is_admin) gets 404 on another user's project/board/ticket/usage via web and via API with his token;
  admin JSON contains only the listed keys; disabled user → login refused, sessions gone, token 403, enable → token
  works. **MCP** (done, `tests/Feature/Mcp/McpIsolationTest.php`): Tilen's
  token and another user's token get "Unknown project/ticket" (same as missing) from every read and write tool on the
  other workspace, its data unchanged; list tools and `tools/list` name nothing of it; `tools/list` identical for all.

## 6. MCP server

**Done** (§11, `laravel/mcp`, CoS decision 1). `POST /mcp` (JSON-RPC 2.0, streamable HTTP via `Mcp::web` in
`routes/ai.php`; `GET`/`DELETE` 405, no server stream). Same checks as `/api`: per-user Sanctum Bearer token
(`auth:sanctum`, 401 + `WWW-Authenticate`), `ResolveTokenWorkspace` (token's workspace; disabled / deletion-pending
user 403), `RequireVerifiedEmail` (403), then `throttle:mcp` = 120 requests/min per token id (429).

Tools (`app/Mcp/Tools`, one class each, base `BridgeTool`), thin wrappers over `TicketService`, `ProjectStatusService`,
`StatusUpdateIngest`, `BoardView`, `TicketView` with the REST rules (`TicketService::rules/moveRules/commentRules`,
`TicketView::filterRules`, `ProjectStatusService::rules`, `StatusUpdateIngest::rules`) and the REST lookups
(`FindsTickets`):

| Tool | Args | Ability |
|---|---|---|
| `list_projects` | – | `boards:read` |
| `get_board` | `project` (id or slug), `done_since?`, `include_archived?` | `boards:read` |
| `list_tickets` | `project?`, `status?` (comma list), `label?`, `assignee?` (agent id / `owner` (alias `tilen`) / `user:<id>` / `none`), `q?` (title or key), `limit?` (≤ 200, default 50) | `boards:read` |
| `get_ticket` | `key` | `boards:read` |
| `list_agents`, `list_labels` | – | `boards:read` |
| `create_ticket` | `project`, `title`, ticket fields, `actor?` | `boards:write` |
| `update_ticket` | `key`, ticket fields, `actor?` | `boards:write` |
| `move_ticket` | `key`, `status`, `position?`/`before_id?`/`after_id?`, `ask*?`, `actor?` | `boards:write` |
| `comment_ticket` | `key`, `body`, `actor?` | `boards:write` |
| `post_status` | `project`, `body`, `inputs?`, `resolved_inputs?`, `actor?` | `status:write` |
| `set_project_status` | `project`, `status`, `note?`, `actor?` | `status:write` |

- Abilities as `EnsureTokenAbility` (`*` / legacy `ceo` pass everything); a missing ability is a tool error
  (`isError`, "This token lacks the '…' ability."), never data. `tools/list` is the same for every token.
- Actor as on `/api` (`Actor::forToken`): a per-agent token acts as its agent (a different `actor` → error), a shared
  token takes an optional `actor` (an agent of the workspace; people's ids refused: human input only comes from the UI);
  without one the token's own agent acts (go-live B3: `personal_access_tokens.agent_id`, provisioned with write tokens,
  lazily for older ones). `post_status` uses the actor as `agent_id`.
- Another workspace's project / ticket gives the same "Unknown project 'x'." / "Unknown ticket 'x'." as a missing
  one; no new query path, everything goes through the workspace scope. Validation errors are tool errors.

**Client config** (Cursor `~/.cursor/mcp.json` → `mcpServers.bridge`, Claude Code `.mcp.json` / `claude mcp add
--transport http`): token from Account → Tokens (verified email; `read` or `read & write`):

```json
{"url": "https://<host>/mcp", "headers": {"Authorization": "Bearer <token>"}}
```

## 7. Cursor usage

**Source (Tilen, relayed by the CoS 31 Oct 08:49): the team is on a Cursor _individual_ plan.** That plan has no Admin
API, so Bridge imports the **dashboard CSV export**. Everything is attributed to **one account, shown as
"Cursor (all agents)"** (`agent_id` `cursor`). The export has no per-agent attribution, and Bridge never fakes a split.

**Getting the file (Tilen or whoever holds the Cursor login):**
1. Open `https://cursor.com/dashboard` and sign in.
2. Go to **Usage**.
3. Pick the date range (e.g. the last 7 or 30 days; overlapping earlier uploads is fine).
4. Click **Export CSV**.
5. Upload the file: `POST /usage/cursor-csv` (multipart `file`; the Usage page gets an upload control in the UI run),
   or on the box `php artisan bridge:cursor-csv-import <file.csv> [--workspace=<slug>] [--dry-run]`.

**Required columns** (matched by name: case-insensitive, trimmed, any order, UTF-8 BOM allowed): `Date`, `Model`,
`Input (w/o Cache Write)`, `Input (w/ Cache Write)`, `Cache Read`, `Output Tokens`. Optional: `Cost`, `Max Mode`.
Everything else is ignored (`Kind`, `Total Tokens`, `Cost to you`, `Cloud Agent ID`, `Automation ID`, `User` on team
exports). Known header versions: v1 `Date,Model,Input (w/ Cache Write),Input (w/o Cache Write),Cache Read,Output
Tokens,Total Tokens,Cost,Cost to you`; v2 `Date,Kind,Model,Max Mode,Input (w/ Cache Write),Input (w/o Cache
Write),Cache Read,Output Tokens,Total Tokens,Cost`; v3 = v2 + `Cloud Agent ID,Automation ID`. A missing required
column → 422 naming the missing and the found headers.

**Mapping** (`App\Services\Usage\CursorCsvImporter`), one row per Europe/Ljubljana day × model:

| Bridge (`cli_usage_days`) | CSV |
|---|---|
| `input_tokens` | `Input (w/o Cache Write)` |
| `cache_creation_tokens` | `Input (w/ Cache Write)` (the cache-write count on its own) |
| `cache_read_tokens` | `Cache Read` |
| `output_tokens` | `Output Tokens` |
| `calls` | number of rows |
| `max_mode_calls` | rows with `Max Mode` yes/true (null when the column is absent, v1) |
| `cost_cents` | Σ `Cost` in dollars × 100 (`$0.11`, `0.11`, `1,234.5`); `-`, `Free`, `Included`, empty = 0; null when no row of the file has a numeric cost |
| `date` | `Date` (UTC ISO, `Z`) → calendar day in Europe/Ljubljana (`OrgDate`), DST-aware |

Numbers may be quoted and carry thousands separators. Rows of every `Kind` count, also "Errored, No Charge" /
"Aborted, Not Charged": Cursor processed those tokens (they cost nothing, which `Cost` already shows).

**Import rules.** Each day from the file's first to its last day goes through `CliUsageImporter` with
`covered_tools: ["cursor"]` and `coverage_from` = first day. Only Cursor rows of those days are replaced (Claude Code /
Kimi / OpenCode rows and markers are never touched), and every day in between gets a marker, zero days included. The
first and last day are likely partial (the export window cuts them). Such an edge day replaces the stored day only when
that day has no Cursor marker yet or the new day has ≥ the stored calls; otherwise it is reported as "kept (partial
edge day)". Re-uploading the same file is idempotent. Limits: 10 MB, 200,000 rows. A malformed row → 422 with its line
number (at most 20 errors), and nothing is written (one transaction). The result reports days imported / zero / kept,
rows, total tokens and the date range (`--dry-run` reports the same and writes nothing).

**Who may upload.** `POST /usage/cursor-csv`: the owner of the current workspace (`workspace_user.role = owner`);
everyone else 404; 10 uploads per hour per user.
The Usage page props carry `cli.cursorUpload: {allowed, lastImportAt}`. Cursor rows show through `UsageReport` like
the other tools (tool "Cursor", agent "Cursor (all agents)").

**Storage (unchanged).** `CliUsageImporter` replaces per (workspace, date, tool) for the tools a file covers, upserts
a `cli_usage_imports` marker per covered tool (marker + no rows = zero, no marker = no file), and stores the optional
`cost_cents` (decimal) / `max_mode_calls` columns. `CliUsageDay::TOOLS` includes `cursor`.

**If the team moves to a Team plan** (not the case today): the Admin API (`POST
https://api.cursor.com/teams/filtered-usage-events`, admin key with Basic auth, ≤ 30-day ranges, 20 req/min) returns
per event `userEmail`, `serviceAccountId`, `model`, `maxMode`, `tokenUsage {inputTokens, outputTokens,
cacheWriteTokens, cacheReadTokens}`, `chargedCents`. Captain would pull it (Bridge never stores a Cursor key) and POST
collector files with `covered_tools: ["cursor"]`, attributing via a `serviceAccountId`/`userEmail` → agent map (unmapped
→ `unattributed`). `/teams/daily-usage-data` has no per-model tokens and is not enough.

## 8. Screens → backend

| Screen / state | Needs |
|---|---|
| signup default/errors/loading | `POST /signup` {name, email, password, turnstile, honeypot, ts}; 422 per field; 5/h per IP; creates user + own workspace (`AccountService::register`), sends verification |
| signup email-taken | 422 on `email` with that message (open sign-up makes existence public here anyway; login/reset stay neutral) |
| login wrong / locked | `POST /login`; 422 `{message, tries_left}`, 429 `{locked_until}` when locked; `failed_logins`, `locked_until`; remember me |
| reset request/sent/set-new/expired/success | `POST /forgot-password` (neutral), `GET /reset-password/{token}`, `POST /reset-password` (INVALID_TOKEN → expired); logs out other sessions |
| account profile / saved / error | `PATCH /account` {name, timezone, email}; email change → `pending_email` + signed link; banner props `pending_email`; `POST /account/email/resend`, `DELETE /account/email/pending` (keep old) |
| account password | `PUT /account/password` (`AccountService::changePassword`) + drop other sessions |
| account sessions | props: list of {id, browser/device (parsed `user_agent`), ip, last_active, current}; `DELETE /account/sessions/others`, `DELETE /account/sessions` (all) |
| account delete | `POST /account/delete` {email confirm} → `delete_requested_at`; `GET /account/export`; modal counts = own projects/tickets/tokens |
| tokens with-tokens / empty | props: [{id, name, suffix, scope, created_at, last_used_at, expires_at}] |
| tokens create / reveal | `POST /account/tokens` {name, scope read\|write, expires 30\|90\|365\|null} (verified only) → plaintext once (flash) |
| tokens revoke | `DELETE /account/tokens/{id}` (own tokens only) |
| MCP snippet | prop `mcp_url` = `APP_URL/mcp` |
| onboarding step1 | web `POST /projects` {name, slug, ticket_prefix, description?} — done (§11); no PM (a new workspace has no agents) |
| onboarding step2 | `PUT /projects/{slug}/column-labels` → `projects.column_labels` (rename v1 — done, §11; hide/add/reorder later) |
| onboarding step3 / done | link to tokens; checklist state derived (has project, has columns saved, has token) — no new column; example project = step 1's project |
| usage 7d / yesterday / 30d | existing `/usage` per range, scoped (only own agents) |
| usage Cursor section / cursor-empty | `cli_usage_days` where tool=cursor: per model {requests, input, output, cache_read, total} for the one "Cursor (all agents)" account (no per-agent rows); empty = no cursor marker in range; upload control from `cli.cursorUpload {allowed, lastImportAt}` → `POST /usage/cursor-csv` (§7) |
| shell | shared props `user {name, initials, email}`, `is_owner`, `features.polymarket`, `features.admin`; non-owner → 404 |
| shell admin | `GET /admin/users` → [{id, name, email, created_at, last_login_at, status}]; disable/enable. "View as" = prototype only |

## 9. Step order

Done: schema, backfill, scoping, Polymarket owner-only switch, per-workspace keys, `AccountService`, token abilities, step 1
backend auth, breached-password check, the MCP endpoint, the UI and the web first project + column labels (§11). Next, each with tests, deployable alone:
1. ~~Backend auth~~ — done (§11, JSON only). Left from it for later steps: `PATCH /account` + email change
   (`pending_email` columns exist, flow not built), `GET /reset-password/{token}` screen, sessions list device parsing.
2. ~~MCP endpoint~~ — done (§6, §11).
3. ~~Cursor ingest~~ — done: scoped replace + markers, `cursor` tool, new columns; CSV import (§7).
4. ~~UI from the prototypes~~ — done (§11 "UI"): login / signup (Turnstile) / reset / account / tokens + MCP /
   onboarding / admin / usage Cursor + CSV upload, shell. Redone on the Figma redesigns since (§11 "Redesign
   screens"): app shell + top bar (guest / user / owner), Portfolio cards, Account, API tokens & MCP, Usage overview
   + single agent, Polymarket desk (owner only), all auth states, onboarding steps 1–3 + ready banner, landing
   page, legal pages + footer, on the shared tokens.css + Geist. Left from it (backend, each switches its screen on by itself):
   - `PATCH /account` {name, timezone, email} + email change (`pending_email` signed link), `POST /account/email/resend`,
     `DELETE /account/email/pending` — named `account.update` / `account.email.resend` / `account.email.cancel`; the
     profile form is read-only and the pending banner has no actions until those route names exist.
   - ~~Web `POST /projects` + column labels~~ — done (§11 "First project + column labels"): a new user can now sign
     up, create a first project (no PM), rename its columns, verify, create tokens and use MCP. Left from it: a PM
     for web-created projects (needs a first agent, step 5), hide / add / reorder columns, a "New project" entry
     outside onboarding, editing name / slug / prefix / description from the web.
   - ~~Read states per user~~ — done (B2, §11 "People as actors"): web writes and read states are the signed-in user's.
   - Live: Turnstile keys (The Many), `MAIL_*` (SMTP + DNS for `usebridge.si`, The Many), `SESSION_DRIVER=database` before the Sessions
     card means anything.
5. Later: `workspace_id` NOT NULL, per-workspace slug/prefix/key, per-project token scopes, `--workspace` for
   artisan imports, first-agent creation for new workspaces (`POST /api/agents` is Captain-only today; until then
   web-created projects have no PM, so only Captain / the UI can set their status).

**Prod prerequisite** (env keys prod sets before the multi-user deploy; names + purpose only, values stay private):

- `BRIDGE_OWNER_EMAIL` — owner the backfill migration picks or creates; it refuses to run without it (set, then
  `config:clear`, before `migrate`).
- `BRIDGE_WORKSPACE_SLUG` / `BRIDGE_WORKSPACE_NAME` — the backfilled workspace; a database already migrated keeps
  the slug it was migrated with.
- `BRIDGE_OWNER_WAITING_LABEL` — the exact current waiting label, stored for pre-existing workspaces at migration
  (empty = derived from the default workspace owner's first name).
- `BRIDGE_GATE_PASSWORD` / `BRIDGE_GATE_ENABLED` — no longer read (the shared password is removed); delete them.
- `BRIDGE_PUBLIC_URL` — public base for ticket links in API payloads (empty = `APP_URL`).
- `BRIDGE_LIVE_HOST` — the live host `bridge:rotate-copied-tokens` refuses on staging database copies.
- `BRIDGE_ROSTER_PATH` — roster JSON outside the repo (agents, per-project PMs, fixed ticket prefixes).
- `SANCTUM_TOKEN_PREFIX` — new tokens carry it; without it every minted token lists as "legacy".
- `BRIDGE_SOURCE_SHA` (else `BRIDGE_DEPLOYED_SHA`) — deployed commit for the footer's AGPL source link.
- `BRIDGE_LEGAL_DIR` — final legal texts outside the repo (empty = the shipped placeholders).
- `BRIDGE_LEGAL_ENTITY` — operator name in the footer © line; `BRIDGE_TERMS_VERSION` — terms version stored at
  sign-up (the draft until the final texts ship).
- `LOG_CHANNEL` / `LOG_DAILY_DAYS` — daily log files, kept 14 days (the privacy promise).
- `BRIDGE_ACCOUNT_PURGE_ENABLED=false` — hard purge of deleted accounts stays off until Tilen decides (§13).
- `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` — sign-up bot check; the widget's Pre-clearance stays **OFF** (no
  `cf_clearance` cookie). Until The Many creates the keys, honeypot + minimum fill time apply.
- plus `SESSION_DRIVER=database` (sessions / disable / log out everywhere) and `MAIL_*` (verification / reset
  mail) from the §11 checklist.

## 10. Risks

- **No staging**: backfill runs on live data first → rehearse on a dump copy, counts before/after (§3).
- Any query outside Eloquent (`DB::table`) or via `acrossWorkspaces()` is unscoped → review rule + grep test.
- Hand-made live tokens without `*`/`ceo` lose access (abilities enforced) → check before deploy (§11).
- Per-account lockout can be triggered by anyone knowing an email (15 min, reset unlocks).
- Open sign-up before mail works = unverified accounts that can use boards but not tokens/MCP.
- Global slug/prefix: another user can't reuse Tilen's and learns it exists, until per-workspace keys.

## 11. Groundwork done (branch `feat/multi-user`)

**Owner login (no shared password any more).** The backfill migration (`2026_10_31_110000_multi_user_backfill`) makes
the `BRIDGE_OWNER_EMAIL` user the owner + admin of the default workspace; when no user has that email it creates one
with an unusable random password (and an unverified email). That owner then sets a password with **Forgot password**
on `/login` (needs working `MAIL_*`; with `MAIL_MAILER=log` the link is in the application log on the server). An
owner who is already in `users` keeps his password. Wrong owner on an older copy: `php artisan bridge:set-owner
--email=… --dry-run`, then without `--dry-run`. Staging only: `bridge:verify-email --email=…` marks the email verified.

Not deployed. All green incl. `tests/Feature/MultiUser/*`.

| Piece | Where |
|---|---|
| Schema, backfill, per-workspace keys | `2026_10_31_100000/110000/120000_multi_user_*` |
| `Workspace`, `CurrentWorkspace`, `WorkspaceScope` + `BelongsToWorkspace` (17 models), `MissingWorkspace` | `app/Models`, `app/Support/Workspaces` |
| `WorkspaceBoundary`, `EnsureUserWorkspace` (login → own workspace), `ResolveTokenWorkspace`, `EnsureTokenAbility` | `app/Http/Middleware`, `routes/api.php` |
| `WorkspaceRule::exists`, bulk inserts set `workspace_id` | controllers, importers, `BridgeRoster`, `TicketService` |
| Polymarket feature switch (owner only) | `RequireWorkspaceFeature`, `HandleInertiaRequests`, `TopBar.tsx` |
| `AccountService` (register + workspace + verification mail, reset, change password; `MIN_PASSWORD = 10`) | `app/Services/Auth` |
| Auth columns: `users` lockout/last login/disabled/pending email/timezone/delete grace/soft delete, `token_suffix`; existing users marked verified; `usage_upload` feature on Tilen's workspace | `2026_10_31_130000_multi_user_auth` |
| Login: `LoginService` (5 fails → 15 min lock, unknown emails identical via `RateLimiter`, 10/min per IP, disabled / deletion pending → 403, `last_login_at`, regenerate), `POST /login`, `/logout`, `/login/cancel-deletion` | `LoginService`, `Auth\LoginController` |
| Sessions: `DeviceSessions` (list own rows, hashed ids; others; all + remember token), `GET/DELETE /account/sessions[/others]`; password change ends other sessions, reset ends all and clears the lock; `POST /forgot-password` (neutral), `/reset-password`, `PUT /account/password` | `DeviceSessions`, `Auth\SessionController`, `Auth\PasswordController` |
| Verification: `User implements MustVerifyEmail`, signed `GET /email/verify/{id}/{hash}`, `POST /email/verification-notification`; `RequireVerifiedEmail` (JSON 403) on token creation and `/mcp` | `Auth\EmailVerificationController`, `RequireVerifiedEmail` |
| Tokens: `ApiTokens` (read/write → abilities, usage/polymarket by feature; 30/90/365/none; `brg_live_` default via `SANCTUM_TOKEN_PREFIX`; `token_suffix`; bound to the workspace; masked list, legacy = no suffix; revoke own), `GET/POST/DELETE /account/tokens` | `ApiTokens`, `Auth\TokenController`, `config/sanctum.php` |
| Disabled / deletion pending: `ResolveTokenWorkspace` 403 (tokens blocked, not revoked), `LogoutBlockedUser` (web), logged-in web users act in their own workspace (`User::homeWorkspace`) | `app/Http/Middleware` |
| Admin: `GET /admin/users` → `[id, name, email, created_at, last_login_at, status]` only; `POST /admin/users/{id}/disable\|enable` (not self; disable deletes sessions); 404 unless logged-in `is_admin` | `AdminUsers`, `Admin\UserController`, `RequireAdmin` |
| Delete: `POST /account/delete` {email, current_password} (grace 7 days, logged out everywhere), cancel at login, `bridge:accounts-delete-expired [--dry-run]` soft-deletes (no purge; workspace rows kept; scheduled daily, a no-op unless `BRIDGE_ACCOUNT_PURGE_ENABLED=true`), `GET /account/export` (own workspace JSON, scoped) | `AccountDeletion`, `WorkspaceExport`, `routes/console.php` |
| Sign-up: `GET /signup/form` (signed `form_token`, Turnstile site key), `POST /signup` (5/h per IP, honeypot `website`, ≥ 3 s fill time, Turnstile server-side when `TURNSTILE_SECRET_KEY` is set, fail closed), logs in unverified | `SignupController`, `SignupBotCheck`, `TurnstileVerifier`, `config/turnstile.php` |
| Shared AUTH-1 password removed (decision 9): login only, a stale gate flag gets nothing; tests `WebAccessAndPagesTest` | `EnsureUserWorkspace`, `routes/web.php` |
| Waiting column label (CoS decision 10, default changed by B2): `BoardColumns` default "Waiting on <owner's first name>" ("Waiting on you" without an owner), `workspaces.settings.waiting_label` per workspace (backfilled for existing ones), project `column_labels` win; portfolio gets the workspace defaults, the ask dialog the board's label; tests `WaitingColumnLabelTest` | `BoardColumns::defaults()`, `Workspace::waitingLabel()`, `TicketService::askRequired()`, `2026_10_31_160000_workspace_settings` |
| People as actors (go-live B2): web writes record the signed-in user, `Actor::forUser` → `HumanActors::idFor`: `user:<users.id>`, except the owner of the legacy default workspace (`bridge.default_workspace`), who keeps `tilen` so old rows / read states stay his. Labels: `user:<id>` → the user's name, `tilen` → the workspace owner's first name (fallback "Owner"); `Actor::names()` adds the workspace's people. "Human side" checks use `Actor::isHuman()` / `isHumanId()` ('tilen' or `user:<id>`); owner shorthand `owner` (alias `tilen`) → `HumanActors::ownerId()`; `user:<id>` assignees must be in the workspace. Read states and unread are per reader (`HumanActors::viewerId`: the web user; over API/MCP the owner). The board's `people` = the workspace's people (viewer first, "You") + agents; shared prop `auth.user.actor_id`. Owner-input fields: old names kept as aliases next to `waiting_on_owner`, `owner_input_at`, `owner_input_ack_at`, `owner_answered_ask`, `unread_for_me`, `is_owner_input`, `status_unseen_by_me`, `waitingOwnerCount`, `status_set_by_human` / `pmStatusSetByHuman`; params `unread_for_owner` / `unread_for_me`, `is_owner_input`, feed `author=humans`. `status_source` is `human` for `user:<id>` (`tilen` unchanged). Export adds `actor_names`. No migration; column names, the key `waiting_tilen` and the `tilen` actor id stay. Tests `NewWorkspaceNamesTest` (no "tilen" in any value for a new workspace; member recorded as themselves), `LegacyOwnerOutputTest` (legacy output unchanged) | `app/Services/Tickets/HumanActors.php`, `Actor`, `TicketView`, `TicketController`, `ProjectController::people()` |
| Breached passwords (CoS decision 4): sign-up, reset and change reject passwords found via the HIBP range API (`GET api.pwnedpasswords.com/range/{5 hex of SHA-1}`, `Add-Padding`, 3 s); fail-open with a warning that has no password/hash; `BRIDGE_HIBP_ENABLED` (default on, off in `phpunit.xml`) | `BreachedPasswords`, `NotBreachedPassword`, `AccountService::passwordRules()` |
| MCP (§6): `POST /mcp` via `laravel/mcp`, 12 tools, abilities per tool, actor as `/api`, 120/min per token, verified + not blocked; isolation both ways (incl. Tilen as owner/admin) | `routes/ai.php`, `app/Mcp`, `tests/Feature/Mcp/*` |
| Cursor CSV (§7): `CursorCsvImporter` (v1/v2/v3 headers, day × model, "Cursor (all agents)", edge-day rule, zero-day markers, one transaction), `POST /usage/cursor-csv` (owner only, 404 else, 10/h), `bridge:cursor-csv-import [--workspace] [--dry-run]`, props `cli.cursorUpload` | `app/Services/Usage/CursorCsvImporter.php`, `CursorCsvController`, `tests/Feature/CursorCsvImportTest.php`, `tests/fixtures/cursor/` |
| Mail: sender from `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` (empty → `support@usebridge.si` / "Bridge"), `.env.example` `MAIL_MAILER=log`; verification / reset links only in the mail log | `config/mail.php`, `tests/Feature/MultiUser/MailConfigTest.php` |
| Security fix (existence oracle / cross-workspace agent): `POST /api/status-updates` checked `project_id` / `agent_id` with unscoped `exists:` rules, so another workspace's project answered 404 but an unknown one 422, and a token could file a status under another workspace's agent id. Now the project is looked up in the current workspace (unknown and foreign both 404, project checked first), then `agent_id` via `WorkspaceRule::exists` (unknown and foreign both 422, nothing written). Audit of every REST/web controller and FormRequest: `NextStepController` store/update had the same unscoped `exists:projects` / `exists:agents` → `WorkspaceRule::exists`; no other `exists:` / `Rule::exists` / raw `DB::table` lookup on an owned table remains (`DeviceSessions` reads `sessions` by user id). `/api` rate limit: 120/min per token id (`throttle:api-token`, a first-party session per user), JSON 429 | `Api\StatusUpdateController`, `Api\NextStepController`, `AppServiceProvider`, `routes/api.php`, `WorkspaceIsolationTest` |
| First project + column labels (§8 onboarding 1–2): migration `2026_10_31_140000_multi_user_projects_web` (`projects.owner_agent_id` nullable, FK kept; + `description`, + `column_labels` JSON; down() refuses while a project has no PM, a description or labels). Web `POST /projects` (`projects.store`, logged-in owner/member, own workspace, 30/min) {name, slug (lowercase-dashes, ≤ 64), ticket_prefix (`TicketPrefix::PATTERN`), description?} → 201 {id, name, slug, ticket_prefix, description, column_labels_url}; slug / prefix still globally unique: taken → 422 on that field "This slug is already taken." / "This ticket prefix is already taken." (never says where; a lost race answers the same). `PUT /projects/{slug}/column-labels` (`projects.column-labels.update`) {column_labels: {key: label}}: known keys only, 1–40 chars after trim, unsent keys keep theirs, a default label is not stored; another workspace's project 404, guests 401. Labels flow into `BoardView` (board props and the API board), Portfolio `columnLabels`, `/welcome`. `POST /api/projects` unchanged (owner_agent_id required, same keys) | `ProjectSetupController`, `BoardColumns::all($labels)`, `Project::boardColumns()`, `tests/Feature/MultiUser/ProjectSetupTest.php` |
| Isolation (§5): Tilen's token and web session get 404/empty on another user's project, board, tickets, statuses, usage; admin keys; grep test pins `acrossWorkspaces()` / scope removal / raw `DB::` | `tests/Feature/MultiUser/AdminIsolationTest.php`, `tests/Unit/AcrossWorkspacesUsageTest.php` |

Step 1 notes: all routes are JSON in the `web` group (CSRF applies; the screens of step 4 call them); web pages need a
normal login. A soft-deleted user's email stays taken (unique). Deleted users keep their workspace
(no workspace marker column yet; the soft-deleted owner is the marker). Changing the password also rotates the remember
token, so this device's "keep me logged in" cookie ends too (its session continues). Still global by design (later step): client-chosen
ids (`unique:` on `projects` / `agents` / `status_updates` / `next_steps` ids) and project slug / ticket prefix, so a
create with an id or slug another workspace already uses answers 422 "taken"; this only shows that the key is in use
somewhere, not where, and nothing can be read or written through it.

**UI (step 4, branch `feat/multi-user`, Tommy's prototypes `design/bridge-multiuser/`):** styles from `shared.css` §4
in `resources/css/account.css` (kanban excerpts lifted / scoped to `.colprev`, prototype `.proto` dropped; 720 / 1100 px
breakpoints, reduced motion). Every write goes to the JSON routes above; the new GET routes only render Inertia props
(no new business logic). Tests: `tests/Feature/Screens/*`.

| Screen | Route → component | Notes |
|---|---|---|
| Shell | shared props `auth.user {id, name, email, initials, is_admin, email_verified}` (null for guests), `features.polymarket`, `features.admin` | `TopBar`: account menu (Account, API tokens & MCP, Log out = `POST /logout`), Owner chip + Admin nav for `is_admin`, Polymarket only for the workspace owner with the feature |
| Log in | `GET /login` → `Auth/Login` | guests are redirected here; wrong (tries left), locked (countdown to `locked_until`, reset link), disabled, deletion pending → "Cancel deletion and log in" (`POST /login/cancel-deletion`) |
| Sign up | `GET /signup` → `Auth/Signup` {formToken, turnstileSiteKey, minPassword} | honeypot `website` + `form_token` always; the Turnstile script loads only when `turnstile.site_key` is set (secret never in props); email taken banner; lands on `/welcome` |
| Reset | `GET /forgot-password` → `Auth/ForgotPassword`; `GET /reset-password/{token}?email=` (`password.reset`, the mail link) → `Auth/ResetPassword` {token, email, valid} | request → sent (neutral), set new (meter, match line) → success, expired (also for an unknown email) |
| Account | `GET /account` → `Account/Settings` {account, sessions, timezones, minPassword, deletion {graceDays, counts}, can} (JSON clients keep the JSON) | verify-email banner (resend), profile (read-only until `account.update`), password, sessions (this device, others, log out others with password, all devices), danger zone (export download, delete with typed email, 7-day grace → `/login?status=deletion-requested`) |
| Tokens & MCP | `GET /account/tokens` → `Account/Tokens` {tokens (masked), mcpUrl, emailVerified, expiryDays} (JSON clients keep the JSON) | create read / read & write, 30/90/365/never (verified only), show-once plaintext from the POST response with copy, revoke (alertdialog), MCP URL / header / JSON snippets |
| Onboarding | `GET /welcome` (auth) → `Onboarding` {name, project, hasToken, emailVerified, columns (first project's labels), defaultColumns, createProjectUrl, saveColumnsUrl (null until a project exists)} | derived steps; step 1 name / slug / prefix (follow the name until edited) / description: empty, errors (summary + field, "already taken"), loading, created (Back shows it); step 2 renames the columns with the URL from step 1's answer |
| Board settings | `GET /projects/{slug}` + {columnLabelsUrl (logged-in members only), defaultColumns}; `board.project.description` | "Column labels" button → sheet / dialog (default per column, "Use default names", per-field errors) → toast + board reload; description under the name |
| Admin | `GET /admin/users` → `Admin/Users` {users (exactly `AdminUsers::KEYS`), meId} (JSON clients keep the JSON); 404 for non-admins | status chips, disable (confirm) / enable; no counts, no invited state |
| Usage | `/usage` (`Usage/Overview`) | Cursor models section: stats, per-model table, daily chart by model, one account "Cursor (all agents)", cost when the CSV had one (`costCents`, only where a row has `cost_cents`); "no file that day" vs zero per tool from `cli.importedTools` (coding tools and Cursor); owner's upload panel (`cli.cursorUpload.allowed`) with summary, kept partial edge days and 422 line errors |

**Redesign screens (Figma, on `feat/multi-user`; the routes/props above are unchanged):** one shared
`resources/css/tokens.css` (Figma-named variables, byte-identical to the landing's copy) + Geist / Geist Mono;
the legacy `bridge.css` `:root` still shadows `--paper` / `--line` / `--ok` in the app, so screens that need the
token values repeat those three locally.

| Screen | Styles / scope | Notes |
|---|---|---|
| App shell + top bar (guest / user / owner) | `app-shell.css` (`.app` / `.atb`), `AppTopBar` | top bar states per audience; onboarding lives on it |
| Portfolio cards | `app-shell.css`, Portfolio | redesign cards take the workspace's waiting label; ReadyBanner ("… is ready" via `GET /?ready=<slug>`) and the dashed violet ConnectBotCard mount until the first API token |
| Account | `account-redesign.css` (`.acx`) | Tommy's Figma |
| API tokens & MCP | `account-redesign.css` (`.acx`) | Tommy's Figma |
| Usage overview + single agent | `usage-redesign.css` (`.bu`) | charts use only `--chart-1..5` / `--chart-grid` |
| Polymarket desk (owner only) | `pm-desk.css` (`.pmx`) | KPIs, book chart, positions, fills, sweeps; non-owner 404 |
| Auth: sign up / log in / reset, all states | `auth.css` on tokens.css | AuthLayout (dot-grid stage + 400 px column, GuestTopBar narrow), Button / TextField / Badge / Wordmark; Turnstile module/sign-up kept |
| Onboarding steps 1–3 + ready banner | `onboarding.css` | stepper (done ink / current lime / next outline) + cards, auth components reused |
| Landing page | `landing.css` + hero | public, no login; draft blocks (origin story, why free, no-ads card, coffee section, FAQ 1) behind `BRIDGE_LANDING_SHOW_DRAFTS` (default off = not rendered) |
| Legal pages + footer | §13(a), `SiteFooter` + Blade partial | one footer on every page, EN \| SL |

Tests with the redesigns: usage screens, Polymarket desk states (owner-only 404, props, stale, unavailable, no
positions), rendered footer lines EN/SL, landing page (incl. tokens/whitelisted-selectors greps).

Also: the verification mail link opened in a browser redirects (tokens page or login) with a flash instead of
showing JSON; a user disabled mid-session lands on `/login` with the reason; an empty Portfolio links "Create your first
project" to `/welcome`; Portfolio cards count tickets under the project's own column labels.

**Before deploying (The Many):** (1) set `BRIDGE_OWNER_EMAIL` + `config:clear` before `migrate` — without it the
backfill throws and `migrate` stops (it never picks the token owner or first user); (2) `SELECT id, name, abilities FROM
personal_access_tokens` — each needs `*`/`ceo` or the abilities it uses; (3) rehearse on a dump copy incl.
`migrate:rollback --step=4`; (4) after: counts + smoke tests; (5) `SESSION_DRIVER=database` (sessions / disable / log
out everywhere rely on it); (6) `MAIL_*` before sign-up opens (verification; until the domain is decided `MAIL_MAILER=log`, set
`MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` when it is); (7) `TURNSTILE_*` once The Many has created the keys; the vhost CSP needs `https://challenges.cloudflare.com` in `script-src` and `frame-src` (Cloudflare's Turnstile CSP page); `data:` in `connect-src` only silences Turnstile's harmless `data:,` probes (not required by Cloudflare); the app sets no CSP of its own; (7b) PHP
`upload_max_filesize` / `post_max_size` ≥ 10 MB for `POST /usage/cursor-csv`; (8) the Laravel scheduler cron
(`* * * * * php artisan schedule:run`), which runs `bridge:accounts-delete-expired` and `bridge:accounts-purge` daily;
both are no-ops while `BRIDGE_ACCOUNT_PURGE_ENABLED=false`; (9) `laravel/mcp` is a runtime dependency now: the deploy runs
`composer install --no-dev --optimize-autoloader` (vendor must contain it, else every request fails at boot);
(10) `BRIDGE_HIBP_ENABLED` stays unset (on) — the box needs outbound HTTPS to `api.pwnedpasswords.com` (fail-open
otherwise, warning in the log); (11) after deploy, `POST /mcp` `tools/list` with a verified user's token.

**Staging (a copy migrated before (1) was enforced: Captain's API user is owner + admin, no owner user):**
1. `php artisan bridge:set-owner --email=<owner email> --name=<name> --dry-run` (`--workspace` defaults to
   `bridge.default_workspace`), then without `--dry-run`. The owner is created (verified, admin, unusable password) and
   becomes owner; Captain becomes member, not admin; tokens untouched. The owner sets a password via `/forgot-password`
   (with `MAIL_MAILER=log` the mail is in the log; never paste the link).
2. `php artisan bridge:rotate-copied-tokens --out=<new file>` (`APP_ENV=staging`, or `--force-env=staging` on a
   non-production env; refuses when `APP_URL` is `BRIDGE_LIVE_HOST`). Every copied live token gets a new plaintext in
   place; the new `NAME=id|token` lines (`BRIDGE_API_TOKEN` for `ceo`) are only in that 0600 file. Live tokens then
   answer 401 there.

**Prod:** set `BRIDGE_OWNER_EMAIL` (and the workspace slug/name the database was migrated with) before `migrate`
(step (1) above); no set-owner / rotation needed. The exact sequences and values are kept privately.

**Configuration (env, all optional unless noted):**

| Env | Default | Meaning |
|---|---|---|
| `BRIDGE_OWNER_EMAIL` | – (required before the backfill migration) | owner of the backfilled workspace |
| `BRIDGE_WORKSPACE_SLUG` / `BRIDGE_WORKSPACE_NAME` | `main` / "My workspace" | backfilled workspace (console writes act in it); a migrated database must keep its slug |
| `BRIDGE_PUBLIC_URL` | `APP_URL` | base for ticket links in API payloads |
| `BRIDGE_LIVE_HOST` | empty (no host check) | the live host `bridge:rotate-copied-tokens` refuses |
| `BRIDGE_OWNER_WAITING_LABEL` | empty ("Waiting on " + default workspace owner's first name) | `waiting_tilen` label stored on workspaces existing at migration `2026_10_31_160000` (§0 decision 10) |
| `BRIDGE_LEGAL_ENTITY` | "Your company" | operator name in the footer © line (`:entity`) |
| `BRIDGE_TERMS_VERSION` | `2026-10-draft` | stored as `users.terms_version` at sign-up |
| `BRIDGE_LEGAL_DIR` | empty (placeholders in `resources/legal`) | directory with the final legal texts, outside the repo |
| `BRIDGE_SOURCE_URL` / `BRIDGE_DEPLOYED_SHA` | the public repo / empty (else `.bridge-deploy-sha`) | AGPL §13 "Source code" link, `/tree/<sha>` when known |
| `BRIDGE_ACCOUNT_PURGE_ENABLED` | `false` | the only switch for `bridge:accounts-purge` (§13) |
| `BRIDGE_SUPPORT_EMAIL_RETENTION_DAYS` | `365` | config only; support mailbox clean-up is manual |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_DAILY_DAYS` | `daily` / `daily` / `14` | daily log files kept 14 days |
| `BRIDGE_ROSTER_PATH` | empty (empty roster) | JSON file outside the repo: `{"agents": {"agent-id": ["Name", "Role"]}, "pms": {"project-id": "agent-id"}, "ticket_prefixes": {"project-id": "PREFIX"}}` (`ticket_prefixes` optional); used by the 2026_10_29 roster migration, `bridge:set-pms` and fixed ticket prefixes. Missing / unreadable / invalid → empty roster and one warning log line (no contents), nothing changes |

## 13. Legal, retention and purge (31 Oct)

- **(a) Legal pages + footer.** Public `/terms`, `/privacy`, `/imprint` and `/sl/pogoji`, `/sl/zasebnost`, `/sl/impresum`
  (no login); placeholder text until the final texts drop into `BRIDGE_LEGAL_DIR` (markdown, rendered with
  HTML stripped). Each page shows "For users in Slovenia, the Slovenian version prevails" and an EN | SL switch (legal
  pages only; the app stays English). One footer on every page (`SiteFooter`, nav landmark): Imprint · Terms · Privacy
  · Source code (AGPL-3.0-or-later), and the © line with `:entity` and the source link (deployed commit when known).
  Copy in `lang/{en,sl}/legal.php`, tested character for character against the legal UI copy.
- **(b) Sign-up acceptance.** One required, unticked checkbox (18+, Terms, Privacy), button disabled until ticked,
  sensitive-data small print; server requires `accept_terms`; `users.terms_accepted_at`, `age_confirmed_at`,
  `terms_version` (additive; existing users stay null). Turnstile loads only when the form is submitted (execute mode,
  interaction-only); the widget's Pre-clearance setting must stay off (no `cf_clearance` cookie).
- **(c) Hard purge.** `bridge:accounts-purge [--dry-run]`, scheduled daily, does nothing unless
  `BRIDGE_ACCOUNT_PURGE_ENABLED=true`. Due: soft-deleted users whose request is 7 + 30 = 37 days old. In one
  transaction per user it deletes the workspaces they solely own and every row that references them or the user —
  tables are found from the schema (`workspace_id`, `user_id`, foreign keys, `<name>_id`, token morphs, password
  resets), children first — then the user row (anonymised instead if a constraint still holds it). Skipped and
  reported: admins, the default workspace, workspaces with other members. Logs one line per user: id + row counts per
  table, never email or name. A test proves no row in any table still references the purged user or workspace and
  that every other row is byte-identical. The soft-delete command is unchanged.
- **(d) Logs.** Default channel `daily`, kept 14 days (`LOG_DAILY_DAYS`).
- **Licence / CLA.** AGPL-3.0-or-later (LICENSE, NOTICE, CLA.md, CONTRIBUTING.md); CLA Assistant Lite workflow
  (`.github/workflows/cla.yml`, signatures on the `cla-signatures` branch, which must be created before the first PR).

**Open:** (1) the purge flag: conflicts with the standing "nothing hard-deleted" rule; stays off until Tilen decides.
(2) Final legal texts (EN + SL) and the operator name (`BRIDGE_LEGAL_ENTITY`), then a real `BRIDGE_TERMS_VERSION`.
(3) Create the `cla-signatures` branch and enable Actions on the public repo (not done here).

## 12. Open questions for Tilen

Answered by the Chief of Staff on 31 Oct (§0): MCP → `laravel/mcp`; staging first; HIBP breached check, fail-open;
per-project scopes v2; working defaults confirmed; Turnstile; workspace name/slug from config. Answered by Tilen
(relayed by the CoS, 31 Oct 08:49, §0): owner email (env); Cursor individual plan → CSV import, one "Cursor (all
agents)" account; mail stays on `log` with an env-configured sender; Turnstile keys created by The Many. Still open:

1. SMTP + SPF/DKIM/DMARC for `usebridge.si`, The Many (domain decided 31 Oct 09:36). Until then `MAIL_MAILER=log`.
