<?php

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
