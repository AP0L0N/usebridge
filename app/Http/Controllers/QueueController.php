<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\CeoQueueItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function index(): Response
    {
        $items = CeoQueueItem::query()->with(['project', 'assignedAgent'])->orderByDesc('surfaced_at')->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'reason' => $i->reason,
                'suggestedAction' => $i->suggested_action,
                'projectId' => $i->project_id,
                'projectSlug' => $i->project?->slug,
                'chipLabel' => $i->chip_label,
                'status' => $i->status,
                'surfacedAt' => optional($i->surfaced_at)?->format('Y-m-d'),
                'assignedAgentId' => $i->assigned_agent_id,
                'assignedAgentName' => $i->assignedAgent?->name,
            ]);

        return Inertia::render('Queue', [
            'items' => $items,
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name]),
        ]);
    }

    public function dismiss(string $id): RedirectResponse
    {
        $item = CeoQueueItem::query()->findOrFail($id);
        $item->update(['status' => 'dismissed', 'assigned_agent_id' => null]);

        return back();
    }

    public function assign(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'exists:agents,id'],
        ]);

        $item = CeoQueueItem::query()->findOrFail($id);
        $item->update([
            'status' => 'assigned',
            'assigned_agent_id' => $data['agent_id'],
        ]);

        return back();
    }
}
