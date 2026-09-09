<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\HealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function index(HealthService $health): JsonResponse
    {
        $projects = Project::query()->with(['owner', 'risks', 'statusUpdates', 'workItems'])->orderBy('name')->get()
            ->map(fn ($p) => $health->enrichProject($p));

        return response()->json(['data' => $projects]);
    }

    public function show(string $id, HealthService $health): JsonResponse
    {
        $project = Project::query()->with(['owner', 'risks', 'statusUpdates', 'workItems', 'milestones'])->findOrFail($id);

        return response()->json(['data' => $health->enrichProject($project)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:projects,slug'],
            'owner_agent_id' => ['required', 'string', 'exists:agents,id'],
            'goal' => ['required', 'string'],
            'phase' => ['required', 'string'],
            'phase_done_looks_like' => ['required', 'string'],
            'next_deadline' => ['nullable', 'date'],
            'needs_decision' => ['nullable', 'string'],
        ]);
        $data['id'] = $data['id'] ?? 'proj-'.Str::lower(Str::ulid());
        $project = Project::create($data);

        return response()->json(['data' => $project], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:projects,slug,'.$project->id.',id'],
            'owner_agent_id' => ['sometimes', 'string', 'exists:agents,id'],
            'goal' => ['sometimes', 'string'],
            'phase' => ['sometimes', 'string'],
            'phase_done_looks_like' => ['sometimes', 'string'],
            'next_deadline' => ['nullable', 'date'],
            'needs_decision' => ['nullable', 'string'],
        ]);
        $project->update($data);

        return response()->json(['data' => $project->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        Project::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
