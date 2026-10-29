# Bridge

LANTIS CEO overview (Captain William Diego / Tilen). Portfolio, cockpit, ops stub, agents, CEO queue.

## Stack

- **Laravel 13** + **Inertia** + **React** + **TypeScript**
- SQLite for local/`php artisan test`; **MySQL** for production
- AUTH-1 shared workspace gate (session) — single password, no per-agent ACL

## Former SPA

Previously a Vite+React SPA (repo history / `/workspace/lantis-command/app`). This tree replaces that SPA with a Laravel app. Seed data and Ink Command UI tokens were ported from the SPA.


## Web UI auth (AUTH-1)

Shared workspace password via `BRIDGE_GATE_PASSWORD` (default `lantis-internal`). Stored in `.bridge-deploy-secrets` (chmod 600, not committed). Do not print secrets in chat.

Primary nav: Portfolio · Ops · Agents · Queue. Project cockpits are reached from Portfolio cards (`/projects/{slug}`) — no Cockpit nav link.

## REST API (Sanctum)

**Tickets (Kanban) API for PM agents: see [docs/API-TICKETS.md](docs/API-TICKETS.md).**

Bearer token auth on `/api/*`. CEO personal access token is created by `BridgeApiSeeder` and written to `.bridge-deploy-secrets` as `BRIDGE_API_TOKEN` (plaintext once; never commit/print).

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

Base URL (live): `https://bridge.tilenpoje.eu`

Token lives in `.bridge-deploy-secrets` as `BRIDGE_API_TOKEN` (server: `/var/www/bridge/.bridge-deploy-secrets`) — never commit or paste into chat.

### Example: POST status update

```bash
# token from secrets file — do not echo it into logs/chat
TOKEN=$(sed -n 's/^BRIDGE_API_TOKEN=//p' .bridge-deploy-secrets)

curl -sS -X POST "https://bridge.tilenpoje.eu/api/status-updates" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "project_id": "proj-concierge-avant",
    "agent_id": "agent-william",
    "body": "Reviewed write path — status history looks good."
  }'
```

Seed IDs: projects `proj-merkwell`, `proj-concierge-avant`; agents include `agent-william`, `agent-anatoly`, `agent-janice`, `agent-tommy`, `agent-shodan`.

### Example: create CEO queue item

```bash
curl -sS -X POST "https://bridge.tilenpoje.eu/api/ceo-queue-items" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "reason": "Concierge needsDecision still open — needs Tilen call.",
    "suggested_action": "Approve or reject phase-2 scope freeze.",
    "project_id": "proj-concierge-avant"
  }'
```

| Method | Path | Notes |
|--------|------|-------|
| GET/POST | `/api/projects` | list / create |
| GET/PATCH/DELETE | `/api/projects/{id}` | show / update / destroy |
| GET/POST | `/api/milestones` | `?project_id=` |
| PATCH/DELETE | `/api/milestones/{id}` | |
| GET/POST | `/api/work-items` | `?project_id=` |
| PATCH/DELETE | `/api/work-items/{id}` | |
| GET/POST | `/api/risks` | `?project_id=` |
| PATCH/DELETE | `/api/risks/{id}` | |
| GET/POST | `/api/status-updates` | |
| DELETE | `/api/status-updates/{id}` | |
| GET/POST | `/api/agents` | read + limited write |
| PATCH | `/api/agents/{id}` | |
| GET/POST | `/api/ceo-queue-items` | |
| POST | `/api/ceo-queue-items/{id}/dismiss` | |
| POST | `/api/ceo-queue-items/{id}/assign` | body `{ "agent_id": "..." }` |
| GET | `/api/ops-digest` | read-only stub / empty |

No Lead Orbit write paths. Ops digest stays empty until a real feed exists.

## Local

```bash
cp .env.example .env   # if needed
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

## Production DB

Set in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bridge
DB_USERNAME=bridge
DB_PASSWORD=...
```

## Deploy note

Live DocumentRoot: `/var/www/bridge/public` on Xerxes (`https://bridge.tilenpoje.eu`).

## Tests / build

```bash
php artisan test
npm run build
```

## Out of scope (v1)

Lead Orbit / Merkwell CRM connector — Ops stays an honest empty/gap state. No invented clients or revenue.
