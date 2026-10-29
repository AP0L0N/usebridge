<?php

use App\Models\Project;
use App\Services\Polymarket\InvalidPolymarketFeed;
use App\Services\Polymarket\PolymarketFeedImporter;
use App\Services\Polymarket\PolymarketFeedValidator;
use App\Services\Tickets\LegacyCarryOver;
use App\Services\Usage\CliUsageImporter;
use App\Services\Usage\CliUsageValidator;
use App\Services\Usage\InvalidUsageDay;
use App\Services\Usage\UsageDayImporter;
use App\Services\Usage\UsageDayValidator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
// tokens. Same validator + replace-per-day as the `cli_usage` key of a day file; for backfill on the server.
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
})->purpose('Import measured Claude Code / Kimi Code usage files (strictly validated, replace by date)');

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
