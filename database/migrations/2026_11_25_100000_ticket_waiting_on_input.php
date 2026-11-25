<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: tickets.waiting_on_input_at (nullable) = "input is needed from the workspace owner" on a ticket in any
 * column, and when that was set; null = not flagged. It replaces the waiting_tilen column (the next migration moves
 * its tickets out). tickets.waiting_since is no longer read or written. Existing rows stay null; down() drops only
 * this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'waiting_on_input_at')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->timestamp('waiting_on_input_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'waiting_on_input_at')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropColumn('waiting_on_input_at');
            });
        }
    }
};
