<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Portfolio workstreams (design: portfolio-workstreams README §2–§5).
 * - workstreams: per project, one is_default ("General"); soft-deleted so history keeps the name.
 * - workstream_views: when a user last opened a workstream's board (badge = changes since then).
 * - workstream_events: audit of created / renamed / deleted / merged.
 * - tickets.workstream_id NOT NULL: every existing ticket goes to its project's new "General".
 * - ticket_events.workstream_id: the workstream the ticket was in when the event happened (badge counts).
 * - projects.deleted_at: project delete is a soft delete (recoverable).
 *
 * Not in a transaction: SQLite rebuilds `tickets` for the NOT NULL change, and the foreign_keys pragma (so the rebuild
 * never cascades into comments / events) only works outside one.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('workstreams', function (Blueprint $table) {
            $table->string('id')->primary(); // ws-<ulid>
            $table->foreignId('workspace_id')->nullable()->index()->constrained('workspaces');
            $table->string('project_id');
            $table->string('name', 60);
            $table->integer('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->json('renamed_from')->nullable(); // older names, oldest first
            $table->string('created_by', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'deleted_at']);
        });

        Schema::create('workstream_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('workstream_id');
            $table->timestamp('last_viewed_at');

            $table->foreign('workstream_id')->references('id')->on('workstreams')->cascadeOnDelete();
            $table->unique(['user_id', 'workstream_id']);
        });

        Schema::create('workstream_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->index()->constrained('workspaces');
            $table->string('project_id');
            $table->string('workstream_id');
            $table->string('type', 10); // created|renamed|deleted|merged
            $table->string('from_name', 60)->nullable();
            $table->string('to_name', 60)->nullable();
            $table->string('merged_into_id')->nullable();
            $table->unsignedInteger('ticket_count')->default(0);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_agent_id', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'created_at']);
            $table->index('workstream_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('ticket_events', function (Blueprint $table) {
            $table->string('workstream_id')->nullable();
            $table->index(['workstream_id', 'created_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('workstream_id')->nullable();
        });

        $this->backfill();

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('workstream_id')->nullable(false)->change();
            $table->foreign('workstream_id')->references('id')->on('workstreams');
            $table->index(['workstream_id', 'status']);
        });
    }

    /** One "General" (default) per project; every ticket and its past events go there. */
    private function backfill(): void
    {
        $now = Carbon::now();
        foreach (DB::table('projects')->orderBy('id')->get(['id', 'workspace_id']) as $p) {
            $id = 'ws-'.Str::lower((string) Str::ulid());
            DB::table('workstreams')->insert([
                'id' => $id, 'workspace_id' => $p->workspace_id, 'project_id' => $p->id, 'name' => 'General',
                'position' => 0, 'is_default' => true, 'created_by' => 'migration', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('workstream_events')->insert([
                'workspace_id' => $p->workspace_id, 'project_id' => $p->id, 'workstream_id' => $id, 'type' => 'created',
                'to_name' => 'General', 'ticket_count' => DB::table('tickets')->where('project_id', $p->id)->count(), 'created_at' => $now,
            ]);
            DB::table('tickets')->where('project_id', $p->id)->update(['workstream_id' => $id]);
            DB::table('ticket_events')->where('project_id', $p->id)->update(['workstream_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['workstream_id']);
            $table->dropIndex(['workstream_id', 'status']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('workstream_id');
        });
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->dropIndex(['workstream_id', 'created_at']);
        });
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->dropColumn('workstream_id');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::dropIfExists('workstream_events');
        Schema::dropIfExists('workstream_views');
        Schema::dropIfExists('workstreams');
    }
};
