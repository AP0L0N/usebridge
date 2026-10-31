<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Question;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use App\Models\Ticket;
use App\Services\Tickets\Actor;
use App\Services\Tickets\BoardView;
use App\Support\BoardColumns;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bridge r1 status rules (SPEC.md §5, approved by Tilen):
 * - archived_at set            -> archived
 * - any open input or question -> waiting; level from the OLDEST open item's age
 *                                 (input: since, r4 question: created_at)
 *                                 (r3: <3 days = L1 yellow, 3-6 days = L2 orange, >6 days = L3 red)
 * - otherwise                  -> smooth
 * - "Reopened" chip: reopened_at within the last 7 days (it is also cleared by the next update).
 */
class ProjectStatus
{
    public const REOPENED_DAYS = 7;

    public const DONE_VISIBLE_DAYS = 2;

    public static function ageDays(CarbonInterface $since, ?CarbonInterface $now = null): int
    {
        $now ??= Carbon::now();

        return max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 86400));
    }

    public static function level(int $days): int
    {
        return $days > 6 ? 3 : ($days >= 3 ? 2 : 1);
    }

    public static function isReopened(Project $project, ?CarbonInterface $now = null): bool
    {
        $now ??= Carbon::now();

        return $project->archived_at === null
            && $project->reopened_at !== null
            && $project->reopened_at->getTimestamp() > $now->getTimestamp() - self::REOPENED_DAYS * 86400;
    }

    /** @return array{status:string,level:int|null,ageDays:int|null} */
    public static function derive(Project $project, ?CarbonInterface $now = null): array
    {
        if ($project->archived_at !== null) {
            return ['status' => 'archived', 'level' => null, 'ageDays' => null];
        }
        $oldest = $project->requiredInputs
            ->filter(fn (RequiredInput $i) => $i->resolved_at === null)
            ->map(fn (RequiredInput $i) => $i->since)
            ->concat($project->questions->filter(fn (Question $q) => $q->isOpen())->map(fn (Question $q) => $q->created_at))
            ->sortBy(fn (CarbonInterface $since) => $since->getTimestamp())
            ->first();
        if (! $oldest) {
            return ['status' => 'smooth', 'level' => null, 'ageDays' => null];
        }
        $days = self::ageDays($oldest, $now);

        return ['status' => 'waiting', 'level' => self::level($days), 'ageDays' => $days];
    }

    /** First plain-text line of a Markdown body. */
    public static function firstLine(?string $body): string
    {
        foreach (preg_split('/\R/', (string) $body) as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            $t = preg_replace('/^(#{1,6}\s+|>\s*|[-*+]\s+|\d+[.)]\s+)/', '', $t);
            $t = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $t);
            $t = preg_replace('/(\*\*|__|\*|_|`|~~)/', '', $t);
            $t = trim(strip_tags($t));
            if ($t !== '') {
                return $t;
            }
        }

        return '';
    }

    public static function summary(Project $project, ?CarbonInterface $now = null, ?array $names = null): array
    {
        $names ??= Actor::names();
        // Kanban: the waiting level/age count from status_set_at (SPEC §2), same thresholds as level().
        $pmDays = $project->status === 'waiting' && $project->status_set_at ? self::ageDays($project->status_set_at, $now) : null;
        $updates = $project->statusUpdates->sortByDesc(fn (StatusUpdate $u) => $u->created_at?->getTimestamp() ?? 0)->values();
        $latest = $updates->first();
        $derived = self::derive($project, $now);
        $lastAt = $latest?->created_at ?? $project->updated_at;
        $counts = self::ticketCounts($project);

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            ...$derived,
            'archived' => $project->archived_at !== null,
            'archivedAt' => $project->archived_at?->toIso8601String(),
            'reopened' => self::isReopened($project, $now),
            'latestLine' => self::firstLine($latest?->body),
            'lastUpdatedAt' => $lastAt?->toIso8601String(),
            'openInputs' => $project->requiredInputs->whereNull('resolved_at')->count(),
            'openQuestions' => $project->questions->where('state', 'open')->count(),
            // Tickets: PM-set status (PATCH /api/projects/{id}/status) and ticket counts; the UI run switches the badge to these.
            'pmStatus' => $project->status,
            'pmStatusNote' => $project->status_note,
            'pmStatusSetBy' => $project->status_set_by,
            'pmStatusSetAt' => $project->status_set_at?->toIso8601String(),
            'pmStatusSetByName' => Actor::label($project->status_set_by, $names),
            'pmStatusSetByHuman' => Actor::isHumanId($project->status_set_by),
            'pmLevel' => $pmDays === null ? null : self::level($pmDays),
            'pmAgeDays' => $pmDays,
            'pmAgentId' => $project->owner_agent_id,
            'pmName' => Actor::label($project->owner_agent_id, $names),
            'ticketPrefix' => $project->ticket_prefix,
            'ticketCounts' => $counts,
            'waitingTilenCount' => $counts[BoardColumns::WAITING_TILEN], // compat alias of waitingOwnerCount
            'waitingOwnerCount' => $counts[BoardColumns::WAITING_TILEN],
            'lastActivityAt' => $project->last_activity_at?->toIso8601String(),
            // Search corpus: name + every update body (SPEC §5).
            'searchText' => $updates->pluck('body')->implode("\n"),
        ];
    }

    /**
     * Live (not archived) tickets per column, every column key present. Done counts only tickets done in the last
     * BoardView::DONE_DAYS days (Tommy, 29 Oct: the card legend reads "Done (7 days)").
     *
     * @return array<string,int>
     */
    public static function ticketCounts(Project $project): array
    {
        $since = Carbon::now()->subDays(BoardView::DONE_DAYS);
        $rows = $project->relationLoaded('tickets')
            ? $project->tickets->whereNull('archived_at')
                ->filter(fn ($t) => $t->status !== BoardColumns::DONE || ($t->done_at !== null && $t->done_at->gte($since)))
                ->countBy('status')->all()
            : Ticket::query()->where('project_id', $project->id)->whereNull('archived_at')
                ->where(fn ($w) => $w->where('status', '!=', BoardColumns::DONE)->orWhere('done_at', '>=', $since))
                ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        return array_map(fn (string $k) => (int) ($rows[$k] ?? 0), array_combine(BoardColumns::keys(), BoardColumns::keys()));
    }

    /**
     * r3: cleared items disappear 2 days after they were resolved/answered.
     * Shared by Required inputs (resolved_at) and r4 questions (answered_at); newest first.
     */
    public static function recentlyDone(Collection $items, string $doneAttr, ?CarbonInterface $now = null): Collection
    {
        $doneCutoff = ($now ?? Carbon::now())->copy()->subDays(self::DONE_VISIBLE_DAYS)->getTimestamp();

        return $items->whereNotNull($doneAttr)
            ->filter(fn ($i) => $i->{$doneAttr}->getTimestamp() >= $doneCutoff)
            ->sortByDesc(fn ($i) => $i->{$doneAttr}->getTimestamp())->values();
    }

    /** Human actor ids ('tilen', 'user:<id>') -> their label (Actor::label); 'token:x' -> 'x'; agent names unchanged. */
    public static function resolverLabel(?string $by): ?string
    {
        if ($by === null) {
            return null;
        }
        if (Actor::isHumanId($by)) {
            return Actor::label($by);
        }

        return str_starts_with($by, 'token:') ? substr($by, 6) : $by;
    }

    public static function inputView(RequiredInput $i, ?CarbonInterface $now = null): array
    {
        $open = $i->resolved_at === null;
        $days = $open ? self::ageDays($i->since, $now) : null;

        return [
            'id' => $i->id,
            'text' => $i->text,
            'waitingOn' => $i->waiting_on,
            'since' => $i->since->toIso8601String(),
            'resolvedAt' => $i->resolved_at?->toIso8601String(),
            'resolvedBy' => $i->resolved_by,
            'resolvedByLabel' => self::resolverLabel($i->resolved_by),
            // API (snake_case) aliases
            'resolved_at' => $i->resolved_at?->toIso8601String(),
            'resolved_by' => $i->resolved_by,
            'open' => $open,
            'ageDays' => $days,
            'level' => $open ? self::level($days) : null,
        ];
    }
}
