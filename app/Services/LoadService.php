<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Project;
use App\Models\WorkItem;
use App\Support\OrgDate;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Agent load — LOAD-1 CONFIRMED.
 */
final class LoadService
{
    public const DUE_SOON_DAYS = 7;

    /**
     * @param  array{dueSoonCount:int,distinctProjectsIncludingContrib:int,hasOwnership:bool}  $input
     */
    public function computeLoad(array $input): string
    {
        $dueSoonCount = $input['dueSoonCount'];
        $distinct = $input['distinctProjectsIncludingContrib'];
        $hasOwnership = $input['hasOwnership'];

        if ($dueSoonCount >= 3 || ($distinct >= 2 && $dueSoonCount >= 2)) {
            return 'overloaded';
        }
        if ($dueSoonCount === 0 && ! $hasOwnership) {
            return 'free';
        }

        return 'balanced';
    }

    public function isDueSoon(?string $dueAt, ?DateTimeInterface $now = null): bool
    {
        if ($dueAt === null || $dueAt === '') {
            return false;
        }
        $today = OrgDate::today($now);
        $end = OrgDate::addCalendarDays($today, self::DUE_SOON_DAYS);

        return $dueAt >= $today && $dueAt <= $end;
    }

    /**
     * @return list<array{agent:array,load:string,dueSoonCount:int,dueSoonDisplay:string,chips:list<array{label:string,title?:string}>}>
     */
    public function buildAgentLoadRows(?DateTimeInterface $now = null): array
    {
        $agents = Agent::query()->orderBy('name')->get();
        $projects = Project::all()->keyBy('id');
        $workItems = WorkItem::all();

        return $agents->map(function (Agent $agent) use ($projects, $workItems, $now) {
            $owned = $projects->where('owner_agent_id', $agent->id)->values();
            $items = $workItems->where('owner_agent_id', $agent->id)->values();
            $contribIds = $items->pluck('project_id')->unique();
            $ownedIds = $owned->pluck('id');
            $distinctIds = $ownedIds->merge($contribIds)->unique();

            $dueSoonCount = $items->filter(
                fn (WorkItem $w) => $w->status !== 'done'
                    && $this->isDueSoon(optional($w->due_at)?->format('Y-m-d'), $now)
            )->count();

            $hasOwnership = $owned->isNotEmpty();
            $load = $this->computeLoad([
                'dueSoonCount' => $dueSoonCount,
                'distinctProjectsIncludingContrib' => $distinctIds->count(),
                'hasOwnership' => $hasOwnership,
            ]);

            $contribProjects = $projects->filter(
                fn (Project $p) => $contribIds->contains($p->id) && ! $ownedIds->contains($p->id)
            )->values();

            return [
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'roleLabel' => $agent->role_label,
                ],
                'load' => $load,
                'dueSoonCount' => $dueSoonCount,
                'dueSoonDisplay' => $items->isEmpty() ? '—' : (string) $dueSoonCount,
                'chips' => $this->projectChips($owned, $contribProjects, $items),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Project>  $owned
     * @param  Collection<int, Project>  $contribProjects
     * @param  Collection<int, WorkItem>  $items
     * @return list<array{label:string,title?:string}>
     */
    private function projectChips(Collection $owned, Collection $contribProjects, Collection $items): array
    {
        if ($owned->isEmpty() && $contribProjects->isEmpty()) {
            return [['label' => 'queue only']];
        }

        $chips = $owned->map(fn (Project $p) => ['label' => $p->slug])->all();

        foreach ($contribProjects as $p) {
            $agentItems = $items->where('project_id', $p->id);
            $allUndated = $agentItems->every(fn (WorkItem $w) => $w->due_at === null);
            if ($allUndated && $owned->isEmpty() && $contribProjects->count() === 1) {
                $chips[] = ['label' => '— scaffold only'];
            } elseif ($owned->isNotEmpty()) {
                $chips[] = [
                    'label' => $p->slug.' · contrib',
                    'title' => 'Work items only — not project owner',
                ];
            } else {
                $chips[] = ['label' => $p->slug];
            }
        }

        return $chips;
    }
}
