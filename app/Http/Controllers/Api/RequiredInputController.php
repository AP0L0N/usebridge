<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RequiredInput;
use App\Services\InputResolution;
use App\Services\ProjectStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RequiredInputController extends Controller
{
    /**
     * Filters: project_id, state=open|done, resolved_by=<value>, since=<ISO> (resolved_at >= since).
     * r1 filter open=1|0 still accepted.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'string'],
            'state' => ['nullable', 'in:open,done'],
            'resolved_by' => ['nullable', 'string', 'max:120'],
            'since' => ['nullable', 'date'],
            'open' => ['nullable', 'boolean'],
        ]);
        $q = RequiredInput::query()->orderBy('since');
        if (! empty($data['project_id'])) {
            $q->where('project_id', $data['project_id']);
        }
        $state = $data['state'] ?? (isset($data['open']) ? ($request->boolean('open') ? 'open' : 'done') : null);
        if ($state === 'open') {
            $q->whereNull('resolved_at');
        } elseif ($state === 'done') {
            $q->whereNotNull('resolved_at');
        }
        if (! empty($data['resolved_by'])) {
            $q->where('resolved_by', $data['resolved_by']);
        }
        if (! empty($data['since'])) {
            $q->where('resolved_at', '>=', Carbon::parse($data['since']));
        }

        return response()->json(['data' => $q->get()->map(fn ($i) => ['project_id' => $i->project_id, ...ProjectStatus::inputView($i)])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'text' => ['required', 'string', 'max:500'],
            'waiting_on' => ['required', 'string', 'max:120'],
            'since' => ['nullable', 'date'],
            'status_update_id' => ['nullable', 'string', 'exists:status_updates,id'],
        ]);
        $input = RequiredInput::create([
            ...$data,
            'id' => 'in-'.Str::lower((string) Str::ulid()),
            'since' => isset($data['since']) ? Carbon::parse($data['since']) : Carbon::now(),
        ]);

        return response()->json(['data' => ProjectStatus::inputView($input)], 201);
    }

    /**
     * PATCH {done|resolved: bool, resolved_by?|done_by?}. Resolver defaults to "token:<token name>".
     * Reopen clears resolved_by and resolved_at. text/waiting_on editable.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $input = RequiredInput::query()->findOrFail($id);
        $data = $request->validate([
            'done' => ['sometimes', 'boolean'],
            'resolved' => ['sometimes', 'boolean'],
            'resolved_by' => ['nullable', 'string', 'max:120'],
            'done_by' => ['nullable', 'string', 'max:120'],
            'text' => ['sometimes', 'string', 'max:500'],
            'waiting_on' => ['sometimes', 'string', 'max:120'],
        ]);
        $flag = $data['resolved'] ?? $data['done'] ?? null;
        if ($flag !== null) {
            $by = $data['resolved_by'] ?? $data['done_by'] ?? 'token:'.($request->user()?->currentAccessToken()?->name ?? 'api');
            $flag ? InputResolution::resolve($input, $by) : InputResolution::reopen($input);
        }
        $input->fill(array_intersect_key($data, array_flip(['text', 'waiting_on'])))->save();

        return response()->json(['data' => ProjectStatus::inputView($input->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        RequiredInput::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
