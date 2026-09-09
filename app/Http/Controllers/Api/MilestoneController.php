<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MilestoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Milestone::query()->orderBy('due_at');
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
            'due_at' => ['nullable', 'date'],
            'status' => ['required', 'in:done,current,upcoming'],
        ]);
        $data['id'] = $data['id'] ?? 'ms-'.Str::lower(Str::ulid());

        return response()->json(['data' => Milestone::create($data)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $m = Milestone::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string'],
            'due_at' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:done,current,upcoming'],
        ]);
        $m->update($data);

        return response()->json(['data' => $m->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        Milestone::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
