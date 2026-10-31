<?php

return [
    /*
    | AUTH-1: single shared workspace gate password (Captain-approved).
    | Override via BRIDGE_GATE_PASSWORD. Default remains lantis-internal.
    */
    'gate_password' => env('BRIDGE_GATE_PASSWORD', 'lantis-internal'),

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

    /* Public Bridge URL, used for ticket links in API payloads (answers relay). */
    'public_url' => env('BRIDGE_PUBLIC_URL', 'https://bridge.tilenpoje.eu'),

    'org_timezone' => 'Europe/Ljubljana',

    /*
    | Multi-user (docs/MULTI-USER-PLAN.md). default_workspace: slug of Tilen's workspace, created by the backfill
    | migration; AUTH-1 gate sessions and console writes without a workspace act in it (transitional).
    | owner_email: Tilen's login email; read once by the backfill migration to pick/create the owner user.
    */
    'default_workspace' => env('BRIDGE_DEFAULT_WORKSPACE', 'tilen'),
    'default_workspace_name' => env('BRIDGE_DEFAULT_WORKSPACE_NAME', 'LANTIS'),
    'owner_email' => env('BRIDGE_OWNER_EMAIL'),

    /*
    | Breached-password check on sign-up, reset and change (HIBP k-anonymity range API, CoS decision 31 Oct). Fail-open:
    | HIBP down or slower than hibp_timeout seconds = password allowed (warning logged). Off in phpunit.xml.
    */
    'hibp_enabled' => filter_var(env('BRIDGE_HIBP_ENABLED', true), FILTER_VALIDATE_BOOL),
    'hibp_timeout' => 3,
];
