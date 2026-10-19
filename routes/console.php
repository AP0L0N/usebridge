<?php

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
