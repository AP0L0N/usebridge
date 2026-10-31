# Bridge multi-user plan (v2)

Tilen's initiative (31 Oct 2026, via Captain W. Diego): other people run their own projects in Bridge with their own Grok
bots. Tommy + Marc's prototypes: `bridge-design-multiuser/design/bridge-multiuser/` (DESIGN.md + 8 screens). Groundwork
is on `feat/multi-user` (§11, not deployed). Public landing page: not now.

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
2. **Owner admin = user list + disable/enable ONLY.** Tilen cannot see other users' boards, tickets, statuses, usage or
   any data; no impersonation. Enforced in API + query layer (§5).

**Working defaults (CoS, 31 Oct, not confirmed by Tilen)** — we build to these; each is cheap to change:

| Topic | Default |
|---|---|
| MCP | `/mcp`, streamable HTTP, `Authorization: Bearer`, rate limit per token |
| Token scopes v1 | `read` / `read & write` (mapping §4); per-project scoping later |
| Token expiry | 30 / 90 / 365 days or none |
| Polymarket, Admin for non-owners | 404 |
| Passwords | ≥ 10 chars, strength meter, no composition rules; breached check optional/open (code: 10) |
| Lockout | 5 failed logins → account locked 15 min |
| 2FA | later |
| Email verification | required before creating tokens (and MCP); not for using the boards |
| Account delete | 7-day grace, data export first; soft delete, purge only with Tilen's OK |
| Sessions | "Log out of all devices" in v1 (database sessions) |
| Existing data | all projects, agents, usage → Tilen's user/workspace (the §11 backfill does this) |

**DESIGN.md open questions**

| # | Question | Answer | Status |
|---|---|---|---|
| 1 | Cursor data source | Admin API `filtered-usage-events` via Captain; CSV fallback (§7) | working default; Cursor plan open |
| 2 | Cursor agent attribution | `serviceAccountId`/`userEmail` → agent map kept by Captain; else *Unattributed* | working default |
| 3 | Scopes, expiry | read / read&write; 30/90/365/none; per-project later | working default |
| 4 | MCP endpoint | `/mcp`, streamable HTTP, Bearer, per-token limit, tools §6 | working default; dependency open |
| 5 | Email verification | before tokens/MCP, not before use | relayed (1) + default |
| 6 | Password policy | 10 chars, meter, 5 → 15 min lock, 2FA later; breached check | default; breached open |
| 7 | Owner admin | list + disable/enable only; no data, no delete, no impersonation; open sign-up | **decided** (relayed) |
| 8 | Data separation | everything existing → Tilen; shared-box CLI logs stay Tilen's (other users' tools aren't on the box) | default |
| 9 | 404 or 403 | 404 | default |
| 10 | Account deletion | 7-day grace + export, soft delete | default |
| 11 | Log out all devices | yes, v1 | default |

## 1. Data model

Done (§11): `workspaces` (`slug`, `name`, `owner_user_id`, `features` json), `workspace_user` (`role`
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
| `projects` | `column_labels` json null (rename/hide per fixed key) | onboarding step 2 (columns are fixed in `BoardColumns`, incl. "Waiting on Tilen") |
| `cli_usage_imports` | `workspace_id`, `date`, `tool`, `rows`, `imported_at`; unique (ws, date, tool) | "no file" vs zero (§7) |
| `cli_usage_days` | `cost_cents` decimal(12,4) null, `max_mode_calls` int null | Cursor (§7) |
| `ticket/project_read_states` | `user` = user id instead of `'tilen'` | with login |

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
  user and marks the workspace; **no purge** of rows without Tilen's explicit OK. Export first: a JSON download of the
  workspace (projects, tickets, comments, statuses, usage) from the Danger zone.
- Password reset: built-in broker, 60 min, 60 s throttle (done in `AccountService`). CSRF, `same_site=lax`,
  `SESSION_SECURE_COOKIE=true` on live. Transition: the AUTH-1 gate stays until login ships, then it is removed and
  gate sessions invalidated.

## 3. Migrating current data

Done: `2026_10_31_110000_multi_user_backfill` (owner = `BRIDGE_OWNER_EMAIL`, else the token owner, …; workspace
`tilen`/`LANTIS`, polymarket on; every row and token → that workspace; idempotent, `down()` drops only what it made).
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
    other users have a collector).
  - Legacy `ceo` / `*` keep meaning everything; the UI never issues them. List shows scope derived from abilities.
- Expiry 30/90/365 days or none → `expires_at` (Sanctum rejects expired tokens). Rate limit 120/min per token (API
  and MCP). Creation requires a verified email.
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
  works. **MCP placeholder plan** (when `/mcp` lands): owner token calling `get_board`/`list_tickets`/`get_ticket` on
  another workspace's project → not found; `tools/list` identical for owner and user except feature tools.

## 6. MCP server

`POST /mcp` (JSON-RPC 2.0, streamable HTTP; `GET` 405, no server stream), Bearer per-user token, same workspace
resolution, abilities, verification and disabled checks as `/api`. Tools (thin wrappers over `TicketService`,
`ProjectStatusService`, `StatusUpdateIngest`, `BoardView`, `TicketView`), each declaring its ability:
read — `list_projects`, `get_board`, `list_tickets`, `get_ticket`, `list_agents`, `list_labels`; write —
`create_ticket`, `update_ticket`, `move_ticket`, `comment_ticket` (`boards:write`), `post_status`,
`set_project_status` (`status:write`). Implementation: `laravel/mcp` (new dependency, Tilen's call) or a hand-rolled
controller (`initialize`, `tools/list`, `tools/call`, `ping`; ~300 lines, no dependency).

## 7. Cursor usage

**Feasibility.** Admin API: yes, needs a Cursor **Team** plan admin key (Basic auth). `POST
https://api.cursor.com/teams/filtered-usage-events` (`startDate`/`endDate` epoch ms, `email?`, `page`, `pageSize`) returns
per event: `timestamp`, `userEmail`, `serviceAccountId/Name`, `cloudAgentId`, `automationId`, `conversationId`,
`model`, `kind`, `maxMode`, `requestsCosts`, `isTokenBasedCall`, `isChargeable`, `isHeadless`, `tokenUsage
{inputTokens, outputTokens, cacheWriteTokens, cacheReadTokens, totalCents}`, `chargedCents`, `cursorTokenFee`.
`/teams/daily-usage-data` has no per-model tokens → not enough. Limits: ≤ 30-day ranges, 20 req/min, poll ≤ hourly.
CSV: dashboard export (Date, Kind, Model, Max Mode, Input w/ and w/o Cache Write, Cache Read, Output, Total, Cost)
works on individual plans but is manual, one account per file, no attribution beyond the account.

**Recommendation**: Admin API, pulled by **Captain** (Bridge never stores a Cursor key), aggregated per
Europe/Ljubljana day × agent × model; attribution via `serviceAccountId`/`userEmail` → Bridge `agent_id` map kept by
Captain, unmapped → `unattributed`. CSV (converted by Captain into the same rows) if LANTIS is not on a Team plan.
Row (extends `cli_usage`; replaces the earlier `cost_usd`/`requests` proposal — "Requests" in the UI = `calls`):

```json
{"tool": "cursor", "agent_id": "agent-erin", "name": "Erin Bloome", "model": "claude-4.5-sonnet",
 "input": 120000, "output": 8000, "cache_read": 900000, "cache_creation": 0, "calls": 42,
 "cost_cents": 312.5, "max_mode_calls": 3}
```

`input` = inputTokens, `cache_creation` = cacheWriteTokens, `calls` = event count, `cost_cents` = Σ chargedCents
(optional), `max_mode_calls` (optional). `CliUsageDay::TOOLS += 'cursor'`.

**Ingest change (done, branch `wip/usage-per-tool`)**: `CliUsageImporter` used to delete **all** cli rows of the date
before inserting, so a separate Cursor POST would have wiped that day's Claude Code/Kimi/OpenCode rows. Now it
**replaces per (workspace, date, tool)** for the tools the file covers, in one transaction. A collector object takes an
optional top-level **`covered_tools: [...]`** (distinct `CliUsageDay::TOOLS`, max 10; not `tools`, which is the rows
key) = "this file is authoritative for these tools on this date, even with zero rows"; a row whose tool is not listed →
422. Without it the covered set is the tools in the rows, plus `CliUsageDay::LEGACY_COLLECTOR_TOOLS` (claude_code,
kimi_code, opencode_go) when no row is `cursor`, so today's `cli_usage.py` files (empty ones too) behave exactly as
before. Captain's Cursor file should send `"covered_tools": ["cursor"]`. Rows may carry `cost_cents` (number 0–1e12)
and `max_mode_calls` (int 0–`calls`), stored in nullable `cli_usage_days` columns. Each covered (workspace, date, tool)
upserts a `cli_usage_imports` marker (`rows`, `total`, `imported_at`; backfilled from existing rows): marker + no rows
= zero, no marker = "no file for that day". The Usage page props carry `cli.importedTools` (date → covered tools); the
Cursor tile shows up once Cursor has rows in the range (no other UI yet). Cadence: nightly for yesterday + re-POST of the
last 2 days (late events).

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
| onboarding step1 | web `POST /projects` {name, description, pm} (today projects are created only via API) |
| onboarding step2 | `PATCH /projects/{slug}/columns` → `column_labels` (rename/hide v1; add/reorder later) |
| onboarding step3 / done | link to tokens; checklist state derived (has project, has columns saved, has token) — no new column; example project = step 1's project |
| usage 7d / yesterday / 30d | existing `/usage` per range, scoped (only own agents) |
| usage Cursor section / cursor-empty | `cli_usage_days` where tool=cursor: per model {requests, input, output, cache_read, total}, per agent with model rows; empty = no cursor marker in range |
| shell | shared props `user {name, initials, email}`, `is_owner`, `features.polymarket`, `features.admin`; non-owner → 404 |
| shell admin | `GET /admin/users` → [{id, name, email, created_at, last_login_at, status}]; disable/enable. "View as" = prototype only |

## 9. Step order

Done: schema, backfill, scoping, Polymarket gate, per-workspace keys, `AccountService`, token abilities, and step 1
backend auth (§11). Next, each with tests, deployable alone:
1. ~~Backend auth~~ — done (§11, JSON only). Left from it for later steps: `PATCH /account` + email change
   (`pending_email` columns exist, flow not built), `GET /reset-password/{token}` screen, sessions list device parsing.
2. MCP endpoint (after the dependency decision).
3. Cursor ingest: scoped replace + markers, `cursor` tool, new columns.
4. UI from the prototypes: login/signup (Turnstile) / reset / account / tokens / onboarding / admin / usage Cursor;
   then remove the gate, read states per user.
5. Later: `workspace_id` NOT NULL, per-workspace slug/prefix/key, per-project token scopes, `--workspace` for
   artisan imports, first-agent creation for new workspaces (`POST /api/agents` is Captain-only today).

## 10. Risks

- **No staging**: backfill runs on live data first → rehearse on a dump copy, counts before/after (§3).
- Any query outside Eloquent (`DB::table`) or via `acrossWorkspaces()` is unscoped → review rule + grep test.
- Hand-made live tokens without `*`/`ceo` lose access (abilities enforced) → check before deploy (§11).
- Per-account lockout can be triggered by anyone knowing an email (15 min, reset unlocks).
- Open sign-up before mail works = unverified accounts that can use boards but not tokens/MCP.
- Global slug/prefix: another user can't reuse Tilen's and learns it exists, until per-workspace keys.

## 11. Groundwork done (branch `feat/multi-user`)

Not deployed. All green incl. `tests/Feature/MultiUser/*`.

| Piece | Where |
|---|---|
| Schema, backfill, per-workspace keys | `2026_10_31_100000/110000/120000_multi_user_*` |
| `Workspace`, `CurrentWorkspace`, `WorkspaceScope` + `BelongsToWorkspace` (17 models), `MissingWorkspace` | `app/Models`, `app/Support/Workspaces` |
| `WorkspaceBoundary`, gate → Tilen's workspace, `ResolveTokenWorkspace`, `EnsureTokenAbility` | `app/Http/Middleware`, `routes/api.php` |
| `WorkspaceRule::exists`, bulk inserts set `workspace_id` | controllers, importers, `BridgeRoster`, `TicketService` |
| Polymarket feature gate | `RequireWorkspaceFeature`, `HandleInertiaRequests`, `TopBar.tsx` |
| `AccountService` (register + workspace + verification mail, reset, change password; `MIN_PASSWORD = 10`) | `app/Services/Auth` |
| Auth columns: `users` lockout/last login/disabled/pending email/timezone/delete grace/soft delete, `token_suffix`; existing users marked verified; `usage_upload` feature on Tilen's workspace | `2026_10_31_130000_multi_user_auth` |
| Login: `LoginService` (5 fails → 15 min lock, unknown emails identical via `RateLimiter`, 10/min per IP, disabled / deletion pending → 403, `last_login_at`, regenerate), `POST /login`, `/logout`, `/login/cancel-deletion` | `LoginService`, `Auth\LoginController` |
| Sessions: `DeviceSessions` (list own rows, hashed ids; others; all + remember token), `GET/DELETE /account/sessions[/others]`; password change ends other sessions, reset ends all and clears the lock; `POST /forgot-password` (neutral), `/reset-password`, `PUT /account/password` | `DeviceSessions`, `Auth\SessionController`, `Auth\PasswordController` |
| Verification: `User implements MustVerifyEmail`, signed `GET /email/verify/{id}/{hash}`, `POST /email/verification-notification`; `RequireVerifiedEmail` (JSON 403) on token creation, ready for `/mcp` | `Auth\EmailVerificationController`, `RequireVerifiedEmail` |
| Tokens: `ApiTokens` (read/write → abilities, usage/polymarket by feature; 30/90/365/none; `brg_live_` default via `SANCTUM_TOKEN_PREFIX`; `token_suffix`; bound to the workspace; masked list, legacy = no suffix; revoke own), `GET/POST/DELETE /account/tokens` | `ApiTokens`, `Auth\TokenController`, `config/sanctum.php` |
| Disabled / deletion pending: `ResolveTokenWorkspace` 403 (tokens blocked, not revoked), `LogoutBlockedUser` (web), logged-in web users act in their own workspace (`User::homeWorkspace`, login wins over the gate) | `app/Http/Middleware` |
| Admin: `GET /admin/users` → `[id, name, email, created_at, last_login_at, status]` only; `POST /admin/users/{id}/disable\|enable` (not self; disable deletes sessions); 404 unless logged-in `is_admin` | `AdminUsers`, `Admin\UserController`, `RequireAdmin` |
| Delete: `POST /account/delete` {email} (grace 7 days, logged out everywhere), cancel at login, `bridge:accounts-delete-expired [--dry-run]` soft-deletes (no purge; workspace rows kept), `GET /account/export` (own workspace JSON, scoped) | `AccountDeletion`, `WorkspaceExport`, `routes/console.php` |
| Sign-up: `GET /signup/form` (signed `form_token`, Turnstile site key), `POST /signup` (5/h per IP, honeypot `website`, ≥ 3 s fill time, Turnstile server-side when `TURNSTILE_SECRET_KEY` is set, fail closed), logs in unverified | `SignupController`, `SignupBotCheck`, `TurnstileVerifier`, `config/turnstile.php` |
| Isolation (§5): Tilen's token and web session get 404/empty on another user's project, board, tickets, statuses, usage; admin keys; grep test pins `acrossWorkspaces()` / scope removal / raw `DB::` | `tests/Feature/MultiUser/AdminIsolationTest.php`, `tests/Unit/AcrossWorkspacesUsageTest.php` |

Step 1 notes: all routes are JSON in the `web` group (CSRF applies; screens come in step 4); the AUTH-1 gate still
works for the existing pages. A soft-deleted user's email stays taken (unique). Deleted users keep their workspace
(no workspace marker column yet; the soft-deleted owner is the marker). Changing the password also rotates the remember
token, so this device's "keep me logged in" cookie ends too (its session continues).

**Before deploying (The Many):** (1) set `BRIDGE_OWNER_EMAIL` + `config:clear` before `migrate`, else Captain's API
user becomes owner; (2) `SELECT id, name, abilities FROM personal_access_tokens` — each needs `*`/`ceo` or the
abilities it uses; (3) rehearse on a dump copy incl. `migrate:rollback --step=4`; (4) after: counts + smoke tests;
(5) `SESSION_DRIVER=database` (sessions / disable / log out everywhere rely on it); (6) `MAIL_*` before sign-up opens
(verification); (7) optional `TURNSTILE_*`; (8) daily cron `php artisan bridge:accounts-delete-expired`.

## 12. Open questions for Tilen

1. Cursor: which plan is LANTIS on — Team (admin API key, held by Captain) or individual (CSV)?
2. MCP: add `laravel/mcp` or hand-roll?
3. Mail provider and sender (e.g. `bridge@tilenpoje.eu`, SPF/DKIM); who holds the credentials (The Many)?
4. Turnstile keys (Cloudflare dashboard) — OK to use Cloudflare?
5. Staging: second vhost `bridge-staging.tilenpoje.eu` with a dump copy, set up by The Many?
6. `BRIDGE_OWNER_EMAIL` (Tilen's login email) and workspace name `LANTIS` / slug `tilen`?
7. Breached-password check (HIBP k-anonymity call on signup/reset): yes/no?
8. Per-project token scopes: when?
9. Confirm the working defaults in §0 (CoS, not yet confirmed by Tilen).
