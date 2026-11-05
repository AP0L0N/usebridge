<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Power Saver thresholds only. New table, reversible. Does not alter projects, agents, or usage days.
 * A row is a stored stop percent. Deleting the row means "no threshold". Nothing here enables, disables,
 * archives, or schedules a project or a routine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('power_saver_thresholds')) {
            return;
        }

        Schema::create('power_saver_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('subject_type', 16);
            $table->string('project_id')->nullable();
            $table->string('agent_id', 64)->nullable();
            $table->string('routine_id', 120)->nullable();
            $table->unsignedTinyInteger('stop_percent');
            $table->char('identity', 64);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'identity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('power_saver_thresholds');
    }
};
