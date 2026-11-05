# Power Saver API

Locked contract for Captain's enforcement routine. Bridge **stores and reads thresholds**. It does not compare usage, and it does not pause or resume a project or a routine.

- `GET /api/power-saver` needs `boards:read` (`*` and legacy `ceo` also pass).
- `PATCH /api/power-saver` needs `boards:write`.
- The signed-in page is `GET /power-saver` and `PATCH /power-saver` (workspace member, same JSON body).
- Another workspace's project or routine is not listed and cannot be written (422).

The only schema change is the additive table `power_saver_thresholds` (`2026_11_05_123157_create_power_saver_thresholds_table`). Projects, agents, and usage days are not altered. There is no enabled flag. Archive and project status are not a stop.

## Ceiling and comparison

`stop_percent` is a percent of the **shared weekly Ultra usage limit** (one limit shared across the plan's apps).

It is not a Grok-Bot-only percentage and it is not additional credits. Bridge does not store that limit and does not compute `current_usage`.

| `stop_percent` | Meaning |
| --- | --- |
| `null` (no row) | Never stop. |
| `0` | Stop when `current_usage >= 0` (immediately, including zero usage). |
| `100` | Stop only when the shared weekly limit is fully used. |
| `1`–`99` | Stop when `current_usage >= stop_percent`. |

Resume only when `current_usage < stop_percent`. The weekly reset lowers usage, so work comes back on without a Bridge call.

## What `current_usage` is made from

**Project (approximation).** Bridge usage estimates are per agent, not measured per-project totals. The map is `projects.owner_agent_id` (the PM). Use that agent's **total** estimated usage. If the same agent is PM of several projects, apply that whole total to **each** project. Do not split it.

**Routine.** Use that routine's own reported usage (`usage_days` → `agents[].by_routine` for this `agent_id` + `routine_id`). `unmatched` and `other_est_tokens` are not routines. If the routine has not been observed, `current_usage` is `0` until it is.

## Enforcement

`external_only`. Each agent pauses and resumes **its own** routines. Bridge cannot do it. `stoppable_in_bridge` is always `false`.

## GET `/api/power-saver`

```json
{
  "data": {
    "semantics": {
      "locked": true,
      "ceiling": "shared_weekly_ultra_usage_limit",
      "ceiling_scope": "shared_across_plan_apps",
      "ceiling_not": ["grok_bot_only_percentage", "additional_credits"],
      "comparison": "gte",
      "comparison_rule": "current_usage >= stop_percent",
      "resume": "strict_lt",
      "resume_rule": "current_usage < stop_percent",
      "weekly_reset_reenables": true,
      "bridge_computes_current_usage": false,
      "project_usage_attribution": "approximate_agent_total_not_split",
      "project_usage": {
        "quality": "approximate",
        "basis": "agent_total_estimated_usage",
        "mapping": "projects.owner_agent_id",
        "same_agent_many_projects": "apply_agent_total_to_each_project",
        "split_usage": false
      },
      "routine_usage": {
        "signal": "own_routine_reported_usage",
        "unobserved_current_usage": 0
      },
      "project_agent_id": { "field": "agent_id", "cardinality": "at_most_one", "null_when": "no_pm" },
      "project_agent_ids": { "field": "agent_ids", "cardinality": "zero_or_one" },
      "enforcement": "external_only",
      "mutates_subjects": false
    },
    "gaps": {
      "chosen_stop_option": "external_only",
      "project_enabled_state": false,
      "routine_enabled_state": false,
      "bridge_can_pause_routines": false,
      "project_usage_is_approximate": true
    },
    "projects": [
      {
        "id": "proj-northwind",
        "name": "Northwind",
        "slug": "northwind",
        "status": "smooth",
        "status_label": "Smooth sailing",
        "archived": false,
        "agent_id": "agent-anatoly",
        "agent_ids": ["agent-anatoly"],
        "agent_name": "Anatoly Korenchkin",
        "project_ids_for_agent": ["proj-northwind"],
        "split_usage": false,
        "usage_signal": "agent_total_estimated",
        "usage_approximation": true,
        "stop_percent": null,
        "stoppable_in_bridge": false,
        "threshold_updated_at": null
      }
    ],
    "routines": [
      {
        "agent_id": "agent-janice",
        "agent_name": "Dr. Janice Polito",
        "routine_id": "support-inbox",
        "name": "Support inbox",
        "seen_in_usage": true,
        "usage_signal": "own_routine",
        "unobserved_current_usage": 0,
        "stop_percent": 40,
        "stoppable_in_bridge": false,
        "threshold_updated_at": "2026-11-05T12:00:00+01:00"
      }
    ]
  }
}
```

`agent_id` is the project's PM (`owner_agent_id`): at most one, `null` when the project has no PM. `agent_ids` is that same PM as a list (`[]` or `[agent_id]`), so a caller can walk mappings without a special case. `project_ids_for_agent` is every project in this workspace with that PM, including this one, sorted by id. When that list has more than one id, those projects share one agent and each gets the agent's full estimated usage.

## PATCH `/api/power-saver`

```json
{ "thresholds": [
  { "subject_type": "project", "project_id": "proj-northwind", "stop_percent": 80 },
  { "subject_type": "routine", "agent_id": "agent-janice", "routine_id": "support-inbox", "stop_percent": null }
]}
```

`stop_percent` is required on every item. It must be a JSON integer from 0 to 100, or JSON `null` (clears the row). A numeric string such as `"12"`, an empty string, a float, a boolean, an array, or an object is **422**, including when another item in the same batch is valid: nothing in that batch is saved. The page sends a JSON integer or `null`, never a string. A project threshold must name a project in this workspace and must not send an agent or routine. A routine threshold must name an agent id (`A–Z a–z 0–9 _ -`, 1–64) and a routine id (1–120 characters) that is not `unmatched` or `other_est_tokens`. The agent must exist here, or a usage file must already list that routine. The response `data` array echoes each saved subject (`stop_percent` null after a clear).
