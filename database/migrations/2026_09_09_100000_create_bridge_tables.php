<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('role_label');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('owner_agent_id');
            $table->text('goal');
            $table->string('phase');
            $table->text('phase_done_looks_like');
            $table->date('next_deadline')->nullable();
            $table->text('needs_decision')->nullable();
            $table->timestamps();

            $table->foreign('owner_agent_id')->references('id')->on('agents');
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->string('title');
            $table->date('due_at')->nullable();
            $table->string('status'); // done|current|upcoming
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('work_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->string('title');
            $table->string('owner_agent_id');
            $table->date('due_at')->nullable();
            $table->string('status'); // open|active|blocked|done
            $table->text('done_looks_like');
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('owner_agent_id')->references('id')->on('agents');
        });

        Schema::create('risks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->text('text');
            $table->string('severity'); // low|medium|high
            $table->boolean('open')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('status_updates', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->string('agent_id');
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('agents');
        });

        Schema::create('ops_digest_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id')->nullable();
            $table->string('kind'); // unanswered|commitment|overdue
            $table->text('summary');
            $table->string('source_ref')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::create('ceo_queue_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->text('reason');
            $table->text('suggested_action');
            $table->string('project_id')->nullable();
            $table->string('chip_label')->nullable();
            $table->string('status'); // open|assigned|dismissed
            $table->date('surfaced_at');
            $table->string('assigned_agent_id')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('assigned_agent_id')->references('id')->on('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ceo_queue_items');
        Schema::dropIfExists('ops_digest_items');
        Schema::dropIfExists('status_updates');
        Schema::dropIfExists('risks');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('milestones');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('agents');
    }
};
