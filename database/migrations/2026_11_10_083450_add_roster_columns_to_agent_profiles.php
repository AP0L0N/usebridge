<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents roster sync (docs/agents-sync.md). Additive and reversible, agent_profiles only.
 *
 * - full_name: "Name · Title" exactly as the roster sends it (name/title stay split for existing code).
 * - section / section_order: the group on /agents and its position.
 * - listed: only rows the roster sync (POST /api/agents/sync) marked as real agents show on /agents. Existing rows,
 *   legacy POST /api/agent-profiles ingests and usage-only ids default to not listed.
 * - roster_synced_at: last roster sync that carried this agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('title');
            $table->string('section', 64)->nullable()->after('full_name');
            $table->unsignedSmallInteger('section_order')->nullable()->after('section');
            $table->boolean('listed')->default(false)->after('section_order');
            $table->timestamp('roster_synced_at')->nullable()->after('synced_at');

            $table->index(['workspace_id', 'listed']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'listed']);
            $table->dropColumn(['full_name', 'section', 'section_order', 'listed', 'roster_synced_at']);
        });
    }
};
