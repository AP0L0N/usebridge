<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\StatusUpdate;
use App\Services\StatusUpdateIngest;
use App\Services\Tickets\Actor;
use App\Support\Workspaces\WorkspaceRule;
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
     * r0 contract: {project_id, agent_id?, body, id?} (agent_id omitted = the token's own agent, go-live N1) -> 201 {data: StatusUpdate}.
     * r1 optional extras: inputs[], resolved_inputs[]; response adds `inputs`, `resolved_inputs`, `unarchived`.
     * project_id / agent_id are looked up in the current workspace only, so another workspace's id answers exactly like
     * an unknown one (no existence oracle): project 404 (checked first), agent 422.
     */
    public function store(Request $request, StatusUpdateIngest $ingest): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64', 'unique:status_updates,id'],
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string'],
            ...StatusUpdateIngest::rules(),
        ]);
        $project = Project::query()->findOrFail($data['project_id']);
        if (($data['agent_id'] ?? '') === '') {
            // Go-live N1: like every other write, no agent_id means the token's own agent (Actor B3 rules).
            $data['agent_id'] = Actor::forToken(null, $request->user())->id;
        } else {
            $request->validate(['agent_id' => [WorkspaceRule::exists('agents', 'id')]]);
        }

        $result = $ingest->handle(
            $project,
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
