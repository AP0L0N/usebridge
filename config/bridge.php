<?php

return [
    /*
    | AUTH-1: single shared workspace gate password (transitional, App\Support\WorkspaceGate). Fails closed (CoS
    | decision 31 Oct): no default; unset or empty = the gate rejects every password. Set it explicitly in .env.
    | gate_enabled false = the gate is retired: /gate 404s and gate sessions (even already unlocked ones) unlock
    | nothing, so everyone logs in. Retired in production when multi-user launches (docs/MULTI-USER-PLAN.md).
    */
    'gate_password' => (string) env('BRIDGE_GATE_PASSWORD'),
    'gate_enabled' => filter_var(env('BRIDGE_GATE_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    | Bridge r1 migration: carry r0 items over as open Required inputs?
    | true  (default) = projects.needs_decision + open/assigned project CEO-queue items
    |                   become open inputs.
    | false ("start clean") = no inputs are created; the r0 data is still kept
    |                   in legacy_r0_* tables either way.
    | Only read once, by migration 2026_10_12_180000. Set BRIDGE_R1_CARRY_OVER=false
    | in .env (or inline on the migrate command) and clear the config cache first.
    */
    'r1_carry_over' => filter_var(env('BRIDGE_R1_CARRY_OVER', true), FILTER_VALIDATE_BOOL),

    /*
    | Tickets cutover: when true, legacy write endpoints (questions, inputs, next-steps, ceo-queue-items; web input
    | resolve and question answer) answer 410 and point to docs/API-TICKETS.md. GETs keep working. Flip at UI cutover.
    */
    'legacy_read_only' => filter_var(env('BRIDGE_LEGACY_READ_ONLY', false), FILTER_VALIDATE_BOOL),

    /* Public Bridge URL, used for ticket links in API payloads (answers relay). Defaults to APP_URL. */
    'public_url' => env('BRIDGE_PUBLIC_URL') ?: env('APP_URL', 'http://localhost'),

    /*
    | Host of the live Bridge: `bridge:rotate-copied-tokens` refuses to run when APP_URL has this host (set it on
    | staging copies of the live database). Empty = no host check (the APP_ENV checks still apply).
    */
    'live_host' => strtolower((string) env('BRIDGE_LIVE_HOST', '')),

    'org_timezone' => 'Europe/Ljubljana',

    /*
    | Multi-user (docs/MULTI-USER-PLAN.md). default_workspace: slug of the owner's workspace (BRIDGE_WORKSPACE_SLUG),
    | created by the backfill migration with the name BRIDGE_WORKSPACE_NAME; AUTH-1 gate sessions and console writes
    | without a workspace act in it (transitional). An already migrated database must keep the slug it was created
    | with: set BRIDGE_WORKSPACE_SLUG to it.
    | owner_email: the owner's login email; read once by the backfill migration to pick/create the owner user.
    */
    'default_workspace' => (string) (env('BRIDGE_WORKSPACE_SLUG') ?: 'main'),
    'default_workspace_name' => (string) (env('BRIDGE_WORKSPACE_NAME') ?: 'My workspace'),
    'owner_email' => env('BRIDGE_OWNER_EMAIL'),

    /*
    | Label of the waiting_tilen column on workspaces that already existed when migration 2026_10_31_160000 ran (it
    | stores it in workspaces.settings.waiting_label; read only then). Empty = "Waiting on " + the first name of the
    | default workspace's owner (what those workspaces showed before). New workspaces show BoardColumns' default.
    */
    'owner_waiting_label' => trim((string) env('BRIDGE_OWNER_WAITING_LABEL')),

    /* Login email of the API user BridgeApiSeeder creates or reuses. */
    'api_user_email' => (string) (env('BRIDGE_API_USER_EMAIL') ?: 'captain@example.test'),

    /*
    | Roster (App\Services\BridgeRoster): JSON file outside the repo with the agents that must exist, each project's
    | PM and optional fixed ticket prefixes. Empty / missing / invalid = empty roster (one warning log line).
    */
    'roster' => [
        'path' => env('BRIDGE_ROSTER_PATH'),
    ],

    /*
    | Breached-password check on sign-up, reset and change (HIBP k-anonymity range API, CoS decision 31 Oct). Fail-open:
    | HIBP down or slower than hibp_timeout seconds = password allowed (warning logged). Off in phpunit.xml.
    */
    'hibp_enabled' => filter_var(env('BRIDGE_HIBP_ENABLED', true), FILTER_VALIDATE_BOOL),
    'hibp_timeout' => 3,

    /*
    | Legal (sign-up acceptance, legal pages, footer). terms_version: stored on the user when they accept at sign-up.
    | legal_entity: the operator shown in the footer (":entity"). legal_dir: directory with the final legal texts
    | (terms.en.md, privacy.en.md, imprint.en.md, pogoji.sl.md, zasebnost.sl.md, impresum.sl.md), kept outside the repo;
    | null = the placeholders in resources/legal. source_url / source_sha: the AGPL §13 "Source code" link, to
    | <source_url>/tree/<sha> when the deployed commit is known (else the file .bridge-deploy-sha, if present).
    */
    'terms_version' => env('BRIDGE_TERMS_VERSION', '2026-10-31'),
    'legal_entity' => env('BRIDGE_LEGAL_ENTITY', 'Your company'),
    'legal_dir' => env('BRIDGE_LEGAL_DIR'),
    'source_url' => env('BRIDGE_SOURCE_URL', 'https://github.com/AP0L0N/usebridge'),
    'source_sha' => env('BRIDGE_SOURCE_SHA', env('BRIDGE_DEPLOYED_SHA')),
];
