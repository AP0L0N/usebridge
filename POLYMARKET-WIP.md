# Bridge /polymarket — WIP handoff (25 Oct 2026, Xerxes)

Status: built locally, NOT deployed. Nothing changed on production Bridge (no migration, no DB backup, no seed, no POST).
Full suite green locally (179 tests, 45 new); `npm run build` + `npx tsc --noEmit` clean.
Spec: Tommy's mock in /workspace/bridge-polymarket/mock/ (DATA-SPEC.md, index.html, states.html, polymarket.css) on the box.

## Ingest decision (Captain, Tilen 25 Oct 21:46)
Only Captain posts (weekday ~19:00 routine, BRIDGE_API_TOKEN). Frank does NOT push. Overrides DATA-SPEC §2 A/B.

## Endpoint
`POST /api/polymarket/feed`  (auth:sanctum group)
Headers: `Authorization: Bearer <BRIDGE_API_TOKEN>`, `Content-Type: application/json` (else 415), `Accept: application/json`. Body ≤ 1 MB (else 413).

```json
{
  "snapshot": {                         // = Frank's feed.json, stored as-is (minus history[])
    "as_of": "2026-10-25T21:52+01:00",  // required, ISO-8601 with offset
    "book_value_usd": 719.36,           // required number
    "positions": [ { "market": "...", "outcome": "Yes", "shares": 205, "avg_price": 0.39,
                     "cur_price": 0.9785, "cost_usd": 79.95, "value_usd": 200.59, "pnl_usd": 120.64,
                     "end_date": "2026-10-25", "event_slug": "...", "settled": false } ],  // required array
    "cash_usd": 162.9, "open_value_usd": 556.46, "open_count": 8, "high_water_usd": 1019.34,
    "funded_usd": 635.1, "funded_since": "...", "total_pnl_usd": 342.75, "realized_pnl_usd": 458.56,
    "sweeps_total_usd": 258.49, "last_decision": "PASS",
    "gateway": {"status": "ok", "geoblock": false, "country": "FI"},
    "fills": [...], "sweeps_detail": [...], "note": "...", "note_ts": "...",
    "history": [ {"ts": "2026-10-03T16:05+02:00", "book_value_usd": 635.1, "stop_usd": 317.55} ]  // optional; merged into series stop_usd
  },
  "series": [                            // = book_series.json, required array (may be empty), ≤ 20000
    {"as_of": "2026-10-03T16:05+02:00", "book_value_usd": 635.1, "cash_usd": null, "source": "odds-desk-2026-10-03-1605.json"}
  ]
}
```
Notes: optional snapshot fields are type-checked when present; unknown keys are kept. The older latest.json shape
(side/avg_entry/current_value_usd, no gateway/funded/history) is accepted and renders with "—" + hints.

Semantics (idempotent, one transaction, body never logged):
- snapshot: single current row; replaced only if incoming as_of ≥ stored; older → "kept_newer"; equal values → "unchanged".
- series: upsert by UTC ts; rows updated only when a value changed (no updated_at churn).
- Response: 201 first snapshot, else 200:
  `{"data":{"snapshot":"created|replaced|unchanged|kept_newer","as_of":"...","series":{"received":128,"inserted":0,"updated":0,"unchanged":128,"total":128}}}`
- 422 `{message, errors}` on a bad payload; 401 without token.

One-off seed / backfill (same importer): `php artisan bridge:polymarket-import <snapshot.json> <series.json> [--dry-run]`

## Files
Migration `database/migrations/2026_10_25_220000_bridge_polymarket.php` (polymarket_snapshots, polymarket_series_points);
`app/Services/Polymarket/*`, `app/Http/Controllers/{PolymarketController,Api/PolymarketFeedController}.php`,
`app/Http/Middleware/GuardPolymarketUpload.php`, models, `resources/js/Pages/Polymarket/Desk.tsx`,
`resources/js/Components/Polymarket/*`, `resources/js/lib/polymarket.ts`, `resources/css/polymarket.css`,
tests `tests/Feature/Polymarket{Feed,Desk}Test.php` + `tests/fixtures/polymarket/` (real feed.json, book_series.json, latest.json).

## Calls made where the spec was silent
- Δ vs yesterday / 7 days measured from snapshot as_of (not page-load time); freshness + 7-day fills window use now.
- "High-water … set {date}" only when a stored run equals the high-water.
- realized + unrealized ≈ total check logs a warning at import, not per view.
- "All N" sweeps expands in place (no separate page).

## Deviations from the mock
- Mobile chart has its own 480-wide layout (1312 unreadable on phone); end labels into legend; 7-day default.
- Mobile positions = stacked cards (not mocked).
- "Resolves today" chip class `kb navy` (mock `kb nav` collides with global `.nav`, hidden on mobile).
- `.dhead .summary` scoped to `.pmdesk` so Portfolio/Usage headers don't change.
- No loading skeletons (server-rendered Inertia, no loading phase).
- Copy inline, not strings.ts (Usage pages don't use it either).

## NOT done
- Not visually re-checked by Xerxes against the PNG mocks (Claude Code checked locally at 1440/390); no screenshots saved under /workspace/bridge-polymarket/shots/.
- No production deploy: DB backup (see /root/backups/bridge-db-pre-cli-usage-* pattern: mysqldump | gzip, chmod 600), git fetch/checkout of the release SHA as apolon,
  `php artisan migrate --force`, npm run build, optimize, reload php8.4-fpm, seed with bridge:polymarket-import on feed.json + book_series.json.
- No live verification (logged-out 302, logged-in screenshot, re-POST no-dup).
