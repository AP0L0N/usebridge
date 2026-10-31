<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Best-effort cli_usage_imports markers for the days imported before markers existed: one per (workspace, date, tool)
 * that has cli_usage_days rows. Days a file covered with zero rows for a tool left no trace, so they get no marker.
 * Idempotent (existing markers are kept); down() leaves the markers, the schema migration drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        DB::table('cli_usage_days')
            ->whereNotNull('workspace_id')
            ->select('workspace_id', 'date', 'tool')
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('SUM(input_tokens + output_tokens + cache_read_tokens + cache_creation_tokens) AS token_total')
            ->groupBy('workspace_id', 'date', 'tool')
            ->orderBy('workspace_id')->orderBy('date')->orderBy('tool')
            ->get()
            ->map(fn ($g) => [
                'workspace_id' => $g->workspace_id,
                'date' => substr((string) $g->date, 0, 10),
                'tool' => $g->tool,
                'rows' => (int) $g->row_count,
                'total' => (int) $g->token_total,
                'imported_at' => $now,
            ])
            ->chunk(500)
            ->each(fn ($chunk) => DB::table('cli_usage_imports')->insertOrIgnore($chunk->values()->all()));
    }

    public function down(): void {}
};
