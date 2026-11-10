# Agents roster sync (access contract)

The agents sync file is the list of the workspace's **real agents**: their full name with title, the section they belong
to, and their profile description. Bridge's `/agents` page shows **only** the agents of the last roster sync, grouped by
section. Group chats and rooms that show up in usage files or older profile ingests are not agents and are not listed.

This complements the instructions sync ([agent-instructions-sync.md](agent-instructions-sync.md)): the roster sync uses
the same ingest step for the description, so pending owner edits follow the same rules.

## Auth

```
Authorization: Bearer $BRIDGE_AGENT_SYNC_TOKEN
Accept: application/json
Content-Type: application/json
```

`$BRIDGE_AGENT_SYNC_TOKEN` is an environment variable on the caller's side. Never paste the value into chat, logs,
tickets or comments.

Same model as `POST /api/agent-profiles`: the token must belong to the **workspace owner** and carry `agents:write`
(a `read & write` token; `*` and the legacy `ceo` ability also pass, the owner check still applies).

| Case | Answer |
|---|---|
| No or invalid token | **401** |
| Token of a member or any other non-owner user | **403** |
| Owner token without `agents:write` | **403** |
| More than 120 requests per minute per token | **429** |

## POST /api/agents/sync

Send the sync file **as-is**:

```bash
curl -sS -X POST "$BRIDGE/api/agents/sync" \
  -H "Authorization: Bearer $BRIDGE_AGENT_SYNC_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d @agents-sync.json
```

### Request

```json
{
  "generated_at": "2026-11-10T05:00:00+01:00",
  "source": "captain roster",
  "sections": ["Project managers", "Development", "Trading", "Unassigned"],
  "agents": [
    {"agent_id": "5b79509e-da30-43eb-a944-a73c98593b18", "full_name": "Shodan · Lead Developer",
     "section": "Development", "section_order": 1, "description": "You are Shodan, the lead developer.\n…"},
    {"agent_id": "0f3c2a10-1111-4c4c-9a9a-000000000001", "full_name": "Juan Curtiz",
     "section": "Trading", "section_order": 2, "description": ""}
  ]
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `generated_at` | string ≤64 | no | Accepted, not stored. |
| `source` | string ≤255 | no | Accepted, not stored. |
| `sections` | array of strings ≤64 | no | Accepted, not used for ordering (`section_order` is). |
| `agents` | array, 1–200 | **yes** | The full roster. |
| `agents[].agent_id` | string | **yes** | Usage agent id, `[A-Za-z0-9_-]{1,64}`, unique in the body. |
| `agents[].full_name` | string ≤255 | **yes** | Shown exactly as sent, e.g. `Shodan · Lead Developer`. |
| `agents[].section` | string ≤64 | **yes** | Section heading on `/agents`. |
| `agents[].section_order` | integer 0–99 | **yes** | A JSON integer (`"1"` is refused). |
| `agents[].description` | string ≤20000 chars | no (may be `""` or `null`) | The agent's real profile description. Missing/`null` is stored as `""`. |

Unknown fields (top level or per agent) are ignored. Strings are neither trimmed nor turned into `null`: the text you
send is the text that is stored and hashed.

**422** for any invalid field (missing `full_name`, bad or duplicate `agent_id`, `section_order` out of range or not an
integer, description over 20000 characters, empty `agents` …). Nothing is stored: the whole body is validated before
any write.

### Response 200

```json
{"data": {
  "received": 20,
  "created": 0,
  "updated": 1,
  "unchanged": 19,
  "unlisted": 0,
  "sections": ["Project managers", "Development", "Trading", "Unassigned"],
  "agents": [
    {"agent_id": "5b79509e-da30-43eb-a944-a73c98593b18", "result": "updated",
     "description_hash": "3f0c…64 hex…", "pending_edit_status": "conflict"},
    {"agent_id": "0f3c2a10-1111-4c4c-9a9a-000000000001", "result": "unchanged",
     "description_hash": "e3b0c442…", "pending_edit_status": null}
  ]
}}
```

| Count | Meaning |
|---|---|
| `received` | Agents in the body. |
| `created` | No profile with this `agent_id` existed in the workspace. |
| `updated` | A profile existed but was not listed, or its `full_name`, `section`, `section_order` or `description` differs. |
| `unchanged` | A listed profile with the identical `full_name`, `section`, `section_order` and `description`. |
| `unlisted` | Listed profiles of the workspace that are missing from this body (see below). |

`sections`: the section names of this body, ordered by `section_order` (the lowest one in the section), then by name.
`agents[]` follows the body order: `result` is `created` / `updated` / `unchanged`; `description_hash` (sha256 of the
exact description) and `pending_edit_status` are the same as in `POST /api/agent-profiles`.

Re-posting the same body gives `created` 0, `updated` 0, `unlisted` 0.

### What is stored

Upsert by (workspace, `agent_id`) on `agent_profiles`:

- `full_name`, `section`, `section_order`, `listed = true`, `roster_synced_at`;
- `name` / `title`: `full_name` split on the first ` · ` (`Shodan · Lead Developer` → `Shodan` / `Lead Developer`;
  `Juan Curtiz` → `Juan Curtiz` / no title), so code that reads `name` and `title` keeps working;
- `description`, `description_hash`, `synced_at`: through the same ingest step as `POST /api/agent-profiles`.

### Snapshot: unlisting

The body is the **full roster**. A profile of the workspace that is listed now but missing from the body becomes
**unlisted** (`listed = false`). It is not deleted: its description, section, edits and history stay, and its page
`/usage/agents/{agentId}` still works. It is listed again (counted as `updated`) when a later sync brings it back.

## Description and owner-edited instructions

The synced `description` **is** the agent's real profile description: the same field the owner proposes edits to on
`/usage/agents/{agentId}` and the same field `POST /api/agent-profiles` writes. It goes through the same ingest step
(sha256 of the exact text, writes serialised per workspace), including the conflict rule for the agent's pending edit:

1. the synced description **equals** the edit's proposed text → the edit becomes `applied`;
2. else its hash **differs** from the edit's `base_hash` → `conflict` (the owner sees both texts);
3. else the edit stays `pending`.

Owner edits live as revisions in `agent_instruction_edits`; a roster sync never changes or deletes them. Applying an
edit to the real agent stays the job of the instructions sync (its [daily routine](agent-instructions-sync.md#daily-routine)).

`POST /api/agent-profiles` and the edit endpoints keep working as before. They never change `listed`, `full_name`,
`section` or `section_order`, so an agent only appears on `/agents` through this roster sync.

## Listing and grouping (`/agents`)

- Only `agent_profiles` rows with `listed = true` in the current workspace. Agent ids seen only in usage files and
  profiles that only came through `POST /api/agent-profiles` are **not** listed. Bridge has no list of room names; the
  roster decides.
- One section per `section`, headed by its name, ordered by `section_order` (lowest value in the section), then by name.
- Inside a section: by `full_name`, case-insensitive, then `agent_id`. The roster file is in this order already; Bridge
  sorts itself so the page does not depend on the body order.
- Each row: `full_name` (linking to `/usage/agents/{agentId}`), a description excerpt (160 characters), last sync time
  and a pending/conflict badge. An empty or missing description shows **No description**; Bridge never invents text.
- The agent page heading shows `full_name`; its Instructions section shows the whole description (or **No description**).

## Schema

Additive, reversible migration `2026_11_10_083450_add_roster_columns_to_agent_profiles` on `agent_profiles` only:
`full_name` (nullable string), `section` (nullable string 64), `section_order` (nullable unsigned small integer),
`listed` (boolean, default `false`), `roster_synced_at` (nullable timestamp), index `workspace_id, listed`. Existing rows
start as not listed: `/agents` is empty until the first roster sync.
