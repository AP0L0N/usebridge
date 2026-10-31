<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\User;
use App\Services\Tickets\Actor;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Agent::query()->orderBy('name')->get()]);
    }

    /**
     * POST /api/agents {actor?, id: agent-[a-z0-9-], name, role_label?} (agents:write). Allowed for a token of the
     * workspace owner (no actor needed: go-live B3) and, as before, for Captain. 403 for anyone else, 422 for a
     * missing/invalid/duplicate id (ids are global). The agent belongs to the token's workspace. Agents are never
     * deleted (no DELETE route).
     */
    public function store(Request $request): JsonResponse
    {
        $actor = Actor::fromApi($request);
        $workspace = app(CurrentWorkspace::class)->get();
        $user = $request->user();
        abort_unless($actor->isCaptain() || ($user instanceof User && $workspace?->isOwnedBy($user)), 403,
            'Only the workspace owner (or Captain) can add agents.');
        $data = $request->validate([
            'id' => ['required', 'string', 'max:64', 'regex:/^agent-[a-z0-9]+(-[a-z0-9]+)*$/', 'not_in:'.Actor::TILEN.','.Actor::MIGRATION, 'unique:agents,id'],
            'name' => ['required', 'string', 'max:255'],
            'role_label' => ['nullable', 'string', 'max:255'],
        ], ['id.regex' => 'The id must look like agent-firstname (lowercase letters, digits, single dashes).',
            'id.unique' => 'An agent with this id already exists.']);
        $data['role_label'] = filled($data['role_label'] ?? null) ? $data['role_label'] : 'Agent';

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
