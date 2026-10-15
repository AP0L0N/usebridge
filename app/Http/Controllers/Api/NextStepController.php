<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NextStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Bridge r3 Next steps API (Sanctum, same style as status-updates / inputs). */
class NextStepController extends Controller
{
    /** Filters: project_id, kind=in_progress|blocker, state=open|done|all (default open). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'string'],
            'kind' => ['nullable', Rule::in(NextStep::KINDS)],
            'state' => ['nullable', 'in:open,done,all'],
        ]);
        $q = NextStep::query()->orderBy('project_id')->orderBy('kind')->orderBy('position')->orderBy('created_at');
        if (! empty($data['project_id'])) {
            $q->where('project_id', $data['project_id']);
        }
        if (! empty($data['kind'])) {
            $q->where('kind', $data['kind']);
        }
        $state = $data['state'] ?? 'open';
        if ($state === 'open') {
            $q->whereNull('done_at');
        } elseif ($state === 'done') {
            $q->whereNotNull('done_at');
        }

        return response()->json(['data' => $q->get()->map->toView()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64', 'unique:next_steps,id'],
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'kind' => ['required', Rule::in(NextStep::KINDS)],
            'text' => ['required', 'string', 'max:500'],
            'owner' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['nullable', 'string', 'exists:agents,id'],
            'position' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $step = NextStep::create([
            ...$data,
            'id' => $data['id'] ?? 'ns-'.Str::lower((string) Str::ulid()),
            'position' => $data['position'] ?? 0,
        ]);

        return response()->json(['data' => $step->fresh()->toView()], 201);
    }

    /** PATCH any of kind, text, owner, agent_id, position, done (bool), done_by. done:false clears done_at/done_by. */
    public function update(Request $request, string $id): JsonResponse
    {
        $step = NextStep::query()->findOrFail($id);
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(NextStep::KINDS)],
            'text' => ['sometimes', 'string', 'max:500'],
            'owner' => ['sometimes', 'nullable', 'string', 'max:120'],
            'agent_id' => ['sometimes', 'nullable', 'string', 'exists:agents,id'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'done' => ['sometimes', 'boolean'],
            'done_by' => ['nullable', 'string', 'max:120'],
        ]);
        $step->fill(array_intersect_key($data, array_flip(['kind', 'text', 'owner', 'agent_id', 'position'])));
        if (array_key_exists('done', $data)) {
            if ($data['done']) {
                $step->done_at ??= Carbon::now();
                $step->done_by = $data['done_by'] ?? 'token:'.($request->user()?->currentAccessToken()?->name ?? 'api');
            } else {
                $step->done_at = null;
                $step->done_by = null;
            }
        }
        $step->save();

        return response()->json(['data' => $step->fresh()->toView()]);
    }

    public function destroy(string $id): JsonResponse
    {
        NextStep::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
