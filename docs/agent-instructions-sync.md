# Agent instructions sync (access contract)

Bridge shows each agent's **instructions** (the `description` of its real profile) on the agent page
`/usage/agents/{agentId}` and lets the workspace owner propose a new text there. The real profiles live on the agents'
host (`profile.json`: `name`, `title`, `description`). Bridge has no access to that host. A daily sync routine
(Captain) is the only bridge between the two:

1. it pushes the real profiles to Bridge, so Bridge shows the **actual** description, never invented text;
2. it picks up the owner's pending edits, applies them to the real profile, and reports back.

The `agentId` is the agent UUID used in the usage files (for example `df74ed60-9505-4b2b-80c5-4c3dc2b2f58b`), the same
id as in `/usage/agents/{agentId}`. It is **not** a Bridge `agents.id` (`agent-firstname`).

## Saved in Bridge is not applied to the agent

| Step | Who | What changes |
|---|---|---|
| Owner clicks **Save** on the agent page | Owner (web) | A new `agent_instruction_edits` row, status `pending`. The agent is **not** changed. Bridge's copy of the profile is **not** changed. |
| Daily sync applies the edit | Sync (API) | The real profile on the host (`description` only). Then `POST …/applied` updates Bridge's copy. |

The page says so after saving: *"Saved in Bridge — pending application to the agent profile. The daily sync applies
it; the agent itself is not changed yet."* Bridge's `agent_profiles.description` only changes through
`POST /api/agent-profiles` and `POST /api/agent-instruction-edits/{id}/applied`.

## Auth

```
Authorization: Bearer $BRIDGE_AGENT_SYNC_TOKEN
Accept: application/json
Content-Type: application/json
```

`$BRIDGE_AGENT_SYNC_TOKEN` is an environment variable on the caller's side, read from its secrets file. Never paste
the value into chat, logs, tickets or comments.

- The token must belong to the **workspace owner** (created by the owner under **Account → API tokens & MCP**). A token
  of any other user, including a member of the workspace, gets **403** on every route below.
- `GET` needs `boards:read` (a `read` token is enough). `POST` needs `agents:write` (a `read & write` token). `*` and
  the legacy `ceo` ability also pass, but the owner check still applies.
- No token: **401**. An edit id of another workspace: **404**. Rate limit: 120 requests per minute per token.

## Hash

`description_hash`, `base_hash`, `proposed_hash`, `applied_hash`: **sha256, lowercase hex, of the exact UTF-8
description string**. No trimming, no newline normalisation. A missing/`null` description hashes as the empty string.

```bash
printf '%s' "$DESCRIPTION" | sha256sum   # same as hash('sha256', $description) / hashlib.sha256(s.encode()).hexdigest()
```

Bridge does not trim or empty-to-null these request bodies, so the text you send is the text that is hashed.

## Status lifecycle

```
pending ──► applied      the sync applied it (POST …/applied), or an ingest brought exactly the proposed text
        ├─► rejected     the sync refused it (POST …/rejected)
        ├─► conflict     the profile changed after the edit was made (ingest rule, or a checked POST …/conflict)
        └─► superseded   the owner saved a newer revision first
```

- At most one `pending` edit per agent. Saving a new revision turns the older pending one into `superseded`; history
  is kept. `revision` counts 1, 2, 3 … per agent.
- `base_hash` is the profile's `description_hash` when the edit was saved (`null` if no profile was synced yet).
- Only `pending` edits change state. Any transition from another state answers **409**.
- Bridge never trusts a reported hash: `applied` and `conflict` compare it with the edit's own hashes (below), and the
  ingest hashes the description it receives itself.
- Writes of one workspace (ingest, saves, `applied` / `rejected` / `conflict`) run one at a time, so two saves at once
  still get consecutive revisions and leave one `pending` edit; a write that loses a race on a unique key is retried,
  not answered with a 500.
- After a conflict the owner sees both texts and saves again from the current description (a new revision).

### Conflict rule (on every `POST /api/agent-profiles`)

For an agent with a pending edit, after storing the new profile:

1. new `description` **equals** the edit's `proposed_description` exactly → `applied` (`applied_hash` = its hash);
2. else new `description_hash` **differs** from the edit's `base_hash` → `conflict`, reason
   `profile description changed after this edit was made`;
3. else the edit stays `pending`.

An edit saved before any profile existed (`base_hash` `null`) becomes `conflict` on the first ingest unless that
ingest brings exactly the proposed text.

## Endpoints

### POST /api/agent-profiles

Upsert the real profiles. Only `name`, `title` and `description` are stored; any other field is ignored. Send every
agent each day (an agent missing from the body is left as it was).

| Field | Type | Required | Notes |
|---|---|---|---|
| `profiles` | array, 1–200 | **yes** | |
| `profiles[].agent_id` | string | **yes** | Usage agent id, `[A-Za-z0-9_-]{1,64}`, unique in the body. |
| `profiles[].name` | string ≤255 | **yes** | |
| `profiles[].title` | string ≤255 | no | |
| `profiles[].description` | string ≤20000 chars | **yes** (may be `""` or `null`) | Exact text from the profile. |

```bash
curl -sS -X POST "$BRIDGE/api/agent-profiles" \
  -H "Authorization: Bearer $BRIDGE_AGENT_SYNC_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"profiles":[{"agent_id":"df74ed60-9505-4b2b-80c5-4c3dc2b2f58b","name":"Nova","title":"Support lead","description":"You are Nova, the support lead.\n- Answer within a day"}]}'
```

**200**

```json
{"data": [
  {"agent_id": "df74ed60-9505-4b2b-80c5-4c3dc2b2f58b",
   "description_hash": "3f0c…64 hex…",
   "pending_edit_status": "conflict"}
]}
```

`pending_edit_status`: the status of the agent's edit that was pending before this call, after the conflict rule
(`pending`, `applied` or `conflict`); `null` when there was none. **422** for a missing/invalid field or a description
over 20000 characters (nothing is stored).

### GET /api/agent-instruction-edits

`?status=pending` (default) `| applied | rejected | conflict | superseded`. Oldest first. **422** for another status.

**200**

```json
{"data": [
  {"id": 12,
   "agent_id": "df74ed60-9505-4b2b-80c5-4c3dc2b2f58b",
   "agent_name": "Nova",
   "revision": 3,
   "status": "pending",
   "base_hash": "3f0c…",
   "proposed_description": "You are Nova, the support lead.\n- Answer within four hours",
   "proposed_hash": "9a1b…",
   "created_at": "2026-11-08T09:12:44+00:00",
   "author": "tilen"}
]}
```

`author` is the Bridge actor id of the owner who saved it.

### POST /api/agent-instruction-edits/{id}/applied

Body `{"description_hash": "<sha256 of the description now on the real profile>"}`. It must equal
`sha256(proposed_description)`, else **422** (the edit stays `pending`, nothing changes; checked inside the same locked
transaction that resolves the edit). Sets the edit `applied` (`applied_hash`, `applied_at`)
and Bridge's profile copy to the proposed text (`description`, `description_hash`, `synced_at`).

**200**

```json
{"data": {"id": 12, "agent_id": "df74ed60-…", "revision": 3, "status": "applied", "status_reason": null,
  "proposed_hash": "9a1b…", "applied_hash": "9a1b…", "applied_at": "2026-11-09T06:00:03+00:00",
  "resolved_at": "2026-11-09T06:00:03+00:00", "profile_description_hash": "9a1b…"}}
```

### POST /api/agent-instruction-edits/{id}/rejected

Body `{"reason": "optional, ≤255"}`. Sets `rejected`. Same response shape. The profile is not changed.

### POST /api/agent-instruction-edits/{id}/conflict

Body `{"current_description_hash": "<sha256 of the real profile's description>", "reason": "optional, ≤255"}`.
Bridge compares the hash with the edit:

| `current_description_hash` | Answer | Meaning |
|---|---|---|
| equals the edit's `base_hash` | **422**, edit stays `pending` | The profile has not changed since the edit was made: apply it (`…/applied`) instead. |
| equals the edit's `proposed_hash` | **422**, edit stays `pending` | The profile already has the proposed text: report `…/applied` instead. |
| anything else | **200**, `conflict` | |

On a conflict `status_reason` is `<reason> (current_description_hash <hash>)`; the reason defaults to
`profile description changed after this edit was made` and is shortened so the whole value fits 255 characters. The
response also echoes `current_description_hash`. The profile is not changed: send the real profile with
`POST /api/agent-profiles` so the owner sees the current text.

### Status codes (all routes)

| Code | When |
|---|---|
| 200 | OK |
| 401 | No or invalid token |
| 403 | Token lacks the ability, or its user is not the workspace owner |
| 404 | Edit id not in the token's workspace |
| 409 | Edit is not `pending` |
| 422 | Validation (fields, sizes, hash mismatch on `applied`, `conflict` hash equal to `base_hash` or `proposed_hash`, unknown `status` filter) |
| 429 | Rate limit |

## Daily routine

1. **Ingest.** For each agent read its real profile (`name`, `title`, `description` only) and send them all in one
   `POST /api/agent-profiles`.
2. **Read pending.** `GET /api/agent-instruction-edits` (pending).
3. **For each pending edit:** compute `sha256` of that agent's **current** profile description.
   - Equal to `base_hash`: apply `proposed_description` with the supported Grok Bot profile tool (`UpdateAgent` /
     `UpdateProfile`, the `description` field only). Re-read the profile, hash its description, and
     `POST …/{id}/applied` with that hash. If the tool fails or the agent refuses, `POST …/{id}/rejected` with a reason.
   - Different: do not apply. `POST …/{id}/conflict` with `current_description_hash`. The owner resolves it in Bridge.
4. **Never send** hidden system prompts, settings, secrets, credentials or any field other than `name`, `title`,
   `description`.
5. **Re-ingest** the profiles you changed (`POST /api/agent-profiles`), so Bridge shows what the host really has.

Edits saved after step 2 wait for the next day.

## Web (for reference)

- `GET /agents`: every agent of the workspace (synced profiles + agents seen in usage files) with title, a description
  excerpt, last sync time and a pending/conflict badge; each row links to `/usage/agents/{agentId}`.
- `GET /usage/agents/{agentId}`: usage plus the **Instructions / Description** section. An agent with a profile but no
  usage renders with empty usage; an agent with neither answers 404.
- `POST /usage/agents/{agentId}/instructions` `{"description": "…"}`: workspace owner only (403 for others, 404 for an
  unknown agent, 422 when the text equals the current description). Creates the next revision.

## Schema

Additive migration `2026_11_08_100000_create_agent_instruction_tables`: `agent_profiles` (unique
`workspace_id, agent_id`) and `agent_instruction_edits` (unique `workspace_id, agent_id, revision`; index
`workspace_id, agent_id, status`). Both are workspace-scoped. No existing table changes.
