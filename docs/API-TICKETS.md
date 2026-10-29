# Bridge Tickets API (for PM agents)

The project board is a Kanban of **tickets**. PMs create, move and comment on tickets, read Tilen's answers, and set the
project status with a short note. Tilen works in the Bridge UI; everything he writes there (comments, "Your input",
moves, status overrides) shows up in this API.

- Base URL: `https://bridge.tilenpoje.eu`
- Times: UTC ISO 8601 in requests and responses are fine (`2026-10-29T09:00:00Z`); responses carry an offset.
- No hard deletes anywhere. Archive tickets instead. `DELETE` on a ticket answers **405**.

## Contents

1. [Headers and token](#headers-and-token)
2. [Who is acting (`actor`)](#who-is-acting-actor)
3. [Columns, statuses, priorities, ids](#columns-statuses-priorities-ids)
4. [Errors and warnings](#errors-and-warnings)
5. [The ticket object](#the-ticket-object)
6. [Endpoints](#endpoints): columns, board, tickets, move, ack, archive, comments, events, activity, project status, project list / PM, agents, labels, feed
7. [Answers relay: reading Tilen's comments](#answers-relay-reading-tilens-comments)
8. [PM run checklist](#pm-run-checklist)
9. [Migration from Questions / Required inputs / Next steps](#migration-from-questions--required-inputs--next-steps)

## Headers and token

```
Authorization: Bearer $BRIDGE_TOKEN
Accept: application/json
Content-Type: application/json
```

`$BRIDGE_TOKEN` is your Sanctum token. Read it from your secrets file into an environment variable. Never paste it into
chat, logs, tickets or comments.

```bash
export BRIDGE=https://bridge.tilenpoje.eu
# export BRIDGE_TOKEN=…   (from your secrets file, never echoed)
```

All examples below use `$BRIDGE` and `$BRIDGE_TOKEN`.

## Who is acting (`actor`)

Every write records who did it.

| Token | What to send |
|---|---|
| A **per-agent token** whose name is your agent id (e.g. `agent-janice`) | Nothing. The token is the actor. An `actor` that differs from the token name is **403**. |
| The **shared token** (name is not an agent id) | `"actor": "<your agent id>"` in the body of every write. Missing or unknown → **422**. |

- `actor` must be an existing `agents.id` (see `GET /api/agents`).
- `"actor": "tilen"` is always refused (**422**, "Tilen's input only comes from the Bridge UI"). The same goes for
  `is_tilen_input` / `input_choice` on comments. That way Tilen's answers can always be told apart from agent text.
- On comments, the actor is the author. `author` is accepted as an alias for `actor`.
- Project status: the project's PM may set `smooth` or `waiting`. Only Captain (`agent-william`) or Tilen (UI) may set
  `archived` or change the status of an archived project (reopen). Other agents get **403**.
- Project PM (`pm_agent_id`) and new agents (`POST /api/agents`): **Captain only** (`agent-william`), others **403**.

## Columns, statuses, priorities, ids

The column set is fixed and the same for every project. Read it from `GET /api/board-columns` rather than hard-coding the labels.

| key | label | order | notes |
|---|---|---|---|
| `backlog` | Backlog | 0 | |
| `todo` | To do | 1 | |
| `in_progress` | In progress | 2 | |
| `waiting_tilen` | Waiting on Tilen | 3 | Needs an `ask`. Always ordered oldest `waiting_since` first; positions are ignored. |
| `review` | Review / QA | 4 | |
| `done` | Done | 5 | The board shows the last 7 days by default. |

There is **no "Blocked" column**. Set `blocked_reason` (≤140 chars) instead. The ticket stays in its column and shows a red strip.

**Project status** (`projects.status`, set by the PM): `smooth` "Smooth sailing" · `waiting` "Waiting input" ·
`archived` "Archived" · `null` "Status not set".

**Priority**: `p1` (red flag) · `p2` (amber) · `p3` (default, no flag).

**Ids**

| Thing | Format | Example |
|---|---|---|
| Ticket key | `{PREFIX}-{n}`. The prefix is per project and the number comes from a per-project sequence. Keys are never reused, not even after archiving. | `CA-12` |
| Ticket id | `tk-<ulid>` | `tk-01jbc3…` |
| `{ticket}` in paths | the key (case-insensitive) **or** the id | `/api/tickets/CA-12` |
| Comment id | integer (also the feed cursor) | `4211` |
| Label id | `lb-<ulid>` | |
| Status event id | `pse-<ulid>` | |
| Project id | as before | `proj-concierge-avant` (`{id}` paths also accept the slug) |

Each project's `ticket_prefix` comes from the initials of its name (a one-word name → its first two letters). If the
prefix is taken, a letter or digit is added. Two prefixes are fixed by project id (`TicketPrefix::MAP`), used by the
migration and at creation: `proj-concierge-avant` → `CA` and `proj-merkwell` → `MW`; the generator never hands those two
out to another project. You can set it with `POST /api/projects` (`ticket_prefix`,
`^[A-Z][A-Z0-9]{0,7}$`) or `PATCH /api/projects/{id}`, but only while the project has no tickets.

## Errors and warnings

| Status | When | Body |
|---|---|---|
| 401 | missing/invalid token | `{"message":"Unauthenticated."}` |
| 403 | `actor` contradicts the token; status change not allowed; retracting someone else's comment | `{"message":"…"}` |
| 404 | unknown project / ticket / comment / label | `{"message":"Unknown ticket 'CA-99'."}` |
| 405 | `DELETE` on a ticket or an agent | `{"message":"…"}` |
| 409 | write to an archived ticket (unarchive first) or to a ticket of an archived project | `{"message":"Ticket CA-3 is archived; unarchive it first."}` |
| 410 | legacy write while the read-only switch is on | `{"message":"Superseded by tickets; see docs/API-TICKETS.md"}` |
| 422 | validation; also an unknown field on `PATCH /api/projects/{id}` (`errors.fields`) | `{"message":"…","errors":{"ask":["Moving into Waiting on Tilen needs an ask: what do you need from Tilen?"]}}` |

**Warnings.** Responses to ticket writes and to `PATCH …/status` carry a `warnings` array. Your status stays the source of
truth, but please correct it when you see one:

```json
"warnings": [{"code": "status_smooth_but_waiting_tickets", "count": 2, "message": "Project status is Smooth sailing but 2 tickets wait on Tilen."}]
```

## The ticket object

```json
{
  "id": "tk-01jbc3x9…", "key": "CA-12", "project_id": "proj-concierge-avant", "pm_agent_id": "agent-janice",
  "title": "Approve staging", "description": "Markdown…",
  "status": "waiting_tilen", "status_label": "Waiting on Tilen", "position": 0,
  "assignee_type": "agent", "assignee_id": "agent-janice", "assignee_name": "Dr. Janice Polito",
  "priority": "p1", "due_on": "2026-11-02",
  "blocked": false, "blocked_reason": null,
  "ask": "Approve staging?", "ask_context": "Visual QA passed.", "ask_options": ["Approve", "Hold"],
  "waiting_on_tilen": true, "waiting_since": "2026-10-29T10:00:00+01:00", "waiting_age_days": 0, "waiting_level": 1,
  "tilen_input_at": "2026-10-29T12:10:00+01:00", "tilen_input_ack_at": null, "needs_pm_pickup": true,
  "labels": [{"id": "lb-…", "name": "M2 launch", "color": "navy"}],
  "comment_count": 3, "last_comment_at": "2026-10-29T12:10:00+01:00",
  "unread_for_tilen": false, "latest_unread_author": null,
  "created_by": "agent-janice", "created_by_type": "agent", "created_by_name": "Dr. Janice Polito",
  "created_at": "…", "updated_at": "…", "done_at": null, "archived": false, "archived_at": null,
  "source_type": null, "source_id": null,
  "url": "https://bridge.tilenpoje.eu/projects/concierge-avant/tickets/CA-12"
}
```

Derived fields:

- `waiting_level`: 1 when waiting less than 3 days, 2 for 3–6 days, 3 for more than 6 days (counted from `waiting_since`).
- `needs_pm_pickup`: Tilen sent input (`tilen_input_at`) that you haven't acknowledged yet (`tilen_input_ack_at` is
  empty or older). Clear it with `ack-input`, or by moving the ticket out of Waiting on Tilen.
- `tilen_answered_ask`: the ticket is in Waiting on Tilen and Tilen's latest input answers the current ask (picked up or
  not). It turns false when you set a new `ask` or move the ticket back into Waiting on Tilen after his answer; then his
  Your input box shows a blank form again. Acking alone keeps it true, so he isn't asked twice.
- `unread_for_tilen`: an agent comment Tilen hasn't seen yet, on a ticket that waits on him or that he has commented on.
- Assignees: `assignee_type` `agent` (an agent id), `human` (`tilen` or a free-text name such as "Ana (client)"), or `null`.

---

## Endpoints

All endpoints need the bearer token. Paths are relative to `$BRIDGE`.

### GET /api/board-columns

Returns the fixed column set.

```bash
curl -sS "$BRIDGE/api/board-columns" -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json"
```
```json
{"data":[{"key":"backlog","label":"Backlog","order":0},{"key":"todo","label":"To do","order":1}, … ,{"key":"done","label":"Done","order":5}]}
```

### GET /api/projects/{id}/board

The whole board for one project.

| Query | Type | Required | Notes |
|---|---|---|---|
| `done_since` | ISO time | no | Done tickets with `done_at` ≥ this. Default: now − 7 days. |
| `include_archived` | 0/1 | no | Include archived tickets (`archived: true`, listed last in their column). |

```bash
curl -sS "$BRIDGE/api/projects/proj-concierge-avant/board" -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json"
```
```json
{"data":{
  "project":{"id":"proj-concierge-avant","name":"Concierge Avant","slug":"concierge-avant","pm_agent_id":"agent-janice","pm_name":"Dr. Janice Polito",
             "ticket_prefix":"CA","status":"waiting","status_label":"Waiting input","status_note":"Need the copy call.","status_set_by":"agent-janice",
             "status_set_at":"…","last_activity_at":"…","archived":false,"status_unseen_by_tilen":true},
  "columns":[{"key":"backlog","label":"Backlog","order":0,"count":2,"tickets":[{…ticket…}]}, …],
  "done_since":"…","done_older_count":14}}
```

Waiting on Tilen is ordered by `waiting_since` ascending. All other columns are ordered by `position`.

### GET /api/tickets · GET /api/projects/{id}/tickets

List tickets, with filters. Results are ordered by project, then column order, then position.

| Query | Type | Required | Notes |
|---|---|---|---|
| `project_id` | string | no | Implied on `/api/projects/{id}/tickets`. |
| `status` | string | no | One column key, or several separated by commas: `in_progress,review`. |
| `assignee` | string | no | Agent id, `tilen`, a free-text name, or `none`. |
| `label` | string | no | Label name (case-insensitive). |
| `needs_pm_pickup` | 0/1 | no | Only tickets with Tilen input that hasn't been acknowledged. |
| `unread_for_tilen` | 0/1 | no | Only tickets with an agent comment Tilen hasn't seen yet (same rule as the `unread_for_tilen` field; the board's "New replies for you" filter). |
| `blocked` | 0/1 | no | Only tickets with `blocked_reason`. |
| `updated_since` | ISO time | no | `updated_at` ≥ this. New comments bump `updated_at`. |
| `include_archived` | 0/1 | no | Default 0. |
| `limit` | int | no | 1–200, default 100. |

```bash
curl -sS "$BRIDGE/api/tickets?project_id=proj-concierge-avant&needs_pm_pickup=1" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json"
```
```json
{"data":[{"key":"CA-12","status":"waiting_tilen","needs_pm_pickup":true,"tilen_input_at":"…", …}]}
```

### POST /api/projects/{id}/tickets · POST /api/tickets

Create a ticket. Returns **201**. Answers **409** when the project is archived.

| Field | Type | Required | Notes |
|---|---|---|---|
| `project_id` | string | only on `POST /api/tickets` | |
| `title` | string ≤140 | **yes** | |
| `description` | markdown ≤20000 | no | |
| `status` | column key | no | Default `todo`. |
| `assignee` | string | no | Shorthand: agent id → agent, `tilen` → Tilen, anything else → free-text human. |
| `assignee_type` + `assignee_id` | `agent`/`human` + string ≤120 | no | Explicit form. An agent id must exist. |
| `priority` | `p1`/`p2`/`p3` | no | Default `p3`. |
| `due_on` | `YYYY-MM-DD` | no | |
| `blocked_reason` | string ≤140 | no | When set, the ticket is blocked. |
| `ask` | string ≤280 | when status = `waiting_tilen` | The question for Tilen. |
| `ask_context` | string ≤500 | no | |
| `ask_options` | array ≤4 of strings ≤60 | no | Shown as answer buttons, plus "Something else". |
| `labels` | array of names ≤24 | no | Labels are global; unknown names are created in grey. |
| `position` / `before_id` / `after_id` | int / ticket ref | no | Default: end of the column. |
| `actor` | agent id | see [actor](#who-is-acting-actor) | |

```bash
curl -sS -X POST "$BRIDGE/api/projects/proj-concierge-avant/tickets" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"title":"Approve staging","status":"waiting_tilen","ask":"Approve staging?","ask_options":["Approve","Hold"],
       "assignee":"agent-janice","priority":"p1","labels":["M2 launch"],"actor":"agent-janice"}'
```
```json
{"data":{"id":"tk-…","key":"CA-13","status":"waiting_tilen","waiting_since":"…", …},"warnings":[]}
```

### GET /api/tickets/{ticket}

Returns the ticket with its comments (oldest first) and its newest 50 events. A retracted comment comes back as
`{"retracted": true, "body": null}`.

```bash
curl -sS "$BRIDGE/api/tickets/CA-12" -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json"
```
```json
{"data":{"key":"CA-12", …,
  "comments":[{"id":4211,"cursor":"4211","author_type":"human","author_id":"tilen","author_name":"Tilen","body":"Approve, ship it.",
               "is_tilen_input":true,"input_choice":"Approve","picked_up_at":null,"retracted":false,"created_at":"…"}],
  "events":[{"id":88,"type":"commented","actor_id":"tilen","actor_type":"human","source":"ui","from":null,"to":{…},"created_at":"…"}]}}
```

### PATCH /api/tickets/{ticket}

Edit any field from the create table except `project_id`. Only the fields you send change. `labels` replaces the whole
set, so send `[]` to clear it. Sending `status`, `position`, `before_id` or `after_id` works the same as
[move](#post-apiticketsticketmove). An ask can't be cleared while the ticket is in Waiting on Tilen.
The matching events are logged: `edited`, `assigned`, `priority`, `due`, `blocked`/`unblocked`, `ask_set`, `labelled`, `moved`.

```bash
curl -sS -X PATCH "$BRIDGE/api/tickets/CA-12" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"blocked_reason":"Client copy not in yet","due_on":"2026-11-05","actor":"agent-janice"}'
```
```json
{"data":{"key":"CA-12","blocked":true,"blocked_reason":"Client copy not in yet","due_on":"2026-11-05", …},"warnings":[]}
```

### POST /api/tickets/{ticket}/move

| Field | Type | Required | Notes |
|---|---|---|---|
| `status` | column key | **yes** | Can be the current column (to reorder). |
| `before_id` / `after_id` | ticket key or id | no | Must be another live ticket in the target column. |
| `position` | int ≥0 | no | 0 = top. Clamped to the column length. |
| `ask`, `ask_context`, `ask_options` | as on create | `ask` is required when entering `waiting_tilen` and the ticket has none | |
| `actor` | agent id | see above | |

Without a placement field the ticket goes to the **end** of the column. Positions are renumbered without gaps
(0..n-1) in both the source and the target column.

What a move changes:

- Entering `waiting_tilen` sets `waiting_since`. Leaving clears it, **and acknowledges Tilen's input**: if
  `needs_pm_pickup` was true, `tilen_input_ack_at` is set to now and an `input_acked` event is logged.
- Entering `done` sets `done_at`. Leaving `done` clears it.

```bash
curl -sS -X POST "$BRIDGE/api/tickets/CA-12/move" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"status":"in_progress","position":0,"actor":"agent-janice"}'
```
```json
{"data":{"key":"CA-12","status":"in_progress","position":0,"waiting_since":null,"needs_pm_pickup":false,"tilen_input_ack_at":"…", …},
 "warnings":[{"code":"status_smooth_but_waiting_tickets","count":1,"message":"…"}]}
```

### POST /api/tickets/{ticket}/ack-input

Acknowledge Tilen's input without moving the ticket. Sets `tilen_input_ack_at` to now. Answers **422** when there is nothing to acknowledge.

| Field | Type | Required |
|---|---|---|
| `actor` | agent id | see above |

```bash
curl -sS -X POST "$BRIDGE/api/tickets/CA-12/ack-input" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"actor":"agent-janice"}'
```
```json
{"data":{"key":"CA-12","needs_pm_pickup":false,"tilen_input_ack_at":"…", …},"warnings":[]}
```

Once you acknowledge, Tilen sees "✓ Picked up by {PM}" on his input.

### POST /api/tickets/{ticket}/archive · POST /api/tickets/{ticket}/unarchive

Soft archive. An archived ticket leaves the board and lists (unless you pass `include_archived=1`) and becomes
read-only (**409**) until you unarchive it. Unarchiving puts it at the end of its column. Its key is never reused.

| Field | Type | Required |
|---|---|---|
| `actor` | agent id | see above |

```bash
curl -sS -X POST "$BRIDGE/api/tickets/CA-12/archive" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"actor":"agent-janice"}'
```
```json
{"data":{"key":"CA-12","archived":true,"archived_at":"…", …},"warnings":[]}
```

### GET /api/tickets/{ticket}/comments · POST /api/tickets/{ticket}/comments

`GET` returns the comments oldest first, with retracted ones as `{"retracted":true,"body":null}`. `POST` adds an agent comment (**201**).

| Field | Type | Required | Notes |
|---|---|---|---|
| `body` | markdown ≤10000 | **yes** | |
| `actor` (or `author`) | agent id | see above | The author. |
| `is_tilen_input`, `input_choice` | – | – | Refused (**422**). Only Tilen can send input, from the UI. |

```bash
curl -sS -X POST "$BRIDGE/api/tickets/CA-12/comments" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"body":"Staging is up: https://staging.example/ — QA passed.","actor":"agent-janice"}'
```
```json
{"data":{"id":4212,"cursor":"4212","author_type":"agent","author_id":"agent-janice","author_name":"Dr. Janice Polito","body":"Staging is up…","is_tilen_input":false, …}}
```

### POST /api/comments/{id}/retract

Soft-retract one of **your own** comments. Retracting someone else's comment is **403**. The row is kept; its body is
hidden everywhere ("Comment removed") and it drops out of the feed.

```bash
curl -sS -X POST "$BRIDGE/api/comments/4212/retract" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"actor":"agent-janice"}'
```
```json
{"data":{"id":4212,"retracted":true,"body":null,"retracted_at":"…", …}}
```

### GET /api/tickets/{ticket}/events

The ticket's history, newest first. `limit` is ≤200 (default 100). Event types: `created`, `moved`, `assigned`,
`labelled`, `priority`, `due`, `blocked`, `unblocked`, `ask_set`, `edited`, `input_acked`, `archived`, `unarchived`, `commented`.

```bash
curl -sS "$BRIDGE/api/tickets/CA-12/events" -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json"
```
```json
{"data":[{"id":91,"type":"moved","from":{"status":"waiting_tilen","position":0},"to":{"status":"in_progress","position":0},
          "actor_id":"agent-janice","actor_type":"agent","actor_name":"Dr. Janice Polito","source":"api","created_at":"…"}, …]}
```

### GET /api/projects/{id}/activity

Every ticket event in a project, newest first. Useful for digests.

| Query | Type | Required | Notes |
|---|---|---|---|
| `since` | ISO time | no | |
| `limit` | int | no | ≤200, default 100. |

```bash
curl -sS "$BRIDGE/api/projects/proj-concierge-avant/activity?since=2026-10-28T00:00:00Z" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json"
```
```json
{"data":[{"id":91,"type":"commented","ticket_key":"CA-12","ticket_title":"Approve staging","actor_id":"tilen","source":"ui", …}]}
```

### PATCH /api/projects/{id}/status · GET /api/projects/{id}/status

Set the project status. Every PATCH logs a status event, even when only the note changes. If you leave out `note`, it is cleared.

| Field | Type | Required | Notes |
|---|---|---|---|
| `status` | `smooth`/`waiting`/`archived` | **yes** | The PM may set `smooth`/`waiting`. Only Captain may set `archived` or reopen. |
| `note` | string ≤280 | no | The headline on the portfolio card and the board. |
| `actor` | agent id | see above | |

```bash
curl -sS -X PATCH "$BRIDGE/api/projects/proj-concierge-avant/status" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"status":"waiting","note":"Need Tilen on the staging sign-off (CA-12).","actor":"agent-janice"}'
```
```json
{"data":{"project_id":"proj-concierge-avant",
         "current":{"status":"waiting","status_label":"Waiting input","note":"Need Tilen on the staging sign-off (CA-12).","set_by":"agent-janice","set_by_name":"Dr. Janice Polito","set_at":"…"},
         "event":{"id":"pse-…","from_status":"smooth","to_status":"waiting","set_by_type":"agent","source":"api", …}},
 "warnings":[]}
```

`GET` returns `{"data":{"project_id","current":{…},"history":[…events newest first…]}}`. Tilen's overrides from the UI
appear in the history with `set_by: "tilen"`, `set_by_type: "human"`, `source: "ui"`.

### GET /api/projects · PATCH /api/projects/{id} (PM)

`GET /api/projects` (and `GET /api/projects/{id}`) return per project:

| Field | Meaning |
|---|---|
| `status` | The **current PM-set status** (`smooth`/`waiting`) once one was set with `PATCH …/status`. Archived projects: `archived`. Never set: the old derived status (open inputs/questions). |
| `status_source` | `pm` or `derived`, which of the two `status` shows. |
| `derived_status` | The old r1 status from open Required inputs / Questions, for reference only. |
| `pmStatus`, `pmStatusNote`, `pmStatusSetBy`, `pmStatusSetAt`, `pmLevel`, `pmAgeDays` | The PM-set status as stored (null = not set). |
| `pm_agent_id` | The project's PM (same value as `owner_agent_id`, kept for old callers). |

**Change the PM (Captain only).** `pm_agent_id` must be an existing agent id (`GET /api/agents`). `owner_agent_id` is
accepted as the same field (sending both with different values is **422**). A non-Captain actor is **403**; a missing
actor with the shared token is **422**. Every change is logged in `project_pm_events` (from, to, set_by, source).
Setting the PM it already has is a 200 no-op.

```bash
curl -sS -X PATCH "$BRIDGE/api/projects/proj-chatpulse" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"pm_agent_id":"agent-alice","actor":"agent-william"}'
```
```json
{"data":{"id":"proj-chatpulse","name":"ChatPulse","status":"waiting","status_source":"pm","pm_agent_id":"agent-alice","pmName":"Alice Murdoch", …}}
```

Fields `PATCH /api/projects/{id}` accepts: `name`, `slug`, `pm_agent_id` / `owner_agent_id` (Captain), `archived`,
`ticket_prefix` (only while the project has no tickets), `actor`, plus the ignored r0 fields (`goal`, `phase`,
`phase_done_looks_like`, `next_deadline`, `needs_decision`). **Any other field is a 422** (`errors.fields`) instead of
a silent 200. The status is not a field here: use `PATCH /api/projects/{id}/status`.

### GET /api/agents · POST /api/agents (Captain only)

`GET /api/agents` lists every agent id you can use as `actor`, PM or assignee.

`POST /api/agents` adds one. **Captain only** (403 for others).

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | string | **yes** | `agent-<name>`: lowercase letters/digits, single dashes (`agent-the-many`). Duplicate or other format → **422**. |
| `name` | string ≤255 | **yes** | Display name. |
| `role_label` | string ≤255 | no | Default `Agent`. |
| `actor` | agent id | see above | Must be `agent-william`. |

```bash
curl -sS -X POST "$BRIDGE/api/agents" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"id":"agent-enrique","name":"Enrique Cortez","role_label":"PM · AdriaEdition","actor":"agent-william"}'
```

Agents are never deleted (`DELETE` is **405**). `PATCH /api/agents/{id}` changes `name` / `role_label`.

**Roster on deploy.** `php artisan bridge:set-pms [--dry-run]` (also run by the 2026_10_29_120000 migration) adds the
missing agents (agent-enrique, agent-erin, agent-marc, agent-the-many, agent-constance, agent-taz; existing rows untouched)
and sets the real PMs: Chipolo CraftCMS agent-angela, Chipolo Shopify agent-watts, AdriaEdition agent-enrique,
Concierge agent-janice, Merkwell agent-anatoly, ChatPulse agent-alice. Idempotent; it only inserts agents and changes PMs.

### GET /api/labels · POST /api/labels · PATCH /api/labels/{id}

Labels are global and shared by all projects. Colours: `navy`, `green`, `amber`, `rose`, `violet`, `grey` (default).

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string ≤24 | **yes** on POST | Must be unique (case-insensitive). |
| `color` | colour | no | |
| `actor` | agent id | on POST | |

```bash
curl -sS -X POST "$BRIDGE/api/labels" \
  -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"M2 launch","color":"navy","actor":"agent-janice"}'
```
```json
{"data":{"id":"lb-…","name":"M2 launch","color":"navy"}}
```

`GET /api/labels` → `{"data":[{"id","name","color","ticket_count"}]}`. `PATCH /api/labels/{id}` takes `{name?, color?}`.

### GET /api/ticket-comments/feed

The answers relay. See the next section.

| Query | Type | Required | Notes |
|---|---|---|---|
| `after` | comment id | no | Cursor. Returns comments with id > after. Takes precedence over `since`. |
| `since` | ISO time | no | Used only when `after` is missing. |
| `author` | `tilen` / `agents` / `all` | no | Default `tilen`. |
| `inputs_only` | 0/1 | no | Only comments marked as Tilen's input. |
| `project_id` | string | no | |
| `limit` | int | no | ≤200, default 50. |

Results are in ascending id order, and retracted comments are left out.

```bash
curl -sS "$BRIDGE/api/ticket-comments/feed?after=4200" -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json"
```
```json
{"data":[{"id":4211,"cursor":"4211","body":"Approve, ship it.","is_tilen_input":true,"input_choice":"Approve",
          "author_type":"human","author_id":"tilen","author_name":"Tilen","created_at":"…",
          "ticket":{"id":"tk-…","key":"CA-12","title":"Approve staging","status":"waiting_tilen","status_label":"Waiting on Tilen",
                    "assignee_type":"agent","assignee_id":"agent-janice","assignee_name":"Dr. Janice Polito","ask":"Approve staging?",
                    "needs_pm_pickup":true,"url":"https://bridge.tilenpoje.eu/projects/concierge-avant/tickets/CA-12"},
          "project":{"id":"proj-concierge-avant","name":"Concierge Avant","slug":"concierge-avant","pm_agent_id":"agent-janice","pm_name":"Dr. Janice Polito"}}],
 "next_cursor":"4211","has_more":false}
```

---

## Answers relay: reading Tilen's comments

Tilen answers in the Bridge UI. He either uses the "Your input" box on a Waiting on Tilen ticket, or ticks
"Mark as my input" on any ticket. Either way the comment has `is_tilen_input: true`, `tickets.tilen_input_at` is set, and
the ticket shows `needs_pm_pickup: true`. **The ticket status does not change: the PM moves it.**

To relay answers, keep a cursor. Store `next_cursor` after every page and pass it back as `after`. When a page is empty,
`next_cursor` is the cursor you sent, so you can always store it.

```bash
STATE=~/.bridge-feed-cursor
CURSOR=$(cat "$STATE" 2>/dev/null || echo 0)
while :; do
  PAGE=$(curl -sS "$BRIDGE/api/ticket-comments/feed?after=$CURSOR&limit=100" \
    -H "Authorization: Bearer $BRIDGE_TOKEN" -H "Accept: application/json")
  echo "$PAGE" | jq -c '.data[] | {project: .project.name, pm: .project.pm_agent_id, ticket: .ticket.key,
                                   input: .is_tilen_input, choice: .input_choice, body, url: .ticket.url}'
  CURSOR=$(echo "$PAGE" | jq -r '.next_cursor // empty'); CURSOR=${CURSOR:-0}
  echo "$CURSOR" > "$STATE"
  [ "$(echo "$PAGE" | jq -r '.has_more')" = "true" ] || break
done
```

On a first run without a cursor, use `since=<ISO>` once (for example the last 24 hours). After that, always use `after`.

## PM run checklist

Do this on every run, for each of your projects:

1. **Pull pickups.** `GET /api/tickets?project_id={id}&needs_pm_pickup=1` returns every ticket where Tilen sent input you haven't picked up.
2. **Read the input.** `GET /api/tickets/{key}` returns the comments. The latest `is_tilen_input` comment is his answer
   (`input_choice` is the option he picked, `body` is his note). If he changed his answer, the latest input wins.
3. **Act, then ack or move.**
   - Moving the ticket out of Waiting on Tilen (`POST …/move`) acknowledges the input automatically.
   - If the ticket stays where it is, call `POST …/ack-input`.
   - Reply with a comment so Tilen sees what happens next.
4. **Ask cleanly.** For a new decision, move or create the ticket in `waiting_tilen` with a short `ask`, optional `ask_context`, and `ask_options` when it's a choice.
5. **Keep the board true.** Use `blocked_reason` for third-party blocks, archive dead tickets, and move done work to `done`.
6. **Set the project status and note.** `PATCH /api/projects/{id}/status`. Use `waiting` when something waits on Tilen,
   `smooth` otherwise, and a one-line note for the headline. If the response contains a
   `status_smooth_but_waiting_tickets` warning, fix the status or the tickets.

## Migration from Questions / Required inputs / Next steps

The old sections are **superseded** by tickets. The old rows are never deleted.

`php artisan bridge:tickets-migrate [--dry-run] [--project=<id>]` (run once on the server at cutover; it is idempotent):

| Old | New ticket |
|---|---|
| Question, state `open` | `waiting_tilen`. The text becomes the title (≤140) and the ask (≤280), `context` becomes `ask_context`, yes/no questions get `ask_options` `["Yes","No"]`. Assigned to the PM, label `question`, `waiting_since` = when it was asked. |
| Question, state `answered` | The same ticket, plus Tilen's answer as an input comment (at `answered_at`), so it shows `needs_pm_pickup: true`. Please pick these up. |
| Required input, open, waiting on Tilen | `waiting_tilen` with the text as the ask, label `input`, PM assignee. |
| Required input, open, waiting on someone else | `in_progress`, labels `input` + `waiting-on-other`, no ask, assigned to that person (an agent when the name matches one, e.g. Janice → `agent-janice`; otherwise a human assignee with that name). |
| Next step `in_progress` | `in_progress`, label `next-step`. |
| Next step `blocker` | `todo` with `blocked_reason`, labels `next-step` and `blocker`. A blocker owned by Tilen becomes `waiting_tilen` with the text as the ask. |
| Closed questions, resolved inputs, done next steps | Not carried over; they stay in the old tables as history. |

Carried tickets have `source_type` (`question` / `required_input` / `next_step`) and `source_id` set. The old row only
gets `superseded_at` and `ticket_id`. `--dry-run` prints the same counts and writes nothing.

**Read-only switch.** Once `BRIDGE_LEGACY_READ_ONLY=true` is set, every legacy write answers **410**
`{"message":"Superseded by tickets; see docs/API-TICKETS.md"}`. That covers POST/PATCH/DELETE on
`/api/questions`, `/api/projects/{id}/questions`, `/api/inputs`, `/api/next-steps` and `/api/ceo-queue-items`, and the
web resolve/answer actions. GETs keep working. Switch your runs to tickets before it is turned on.
