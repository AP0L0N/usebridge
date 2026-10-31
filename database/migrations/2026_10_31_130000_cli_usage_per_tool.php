<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge Usage, per-tool import (Cursor): optional cost_cents / max_mode_calls on cli_usage_days, and the
 * cli_usage_imports markers (one per workspace, date and tool a file covered). Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cli_usage_days', 'cost_cents')) {
            Schema::table('cli_usage_days', function (Blueprint $table) {
                $table->decimal('cost_cents', 14, 4)->nullable()->after('calls');
                $table->unsignedInteger('max_mode_calls')->nullable()->after('cost_cents');
            });
        }
        if (! Schema::hasTable('cli_usage_imports')) {
            Schema::create('cli_usage_imports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->date('date');
                $table->string('tool', 32);
                $table->unsignedInteger('rows');
                $table->unsignedBigInteger('total');
                $table->timestamp('imported_at');
                $table->unique(['workspace_id', 'date', 'tool']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cli_usage_imports');
        if (Schema::hasColumn('cli_usage_days', 'cost_cents')) {
            Schema::table('cli_usage_days', function (Blueprint $table) {
                $table->dropColumn(['cost_cents', 'max_mode_calls']);
            });
        }
    }
};
