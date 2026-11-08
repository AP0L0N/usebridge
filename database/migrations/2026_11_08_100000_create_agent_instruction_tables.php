<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent instructions (docs/agent-instructions-sync.md). New tables only, reversible.
 *
 * - agent_profiles: the agent's real profile (name, title, description) as last pushed by the daily sync
 *   (POST /api/agent-profiles). Bridge never writes the description itself; only the ingest and "applied" do.
 * - agent_instruction_edits: the owner's proposed descriptions, one revision per save. Nothing here changes the agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_profiles')) {
            Schema::create('agent_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('agent_id', 64);
                $table->string('name');
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->char('description_hash', 64);
                $table->timestamp('synced_at');
                $table->timestamps();

                $table->unique(['workspace_id', 'agent_id']);
            });
        }

        if (! Schema::hasTable('agent_instruction_edits')) {
            Schema::create('agent_instruction_edits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('agent_id', 64);
                $table->unsignedInteger('revision');
                $table->char('base_hash', 64)->nullable();
                $table->text('base_description')->nullable();
                $table->text('proposed_description');
                $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('author', 64);
                $table->string('status', 16);
                $table->string('status_reason')->nullable();
                $table->char('applied_hash', 64)->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'agent_id', 'revision']);
                $table->index(['workspace_id', 'agent_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_instruction_edits');
        Schema::dropIfExists('agent_profiles');
    }
};
