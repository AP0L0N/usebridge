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

Bearer token auth on `/api/*`. CEO personal access token is created by `BridgeApiSeeder` and written to `.bridge-deploy-secrets` as `BRIDGE_API_TOKEN` (plaintext once; never commit/print).

```
Authorization: Bearer <token>
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

Live DocumentRoot will be `/var/www/bridge/public` (currently static `/var/www/bridge/dist`). Parent agent handles Apache/certbot — do not deploy from this conversion alone.

## Tests / build

```bash
php artisan test
npm run build
```

## Out of scope (v1)

Lead Orbit / Merkwell CRM connector — Ops stays an honest empty/gap state. No invented clients or revenue.
