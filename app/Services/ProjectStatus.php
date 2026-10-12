<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Bridge r1 status rules (SPEC.md §5, approved by Tilen):
 * - archived_at set            -> archived
 * - any open input             -> waiting; level from the OLDEST open input's age
 *                                 (<1 day and 1 day = L1 yellow, 2 = L2 orange, 3+ = L3 red)
 * - otherwise                  -> smooth
 * - "Reopened" chip: reopened_at within the last 7 days (it is also cleared by the next update).
 */
class ProjectStatus
{
    public const REOPENED_DAYS = 7;

    public static function ageDays(CarbonInterface $since, ?CarbonInterface $now = null): int
    {
        $now ??= Carbon::now();

        return max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 86400));
    }

    public static function level(int $days): int
    {
        return $days >= 3 ? 3 : ($days === 2 ? 2 : 1);
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
            ->filter(fn (RequiredInput $i) => $i->done_at === null)
            ->sortBy(fn (RequiredInput $i) => $i->since->getTimestamp())
            ->first();
        if (! $oldest) {
            return ['status' => 'smooth', 'level' => null, 'ageDays' => null];
        }
        $days = self::ageDays($oldest->since, $now);

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

    public static function summary(Project $project, ?CarbonInterface $now = null): array
    {
        $updates = $project->statusUpdates->sortByDesc(fn (StatusUpdate $u) => $u->created_at?->getTimestamp() ?? 0)->values();
        $latest = $updates->first();
        $derived = self::derive($project, $now);
        $lastAt = $latest?->created_at ?? $project->updated_at;

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
            'openInputs' => $project->requiredInputs->whereNull('done_at')->count(),
            // Search corpus: name + every update body (SPEC §5).
            'searchText' => $updates->pluck('body')->implode("\n"),
        ];
    }

    public static function inputView(RequiredInput $i, ?CarbonInterface $now = null): array
    {
        $open = $i->done_at === null;
        $days = $open ? self::ageDays($i->since, $now) : null;

        return [
            'id' => $i->id,
            'text' => $i->text,
            'waitingOn' => $i->waiting_on,
            'since' => $i->since->toIso8601String(),
            'doneAt' => $i->done_at?->toIso8601String(),
            'doneBy' => $i->done_by,
            'open' => $open,
            'ageDays' => $days,
            'level' => $open ? self::level($days) : null,
        ];
    }
}
