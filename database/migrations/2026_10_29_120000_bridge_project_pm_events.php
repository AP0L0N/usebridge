<?php

use App\Services\BridgeRoster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PM history (project_pm_events) + the roster (BridgeRoster, file at BRIDGE_ROSTER_PATH): add missing agents and
 * set the PM of every active project; no roster file = nothing changes. Additive and idempotent; nothing is deleted, existing agent rows untouched.
 * The roster step is skipped on an empty database (fresh install / tests); `php artisan bridge:set-pms` re-applies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_pm_events')) {
            Schema::create('project_pm_events', function (Blueprint $table) {
                $table->string('id')->primary(); // ppe-…
                $table->string('project_id');
                $table->string('from_agent_id', 64)->nullable();
                $table->string('to_agent_id', 64);
                $table->string('set_by', 64);
                $table->string('source', 10); // api|migration
                $table->timestamp('created_at')->nullable();

                $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
                $table->index(['project_id', 'created_at']);
            });
        }

        if (DB::table('projects')->exists()) {
            BridgeRoster::apply();
        }
    }

    public function down(): void
    {
        // Agents and PM changes stay (no deletes); only the history table goes.
        Schema::dropIfExists('project_pm_events');
    }
};
