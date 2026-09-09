<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CeoQueueItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CeoQueueItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CeoQueueItem::query()->with(['project', 'assignedAgent'])->orderByDesc('surfaced_at');
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string'],
            'suggested_action' => ['required', 'string'],
            'project_id' => ['nullable', 'string', 'exists:projects,id'],
            'chip_label' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:open,assigned,dismissed'],
            'surfaced_at' => ['nullable', 'date'],
            'assigned_agent_id' => ['nullable', 'string', 'exists:agents,id'],
        ]);
        $data['id'] = $data['id'] ?? 'ceo-'.Str::lower(Str::ulid());
        $data['status'] = $data['status'] ?? 'open';
        $data['surfaced_at'] = $data['surfaced_at'] ?? now('Europe/Ljubljana')->toDateString();

        return response()->json(['data' => CeoQueueItem::create($data)], 201);
    }

    public function dismiss(string $id): JsonResponse
    {
        $item = CeoQueueItem::query()->findOrFail($id);
        $item->update(['status' => 'dismissed', 'assigned_agent_id' => null]);

        return response()->json(['data' => $item->fresh()]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'exists:agents,id'],
        ]);
        $item = CeoQueueItem::query()->findOrFail($id);
        $item->update([
            'status' => 'assigned',
            'assigned_agent_id' => $data['agent_id'],
        ]);

        return response()->json(['data' => $item->fresh()]);
    }
}
