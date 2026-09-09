<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\Project;
use App\Support\OrgDate;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Project health — DESIGN.md §7
 * H1–H6 CONFIRMED. Precedence: RED > YELLOW > GREEN.
 */
final class HealthService
{
    public const STALE_UPDATE_DAYS = 7;

    public const YELLOW_DEADLINE_DAYS = 7;

    private const SEV_RANK = ['high' => 3, 'medium' => 2, 'low' => 1];

    /**
     * @param  array{nextDeadline:?string,needsDecision:?string,openRisks:array<int,array{severity:string}>,latestUpdateAt:?string,hasOpenWork:bool,now?:?DateTimeInterface}  $input
     */
    public function computeHealth(array $input): string
    {
        $now = $input['now'] ?? null;
        $today = OrgDate::today($now);
        $openHigh = collect($input['openRisks'])->contains(fn ($r) => ($r['severity'] ?? null) === 'high');
        $openMedium = collect($input['openRisks'])->contains(fn ($r) => ($r['severity'] ?? null) === 'medium');
        $deadline = $input['nextDeadline'] ?? null;
        $deadlinePast = $deadline !== null && $deadline < $today;
        $deadlineYellow = $deadline !== null
            && $deadline >= $today
            && $deadline <= OrgDate::addCalendarDays($today, self::YELLOW_DEADLINE_DAYS);
        $staleDays = $this->computeStaleDays($input['latestUpdateAt'] ?? null, $now);
        $staleWithOpenWork = $staleDays > self::STALE_UPDATE_DAYS && ($input['hasOpenWork'] ?? false);
        $needsDecision = filled(trim((string) ($input['needsDecision'] ?? '')));

        if ($openHigh || $deadlinePast || $staleWithOpenWork) {
            return 'red';
        }
        if ($deadlineYellow || $openMedium || $needsDecision) {
            return 'yellow';
        }

        return 'green';
    }

    public function computeStaleDays(?string $latestUpdateAt, ?DateTimeInterface $now = null): float
    {
        if ($latestUpdateAt === null || $latestUpdateAt === '') {
            return INF;
        }

        return OrgDate::calendarDaysBetween(
            OrgDate::toOrgCalendarDate($latestUpdateAt),
            OrgDate::today($now),
        );
    }

    /**
     * @param  Collection<int, object|array>  $openRisks
     */
    public function computeTopBlocker(Collection $openRisks): string
    {
        if ($openRisks->isEmpty()) {
            return 'none';
        }

        $sorted = $openRisks->sort(function ($a, $b) {
            $a = (array) $a;
            $b = (array) $b;
            $bySev = (self::SEV_RANK[$b['severity']] ?? 0) - (self::SEV_RANK[$a['severity']] ?? 0);
            if ($bySev !== 0) {
                return $bySev;
            }
            $ca = (string) ($a['created_at'] ?? $a['createdAt'] ?? '');
            $cb = (string) ($b['created_at'] ?? $b['createdAt'] ?? '');

            return strcmp($cb, $ca);
        })->values();

        $first = (array) $sorted->first();

        return (string) ($first['text'] ?? 'none');
    }

    public function enrichProject(Project $project, ?DateTimeInterface $now = null): array
    {
        $project->loadMissing(['risks', 'statusUpdates', 'workItems', 'owner']);

        $openRisks = $project->risks->where('open', true)->values();
        $latest = $project->statusUpdates->sortByDesc('created_at')->first();
        $hasOpenWork = $project->workItems->contains(fn ($w) => $w->status !== 'done');

        $openRiskPayload = $openRisks->map(fn ($r) => [
            'text' => $r->text,
            'severity' => $r->severity,
            'created_at' => optional($r->created_at)?->toIso8601String() ?? '',
        ]);

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'ownerAgentId' => $project->owner_agent_id,
            'ownerName' => $project->owner?->name,
            'goal' => $project->goal,
            'phase' => $project->phase,
            'phaseDoneLooksLike' => $project->phase_done_looks_like,
            'nextDeadline' => optional($project->next_deadline)?->format('Y-m-d'),
            'needsDecision' => $project->needs_decision,
            'health' => $this->computeHealth([
                'nextDeadline' => optional($project->next_deadline)?->format('Y-m-d'),
                'needsDecision' => $project->needs_decision,
                'openRisks' => $openRiskPayload->all(),
                'latestUpdateAt' => optional($latest?->created_at)?->toIso8601String(),
                'hasOpenWork' => $hasOpenWork,
                'now' => $now,
            ]),
            'topBlocker' => $this->computeTopBlocker($openRiskPayload),
        ];
    }

    public function milestoneLane(Milestone $m, ?DateTimeInterface $now = null): string
    {
        if ($m->status === 'done') {
            return 'done';
        }
        $today = OrgDate::today($now);
        $due = optional($m->due_at)?->format('Y-m-d');
        if ($due !== null && $due < $today) {
            return 'overdue';
        }
        if ($m->status === 'current') {
            return 'current';
        }

        return 'upcoming';
    }
}
