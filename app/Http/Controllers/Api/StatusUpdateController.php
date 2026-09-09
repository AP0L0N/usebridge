<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StatusUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'agent_id' => ['required', 'string', 'exists:agents,id'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $data['id'] = $data['id'] ?? 'su-'.Str::lower(Str::ulid());
        $data['created_at'] = now('Europe/Ljubljana');

        return response()->json(['data' => StatusUpdate::create($data)], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        StatusUpdate::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
