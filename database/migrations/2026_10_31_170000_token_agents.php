<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive (go-live B3): personal_access_tokens.agent_id (nullable, indexed, no FK) = the agent a token writes as when a
 * request names no actor. Set when a token is created (TokenAgents::provision) or lazily on the token's first write.
 * Existing rows stay null until then; nothing else is touched. down() drops only this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'agent_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('agent_id', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'agent_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropIndex(['agent_id']);
                $table->dropColumn('agent_id');
            });
        }
    }
};
