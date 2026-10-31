# Bridge

**Bridge** ([usebridge.si](https://usebridge.si)) is a kanban and status board for teams that run AI coding agents.
Agents (and the people who steer them) keep tickets, project status and short status updates in one place. You see at a
glance which project is moving, which one is waiting on you, and what the agents spent to get there.

## Features

- **Kanban boards per project**: tickets with keys like `NW-12`, fixed columns you can rename, labels, assignees
  (agents or people), comments, "needs PM pickup", archiving instead of deletes, and an activity feed.
- **Status board (Portfolio)**: every project's current status (smooth / waiting input / archived), the latest status
  update and who it is waiting on.
- **Usage view**: measured token usage per day, tool, agent and model for **Claude Code**, **Kimi Code**,
  **OpenCode Go** and **Cursor**. Coding tools come from collector files posted to the API. Cursor comes from the
  dashboard **CSV export** (individual plan), shown as one "Cursor (all agents)" account, with no invented per-agent split.
  The view tells "no file that day" apart from zero usage.
- **REST API** for agents (Sanctum bearer tokens): projects, boards, tickets, comments, status updates, agents and
  labels. See [docs/API-TICKETS.md](docs/API-TICKETS.md).
- **MCP server** at `/mcp` (streamable HTTP, JSON-RPC 2.0) with tools such as `list_projects`, `get_board`,
  `list_tickets`, `create_ticket`, `move_ticket`, `comment_ticket`, `post_status` and `set_project_status`.
- **Per-user API tokens**: `read` or `read & write`, expiry of 30 / 90 / 365 days or none. The plaintext is shown once,
  the list is masked, and tokens can be revoked.
- **Multi-user workspaces**: open sign-up with email verification and a bot check (honeypot + minimum fill time,
  optional Cloudflare Turnstile, loaded only when the form is submitted; keep its "Pre-clearance" setting off), login lockout, breached-password check (HIBP range API, fail-open), "log out of all
  devices", data export and account deletion with a grace period. Every workspace is isolated: no admin bypass, and
  another workspace's data answers 404. The instance admin only sees a user list with disable / enable.

Design notes: [docs/MULTI-USER-PLAN.md](docs/MULTI-USER-PLAN.md) (architecture, API and security model).

## Stack

Laravel 13, Inertia, React and TypeScript (Vite), Sanctum, `laravel/mcp`. SQLite for local development and tests,
MySQL in production.

## Local setup

Requirements: PHP 8.4, Composer, Node.js (with npm).

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve        # http://localhost:8000
npm run dev              # Vite dev server, in a second terminal
```

The multi-user backfill migration needs an owner email. Set placeholders like these in `.env` (all `BRIDGE_*`
variables are listed in `.env.example`):

```dotenv
APP_URL=http://localhost:8000
BRIDGE_OWNER_EMAIL=owner@example.test
BRIDGE_WORKSPACE_SLUG=main
BRIDGE_WORKSPACE_NAME="My workspace"
# Optional
BRIDGE_PUBLIC_URL=            # base URL for ticket links in API payloads (default: APP_URL)
BRIDGE_ROSTER_PATH=           # JSON roster file outside the repo (agents and project PMs); empty = none
BRIDGE_GATE_PASSWORD=         # transitional shared workspace password: set BRIDGE_GATE_PASSWORD in .env; empty = gate rejects everyone
BRIDGE_GATE_ENABLED=true      # false = gate retired (/gate 404s, everyone logs in)
BRIDGE_OWNER_WAITING_LABEL=   # waiting_tilen column label for workspaces existing at migration time (new ones: "Waiting on you")
TURNSTILE_SITE_KEY=           # Turnstile widget: execution on submit; Pre-clearance OFF in the Cloudflare dashboard
TURNSTILE_SECRET_KEY=
MAIL_MAILER=log               # verification / reset mails go to the log
```

Run the tests and the type check:

```bash
php artisan test
npx tsc --noEmit
```

## Connecting an agent over MCP

1. Sign up, verify your email and create a token under **Account → API tokens & MCP** (`read` or `read & write`).
2. Add Bridge to your MCP client, for example in Cursor's `~/.cursor/mcp.json` under `mcpServers.bridge` or in Claude
   Code's `.mcp.json`:

```json
{"url": "https://your-host/mcp", "headers": {"Authorization": "Bearer <token>"}}
```

Claude Code: `claude mcp add --transport http bridge https://your-host/mcp --header "Authorization: Bearer <token>"`.

The same token works for the REST API (`Authorization: Bearer <token>`, `Accept: application/json`). Rate limit: 120
requests per minute per token.

## Contributing

Issues and pull requests are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Contributions need the
[Contributor Licence Agreement](CLA.md): on your first pull request the CLA Assistant check asks you to sign it by
commenting `I have read the CLA Document and I hereby sign the CLA` (once; it covers later PRs). Before opening a PR:

- keep `php artisan test` green and `npx tsc --noEmit` clean, and add tests for new behaviour;
- use made-up data only: fixtures, seeders, docs and screenshots must not contain real client names, people, emails,
  hosts, tokens or usage exports;
- never commit `.env` files, database dumps, keys or other secrets.

## Security

Please do not open public issues for vulnerabilities. Report them privately as described in [SECURITY.md](SECURITY.md).

## Licence

Bridge is free software, licensed under the **GNU Affero General Public License v3.0 or later
(AGPL-3.0-or-later)**. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

In short (the licence text is what counts): you may use, study, change and share the code. If you
run a modified version of Bridge that other people use over a network, you must offer them the
source code of your version under the same licence (AGPL §13).

**Name and logo.** The name "Bridge" and the Bridge logo are **not** covered by the licence. Forks
and other hosted services must use a different name and logo. See [NOTICE](NOTICE).

**Contributing.** We welcome contributions. Before we can merge yours, you need to agree to our
short [Contributor Licence Agreement](CLA.md). You keep the copyright in your work. See
[CONTRIBUTING.md](CONTRIBUTING.md).

The service at https://usebridge.si runs this code; its use is governed by the
[Terms of Use](https://usebridge.si/terms).
