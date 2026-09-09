<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Risk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RiskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Risk::query()->orderByDesc('created_at');
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
            'text' => ['required', 'string'],
            'severity' => ['required', 'in:low,medium,high'],
            'open' => ['sometimes', 'boolean'],
        ]);
        $data['id'] = $data['id'] ?? 'risk-'.Str::lower(Str::ulid());
        $data['open'] = $data['open'] ?? true;
        $data['created_at'] = now('Europe/Ljubljana');

        return response()->json(['data' => Risk::create($data)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $r = Risk::query()->findOrFail($id);
        $data = $request->validate([
            'text' => ['sometimes', 'string'],
            'severity' => ['sometimes', 'in:low,medium,high'],
            'open' => ['sometimes', 'boolean'],
        ]);
        $r->update($data);

        return response()->json(['data' => $r->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        Risk::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
