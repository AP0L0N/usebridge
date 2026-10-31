<?php

use App\Models\Project;
use App\Models\Workspace;
use App\Services\Auth\AccountDeletion;
use App\Services\BridgeRoster;
use App\Services\Ops\CopiedTokenRotation;
use App\Services\Ops\WorkspaceOwnerTransfer;
use App\Services\Polymarket\InvalidPolymarketFeed;
use App\Services\Polymarket\PolymarketFeedImporter;
use App\Services\Polymarket\PolymarketFeedValidator;
use App\Services\Tickets\LegacyCarryOver;
use App\Services\Usage\CliUsageImporter;
use App\Services\Usage\CliUsageValidator;
use App\Services\Usage\CursorCsvImporter;
use App\Services\Usage\InvalidUsageDay;
use App\Services\Usage\UsageDayImporter;
use App\Services\Usage\UsageDayValidator;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Same validator + upsert as POST /api/usage/days, for loading files on the server without the token.
Artisan::command('bridge:usage-import {paths* : Day files (YYYY-MM-DD.json)} {--dry-run : Validate only, write nothing}', function (UsageDayImporter $importer) {
    $dry = (bool) $this->option('dry-run');
    $failed = 0;
    foreach ($this->argument('paths') as $path) {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("$path: not a readable file");
            $failed++;

            continue;
        }
        if (filesize($path) > UsageDayValidator::MAX_BYTES) {
            $this->error("$path: larger than 512 KB");
            $failed++;

            continue;
        }
        try {
            $r = $importer->import((string) file_get_contents($path), $dry);
        } catch (InvalidUsageDay $e) {
            $this->error("$path: invalid");
            foreach ($e->errors as $key => $messages) {
                $this->line("  $key: ".implode(' ', $messages));
            }
            $failed++;

            continue;
        }
        $state = ($dry ? 'valid, would be ' : '').($r['created'] ? 'created' : 'updated');
        $this->info(sprintf('%s  %s  total %s  (%d agents)', $r['date'], $state, number_format($r['est_tokens_total']), $r['agents']));
    }

    return $failed === 0 ? 0 : 1;
})->purpose('Import Bridge usage day files (strictly validated, upsert by date)');

// Standalone collector files (bridge-usage/data/cli/YYYY-MM-DD.json, cli_usage.py): measured Claude Code / Kimi Code
// tokens. Same validator + replace per date and covered tool as the `cli_usage` key of a day file; for backfill on the server.
Artisan::command('bridge:cli-usage-import {paths* : Collector files (YYYY-MM-DD.json)} {--dry-run : Validate only, write nothing}', function (CliUsageImporter $importer) {
    $dry = (bool) $this->option('dry-run');
    $failed = 0;
    foreach ($this->argument('paths') as $path) {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("$path: not a readable file");
            $failed++;

            continue;
        }
        if (filesize($path) > CliUsageValidator::MAX_BYTES) {
            $this->error("$path: larger than 512 KB");
            $failed++;

            continue;
        }
        try {
            $r = $importer->importRaw((string) file_get_contents($path), $dry);
        } catch (InvalidUsageDay $e) {
            $this->error("$path: invalid");
            foreach ($e->errors as $key => $messages) {
                $this->line("  $key: ".implode(' ', $messages));
            }
            $failed++;

            continue;
        }
        if ($r['skipped'] !== null) {
            $this->warn(sprintf('%s  skipped: %s (coverage from %s, rows left as they were)', $r['date'], $r['skipped'], $r['coverage_from']));

            continue;
        }
        $state = ($dry ? 'valid, would be ' : '').($r['created'] ? 'created' : 'replaced');
        $this->info(sprintf('%s  %s  total %s measured  (%d rows)', $r['date'], $state, number_format($r['total']), $r['rows']));
    }

    return $failed === 0 ? 0 : 1;
})->purpose('Import measured Claude Code / Kimi Code usage files (strictly validated, replace by date and covered tool)');

// Cursor dashboard CSV export (individual plan, one account → "Cursor (all agents)"), same importer as POST /usage/cursor-csv.
Artisan::command('bridge:cursor-csv-import {path : CSV from cursor.com/dashboard → Usage → Export CSV} {--workspace= : Workspace slug (default: bridge.default_workspace)} {--dry-run : Validate and report, write nothing}', function (CursorCsvImporter $importer, CurrentWorkspace $current) {
    $path = (string) $this->argument('path');
    if (! is_file($path) || ! is_readable($path)) {
        $this->error("$path: not a readable file");

        return 1;
    }
    if (filesize($path) > CursorCsvImporter::MAX_BYTES) {
        $this->error("$path: larger than 10 MB");

        return 1;
    }
    $slug = (string) ($this->option('workspace') ?: config('bridge.default_workspace', 'tilen'));
    $workspace = Workspace::query()->where('slug', $slug)->first();
    if ($workspace === null) {
        $this->error("workspace '$slug' not found");

        return 1;
    }
    $dry = (bool) $this->option('dry-run');
    try {
        $r = $current->runAs($workspace, fn () => $importer->import((string) file_get_contents($path), $dry));
    } catch (InvalidUsageDay $e) {
        $this->error("$path: invalid, nothing imported");
        foreach ($e->errors as $key => $messages) {
            $this->line("  $key: ".implode(' ', $messages));
        }

        return 1;
    }
    foreach ($r['days'] as $d) {
        $state = ($dry && $d['state'] !== CursorCsvImporter::STATE_KEPT ? 'would be ' : '').$d['state'];
        $this->line(sprintf('%s  %-28s %s tokens, %d calls%s', $d['date'], $state, number_format($d['total']), $d['calls'],
            $d['stored_calls'] === null ? '' : sprintf(' (stored %d)', $d['stored_calls'])));
    }
    $this->info(sprintf('%s%s: %s .. %s, %d rows, %s tokens; days imported %d, zero %d, kept %d',
        $dry ? 'DRY RUN (nothing written) ' : '', $slug, $r['from'], $r['to'], $r['rows'], number_format($r['total_tokens']),
        $r['days_imported'], $r['days_zero'], $r['days_kept']));

    return 0;
})->purpose('Import a Cursor dashboard usage CSV export (per day x model, "Cursor (all agents)"; only Cursor rows are replaced)');

// Same validator + importer as POST /api/polymarket/feed, from two files (one-off seed / backfill on the server).
Artisan::command('bridge:polymarket-import {snapshot : feed.json} {series : book_series.json} {--dry-run : Validate only, write nothing}', function (PolymarketFeedImporter $importer) {
    $raw = [];
    foreach (['snapshot', 'series'] as $arg) {
        $path = (string) $this->argument($arg);
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("$path: not a readable file");

            return 1;
        }
        if (filesize($path) > PolymarketFeedValidator::MAX_FILE_BYTES) {
            $this->error("$path: larger than 8 MB");

            return 1;
        }
        $raw[$arg] = (string) file_get_contents($path);
    }
    $dry = (bool) $this->option('dry-run');
    try {
        $r = $importer->importFiles($raw['snapshot'], $raw['series'], $dry);
    } catch (InvalidPolymarketFeed $e) {
        $this->error('invalid');
        foreach ($e->errors as $key => $messages) {
            $this->line("  $key: ".implode(' ', $messages));
        }

        return 1;
    }
    $s = $r['series'];
    $this->info(sprintf('%ssnapshot %s (as_of %s) · series: %d received, %d inserted, %d updated, %d unchanged, %d total',
        $dry ? 'dry run, nothing written · would be: ' : '', $r['snapshot'], $r['as_of'], $s['received'], $s['inserted'], $s['updated'], $s['unchanged'], $s['total']));

    return 0;
})->purpose('Import the Polymarket desk feed (feed.json + book_series.json), same rules as POST /api/polymarket/feed');

// Tickets cutover: open/answered Questions, open Required inputs and not-done Next steps become tickets
// (idempotent; legacy rows only get superseded_at + ticket_id). Not part of the schema migration.
Artisan::command('bridge:tickets-migrate {--dry-run : Compute and roll back, write nothing} {--project= : Only this project id}', function (LegacyCarryOver $carryOver) {
    $dry = (bool) $this->option('dry-run');
    $only = $this->option('project') ?: null;
    if ($only !== null && ! Project::query()->whereKey($only)->exists()) {
        $this->error("Unknown project id: $only");

        return 1;
    }
    $r = $carryOver->run($dry, $only);
    $prefix = $dry ? 'dry run, nothing written · would be: ' : '';
    foreach ($r['projects'] as $p) {
        $this->line(sprintf('%s (%s): %s%s', $p['name'], $p['id'], $prefix, LegacyCarryOver::line($p)));
    }
    $this->info('total: '.$prefix.LegacyCarryOver::line($r['total']));

    return 0;
})->purpose('Carry open Questions / Required inputs / Next steps over to tickets (idempotent; --dry-run writes nothing)');

// Captain's roster (29 Oct): add missing agents, set the real PM per active project. Idempotent; the
// 2026_10_29_120000 migration applies it on deploy, this re-runs or previews it. Only inserts agents / changes PMs.
Artisan::command('bridge:set-pms {--dry-run : Show what would change, write nothing}', function () {
    $dry = (bool) $this->option('dry-run');
    $r = BridgeRoster::apply($dry);
    $w = $dry ? 'would create' : 'created';
    $this->info("Agents $w: ".($r['agents_created'] ? implode(', ', $r['agents_created']) : 'none'));
    $this->line('Agents already present (untouched): '.($r['agents_kept'] ? implode(', ', $r['agents_kept']) : 'none'));
    foreach ($r['pms'] as $p) {
        $this->line(sprintf('  %-34s %s', $p['project'], $p['changed'] ? ($dry ? 'would set ' : 'set ').($p['from'] ?? '—').' -> '.$p['to'] : 'ok ('.$p['to'].')'));
    }
    foreach ($r['missing_projects'] as $id) {
        $this->warn("  $id: project not found, skipped");
    }
    foreach ($r['missing_agents'] as $id) {
        $this->error("  $id: agent missing, PM not set");
    }

    return $r['missing_agents'] ? 1 : 0;
})->purpose("Add the Captain's missing agents and set each active project's real PM (idempotent)");

// Account deletion (docs/MULTI-USER-PLAN.md §2): users whose 7-day grace is over are soft-deleted (deleted_at). Their
// workspace rows are kept: no purge without Tilen's explicit OK. Safe to run daily.
Artisan::command('bridge:accounts-delete-expired {--dry-run : List who would be soft-deleted, change nothing}', function (AccountDeletion $deletion) {
    $dry = (bool) $this->option('dry-run');
    $ids = $deletion->deleteExpired($dry);
    $this->info(($dry ? 'Would soft-delete' : 'Soft-deleted').' '.count($ids).' user(s)'.($ids ? ': '.implode(', ', $ids) : '').'.');

    return 0;
})->purpose('Soft-delete accounts whose deletion grace (7 days) is over; nothing is purged');

// Ops (docs/MULTI-USER-PLAN.md §11): make a user the owner of a workspace, e.g. Tilen on a copy migrated before
// BRIDGE_OWNER_EMAIL was required. Idempotent, transactional, nothing deleted; prints ids only, never a password or link.
Artisan::command('bridge:set-owner {--email= : The new owner\'s login email} {--name= : Name when the user is created (default: the email\'s local part)} {--workspace=tilen : Workspace slug} {--dry-run : Show the plan, change nothing}', function (WorkspaceOwnerTransfer $transfer) {
    $dry = (bool) $this->option('dry-run');
    try {
        $r = $transfer->apply((string) $this->option('email'), $this->option('name'), (string) $this->option('workspace'), $dry);
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->line(sprintf('%sworkspace %s (#%d)', $dry ? '[dry run] ' : '', $r['workspace']->slug, $r['workspace']->id));
    $this->line('owner user id: '.($r['user_id'] ?? 'new'));
    $this->line('created: '.($r['created'] ? ($dry ? 'would be (yes)' : 'yes') : 'no'));
    $this->line('previous owners demoted to member (is_admin off): '.($r['demoted'] ? implode(', ', $r['demoted']) : 'none'));
    if ($dry) {
        $this->info('Dry run: nothing changed.');
    } elseif ($r['created']) {
        $this->info('The new owner has no usable password: use the password reset flow (/forgot-password). With MAIL_MAILER=log the reset mail lands in the application log.');
    }

    return 0;
})->purpose('Make the user with this email the owner (and admin) of a workspace; other owners become members (idempotent)');

// Ops (docs/MULTI-USER-PLAN.md §11): on a staging copy of the live database, give every token a new plaintext so live
// tokens do not work there. Refuses production / the live APP_URL. New tokens go only to the --out file (0600).
Artisan::command('bridge:rotate-copied-tokens {--out= : New file for NAME=id|token lines (created 0600, never overwritten)} {--force-env= : "staging" to run outside APP_ENV=staging (never on production)} {--dry-run : Count only, change nothing}', function (CopiedTokenRotation $rotation) {
    $out = $this->option('out');
    $refusal = CopiedTokenRotation::refusal((string) app()->environment(), $this->option('force-env'), config('app.url'), $out);
    if ($refusal !== null) {
        $this->error($refusal);

        return 1;
    }
    $dry = (bool) $this->option('dry-run');
    try {
        $count = $rotation->rotate((string) $out, $dry);
    } catch (RuntimeException $e) {
        $this->error($e->getMessage().' Nothing changed.');

        return 1;
    }
    if ($dry) {
        $this->info("Dry run: would rotate $count token(s) into $out; nothing changed.");
    } else {
        Log::info("bridge:rotate-copied-tokens rotated $count token(s), written to $out");
        $this->info("Rotated $count token(s); new values written to $out (0600). Old plaintexts no longer work here.");
    }

    return 0;
})->purpose('Staging only: give every API token a new plaintext, written to a new 0600 file');
