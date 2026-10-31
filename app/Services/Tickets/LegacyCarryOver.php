<?php

namespace App\Services\Tickets;

use App\Models\Agent;
use App\Models\NextStep;
use App\Models\Project;
use App\Models\Question;
use App\Models\RequiredInput;
use App\Models\Ticket;
use App\Support\BoardColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan bridge:tickets-migrate`: open/answered Questions, open Required inputs and not-done Next steps become
 * tickets. Idempotent: rows that already carry ticket_id/superseded_at, or whose (source_type, source_id) ticket
 * exists, are skipped. Each carried legacy row only gets superseded_at + ticket_id; nothing else changes, nothing is
 * deleted. One transaction per project; --dry-run rolls it back, so it writes nothing.
 */
final class LegacyCarryOver
{
    private const EMPTY = [
        'questions_open' => 0, 'questions_answered' => 0, 'inputs' => 0, 'next_steps' => 0,
        'tickets' => 0, 'waiting_tilen' => 0, 'todo' => 0, 'in_progress' => 0, 'skipped' => 0,
    ];

    /** @var Collection<int, Agent> */
    private Collection $agents;

    public function __construct(private readonly TicketService $tickets) {}

    /** @return array{projects: list<array>, total: array<string,int>} */
    public function run(bool $dry = false, ?string $projectId = null): array
    {
        $this->agents = Agent::query()->get(['id', 'name']);
        $projects = Project::query()->when($projectId, fn ($q) => $q->whereKey($projectId))->orderBy('name')->get();
        $total = self::EMPTY;
        $out = [];
        foreach ($projects as $project) {
            DB::beginTransaction();
            try {
                $c = $this->project($project);
                $dry ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            $out[] = ['id' => $project->id, 'name' => $project->name, ...$c];
            foreach ($c as $k => $v) {
                $total[$k] += $v;
            }
        }

        return ['projects' => $out, 'total' => $total];
    }

    /** "questions open N, answered N, inputs N, next steps N → tickets N (waiting_tilen N, todo N, in_progress N) · skipped N" */
    public static function line(array $c): string
    {
        return sprintf('questions open %d, answered %d, inputs %d, next steps %d → tickets %d (waiting_tilen %d, todo %d, in_progress %d) · skipped %d',
            $c['questions_open'], $c['questions_answered'], $c['inputs'], $c['next_steps'],
            $c['tickets'], $c['waiting_tilen'], $c['todo'], $c['in_progress'], $c['skipped']);
    }

    private function project(Project $project): array
    {
        $c = self::EMPTY;
        $pm = ['assignee_type' => 'agent', 'assignee_id' => $project->owner_agent_id];

        $questions = Question::query()->where('project_id', $project->id)->whereIn('state', ['open', 'answered'])->orderBy('created_at')->orderBy('id')->get();
        foreach ($questions as $q) {
            if ($this->skip($q, 'question')) {
                $c['skipped']++;

                continue;
            }
            $by = $this->matchAgent($q->asked_by);
            $ticket = $this->create($project, [
                'title' => self::cut($q->text, 140),
                'description' => self::description($q->text, sprintf('_Carried over from Questions for Tilen (asked by %s, %s)._', $q->asked_by, self::date($q->created_at))),
                'status' => BoardColumns::WAITING_TILEN,
                ...$pm,
                'ask' => self::cut($q->text, 280),
                'ask_context' => $q->context === null ? null : self::cut($q->context, 500),
                'ask_options' => $q->type === 'yesno' ? ['Yes', 'No'] : null,
                'labels' => ['question'],
            ], [
                'waiting_since' => $q->created_at,
                'created_at' => $q->created_at,
                'created_by' => $by ?? Actor::MIGRATION,
                'created_by_type' => $by ? 'agent' : 'system',
            ], $q, 'question');

            if ($q->state === 'answered') {
                $choice = $q->type === 'yesno' ? match ($q->answer_choice) {
                    'yes' => 'Yes',
                    'no' => 'No',
                    default => 'other',
                } : null;
                $body = filled($q->answer_text) ? $q->answer_text : match ($q->answer_choice) {
                    'yes' => 'Yes',
                    'no' => 'No',
                    'skip' => 'Skip',
                    default => '(no answer text)',
                };
                $this->tickets->comment($ticket, Actor::human(HumanActors::ownerId($project->workspace)), $body, true, $choice, $q->answered_at ?? $q->updated_at, checkWritable: false);
                $c['questions_answered']++;
            } else {
                $c['questions_open']++;
            }
            $c = $this->count($c, $ticket);
        }

        $inputs = RequiredInput::query()->where('project_id', $project->id)->whereNull('resolved_at')->orderBy('since')->orderBy('id')->get();
        foreach ($inputs as $i) {
            if ($this->skip($i, 'required_input')) {
                $c['skipped']++;

                continue;
            }
            $forTilen = strcasecmp(trim((string) $i->waiting_on), 'tilen') === 0;
            // Only items that need Tilen go to Waiting on Tilen; inputs owned by someone else go to In progress, assigned to them.
            $owner = $forTilen ? null : $this->matchAgent($i->waiting_on);
            $assignee = match (true) {
                $forTilen => $pm,
                $owner !== null => ['assignee_type' => 'agent', 'assignee_id' => $owner],
                filled($i->waiting_on) => ['assignee_type' => 'human', 'assignee_id' => trim((string) $i->waiting_on)],
                default => $pm,
            };
            $ticket = $this->create($project, [
                'title' => self::cut($i->text, 140),
                'description' => self::description($i->text, sprintf('_Carried over from Required inputs (waiting on %s, since %s)._', $i->waiting_on, self::date($i->since))),
                'status' => $forTilen ? BoardColumns::WAITING_TILEN : BoardColumns::IN_PROGRESS,
                ...$assignee,
                'ask' => $forTilen ? self::cut($i->text, 280) : null,
                'labels' => $forTilen ? ['input'] : ['input', 'waiting-on-other'],
            ], [
                'waiting_since' => $forTilen ? $i->since : null,
                'created_at' => $i->created_at ?? $i->since,
                'created_by' => Actor::MIGRATION,
                'created_by_type' => 'system',
            ], $i, 'required_input');
            $c['inputs']++;
            $c = $this->count($c, $ticket);
        }

        $steps = NextStep::query()->where('project_id', $project->id)->whereNull('done_at')->orderBy('position')->orderBy('created_at')->orderBy('id')->get();
        foreach ($steps as $s) {
            if ($this->skip($s, 'next_step')) {
                $c['skipped']++;

                continue;
            }
            $tilenOwned = strcasecmp(trim((string) $s->owner), 'tilen') === 0;
            $blocker = $s->kind === 'blocker';
            $status = ! $blocker ? BoardColumns::IN_PROGRESS : ($tilenOwned ? BoardColumns::WAITING_TILEN : BoardColumns::TODO);
            $ownerAgent = $tilenOwned ? null : $this->matchAgent($s->owner);
            $assignee = match (true) {
                $s->agent_id && $this->agents->contains('id', $s->agent_id) => ['assignee_type' => 'agent', 'assignee_id' => $s->agent_id],
                $ownerAgent !== null => ['assignee_type' => 'agent', 'assignee_id' => $ownerAgent],
                filled($s->owner) => ['assignee_type' => 'human', 'assignee_id' => $tilenOwned ? HumanActors::ownerId($project->workspace) : trim($s->owner)],
                default => $pm,
            };
            $ticket = $this->create($project, [
                'title' => self::cut($s->text, 140),
                'description' => self::description($s->text, sprintf('_Carried over from Next steps (%s, since %s)._', $blocker ? 'blocker' : 'in progress', self::date($s->created_at))),
                'status' => $status,
                ...$assignee,
                'blocked_reason' => $blocker && ! $tilenOwned ? self::cut($s->text, 140) : null,
                'ask' => $status === BoardColumns::WAITING_TILEN ? self::cut($s->text, 280) : null,
                'labels' => $blocker ? ['next-step', 'blocker'] : ['next-step'],
            ], [
                'waiting_since' => $status === BoardColumns::WAITING_TILEN ? $s->created_at : null,
                'created_at' => $s->created_at,
                'created_by' => Actor::MIGRATION,
                'created_by_type' => 'system',
            ], $s, 'next_step');
            $c['next_steps']++;
            $c = $this->count($c, $ticket);
        }

        return $c;
    }

    private function skip(Model $row, string $type): bool
    {
        return $row->ticket_id !== null || $row->superseded_at !== null
            || Ticket::query()->where('source_type', $type)->where('source_id', $row->getKey())->exists();
    }

    private function create(Project $project, array $data, array $extra, Model $row, string $type): Ticket
    {
        $ticket = $this->tickets->create($project, $data, Actor::migration(), [
            ...$extra,
            'source_type' => $type,
            'source_id' => $row->getKey(),
        ], allowArchivedProject: true);
        // Only these two legacy columns ever change (no updated_at bump).
        $row->newQuery()->whereKey($row->getKey())->toBase()->update(['superseded_at' => Carbon::now(), 'ticket_id' => $ticket->id]);

        return $ticket;
    }

    private function count(array $c, Ticket $t): array
    {
        $c['tickets']++;
        if (isset($c[$t->status])) {
            $c[$t->status]++;
        }

        return $c;
    }

    /** asked_by -> agents.id: id or full name (case-insensitive), else a single name part ("Janice" -> Dr. Janice Polito). */
    private function matchAgent(?string $name): ?string
    {
        $n = mb_strtolower(trim((string) $name));
        if ($n === '') {
            return null;
        }
        foreach ($this->agents as $a) {
            if (mb_strtolower($a->id) === $n || mb_strtolower($a->name) === $n) {
                return $a->id;
            }
        }
        foreach ($this->agents as $a) {
            $parts = preg_split('/[\s.]+/', mb_strtolower($a->name), -1, PREG_SPLIT_NO_EMPTY);
            if (in_array($n, $parts, true)) {
                return $a->id;
            }
        }

        return null;
    }

    public static function cut(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    private static function description(string $text, string $footer): string
    {
        return mb_strlen(trim($text)) > 140 ? trim($text)."\n\n".$footer : $footer;
    }

    private static function date(?CarbonInterface $at): string
    {
        return $at ? $at->copy()->setTimezone((string) config('bridge.org_timezone'))->format('j M Y') : 'unknown date';
    }
}
