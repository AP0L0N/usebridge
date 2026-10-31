<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StatusUpdate;
use App\Services\StatusUpdateIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusUpdateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = StatusUpdate::query()->orderByDesc('created_at');
        if ($request->filled('project_id')) {
            $q->where('project_id', $request->string('project_id'));
        }

        return response()->json(['data' => $q->get()]);
    }

    /**
     * r0 contract unchanged: {project_id, agent_id, body, id?} -> 201 {data: StatusUpdate}.
     * r1 optional extras: inputs[], resolved_inputs[]; response adds `inputs`, `resolved_inputs`, `unarchived`.
     */
    public function store(Request $request, StatusUpdateIngest $ingest): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64', 'unique:status_updates,id'],
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'agent_id' => ['required', 'string', 'exists:agents,id'],
            ...StatusUpdateIngest::rules(),
        ]);

        $result = $ingest->handle(
            Project::query()->findOrFail($data['project_id']),
            $data['agent_id'],
            $data['body'],
            $data['id'] ?? null,
            $data['inputs'] ?? [],
            $data['resolved_inputs'] ?? [],
        );

        return response()->json([
            'data' => $result['update'],
            'inputs' => $result['inputs'],
            'resolved_inputs' => $result['resolved'],
            'unarchived' => $result['unarchived'],
        ], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        StatusUpdate::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
