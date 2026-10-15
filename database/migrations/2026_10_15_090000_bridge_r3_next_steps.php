<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bridge r3: per-project Next steps (kind in_progress|blocker). Additive, reversible. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('next_steps')) {
            return;
        }
        Schema::create('next_steps', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->string('kind', 20); // in_progress|blocker
            $table->string('text', 500);
            $table->string('owner', 120)->nullable();
            $table->string('agent_id')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->string('done_by', 120)->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('next_steps');
    }
};
