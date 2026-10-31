<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-user: unique keys that must be per workspace for a second workspace to work at all (docs/MULTI-USER-PLAN.md §1):
 * usage_days (date), cli_usage_days (date, tool, agent_id, model), polymarket_series_points (ts) and labels (name) get
 * workspace_id in front. Existing rows all belong to one workspace, so the new keys hold. Slug / ticket prefix / ticket
 * key stay global for now (later step). down() restores the global keys and refuses (nothing deleted) when two
 * workspaces already share a key.
 */
return new class extends Migration
{
    /** table => old unique columns */
    private const KEYS = [
        'usage_days' => ['date'],
        'cli_usage_days' => ['date', 'tool', 'agent_id', 'model'],
        'polymarket_series_points' => ['ts'],
        'labels' => ['name'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $columns) {
            if ($this->hasIndex($table, $this->name($table, ['workspace_id', ...$columns]))) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($columns) {
                $t->dropUnique($columns);
            });
            Schema::table($table, function (Blueprint $t) use ($columns) {
                $t->unique(['workspace_id', ...$columns]);
            });
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $columns) {
            $clash = DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists();
            if ($clash) {
                throw new RuntimeException("$table: rows of different workspaces share (".implode(', ', $columns).'); the global key cannot be restored without deleting data.');
            }
        }
        foreach (self::KEYS as $table => $columns) {
            if (! $this->hasIndex($table, $this->name($table, ['workspace_id', ...$columns]))) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($columns) {
                $t->dropUnique(['workspace_id', ...$columns]);
            });
            Schema::table($table, function (Blueprint $t) use ($columns) {
                $t->unique($columns);
            });
        }
    }

    private function name(string $table, array $columns): string
    {
        return strtolower($table.'_'.implode('_', $columns).'_unique');
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $name);
    }
};
