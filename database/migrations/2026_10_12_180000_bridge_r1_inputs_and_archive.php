<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge r1: two screens, three statuses.
 *
 * - Adds projects.archived_at / projects.reopened_at and the required_inputs table.
 * - Maps old data (unless config bridge.r1_carry_over / BRIDGE_R1_CARRY_OVER=false): projects.needs_decision and open/assigned project CEO-queue items (suggested_action)
 *   become open Required inputs.
 * - Nothing is deleted. Dropped project columns are copied to
 *   legacy_r0_project_fields, and milestones / work_items / risks /
 *   ops_digest_items / ceo_queue_items are renamed to legacy_r0_*.
 * - down() restores the r0 schema (columns, order, NOT NULL) and values. Inputs, archive state and
 *   reopened markers created after the migration are lost on rollback
 *   (take a DB dump first).
 */
return new class extends Migration
{
    private const LEGACY_TABLES = ['milestones', 'work_items', 'risks', 'ops_digest_items', 'ceo_queue_items'];

    public function up(): void
    {
        Schema::create('legacy_r0_project_fields', function (Blueprint $table) {
            $table->string('project_id')->primary();
            $table->text('goal')->nullable();
            $table->string('phase')->nullable();
            $table->text('phase_done_looks_like')->nullable();
            $table->date('next_deadline')->nullable();
            $table->text('needs_decision')->nullable();
        });

        $projects = DB::table('projects')->get();
        foreach ($projects as $p) {
            DB::table('legacy_r0_project_fields')->insert([
                'project_id' => $p->id,
                'goal' => $p->goal,
                'phase' => $p->phase,
                'phase_done_looks_like' => $p->phase_done_looks_like,
                'next_deadline' => $p->next_deadline,
                'needs_decision' => $p->needs_decision,
            ]);
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
        });

        Schema::create('required_inputs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->string('status_update_id')->nullable();
            $table->text('text');
            $table->string('waiting_on');
            $table->timestamp('since');
            $table->timestamp('done_at')->nullable();
            $table->string('done_by')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('status_update_id')->references('id')->on('status_updates')->nullOnDelete();
            $table->index(['project_id', 'done_at']);
        });

        $now = now();
        $carryOver = $this->carryOver();
        foreach ($carryOver ? $projects : [] as $p) {
            if (filled($p->needs_decision)) {
                DB::table('required_inputs')->insert([
                    'id' => 'in-r0-nd-'.$p->id,
                    'project_id' => $p->id,
                    'text' => $p->needs_decision,
                    'waiting_on' => 'Tilen',
                    'since' => $p->updated_at ?? $p->created_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if ($carryOver && Schema::hasTable('ceo_queue_items')) {
            $agents = DB::table('agents')->pluck('name', 'id');
            $items = DB::table('ceo_queue_items')
                ->whereNotNull('project_id')
                ->whereIn('status', ['open', 'assigned'])
                ->get();
            foreach ($items as $q) {
                DB::table('required_inputs')->insert([
                    'id' => 'in-r0-q-'.$q->id,
                    'project_id' => $q->project_id,
                    'text' => $q->suggested_action ?: $q->reason,
                    'waiting_on' => $q->status === 'assigned' && $q->assigned_agent_id
                        ? ($agents[$q->assigned_agent_id] ?? $q->assigned_agent_id)
                        : 'Tilen',
                    'since' => $q->surfaced_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['goal', 'phase', 'phase_done_looks_like', 'next_deadline', 'needs_decision']);
        });

        foreach (self::LEGACY_TABLES as $t) {
            if (Schema::hasTable($t)) {
                Schema::rename($t, 'legacy_r0_'.$t);
            }
        }
    }

    /** config('bridge.r1_carry_over'); falls back to the env var if config is cached without the key. */
    private function carryOver(): bool
    {
        $v = config('bridge.r1_carry_over');
        if ($v === null) {
            $v = env('BRIDGE_R1_CARRY_OVER', true);
        }

        return filter_var($v, FILTER_VALIDATE_BOOL);
    }

    public function down(): void
    {
        foreach (self::LEGACY_TABLES as $t) {
            if (Schema::hasTable('legacy_r0_'.$t)) {
                Schema::rename('legacy_r0_'.$t, $t);
            }
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->text('goal')->nullable()->after('owner_agent_id');
            $table->string('phase')->nullable()->after('goal');
            $table->text('phase_done_looks_like')->nullable()->after('phase');
            $table->date('next_deadline')->nullable()->after('phase_done_looks_like');
            $table->text('needs_decision')->nullable()->after('next_deadline');
        });

        foreach (DB::table('legacy_r0_project_fields')->get() as $row) {
            DB::table('projects')->where('id', $row->project_id)->update([
                'goal' => $row->goal ?? '',
                'phase' => $row->phase ?? '',
                'phase_done_looks_like' => $row->phase_done_looks_like ?? '',
                'next_deadline' => $row->next_deadline,
                'needs_decision' => $row->needs_decision,
            ]);
        }
        DB::table('projects')->whereNull('goal')->update(['goal' => '', 'phase' => '', 'phase_done_looks_like' => '']);

        // Restore r0 NOT NULL on the required columns.
        Schema::table('projects', function (Blueprint $table) {
            $table->text('goal')->nullable(false)->change();
            $table->string('phase')->nullable(false)->change();
            $table->text('phase_done_looks_like')->nullable(false)->change();
        });

        Schema::dropIfExists('required_inputs');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'reopened_at']);
        });

        Schema::dropIfExists('legacy_r0_project_fields');
    }
};
