<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive (go-live N2 follow-up): status_updates.source (nullable) = where an update was posted: api (REST token),
 * mcp (MCP post_status) or ui (web). Existing rows stay null (unknown); nothing else is touched. down() drops only it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('status_updates', 'source')) {
            Schema::table('status_updates', function (Blueprint $table) {
                $table->string('source', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('status_updates', 'source')) {
            Schema::table('status_updates', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
