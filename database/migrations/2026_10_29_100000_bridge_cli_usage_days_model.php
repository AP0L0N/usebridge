<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge Usage: measured CLI tokens per model. Adds cli_usage_days.model ('' = the log recorded no model,
 * and every row imported before this column existed) and widens the unique key to (date, tool, agent_id,
 * model). Additive: existing rows keep their data. down() folds a day's per-model rows back into one row
 * per (date, tool, agent) before restoring the old key, so no tokens are lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cli_usage_days', 'model')) {
            return;
        }
        Schema::table('cli_usage_days', function (Blueprint $table) {
            $table->string('model', 128)->default('')->after('agent_name');
        });
        Schema::table('cli_usage_days', function (Blueprint $table) {
            $table->dropUnique(['date', 'tool', 'agent_id']);
            $table->unique(['date', 'tool', 'agent_id', 'model']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cli_usage_days', 'model')) {
            return;
        }
        DB::transaction(function () {
            $groups = DB::table('cli_usage_days')
                ->select('date', 'tool', 'agent_id')
                ->groupBy('date', 'tool', 'agent_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            foreach ($groups as $g) {
                $rows = DB::table('cli_usage_days')
                    ->where(['date' => $g->date, 'tool' => $g->tool, 'agent_id' => $g->agent_id])
                    ->orderBy('id')->get();
                $keep = $rows->first();
                DB::table('cli_usage_days')->where('id', $keep->id)->update([
                    'input_tokens' => $rows->sum('input_tokens'),
                    'output_tokens' => $rows->sum('output_tokens'),
                    'cache_read_tokens' => $rows->sum('cache_read_tokens'),
                    'cache_creation_tokens' => $rows->sum('cache_creation_tokens'),
                    'calls' => $rows->sum('calls'),
                ]);
                DB::table('cli_usage_days')->whereIn('id', $rows->pluck('id')->slice(1)->all())->delete();
            }
        });
        Schema::table('cli_usage_days', function (Blueprint $table) {
            $table->dropUnique(['date', 'tool', 'agent_id', 'model']);
            $table->unique(['date', 'tool', 'agent_id']);
        });
        Schema::table('cli_usage_days', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
