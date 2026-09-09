<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = WorkItem::query()->orderBy('due_at');
        if ($request->filled('project_id')) {
            $q->where('project_id', $request->string('project_id'));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'title' => ['required', 'string'],
            'owner_agent_id' => ['required', 'string', 'exists:agents,id'],
            'due_at' => ['nullable', 'date'],
            'status' => ['required', 'in:open,active,blocked,done'],
            'done_looks_like' => ['required', 'string'],
        ]);
        $data['id'] = $data['id'] ?? 'wi-'.Str::lower(Str::ulid());

        return response()->json(['data' => WorkItem::create($data)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $w = WorkItem::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string'],
            'owner_agent_id' => ['sometimes', 'string', 'exists:agents,id'],
            'due_at' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:open,active,blocked,done'],
            'done_looks_like' => ['sometimes', 'string'],
        ]);
        $w->update($data);

        return response()->json(['data' => $w->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        WorkItem::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
