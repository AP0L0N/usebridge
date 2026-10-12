<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RequiredInput;
use App\Services\ProjectStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RequiredInputController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = RequiredInput::query()->orderBy('since');
        if ($request->filled('project_id')) {
            $q->where('project_id', $request->string('project_id'));
        }
        if ($request->filled('open')) {
            $request->boolean('open') ? $q->whereNull('done_at') : $q->whereNotNull('done_at');
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

    /** PATCH {done: true, done_by?} marks provided; {done: false} reopens; text/waiting_on editable. */
    public function update(Request $request, string $id): JsonResponse
    {
        $input = RequiredInput::query()->findOrFail($id);
        $data = $request->validate([
            'done' => ['sometimes', 'boolean'],
            'done_by' => ['nullable', 'string', 'max:120'],
            'text' => ['sometimes', 'string', 'max:500'],
            'waiting_on' => ['sometimes', 'string', 'max:120'],
        ]);
        if (array_key_exists('done', $data)) {
            $input->done_at = $data['done'] ? ($input->done_at ?? Carbon::now()) : null;
            $input->done_by = $data['done'] ? ($data['done_by'] ?? $input->done_by ?? $input->waiting_on) : null;
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
