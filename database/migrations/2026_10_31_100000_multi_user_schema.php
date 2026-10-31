<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-user groundwork (docs/MULTI-USER-PLAN.md §1): users.is_admin, workspaces, workspace_user, and a nullable
 * workspace_id (indexed, FK) on every owned table and on personal_access_tokens. Filled by the backfill migration.
 * Additive and idempotent; down() drops only the added columns and the two new tables.
 */
return new class extends Migration
{
    /** Tables that get their own workspace_id (roots and denormalised children, see the plan). */
    public const OWNED = [
        'projects', 'agents', 'labels', 'usage_days', 'cli_usage_days', 'polymarket_snapshots', 'polymarket_series_points',
        'tickets', 'ticket_comments', 'ticket_events', 'ticket_read_states', 'project_read_states', 'project_status_events',
        'project_pm_events', 'status_updates', 'required_inputs', 'next_steps', 'questions',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_admin')->default(false);
            });
        }

        if (! Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 64)->unique();
                $table->string('name', 120);
                $table->foreignId('owner_user_id')->constrained('users');
                $table->json('features')->nullable(); // {"polymarket": true}
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workspace_user')) {
            Schema::create('workspace_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces');
                $table->foreignId('user_id')->constrained('users');
                $table->string('role', 10); // owner|admin|member
                $table->timestamps();
                $table->unique(['workspace_id', 'user_id']);
                $table->index('user_id');
            });
        }

        foreach ([...self::OWNED, 'personal_access_tokens'] as $name) {
            if (Schema::hasColumn($name, 'workspace_id')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('workspace_id')->nullable()->index()->constrained('workspaces');
            });
        }
    }

    public function down(): void
    {
        foreach ([...self::OWNED, 'personal_access_tokens'] as $name) {
            if (! Schema::hasColumn($name, 'workspace_id')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['workspace_id']);
                $table->dropIndex(['workspace_id']);
            });
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('workspace_id');
            });
        }
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
        if (Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_admin');
            });
        }
    }
};
