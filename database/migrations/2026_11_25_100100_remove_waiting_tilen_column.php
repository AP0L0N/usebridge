<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The waiting_tilen column ("Waiting on <owner>") is gone from every board. Each ticket still stored in it (archived
 * ones too) moves to another column and gets the waiting-on-input flag (tickets.waiting_on_input_at), so none is lost.
 * Raw rows only (no model scopes: every workspace).
 *
 * Target column: the column the ticket was in before it last entered waiting_tilen (its newest `moved` event into
 * waiting_tilen from another column). A ticket created in waiting_tilen, or without such history: in_progress. A
 * ticket that came from done also goes to in_progress: it still needs input, so it is not done, and Done hides tickets
 * after a week. Live tickets go to the end of the target column, longest waiting first; archived ones keep their
 * position. The workstream,
 * the ask and the owner input (needs_pm_pickup) are untouched. Flag time: waiting_since, else now.
 *
 * Idempotent: a second run finds no ticket in waiting_tilen. Each move is logged as two events by `migration`
 * (waiting_set, then moved with to.reason), which is also what down() reads to put exactly those tickets back
 * (status, position, waiting_since) and unflag them. The count per project goes to the console (when anything moved)
 * and to the app log.
 */
return new class extends Migration
{
    private const WAITING = 'waiting_tilen';

    private const FALLBACK = 'in_progress';

    private const TARGETS = ['backlog', 'todo', 'in_progress', 'review'];

    private const REASON = 'waiting_column_removed';

    /** Where the report goes besides the app log (tests pass a buffer). */
    public ?OutputInterface $output = null;

    public function up(): void
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');
        $moved = [];

        DB::transaction(function () use ($now, &$moved) {
            // Per project, longest waiting first (no waiting_since: last), so each target column gets them in that order.
            $tickets = DB::table('tickets')->where('status', self::WAITING)->get()
                ->sortBy(fn (object $t) => [$t->project_id, $t->waiting_since ?? $now, $t->key])->values();
            $ends = [];
            foreach ($tickets as $t) {
                $target = $this->target($t->id);
                $position = (int) $t->position;
                if ($t->archived_at === null) {
                    $column = $t->project_id.'|'.$target;
                    if (! isset($ends[$column])) {
                        $last = DB::table('tickets')->where('project_id', $t->project_id)->where('status', $target)->whereNull('archived_at')->max('position');
                        $ends[$column] = $last === null ? 0 : (int) $last + 1;
                    }
                    $position = $ends[$column]++;
                }
                $flaggedAt = $t->waiting_since ?? $now;

                DB::table('tickets')->where('id', $t->id)->update([
                    'status' => $target,
                    'position' => $position,
                    'waiting_on_input_at' => $flaggedAt,
                    'waiting_since' => null,
                    'updated_at' => $now,
                ]);
                $event = [
                    'ticket_id' => $t->id,
                    'project_id' => $t->project_id,
                    'workstream_id' => $t->workstream_id,
                    'workspace_id' => $t->workspace_id,
                    'actor_id' => 'migration',
                    'actor_type' => 'system',
                    'source' => 'api',
                    'created_at' => $now,
                ];
                DB::table('ticket_events')->insert([
                    [...$event, 'type' => 'waiting_set', 'from' => null,
                        'to' => json_encode(['waiting_on_input_at' => $flaggedAt, 'reason' => self::REASON])],
                    [...$event, 'type' => 'moved',
                        'from' => json_encode(['status' => self::WAITING, 'position' => (int) $t->position, 'waiting_since' => $t->waiting_since]),
                        'to' => json_encode(['status' => $target, 'position' => $position, 'reason' => self::REASON])],
                ]);
                $moved[$t->project_id] = ($moved[$t->project_id] ?? 0) + 1;
            }
        });

        $this->report($moved, 'moved');
    }

    public function down(): void
    {
        $restored = [];

        DB::transaction(function () use (&$restored) {
            $events = DB::table('ticket_events')->where('actor_id', 'migration')->whereIn('type', ['moved', 'waiting_set'])
                ->orderBy('id')->get();
            foreach ($events as $e) {
                $from = json_decode((string) $e->from, true) ?: [];
                $to = json_decode((string) $e->to, true) ?: [];
                if (($to['reason'] ?? null) !== self::REASON) {
                    continue;
                }
                if ($e->type === 'moved' && DB::table('tickets')->where('id', $e->ticket_id)->exists()) {
                    DB::table('tickets')->where('id', $e->ticket_id)->update([
                        'status' => self::WAITING,
                        'position' => (int) ($from['position'] ?? 0),
                        'waiting_since' => $from['waiting_since'] ?? null,
                        'waiting_on_input_at' => null,
                        'done_at' => null,
                    ]);
                    $restored[$e->project_id] = ($restored[$e->project_id] ?? 0) + 1;
                }
                DB::table('ticket_events')->where('id', $e->id)->delete();
            }
        });

        $this->report($restored, 'moved back');
    }

    /** The column the ticket was in before it last entered waiting_tilen; FALLBACK without one (or from done). */
    private function target(string $ticketId): string
    {
        $events = DB::table('ticket_events')->where('ticket_id', $ticketId)->where('type', 'moved')->orderByDesc('id')->get(['from', 'to']);
        foreach ($events as $e) {
            $from = json_decode((string) $e->from, true)['status'] ?? null;
            $to = json_decode((string) $e->to, true)['status'] ?? null;
            if ($to === self::WAITING && $from !== self::WAITING) {
                return in_array($from, self::TARGETS, true) ? $from : self::FALLBACK;
            }
        }

        return self::FALLBACK;
    }

    /** @param  array<string, int>  $counts  project id => tickets */
    private function report(array $counts, string $verb): void
    {
        $names = DB::table('projects')->whereIn('id', array_keys($counts))->pluck('name', 'id');
        $n = fn (int $count) => $count.' ticket'.($count === 1 ? '' : 's');
        $lines = [];
        foreach ($counts as $projectId => $count) {
            $lines[] = sprintf('%s (%s): %s %s', $names[$projectId] ?? '?', $projectId, $n($count), $verb);
        }
        $total = array_sum($counts);
        $lines[] = sprintf('Waiting column removal: %s %s in total, on %d board%s', $n($total), $verb, count($counts), count($counts) === 1 ? '' : 's');

        foreach ($lines as $line) {
            Log::info($line);
        }
        if ($total > 0) {
            $out = $this->output ?? new ConsoleOutput;
            $out->writeln('');
            foreach ($lines as $line) {
                $out->writeln('  '.$line);
            }
        }
    }
};
