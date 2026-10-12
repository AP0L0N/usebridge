<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge r2: required_inputs.done_at/done_by -> resolved_at/resolved_by.
 * Only acts when resolved_* are missing. Existing values are kept; the r1
 * display name "Tilen" becomes the identifier "tilen". Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('required_inputs', 'resolved_at')) {
            return;
        }
        if (Schema::hasColumn('required_inputs', 'done_at')) {
            Schema::table('required_inputs', function (Blueprint $table) {
                $table->renameColumn('done_at', 'resolved_at');
                $table->renameColumn('done_by', 'resolved_by');
            });
            DB::table('required_inputs')->where('resolved_by', 'Tilen')->update(['resolved_by' => 'tilen']);
        } else {
            Schema::table('required_inputs', function (Blueprint $table) {
                $table->timestamp('resolved_at')->nullable();
                $table->string('resolved_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('required_inputs', 'resolved_at')) {
            return;
        }
        DB::table('required_inputs')->where('resolved_by', 'tilen')->update(['resolved_by' => 'Tilen']);
        Schema::table('required_inputs', function (Blueprint $table) {
            $table->renameColumn('resolved_at', 'done_at');
            $table->renameColumn('resolved_by', 'done_by');
        });
    }
};
