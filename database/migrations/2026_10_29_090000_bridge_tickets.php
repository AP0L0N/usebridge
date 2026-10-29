<?php

use App\Support\TicketPrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge tickets (Kanban, SPEC §6). Additive and reversible:
 * - projects += ticket_prefix (filled from the name initials), ticket_seq, PM-set status/note/set_by/set_at, last_activity_at.
 * - New: project_status_events, tickets, labels, ticket_label, ticket_comments, ticket_events, ticket_read_states,
 *   project_read_states. The column set is fixed in code (App\Support\BoardColumns), not a table.
 * - questions / required_inputs / next_steps += superseded_at + ticket_id (filled by `bridge:tickets-migrate`, not here).
 * down() drops only what up() added; legacy rows are never touched.
 */
return new class extends Migration
{
    private const LEGACY = ['questions', 'required_inputs', 'next_steps'];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('ticket_prefix', 8)->nullable()->unique();
            $table->unsignedInteger('ticket_seq')->default(0);
            $table->string('status', 12)->nullable(); // smooth|waiting|archived; null = "Status not set"
            $table->string('status_note', 280)->nullable();
            $table->string('status_set_by', 64)->nullable();
            $table->timestamp('status_set_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
        });

        $taken = [];
        foreach (DB::table('projects')->orderBy('created_at')->orderBy('id')->get(['id', 'name']) as $p) {
            $prefix = TicketPrefix::forProject((string) $p->id, (string) $p->name, $taken);
            $taken[] = $prefix;
            DB::table('projects')->where('id', $p->id)->update(['ticket_prefix' => $prefix]);
        }

        Schema::create('project_status_events', function (Blueprint $table) {
            $table->string('id')->primary(); // pse-…
            $table->string('project_id');
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->string('note', 280)->nullable();
            $table->string('set_by', 64);
            $table->string('set_by_type', 10); // agent|human
            $table->string('source', 10); // api|ui
            $table->timestamp('created_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->string('id')->primary(); // tk-<ulid>
            $table->string('project_id');
            $table->string('key', 20)->unique(); // CA-12
            $table->string('title', 140);
            $table->text('description')->nullable();
            $table->string('status', 20);
            $table->integer('position')->default(0);
            $table->string('assignee_type', 10)->nullable(); // agent|human
            $table->string('assignee_id', 120)->nullable();
            $table->string('priority', 2)->default('p3');
            $table->date('due_on')->nullable();
            $table->string('blocked_reason', 140)->nullable();
            $table->string('ask', 280)->nullable();
            $table->string('ask_context', 500)->nullable();
            $table->json('ask_options')->nullable();
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('tilen_input_at')->nullable();
            $table->timestamp('tilen_input_ack_at')->nullable();
            $table->string('created_by', 120);
            $table->string('created_by_type', 10); // agent|human|system
            $table->timestamp('done_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('source_type', 20)->nullable(); // carry-over: question|required_input|next_step
            $table->string('source_id')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'status', 'position']);
            $table->index('updated_at');
            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->string('id')->primary(); // lb-…
            $table->string('name', 24)->unique();
            $table->string('color', 10)->default('grey');
            $table->timestamps();
        });

        Schema::create('ticket_label', function (Blueprint $table) {
            $table->string('ticket_id');
            $table->string('label_id');
            $table->primary(['ticket_id', 'label_id']);

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('labels')->cascadeOnDelete();
            $table->index('label_id');
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id(); // feed cursor
            $table->string('ticket_id');
            $table->string('author_type', 10); // agent|human
            $table->string('author_id', 120);
            $table->text('body');
            $table->boolean('is_tilen_input')->default(false);
            $table->string('input_choice', 60)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('retracted_at')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->index(['ticket_id', 'id']);
            $table->index(['is_tilen_input', 'id']);
        });

        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->nullable();
            $table->string('project_id');
            $table->string('type', 20);
            $table->json('from')->nullable();
            $table->json('to')->nullable();
            $table->string('actor_id', 120);
            $table->string('actor_type', 10); // agent|human|system
            $table->string('source', 10); // api|ui
            $table->timestamp('created_at')->nullable();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['ticket_id', 'id']);
            $table->index(['project_id', 'id']);
        });

        Schema::create('ticket_read_states', function (Blueprint $table) {
            $table->id();
            $table->string('user', 64);
            $table->string('ticket_id');
            $table->timestamp('last_seen_at');

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->unique(['user', 'ticket_id']);
        });

        Schema::create('project_read_states', function (Blueprint $table) {
            $table->id();
            $table->string('user', 64);
            $table->string('project_id');
            $table->timestamp('status_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['user', 'project_id']);
        });

        foreach (self::LEGACY as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('superseded_at')->nullable();
                $table->string('ticket_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::LEGACY as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['superseded_at', 'ticket_id']);
            });
        }
        Schema::dropIfExists('project_read_states');
        Schema::dropIfExists('ticket_read_states');
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('ticket_label');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('project_status_events');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['ticket_prefix']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['ticket_prefix', 'ticket_seq', 'status', 'status_note', 'status_set_by', 'status_set_at', 'last_activity_at']);
        });
    }
};
