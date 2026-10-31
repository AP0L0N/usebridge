# Bridge multi-user plan

Tilen's initiative (31 Oct 2026, via Captain W. Diego): other people run their own projects in Bridge with their own Grok
bots. Tommy + Marc design the screens; this plan covers the backend. Phase A = this document, Phase B = groundwork on
`feat/multi-user` (see the last section). Public landing page: not now.

## 1. Data model

- **users** += `is_admin` (bool, default false). `name`, `email` (unique), `email_verified_at`, `password` already exist.
- **workspaces**: `id`, `slug` (unique), `name`, `owner_user_id` (FK users), `features` (json, e.g. `{"polymarket": true}`), timestamps. One workspace = one person's Bridge (projects,
  agents, boards, usage).
- **workspace_user**: `workspace_id`, `user_id`, `role` (`owner|admin|member`), unique (workspace_id, user_id).
  Membership is needed already in v1: on live the Sanctum tokens belong to Captain's API user
  (`captain@lantis.internal`, seeded by `BridgeApiSeeder`), while Tilen logs in as himself. Both must reach the same
  workspace. Sharing a workspace with more people is then a data change, not a schema change.
- **personal_access_tokens** += `workspace_id` (nullable FK): the workspace a token acts in.

**Which tables get `workspace_id`.** Every table that a model queries directly gets its own column (denormalised for
children), so one global scope (`workspace_id = ?`) covers every query, including `whereHas`, `toBase()->update()` and the
comment feed cursor, without joins:

| Kind | Tables |
|---|---|
| roots | `projects`, `agents`, `labels`, `usage_days`, `cli_usage_days`, `polymarket_snapshots`, `polymarket_series_points` |
| children (denormalised, copied from the project/ticket on create) | `tickets`, `ticket_comments`, `ticket_events`, `ticket_read_states`, `project_read_states`, `project_status_events`, `project_pm_events`, `status_updates`, `required_inputs`, `next_steps`, `questions` |
| inherit, no column | `ticket_label` (both ends are scoped); `legacy_r0_project_fields`, `legacy_r0_milestones`, `legacy_r0_work_items`, `legacy_r0_risks`, `legacy_r0_ops_digest_items`, `legacy_r0_ceo_queue_items` (frozen r0 archive, read by no code, all Tilen's) |
| not owned | `users`, `password_reset_tokens`, `sessions`, `cache*`, `jobs*`, `failed_jobs`, `migrations` |

Indexes: `workspace_id` alone on every table above (FK on MySQL), plus composite keys where lists are read
(`projects (workspace_id, slug)` arrives with the unique change below).

**Uniqueness that must become per-workspace.**

| Key today | Target | When |
|---|---|---|
| `usage_days.date` | (workspace_id, date) | Phase B (a second workspace cannot post usage otherwise) |
| `cli_usage_days (date, tool, agent_id, model)` | (workspace_id, date, tool, agent_id, model) | Phase B |
| `polymarket_series_points.ts` | (workspace_id, ts) | Phase B |
| `labels.name` | (workspace_id, name) | Phase B |
| `projects.slug`, `projects.ticket_prefix`, `tickets.key` | per workspace | later (validation rules + lookups change together; until then they stay globally unique, so two users cannot both have `CA-1`; prefix generation reads all workspaces) |
| string PKs `proj-*`, `agent-*`, `tk-*`, `lb-*`, `su-*`… | stay global PKs | later, if ever: generated ids are ULIDs; only hand-picked ids (`agent-william`) collide. Option: a new user's agents get a workspace-prefixed id. |
| `ticket_read_states (user, ticket_id)`, `project_read_states (user, project_id)` | `user` becomes the user id instead of `'tilen'` | with the login screens |

The `down()` of a per-workspace unique change refuses (exception, nothing deleted) when restoring the global key would
collide; there is nothing to restore by deleting.

## 2. Web auth

- Laravel `session` guard (`web`), hand-rolled with framework built-ins: Fortify is not installed and this run adds no
  dependency. Login = `Auth::attempt` + `session()->regenerate()` (fixation), logout = `Auth::logout` +
  `invalidate()` + `regenerateToken()`. Passwords via `Hash` (bcrypt, `BCRYPT_ROUNDS=12`; argon2id is a one-line
  `config/hashing.php` switch if the box has it — rehash on login is automatic). Min 12 characters + `Password::uncompromised()` is
  an option (it calls the HIBP API: decide).
- Password reset: built-in broker (`Password::sendResetLink` / `Password::reset`), table `password_reset_tokens` exists,
  60 min expiry, 60 s throttle. Reset link = `APP_URL/reset-password/{token}?email=` (built in code, so no route is
  needed until the screen exists).
- **Mail**: `MAIL_MAILER` defaults to `log` (`config/mail.php`, `.env.example`): live sends nothing today. The Many
  must set `MAIL_MAILER=smtp` (or `postmark`/`ses`), `MAIL_HOST/PORT/USERNAME/PASSWORD/SCHEME`,
  `MAIL_FROM_ADDRESS` (e.g. `bridge@tilenpoje.eu`, SPF/DKIM on that domain) and `MAIL_FROM_NAME=Bridge` before reset
  mail goes live.
- Rate limits (`RateLimiter::for`): login 5/min per email+IP, reset request 3/15 min per email+IP, signup 5/hour per IP,
  API 120/min per token, MCP the same.
- CSRF: Laravel's `VerifyCsrfToken` + Inertia's XSRF cookie, unchanged. Session cookie `secure`, `http_only`,
  `same_site=lax` (set `SESSION_SECURE_COOKIE=true` on live).
- Signup: **open question** (open signup vs invite-only). Recommendation: invite-only (admin creates an invite link) until
  abuse handling exists; `AccountService::register` serves both.
- **Transition**: the AUTH-1 gate (`EnsureWorkspaceGate`, shared password) stays until login screens ship. A gate-unlocked
  session acts in Tilen's workspace (`bridge.default_workspace`). Then: login replaces the gate, the gate route is
  removed, `bridge_gate_unlocked` sessions are invalidated.

## 3. Migrating the current data

One idempotent migration (`2026_10_31_110000_multi_user_backfill`):
1. Owner user: `BRIDGE_OWNER_EMAIL` if set (created with an unusable random password when missing; Tilen sets his own via
   reset), else the user that owns the Sanctum tokens (Captain's API user on live), else the first user, else a new
   `tilen@lantis.internal`. Set `is_admin = true`.
2. Workspace `slug=tilen`, `name=LANTIS`, owner = that user, `features={"polymarket": true}`.
3. Memberships: owner → `owner`; every other user owning a token → `member`.
4. `workspace_id` = that workspace on every row of every table in §1 and on every token with `workspace_id` null.
   Tokens keep their user (`tokenable_id`), hash, name and abilities: `BRIDGE_API_TOKEN` and per-agent tokens keep working.
5. `down()` nulls nothing it did not set: it drops the memberships/workspace it created (by slug) and clears `is_admin`;
   the column-adding migrations' `down()` drop only the added columns.

Verify on live (before = mysqldump taken by the deploy script; after = same queries):
`SELECT COUNT(*) FROM projects` (and tickets, ticket_comments, ticket_events, status_updates, agents, labels,
usage_days, cli_usage_days, polymarket_series_points, personal_access_tokens) must be equal before/after, and
`SELECT COUNT(*) FROM <t> WHERE workspace_id IS NULL` = 0 for each. Smoke: `GET /api/projects` with `BRIDGE_API_TOKEN`
returns the same list; portfolio, a board, `/usage`, `/polymarket` render after the gate.

## 4. Per-user API tokens

- Sanctum `personal_access_tokens` (sha256 at rest, `last_used_at` already updated). New tokens are created by the
  owner in a Tokens screen (later): `name` (an agent id when the token is one agent's identity, see `Actor`), abilities,
  optional `expires_at`; plaintext shown once. Revoke = delete the token row (the only deletion we allow: a credential,
  not data; or set `expires_at = now()` if we prefer to keep the row). Rotation = create new, revoke old.
- Abilities: `boards:read`, `boards:write`, `status:write`, `usage:write`, `polymarket:write`, `agents:write`.
  `*` and the legacy `ceo` ability = everything. Route groups require: GET → `boards:read`; ticket/project/label/comment
  writes → `boards:write`; status updates and project status → `status:write`; `POST /api/usage/days` → `usage:write`;
  `POST /api/polymarket/feed` → `polymarket:write` (plus the workspace feature); agents → `agents:write`.
- Binding: `personal_access_tokens.workspace_id` is set on create; resolution = token's `workspace_id`, else the user's
  owned workspace, else the user's only membership, else 403. The token's user must still be a member (removing a
  member disables their tokens).
- Agent identity: unchanged (`Actor::fromApi`): a token named like an agent of *its* workspace is that agent;
  otherwise body `actor` must be an agent of the workspace.

## 5. MCP server

- Transport: MCP streamable HTTP, `POST /mcp` (JSON-RPC 2.0; `GET` → 405 since we do not stream server events),
  `Authorization: Bearer <per-user token>`, same workspace resolution and abilities as `/api`.
- Implementation: **decision for Tilen**. (a) `laravel/mcp` (official, new dependency: tool classes, schema, inspector);
  (b) a small hand-rolled controller handling `initialize`, `tools/list`, `tools/call`, `ping` (~300 lines + tests, no
  dependency). Recommendation: (a) if Tilen accepts the dependency; the tools below are the same either way.
- Tools (thin wrappers over `TicketService`, `ProjectStatusService`, `StatusUpdateIngest`, `BoardView`, `TicketView`):
  `list_projects {include_archived?}`, `get_board {project}`, `list_tickets {project?, status?, assignee?, label?,
  updated_since?}`, `get_ticket {ticket}`, `create_ticket {project, title, description?, status?, priority?,
  assignee?, labels?, due_on?}`, `update_ticket {ticket, …same fields}`, `move_ticket {ticket, status, before?, after?}`,
  `comment_ticket {ticket, body}`, `post_status {project, body}` (status update), `set_project_status {project, status,
  note?}`, `list_agents`, `list_labels`. Each tool declares its ability; errors map to JSON-RPC errors with the same
  messages as the REST API.

## 6. Polymarket gating

Per-workspace feature flag `workspaces.features.polymarket` (true only for Tilen's workspace by the backfill; no UI to
change it). Off → `GET /polymarket` 404, `POST /api/polymarket/feed` 404, nav item hidden (Inertia shared prop
`features.polymarket`). Data is also workspace-scoped, so even with the flag on another workspace sees only its own desk.
Tests: Tilen unchanged (page + ingest), another workspace 404 on both, nav prop false.

## 7. Cursor usage

Extend the existing measured-usage ingest: `CliUsageDay::TOOLS += 'cursor'` (label "Cursor"), one row per
(date, tool, agent_id, model), nightly via the same push (`POST /api/usage/days` with a `cli_usage` key, or
`bridge:cli-usage-import`), per Europe/Ljubljana day, a re-POST replaces the day (unchanged semantics). Row shape:

```json
{"tool": "cursor", "agent_id": "agent-erin", "name": "Erin Bloome", "model": "claude-4.5-sonnet",
 "input": 120000, "output": 8000, "cache_read": 900000, "cache_creation": 0, "calls": 42,
 "cost_usd": 3.12, "requests": 40}
```

`cost_usd` (number ≥ 0, 4 decimals) and `requests` (int, Cursor's billed request count) are new **optional** row keys,
stored in two nullable columns; Cursor rows without token detail send 0s. `agent_id` = the Bridge agent id the Captain
maps from the Cursor account/API key (same as the other tools). Rows land in the token's workspace. Captain's
collector change is his; Bridge only needs the validator + column + label.

## 8. Step order

1. Schema: workspaces, membership, `users.is_admin`, nullable `workspace_id` + indexes (tests: migrate/rollback on SQLite).
2. Backfill (tests: rows/tokens get the workspace, idempotent, rollback).
3. Scoping: `CurrentWorkspace`, `BelongsToWorkspace` trait, gate → Tilen's workspace, token → workspace; fail closed
   (tests: two workspaces, cross access 404/empty).
4. Polymarket gate (tests above). 5. Per-workspace unique keys for usage/labels/polymarket.
6. Account service (register, reset, change password; tests with `Notification::fake`).
7. Token abilities enforced per route (tests per ability). — *steps 1–7 = Phase B, done (§11).*
8. Login/signup/reset/token screens (Tommy + Marc designs), remove the gate. 9. `workspace_id` NOT NULL. 10. Per-workspace slug/prefix/key. 11. MCP endpoint.
12. Cursor usage. 13. Read states per user.

Each step deploys on its own; 1–3 must deploy together with the code (the scope needs the columns).

## 9. Risks

- **No staging**: the backfill runs on live data first. Mitigation: deploy script's mysqldump; the before/after counts
  in §3; rehearse on a copy of the dump locally (`mysql < dump` + `php artisan migrate`) before the live run.
- A query that bypasses Eloquent (`DB::table`) is not scoped: today only `BridgeRoster::setPm` (fills `workspace_id`
  explicitly); `exists:`/`unique:` validation rules are DB rules (made workspace-aware where they reference owned
  tables). A grep check belongs in review of every later change.
- Per-agent tokens created by hand on the server with abilities other than `*`/`ceo` would lose access once abilities
  are enforced (step 7). Check first: `SELECT name, abilities FROM personal_access_tokens`.
- Global unique slug/prefix: another user cannot pick a slug/prefix Tilen uses (also leaks that it exists) until step 10.

## 10. Open questions for Tilen

1. Signup: open, or invite-only (recommended)?
2. Workspace sharing: one person per workspace in v1, or invite members (schema supports it)?
3. MCP: add `laravel/mcp` (dependency) or hand-roll?
4. Mail: sender address/domain and provider; who owns the SMTP credentials (The Many)?
5. Polymarket: per-workspace flag (implemented) vs. "Tilen only" forever?
6. `BRIDGE_OWNER_EMAIL`: Tilen's login email, to be set on live **before** the backfill runs (otherwise Captain's API
   user becomes the owner and Tilen gets a separate login later).
7. Workspace name for the existing data: `LANTIS` (slug `tilen`) — ok?
8. Staging: none exists. Recommend a second xerxes vhost (`bridge-staging.tilenpoje.eu`, own DB restored from the live
   dump) set up by The Many before the data migration deploys; deploy owner The Many.

## 11. Groundwork done (branch `feat/multi-user`)

Not deployed. Suite: see the last commit (all green, incl. `tests/Feature/MultiUser/*`).

| Piece | Where |
|---|---|
| Schema: `users.is_admin`, `workspaces`, `workspace_user`, nullable indexed `workspace_id` FK on the 18 owned tables + `personal_access_tokens` | `2026_10_31_100000_multi_user_schema` |
| Backfill into workspace `tilen` / `LANTIS` (polymarket on), owner + members, all rows and tokens | `2026_10_31_110000_multi_user_backfill` |
| Per-workspace keys: `usage_days`, `cli_usage_days`, `polymarket_series_points`, `labels` | `2026_10_31_120000_multi_user_workspace_keys` |
| `Workspace` model; `CurrentWorkspace` (singleton; `required` in HTTP); `WorkspaceScope` + `BelongsToWorkspace` on 17 models; `MissingWorkspace` (fail closed) | `app/Models`, `app/Support/Workspaces` |
| `WorkspaceBoundary` (first in web/api, restores state), gate → Tilen's workspace, `ResolveTokenWorkspace`, `EnsureTokenAbility` | `app/Http/Middleware`, `bootstrap/app.php`, `routes/api.php` |
| Workspace-aware `exists:` rules (`WorkspaceRule::exists`); bulk inserts set `workspace_id`; prefix/key generators look across workspaces | controllers, `CliUsageImporter`, `PolymarketFeedImporter`, `BridgeRoster`, `TicketService` |
| Polymarket feature gate (page, ingest, `features.polymarket` shared prop, TopBar) | `RequireWorkspaceFeature`, `HandleInertiaRequests`, `TopBar.tsx` |
| `AccountService` (register + own workspace, reset link/complete, change password); reset URL built in `AppServiceProvider` | `app/Services/Auth` |

Console / artisan commands (imports, `bridge:set-pms`, `bridge:tickets-migrate`, seeders) run unscoped and write into
the parent's or Tilen's workspace; a `--workspace=` option comes with the first non-Tilen import.

**Before deploying this branch (The Many):**
1. Set `BRIDGE_OWNER_EMAIL` (Tilen's login email, open question 6) in `.env` and `php artisan config:clear` *before*
   `migrate` (the deploy script caches config with `optimize` afterwards). Without it, Captain's API user becomes the
   owner.
2. `SELECT id, name, abilities, tokenable_id FROM personal_access_tokens;` — every live token must have `*` or `ceo`
   in `abilities`, or one of the new abilities it needs; otherwise it gets 403 after the deploy (abilities are enforced
   now). Fix before deploying (add `"*"`), not after.
3. Rehearse on a copy of the live dump (no staging): migrate, then the §3 counts; `migrate:rollback --step=3` once
   to prove the way back.
4. After deploy: §3 counts and smoke tests (`BRIDGE_API_TOKEN` lists the same projects; portfolio, board, `/usage`,
   `/polymarket` behind the gate).

**Left (in order):**
- Login / logout / signup / reset / token-management screens + controllers (designs), rate limiters (§2), then remove
  the AUTH-1 gate; read states keyed by user instead of `'tilen'`; `Actor::tilen()` becomes "the logged-in human".
- `POST /api/agents` is Captain-only (`agent-william`, Tilen's roster): a new workspace cannot add its first agent over
  the API. Needs a per-workspace rule (owner's token / UI) — decide with the screens.
- `workspace_id` NOT NULL (after a release with no null rows); per-workspace slug / ticket prefix / ticket key; agent id
  collisions between workspaces (string PKs).
- MCP endpoint (§5, dependency decision), Cursor usage (§7), `--workspace` for artisan imports.
- Mail transport on live (§2), staging vhost (§10.8).
