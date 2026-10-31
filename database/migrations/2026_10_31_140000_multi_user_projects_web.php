<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-user: a new workspace creates its first project from the web (docs/MULTI-USER-PLAN.md §8 onboarding, §9 step 4).
 *
 * - projects.owner_agent_id becomes nullable: a new workspace has no agents (only Captain's POST /api/agents makes
 *   them), so a web-created project starts without a PM. The FK to agents stays. POST /api/projects still requires it.
 * - projects.description (text, nullable): the one-line description from the create form.
 * - projects.column_labels (JSON, nullable): per-project board column names {column key: label}; null = defaults.
 *
 * down() drops the two columns and makes owner_agent_id NOT NULL again; it refuses (nothing deleted) while a project
 * has no PM, a description or column labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('owner_agent_id')->nullable()->change();
        });
        if (! Schema::hasColumn('projects', 'column_labels')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->text('description')->nullable();
                $table->json('column_labels')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('projects')->whereNull('owner_agent_id')->exists()) {
            throw new RuntimeException('projects: some projects have no PM (owner_agent_id null); set one before rolling back.');
        }
        if (Schema::hasColumn('projects', 'column_labels')) {
            if (DB::table('projects')->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('column_labels'))->exists()) {
                throw new RuntimeException('projects: some projects have a description or column labels; rolling back would delete them.');
            }
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn(['description', 'column_labels']);
            });
        }
        Schema::table('projects', function (Blueprint $table) {
            $table->string('owner_agent_id')->nullable(false)->change();
        });
    }
};
