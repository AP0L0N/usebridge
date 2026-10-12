<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ProjectStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /** r0 fields that agents may still send; accepted and ignored (removed in r1). */
    private const LEGACY_FIELDS = ['goal', 'phase', 'phase_done_looks_like', 'next_deadline', 'needs_decision'];

    public function index(): JsonResponse
    {
        $projects = Project::query()->with(['statusUpdates', 'requiredInputs'])->orderBy('name')->get()
            ->map(fn ($p) => $this->present($p));

        return response()->json(['data' => $projects]);
    }

    public function show(string $id): JsonResponse
    {
        $project = Project::query()->with(['statusUpdates', 'requiredInputs'])->findOrFail($id);

        return response()->json(['data' => [
            ...$this->present($project),
            'required_inputs' => $project->requiredInputs->map(fn ($i) => ProjectStatus::inputView($i))->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64', 'unique:projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:projects,slug'],
            'owner_agent_id' => ['required', 'string', 'exists:agents,id'],
            ...array_fill_keys(self::LEGACY_FIELDS, ['nullable']),
        ]);
        $data = array_diff_key($data, array_flip(self::LEGACY_FIELDS));
        $data['id'] = $data['id'] ?? 'proj-'.Str::lower((string) Str::ulid());
        $project = Project::create($data);

        return response()->json(['data' => $this->present($project->load(['statusUpdates', 'requiredInputs']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:projects,slug,'.$project->id.',id'],
            'owner_agent_id' => ['sometimes', 'string', 'exists:agents,id'],
            'archived' => ['sometimes', 'boolean'],
            ...array_fill_keys(self::LEGACY_FIELDS, ['nullable']),
        ]);
        if (array_key_exists('archived', $data)) {
            $project->archived_at = $data['archived'] ? ($project->archived_at ?? Carbon::now()) : null;
            if ($data['archived']) {
                $project->reopened_at = null;
            }
        }
        $project->fill(array_intersect_key($data, array_flip(['name', 'slug', 'owner_agent_id'])))->save();

        return response()->json(['data' => $this->present($project->fresh()->load(['statusUpdates', 'requiredInputs']))]);
    }

    public function archive(string $id): JsonResponse
    {
        return $this->update(new Request(['archived' => true]), $id);
    }

    public function unarchive(string $id): JsonResponse
    {
        return $this->update(new Request(['archived' => false]), $id);
    }

    public function destroy(string $id): JsonResponse
    {
        Project::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function present(Project $p): array
    {
        $s = ProjectStatus::summary($p);
        unset($s['searchText']);

        return [...$s, 'owner_agent_id' => $p->owner_agent_id];
    }
}
