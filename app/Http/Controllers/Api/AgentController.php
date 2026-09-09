<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Agent::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'role_label' => ['required', 'string', 'max:255'],
        ]);
        $data['id'] = $data['id'] ?? 'agent-'.Str::lower(Str::ulid());

        return response()->json(['data' => Agent::create($data)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $agent = Agent::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role_label' => ['sometimes', 'string', 'max:255'],
        ]);
        $agent->update($data);

        return response()->json(['data' => $agent->fresh()]);
    }
}
